<?php
$__script_marker = 'IDX_WGET_COPY';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$pdo = getPDO();

// =====================
// CONFIG
// =====================
define('OUTPUT_DIR', __DIR__ . '/download_index_page');

define('CONCURRENCY', 5);
define('MAX_PER_DOMAIN', 2);
define('BATCH_SIZE', 10);
 define('HARD_KILL_SEC', 18);
define('TIMEOUT_SEC', 15);
define('TRIES', 1);
define('MAX_REDIRECTS', 3);
define('USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) IndexFetcher/1.0');

define('SKIP_IF_EXISTS', true);
define('MIN_FILE_BYTES', 200);

// =====================
// Helpers
// =====================
function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            fwrite(STDERR, "Impossibile creare cartella: $dir\n");
            exit(1);
        }
    }
}

function normalizeUrl($url): string {
    $url = trim((string)$url);
    if ($url === '' || $url === 'â€”' || $url === '-' || $url === 'â€“') return '';
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    return $url;
}

function hostFromUrl(string $url): string {
    $p = parse_url($url);
    return $p['host'] ?? '';
}

function sanitizeForFilename(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('~^www\.~', '', $s);
    $s = preg_replace('~[^a-z0-9]+~', '_', $s);
    $s = trim($s, '_');
    return $s ?: 'host';
}

function registrableDomain(string $host): string {
    $host = strtolower($host);
    $host = preg_replace('~:\d+$~', '', $host);
    $host = preg_replace('~^www\.~', '', $host);

    $parts = array_values(array_filter(explode('.', $host)));
    $n = count($parts);
    if ($n < 2) return $host;

    $multi = [
        'co.uk','org.uk','ac.uk',
        'com.au','net.au','org.au',
        'co.jp','co.kr','co.in',
        'com.ar','com.br','com.mx','com.tr','com.cn','com.hk','com.sg','com.sa',
        'com.co','com.pe','com.cl',
    ];

    $last2 = $parts[$n-2] . '.' . $parts[$n-1];
    if (in_array($last2, $multi, true) && $n >= 3) {
        return $parts[$n-3] . '.' . $last2;
    }
    return $last2;
}

function buildWgetCommand(string $url, string $outFile): array {
    return [
        'wget',
        '--max-redirect=' . MAX_REDIRECTS,
        '--timeout=' . TIMEOUT_SEC,
        '--tries=' . TRIES,
        '--dns-timeout=5',
'--connect-timeout=5',
'--read-timeout=10',
'--timeout=' . TIMEOUT_SEC,
        '-U', USER_AGENT,
        '--header=Accept: text/html,application/xhtml+xml',
        '-O', $outFile,
        '--server-response',
        '--quiet',
        $url,
    ];
}

function startProcess(array $cmd): array {
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return [null, null, null];

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return [$proc, $pipes[1], $pipes[2]];
}

function extractHttpCode(string $stderr): ?int {
    if (preg_match_all('~HTTP/\d\.\d\s+(\d{3})~', $stderr, $m) && !empty($m[1])) {
        return (int) end($m[1]);
    }
    return null;
}

function compactError(string $s, int $maxLen = 240): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if (mb_strlen($s, 'UTF-8') > $maxLen) {
        $s = mb_substr($s, 0, $maxLen, 'UTF-8') . '...';
    }
    return $s;
}

function which(string $bin): bool {
    $out = @shell_exec("command -v " . escapeshellarg($bin) . " 2>/dev/null");
    return is_string($out) && trim($out) !== '';
}

// =====================
// MAIN
// =====================
if (!which('wget')) {
    fwrite(STDERR, "wget non trovato. Installa: sudo apt-get install wget\n");
    exit(1);
}

ensureDir(OUTPUT_DIR);

// UPDATE: segna processato + top=1
$upd = $pdo->prepare("
    UPDATE `1_sites_titoli_da_degiro`
    SET top = '1',
        last_scan = NOW(),
        last_http_code = :code,
        last_error = :err
    WHERE id = :id
");

// RESET: quando finisce top=0
$resetTop = $pdo->prepare("UPDATE `1_sites_titoli_da_degiro` SET top = 0 WHERE top = '1'");

// SELECT batch
$selBatch = $pdo->prepare("
    SELECT id, website
    FROM `1_sites_titoli_da_degiro`
    WHERE top = '0'
    LIMIT " . BATCH_SIZE
);

$totalOk = 0;
$totalErr = 0;
$totalInvalid = 0;
$totalSkipped = 0;
$totalResets = 0;

while (true) {

    $selBatch->execute();
    $rows = $selBatch->fetchAll(PDO::FETCH_ASSOC);

    // Se non ci sono piÃ¹ righe top=0 -> reset e riparti
    if (!$rows) {
        $resetTop->execute();
        $totalResets++;

        // dopo reset, riprovo subito
        $selBatch->execute();
        $rows = $selBatch->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            echo "\nNessuna riga disponibile nemmeno dopo reset top (tabella vuota?).\n";
            break;
        }

        echo "\n[RESET] top riportato a 0. Riparto da capo. (reset #{$totalResets})\n";
    }

    $queue = new SplQueue();

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $url = normalizeUrl($r['website']);

        if ($url === '') {
            $upd->execute([':id'=>$id, ':code'=>null, ':err'=>'invalid_website']);
            $totalInvalid++;
            continue;
        }

        $host = hostFromUrl($url);
        if ($host === '' || !str_contains($host, '.')) {
            $upd->execute([':id'=>$id, ':code'=>null, ':err'=>'invalid_host']);
            $totalInvalid++;
            continue;
        }

        $domainKey = registrableDomain($host);
        $hostSafe  = sanitizeForFilename($host);

        $outFile = rtrim(OUTPUT_DIR, "/\\") . DIRECTORY_SEPARATOR . "id_{$id}__{$hostSafe}.html";

        if (SKIP_IF_EXISTS && is_file($outFile) && filesize($outFile) > MIN_FILE_BYTES) {
            $upd->execute([':id'=>$id, ':code'=>null, ':err'=>'skipped_file_exists']);
            $totalSkipped++;
            continue;
        }

        $queue->enqueue([
            'id' => $id,
            'url' => $url,
            'domain' => $domainKey,
            'out' => $outFile,
        ]);
    }

    if ($queue->isEmpty()) {
        echo "Batch: tutto invalido/skip (ma top aggiornato a 1). Prossimo batch.\n";
        continue;
    }

    echo "\nBatch job: {$queue->count()} | CONCURRENCY=" . CONCURRENCY . " | MAX_PER_DOMAIN=" . MAX_PER_DOMAIN . "\n";

    $active = [];
    $domainActive = [];

    while (!$queue->isEmpty() || !empty($active)) {

        // avvio processi rispettando MAX_PER_DOMAIN
        $attempts = $queue->count();
        while (count($active) < CONCURRENCY && $attempts > 0 && !$queue->isEmpty()) {
            $job = $queue->dequeue();
            $d = $job['domain'];
            $domainActive[$d] = $domainActive[$d] ?? 0;

            if ($domainActive[$d] >= MAX_PER_DOMAIN) {
                $queue->enqueue($job);
                $attempts--;
                continue;
            }

            $tmp = $job['out'] . '.part';
            @unlink($tmp);

            $cmd = buildWgetCommand($job['url'], $tmp);
            [$proc, $outPipe, $errPipe] = startProcess($cmd);

            if (!$proc) {
                $upd->execute([':id'=>$job['id'], ':code'=>null, ':err'=>'proc_open_failed']);
                echo "ERR ID={$job['id']} proc_open_failed {$job['url']}\n";
                $totalErr++;
                $attempts--;
                continue;
            }

            $domainActive[$d]++;

            $key = (int)$proc;   // cast della resource a int (id univoco)

            $active[$key] = [
                'proc' => $proc,
                'out' => $outPipe,
                'err' => $errPipe,
                'job' => $job,
                'tmp' => $tmp,
                'stderr' => '',
            ];

            $attempts--;
        }

        // check processi
        foreach ($active as $key => $a) {
            $a['stderr'] .= stream_get_contents($a['err']);

            $st = proc_get_status($a['proc']);
            if ($st['running']) {
                $active[$key] = $a;
                continue;
            }

            fclose($a['out']);
            fclose($a['err']);
            $exitCode = proc_close($a['proc']);

            $job = $a['job'];
            $http = extractHttpCode($a['stderr']);
            $sizeOk = is_file($a['tmp']) && filesize($a['tmp']) > MIN_FILE_BYTES;

            $httpOk = ($http !== null) && (
                ($http >= 200 && $http < 400) || $http === 401 || $http === 403
            );

            if (($exitCode === 0 && $sizeOk) || ($sizeOk && $httpOk)) {
                @rename($a['tmp'], $job['out']);
                $upd->execute([':id'=>$job['id'], ':code'=>$http, ':err'=>null]);
                echo "OK  ID={$job['id']} HTTP=" . ($http ?? '---') . " {$job['url']}\n";
                $totalOk++;
            } else {
                @unlink($a['tmp']);
                $errMsg = "exit={$exitCode}" . ($http ? " http={$http}" : "");
                $tail = compactError($a['stderr']);
                if ($tail !== '') $errMsg .= " | " . $tail;


                // evita stringhe troppo lunghe in DB
if (function_exists('mb_substr')) {
    $errMsg = mb_substr($errMsg, 0, 500, 'UTF-8');
} else {
    $errMsg = substr($errMsg, 0, 500);
}

                $upd->execute([':id'=>$job['id'], ':code'=>$http, ':err'=>$errMsg]);
                echo "ERR ID={$job['id']} HTTP=" . ($http ?? '---') . " exit={$exitCode} {$job['url']}\n";
                $totalErr++;
            }

            // libera slot dominio
            $d = $job['domain'];
            $domainActive[$d] = max(0, ($domainActive[$d] ?? 1) - 1);

            unset($active[$key]);
        }

        usleep(20000);
    }
}

echo "\nFINE.\nOK={$totalOk} | ERR={$totalErr} | INVALID={$totalInvalid} | SKIP={$totalSkipped} | RESETS={$totalResets}\n";

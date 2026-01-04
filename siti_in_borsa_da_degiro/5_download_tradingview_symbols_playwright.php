<!--cd /var/www/html/test/siti_in_borsa_da_degiro
nohup php 5_download_tradingview_symbols_playwright.php > tv_run.log 2>&1 & disown
-->


<?php
// 5_download_tradingview_symbols_playwright.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$pdo = getPDO();

define('OUTPUT_DIR', '/var/www/html/test/siti_in_borsa_da_degiro/tradingview');
define('LOG_DIR', OUTPUT_DIR . '/logs');

define('BATCH_SIZE', 1);
define('SKIP_IF_EXISTS', true);

// Pausa “normale” tra richieste (ms) -> jitter usato sotto
define('PAUSE_MIN_MS', 6000);
define('PAUSE_MAX_MS', 14000);

// Backoff quando rileva blocco/rate-limit (secondi)
define('BACKOFF_MIN_SEC', 900);   // 15 min
define('BACKOFF_MAX_SEC', 1800);  // 30 min

define('NODE_BIN', 'node');
define('FETCH_JS', '/var/www/html/test/siti_in_borsa_da_degiro/fetch_tv_one.js');

// profilo persistente Playwright
define('PROFILE_DIR', '/var/www/html/test/siti_in_borsa_da_degiro/tv_profile');

// ENV per fetch_tv_one.js
define('PW_HEADLESS', '0');   // 1=headless, 0=headed (xvfb)
define('PW_WAIT_MS',  '1200'); // attesa dopo goto
define('PW_NETIDLE',  '0');    // 1=prova networkidle

function ensureDir(string $dir, int $mode = 0775): void {
    if (!is_dir($dir)) {
        if (!mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new RuntimeException("Impossibile creare: $dir");
        }
    }
}

function symSafe(string $sym): string {
    $sym = strtoupper(trim($sym));
    $sym = preg_replace('~[^A-Z0-9._-]+~', '_', $sym);
    $sym = trim($sym, '_');
    return $sym !== '' ? $sym : 'NOSYM';
}

function looksBlocked(string $html): bool {
    $low = strtolower($html);
    $needles = [
        'too many requests',
        'access denied',
        'unusual traffic',
        'captcha',
        'verify you are human',
        'rate limit',
        'blocked',
        'temporarily blocked',
        '429',
    ];
    foreach ($needles as $n) {
        if (strpos($low, $n) !== false) return true;
    }
    return false;
}

ensureDir(OUTPUT_DIR);
ensureDir(LOG_DIR);
ensureDir(PROFILE_DIR);

$sel = $pdo->prepare("
    SELECT id, simbolo
    FROM `1_sites_titoli_da_degiro`
    WHERE top='0'
    ORDER BY id ASC
    LIMIT " . (int)BATCH_SIZE
");

// successo -> top=1
$markOk = $pdo->prepare("
    UPDATE `1_sites_titoli_da_degiro`
    SET top='1', last_scan=NOW(), last_error=:err
    WHERE id=:id
");

// fall -> top resta 0
$markFail = $pdo->prepare("
    UPDATE `1_sites_titoli_da_degiro`
    SET last_scan=NOW(), last_error=:err
    WHERE id=:id
");

while (true) {
    $sel->execute();
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "Nessuna riga top=0. Fine.\n";
        break;
    }

    foreach ($rows as $r) {
        $id  = (int)$r['id'];
        $sym = strtoupper(trim((string)$r['simbolo'])); // TRIM e basta

        if ($sym === '') {
            $markFail->execute([':id' => $id, ':err' => 'skip_empty_symbol']);
            echo "SKIP ID={$id} simbolo vuoto\n";
            continue;
        }

        $symFile = symSafe($sym);
        $url = 'https://it.tradingview.com/symbols/' . rawurlencode($sym) . '/';

        $outFile = OUTPUT_DIR . '/' . $symFile . '.html';
        $logFile = LOG_DIR . '/' . $symFile . '.log';

        if (SKIP_IF_EXISTS && is_file($outFile) && filesize($outFile) > 0) {
            $markOk->execute([':id' => $id, ':err' => 'skipped_file_exists']);
            echo "SKIP ID={$id} SYM={$sym} file esiste\n";
            continue;
        }

        // comando: passa ENV per profilo persistente e timing
        $cmd =
            'USER_DATA_DIR=' . escapeshellarg(PROFILE_DIR) . ' ' .
            'HEADLESS=' . escapeshellarg(PW_HEADLESS) . ' ' .
            'WAIT_MS='  . escapeshellarg(PW_WAIT_MS)  . ' ' .
            'NETIDLE='  . escapeshellarg(PW_NETIDLE)  . ' ' .
            escapeshellcmd(NODE_BIN) . ' ' .
            escapeshellarg(FETCH_JS) . ' ' .
            escapeshellarg($url) . ' ' .
            escapeshellarg($outFile) . ' ' .
            escapeshellarg($logFile);

        echo "RUN  ID={$id} SYM={$sym} URL={$url}\n";
        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);

        // se ha scaricato, controlla se è pagina di blocco
        $blocked = false;
        if (is_file($outFile) && filesize($outFile) > 0) {
            $html = @file_get_contents($outFile);
            if ($html !== false && looksBlocked($html)) {
                $blocked = true;
            }
        }

        if ($blocked) {
            $markFail->execute([':id' => $id, ':err' => 'blocked_backoff']);
            echo "BLOCKED ID={$id} SYM={$sym} -> backoff\n";

            // pausa lunga 15-30 min
            $sleep = random_int(BACKOFF_MIN_SEC, BACKOFF_MAX_SEC);
            echo "SLEEP {$sleep}s\n";
            sleep($sleep);
            continue;
        }

        if ($rc === 0 && is_file($outFile) && filesize($outFile) > 0) {
            $markOk->execute([':id' => $id, ':err' => 'playwright_ok']);
            echo "OK   ID={$id} SYM={$sym} saved\n";
        } else {
            $markFail->execute([':id' => $id, ':err' => 'playwright_fail_rc_' . $rc]);
            echo "FAIL ID={$id} SYM={$sym} rc={$rc}\n";
        }

        // jitter 6–14 secondi
        $pauseMs = random_int(PAUSE_MIN_MS, PAUSE_MAX_MS);
        echo "PAUSE {$pauseMs}ms\n";
        usleep($pauseMs * 1000);
    }
}

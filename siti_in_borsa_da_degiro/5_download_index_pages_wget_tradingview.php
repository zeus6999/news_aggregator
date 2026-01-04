<?php
// 5_download_tradingview_symbols_wget_bg.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$pdo = getPDO();

// =====================
// CONFIG
// =====================
define('OUTPUT_DIR', '/var/www/html/test/siti_in_borsa_da_degiro/tradingview');
define('LOG_DIR', OUTPUT_DIR . '/logs');
define('COOKIE_DIR', OUTPUT_DIR . '/cookies');

define('OWNER_USER', 'www-data');
define('OWNER_GROUP', 'www-data');
define('DIR_MODE', 0775);
define('FILE_MODE', 0664);

define('BATCH_SIZE', 10);
define('PAUSE_MS', 20000);
define('MAX_BG', 2);

define('TIMEOUT_SEC', 60);
define('TRIES', 1);
define('MAX_REDIRECTS', 3);

define('SKIP_IF_EXISTS', true);

define('RUN_TAG', 'TVSYM_' . getmypid() . '_' . date('Ymd_His'));
define('USER_AGENT', 'TVSymbolFetcherBG/1.0 ' . RUN_TAG);

// =====================
// Helpers
// =====================
function ensureDir(string $dir, int $mode = 0775): void {
    if (!is_dir($dir)) {
        if (!mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new RuntimeException("Impossibile creare: $dir");
        }
    }
}

function which(string $bin): bool {
    $out = @shell_exec("command -v " . escapeshellarg($bin) . " 2>/dev/null");
    return is_string($out) && trim($out) !== '';
}

function chownChgrpRecursive(string $path, string $user, string $group): void {
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        throw new RuntimeException("Serve root per chown/chgrp: $path");
    }

    @chown($path, $user);
    @chgrp($path, $group);

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        $p = $item->getPathname();
        @chown($p, $user);
        @chgrp($p, $group);
    }
}

function chmodRecursive(string $path, int $dirMode, int $fileMode): void {
    @chmod($path, $dirMode);

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        $p = $item->getPathname();
        if ($item->isDir()) @chmod($p, $dirMode);
        else @chmod($p, $fileMode);
    }
}

/**
 * Conta SOLO i processi wget lanciati da questa esecuzione (RUN_TAG nello User-Agent).
 */
function bgCountWgetThisRun(): int {
    $cmd = "pgrep -fa wget | grep -F " . escapeshellarg(RUN_TAG) . " | wc -l";
    $out = @shell_exec($cmd);
    return (int)trim((string)$out);
}

/**
 * Normalizza simbolo per filename
 */
function symSafe(string $sym): string {
    $sym = strtoupper(trim($sym));
    $sym = preg_replace('~[^A-Z0-9._-]+~', '_', $sym);
    $sym = trim($sym, '_');
    return $sym !== '' ? $sym : 'NOSYM';
}

// =====================
// MAIN
// =====================
if (!which('wget')) {
    fwrite(STDERR, "wget non trovato. Installa: sudo apt-get install wget\n");
    exit(1);
}

// setup cartelle + permessi (root)
try {
    ensureDir(OUTPUT_DIR, DIR_MODE);
    ensureDir(LOG_DIR, DIR_MODE);
    ensureDir(COOKIE_DIR, DIR_MODE);

    chownChgrpRecursive(OUTPUT_DIR, OWNER_USER, OWNER_GROUP);
    chmodRecursive(OUTPUT_DIR, DIR_MODE, FILE_MODE);

    echo "OK setup perms/owner su: " . OUTPUT_DIR . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "SETUP ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Seleziona RIGHE (non simboli unici): in ordine per ID
$sel = $pdo->prepare("
    SELECT id, simbolo
    FROM `1_sites_titoli_da_degiro`
    WHERE top='0'
    ORDER BY id ASC
    LIMIT " . (int)BATCH_SIZE
);

// Marca una singola riga per ID
$markQueued = $pdo->prepare("
    UPDATE `1_sites_titoli_da_degiro`
    SET top='1', last_scan=NOW(), last_error=:err
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
        $id     = (int)$r['id'];
        $symRaw = (string)$r['simbolo'];
        $sym    = strtoupper(trim($symRaw)); // <-- TRIM e basta

        // Se simbolo vuoto, marca e vai avanti (così non rimane bloccato)
        if ($sym === '') {
            $markQueued->execute([':id' => $id, ':err' => 'skip_empty_symbol']);
            echo "SKIP ID={$id} simbolo vuoto\n";
            continue;
        }

        $symFile = symSafe($sym);

        $url = 'https://it.tradingview.com/symbols/' . rawurlencode($sym) . '/';

        $outFile = rtrim(OUTPUT_DIR, "/\\") . DIRECTORY_SEPARATOR . $symFile . ".html";
        $logFile = rtrim(LOG_DIR, "/\\") . DIRECTORY_SEPARATOR . $symFile . ".log";

        if (SKIP_IF_EXISTS && is_file($outFile) && filesize($outFile) > 0) {
            $markQueued->execute([':id' => $id, ':err' => 'skipped_file_exists']);
            echo "SKIP ID={$id} SYM={$sym} file esiste\n";
            continue;
        }

        // Segna subito la riga (ORA GIUSTO: :id)
        $markQueued->execute([':id' => $id, ':err' => 'queued_' . RUN_TAG]);

        // Limite processi: SOLO quelli di questo script/run
        while (bgCountWgetThisRun() >= MAX_BG) {
            usleep(200000); // 200ms
        }

        $uaReal = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 ' . RUN_TAG;

        // cookie per dominio (sempre tradingview)
        $hostSafe = 'it_tradingview_com';
        $cookieFile = rtrim(COOKIE_DIR, "/\\") . DIRECTORY_SEPARATOR . $hostSafe . ".txt";

        if (!is_file($cookieFile)) {
            @file_put_contents($cookieFile, "");
            @chmod($cookieFile, FILE_MODE);
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                @chown($cookieFile, OWNER_USER);
                @chgrp($cookieFile, OWNER_GROUP);
            }
        }

        $cookieArgs = [
            '--save-cookies=' . escapeshellarg($cookieFile),
            '--keep-session-cookies',
            '--load-cookies=' . escapeshellarg($cookieFile),
        ];

        $cmd = implode(' ', array_merge([
            'wget',
            '-b',
            '-4',
            '--compression=auto',
            '--https-only',

            '--max-redirect=' . (int)MAX_REDIRECTS,
            '--timeout=' . (int)TIMEOUT_SEC,
            '--tries=' . (int)TRIES,
            '--dns-timeout=5',
            '--connect-timeout=15',
            '--read-timeout=60',
        ], $cookieArgs, [
            '--header=' . escapeshellarg('Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
            '--header=' . escapeshellarg('Accept-Language: it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7'),
            '--header=' . escapeshellarg('Cache-Control: no-cache'),
            '--header=' . escapeshellarg('Pragma: no-cache'),
            '--referer=' . escapeshellarg('https://it.tradingview.com/'),

            '-U', escapeshellarg($uaReal),
            '-o', escapeshellarg($logFile),
            '-O', escapeshellarg($outFile),
            escapeshellarg($url),
        ]));

        @shell_exec($cmd);

        echo "QUEUED ID={$id} SYM={$sym} (bg=" . bgCountWgetThisRun() . ") URL={$url}\n";
        usleep(PAUSE_MS * 1000);
    }
}

echo "RUN_TAG = " . RUN_TAG . "\n";
echo "Output  = " . OUTPUT_DIR . "\n";
echo "Logs    = " . LOG_DIR . "\n";
echo "Cookies = " . COOKIE_DIR . "\n";

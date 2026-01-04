<?php
// wget_module.php
// MODULO PURO WGET: config + setup cartelle + cookie + build comando + throttle.
// UNA SOLA FUNZIONE PUBBLICA: wget_fetch()

// =====================
// CONFIG (SOLO WGET)
// =====================
define('WGET_OUTPUT_DIR', __DIR__ . '/../download_index_page');
define('WGET_LOG_DIR', WGET_OUTPUT_DIR . '/logs');
define('WGET_COOKIE_DIR', WGET_OUTPUT_DIR . '/cookies');

define('WGET_OWNER_USER', 'www-data');
define('WGET_OWNER_GROUP', 'www-data');
define('WGET_DIR_MODE', 0775);
define('WGET_FILE_MODE', 0664);

define('WGET_PAUSE_MS', 120);
define('WGET_MAX_BG', 10);

// timeout / rete
define('WGET_TIMEOUT_SEC', 60);
define('WGET_TRIES', 1);
define('WGET_MAX_REDIRECTS', 3);

// flags
define('WGET_FORCE_IPV4', true);
define('WGET_CONVERT_LINKS', true); // -k
define('WGET_FORCE_HTML_EXT', true); // -E
define('WGET_COMPRESSION_AUTO', true);
define('WGET_HTTPS_ONLY', true);
define('WGET_WAIT_SEC', 0.2);
define('WGET_RANDOM_WAIT', true);
define('WGET_CONTENT_ON_ERROR', true);
define('WGET_NO_VERBOSE', true);

// Header base
define('WGET_HDR_ACCEPT', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8');
define('WGET_HDR_ACCEPT_LANG', 'it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7');

// UA base (RUN_TAG viene aggiunto)
define('WGET_UA_BASE', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// =====================
// INTERNAL STATE (RUN_TAG per questo processo)
// =====================
$GLOBALS['__WGET_RUN_TAG__'] = null;

// =====================
// INTERNAL HELPERS (WGET ONLY)
// =====================
function __wget_which(string $bin): bool {
    $out = @shell_exec("command -v " . escapeshellarg($bin) . " 2>/dev/null");
    return is_string($out) && trim($out) !== '';
}

function __wget_ensure_dir(string $dir, int $mode): void {
    if (!is_dir($dir)) {
        if (!mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new RuntimeException("Impossibile creare: $dir");
        }
    }
}

function __wget_chmod_recursive(string $path, int $dirMode, int $fileMode): void {
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

function __wget_chown_chgrp_recursive(string $path, string $user, string $group): void {
    // SOLO se root. Se non root, non blocca mai.
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) return;

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

function __wget_force_https(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (stripos($url, 'https://') === 0) return $url;
    if (stripos($url, 'http://') === 0) return 'https://' . substr($url, 7);
    return 'https://' . ltrim($url, '/');
}

function __wget_homepage_referer(string $url): string {
    $p = @parse_url($url);
    if (!$p || empty($p['host'])) return $url;
    $scheme = !empty($p['scheme']) ? $p['scheme'] : 'https';
    return $scheme . '://' . $p['host'] . '/';
}

function __wget_host_safe(?string $host): string {
    if (!$host) return 'nohost';
    $host = strtolower($host);
    $safe = preg_replace('~[^a-z0-9]+~i', '_', $host);
    return $safe ?: 'nohost';
}

function __wget_run_tag(): string {
    if (empty($GLOBALS['__WGET_RUN_TAG__'])) {
        $GLOBALS['__WGET_RUN_TAG__'] = 'WGET_' . getmypid() . '_' . date('Ymd_His');
    }
    return $GLOBALS['__WGET_RUN_TAG__'];
}

function __wget_setup_environment(): void {
    static $done = false;
    if ($done) return;

    if (!__wget_which('wget')) {
        throw new RuntimeException("wget non trovato. Installa: sudo apt-get install wget");
    }

    __wget_ensure_dir(WGET_OUTPUT_DIR, WGET_DIR_MODE);
    __wget_ensure_dir(WGET_LOG_DIR, WGET_DIR_MODE);
    __wget_ensure_dir(WGET_COOKIE_DIR, WGET_DIR_MODE);

    // perms/owner: se non sei root non si lamenta
    __wget_chown_chgrp_recursive(WGET_OUTPUT_DIR, WGET_OWNER_USER, WGET_OWNER_GROUP);
    __wget_chmod_recursive(WGET_OUTPUT_DIR, WGET_DIR_MODE, WGET_FILE_MODE);

    $done = true;
}

/**
 * Conta SOLO i processi wget di questo run (RUN_TAG nello user-agent)
 */
function __wget_bg_count_this_run(): int {
    $tag = __wget_run_tag();
    $cmd = "pgrep -fa wget | grep -F " . escapeshellarg($tag) . " | wc -l";
    $out = @shell_exec($cmd);
    return (int)trim((string)$out);
}

function __wget_cookie_file_for_url(string $url): string {
    $host = parse_url($url, PHP_URL_HOST);
    $hostSafe = __wget_host_safe($host);
    $cookieFile = rtrim(WGET_COOKIE_DIR, "/\\") . DIRECTORY_SEPARATOR . $hostSafe . ".txt";

    if (!is_file($cookieFile)) {
        @file_put_contents($cookieFile, "");
        @chmod($cookieFile, WGET_FILE_MODE);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($cookieFile, WGET_OWNER_USER);
            @chgrp($cookieFile, WGET_OWNER_GROUP);
        }
    }
    return $cookieFile;
}

function __wget_build_cmd(string $url, string $outFile, string $logFile, bool $bg, string $ua, string $referer): string {
    $cookieFile = __wget_cookie_file_for_url($url);

    $parts = [];
    $parts[] = 'wget';
    if ($bg) $parts[] = '-b';
    if (WGET_FORCE_IPV4) $parts[] = '-4';
    if (WGET_CONVERT_LINKS) $parts[] = '-k';
    if (WGET_FORCE_HTML_EXT) $parts[] = '-E';

    if (WGET_COMPRESSION_AUTO) $parts[] = '--compression=auto';
    if (WGET_HTTPS_ONLY) $parts[] = '--https-only';

    $parts[] = '--max-redirect=' . (int)WGET_MAX_REDIRECTS;
    $parts[] = '--tries=' . (int)WGET_TRIES;
    $parts[] = '--timeout=' . (int)WGET_TIMEOUT_SEC;
    $parts[] = '--dns-timeout=5';
    $parts[] = '--connect-timeout=15';
    $parts[] = '--read-timeout=60';

    $parts[] = '--wait=' . (float)WGET_WAIT_SEC;
    if (WGET_RANDOM_WAIT) $parts[] = '--random-wait';

    if (WGET_CONTENT_ON_ERROR) $parts[] = '--content-on-error';
    if (WGET_NO_VERBOSE) $parts[] = '--no-verbose';

    // cookie persistenti per host
    $parts[] = '--save-cookies=' . escapeshellarg($cookieFile);
    $parts[] = '--keep-session-cookies';
    $parts[] = '--load-cookies=' . escapeshellarg($cookieFile);

    // header browser-like
    $parts[] = '--header=' . escapeshellarg('Accept: ' . WGET_HDR_ACCEPT);
    $parts[] = '--header=' . escapeshellarg('Accept-Language: ' . WGET_HDR_ACCEPT_LANG);
    $parts[] = '--header=' . escapeshellarg('Cache-Control: no-cache');
    $parts[] = '--header=' . escapeshellarg('Pragma: no-cache');
    $parts[] = '--header=' . escapeshellarg('Upgrade-Insecure-Requests: 1');
    $parts[] = '--header=' . escapeshellarg('DNT: 1');

    // referer + UA (con RUN_TAG)
    $parts[] = '--referer=' . escapeshellarg($referer);
    $parts[] = '-U';
    $parts[] = escapeshellarg($ua);

    // log + output
    $parts[] = '-o';
    $parts[] = escapeshellarg($logFile);
    $parts[] = '-O';
    $parts[] = escapeshellarg($outFile);

    // URL
    $parts[] = escapeshellarg($url);

    return implode(' ', $parts);
}

// =====================
// UNICA FUNZIONE PUBBLICA
// =====================
/**
 * wget_fetch()
 * - modulo puro wget, nessun legame con DB o tabelle.
 *
 * Params:
 *  $url, $outFile, $logFile
 * Options in $opt:
 *  - bg (bool) default true
 *  - run_tag (string) se vuoi forzare tag esterno (altrimenti auto)
 *  - user_agent (string) UA base (senza RUN_TAG) default WGET_UA_BASE
 *  - referer (string) override (default homepage dell'url)
 *  - throttle (bool) default true (rispetta WGET_MAX_BG solo se bg=true)
 *
 * Return:
 *  array con cmd, out, log, url, bg_count, status
 */
function wget_fetch(string $url, string $outFile, string $logFile, array $opt = []): array {
    __wget_setup_environment();

    $url = __wget_force_https($url);
    if ($url === '') {
        return ['status' => 'error_empty_url'];
    }

    // set run tag (se passato, lo usa)
    if (!empty($opt['run_tag']) && is_string($opt['run_tag'])) {
        $GLOBALS['__WGET_RUN_TAG__'] = $opt['run_tag'];
    }
    $tag = __wget_run_tag();

    $bg = array_key_exists('bg', $opt) ? (bool)$opt['bg'] : true;
    $throttle = array_key_exists('throttle', $opt) ? (bool)$opt['throttle'] : true;

    $uaBase = (!empty($opt['user_agent']) && is_string($opt['user_agent']))
        ? $opt['user_agent']
        : WGET_UA_BASE;

    $ua = $uaBase . ' ' . $tag;

    $referer = (!empty($opt['referer']) && is_string($opt['referer']))
        ? $opt['referer']
        : __wget_homepage_referer($url);

    // throttle solo se bg=true
    if ($bg && $throttle) {
        while (__wget_bg_count_this_run() >= WGET_MAX_BG) {
            usleep(200000);
        }
    }

    $cmd = __wget_build_cmd($url, $outFile, $logFile, $bg, $ua, $referer);
    @shell_exec($cmd);

    usleep(WGET_PAUSE_MS * 1000);

    return [
        'status' => 'queued',
        'url' => $url,
        'out' => $outFile,
        'log' => $logFile,
        'cmd' => $cmd,
        'run_tag' => $tag,
        'bg_count' => __wget_bg_count_this_run(),
    ];
}

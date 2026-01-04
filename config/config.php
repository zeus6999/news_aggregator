<?php
// config.php
// Configurazione database - MODIFICA QUESTI VALORI

define('DB_HOST', 'localhost');
define('DB_NAME', 'news_aggregator');
define('DB_USER', 'cristian_news_aggregator');
define('DB_PASS', 'yo2UXPCSxn');

/**
 * Scrive un messaggio di log su file aggregator.log
 */
function logMsg(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/aggregator.log', $line, FILE_APPEND);
}

/**
 * Scarica una pagina HTML usando cURL con User-Agent di browser.
 */
/**
 * Normalizza un hostname:
 * - minuscolo
 * - rimuove "www." iniziale
 */
function normalizeHost(string $host): string
{
    $host = strtolower(trim($host));
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return $host;
}

/**
 * Scarica una pagina "come browser".
 * Se $allowedHost != null, non accetta redirect verso domini diversi.
 * Segue anche meta-refresh interno (sempre rispettando allowedHost).
 */
function httpGetLikeBrowser(string $url, ?string $allowedHost = null, int $depth = 0): ?string
{
    // per sicurezza evitiamo loop infiniti
    if ($depth > 2) {
        return null;
    }

    $ch = curl_init();

    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.7',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:117.0) Gecko/20100101 Firefox/117.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HEADER         => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_REFERER        => 'https://www.google.com/',
    ]);

    $body = curl_exec($ch);

    if ($body === false) {
        curl_close($ch);
        return null;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($httpCode >= 400) {
        return null;
    }

    // controllo dominio finale dopo redirect HTTP
    if ($allowedHost !== null) {
        $allowedNorm = normalizeHost($allowedHost);
        $finalHost   = parse_url($finalUrl ?: $url, PHP_URL_HOST) ?? '';
        $finalNorm   = normalizeHost($finalHost);

        if ($allowedNorm !== '' && $finalNorm !== '' && $allowedNorm !== $finalNorm) {
            // redirect verso dominio esterno -> rifiuto
            return null;
        }
    }

    // ---- CONTROLLO META-REFRESH (HTML) ----
    if (preg_match('~<meta\s+http-equiv=["\']refresh["\'][^>]*content=["\'][^;]+;\s*url=([^"\']+)["\']~i', $body, $m)) {
        $refreshUrl = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // costruiamo URL assoluto usando l'URL finale corrente
        if (function_exists('absolutizeUrl')) {
            $nextUrl = absolutizeUrl($finalUrl ?: $url, $refreshUrl);
        } else {
            $nextUrl = $refreshUrl;
        }

        // se c'è allowedHost, controlliamo che anche la meta-refresh resti sullo stesso dominio
        if ($allowedHost !== null) {
            $allowedNorm = normalizeHost($allowedHost);
            $nextHost    = parse_url($nextUrl, PHP_URL_HOST) ?? '';
            $nextNorm    = normalizeHost($nextHost);

            if ($allowedNorm !== '' && $nextNorm !== '' && $allowedNorm !== $nextNorm) {
                return null;
            }
        }

        // richiamiamo noi stessi per la pagina "vera"
        return httpGetLikeBrowser($nextUrl, $allowedHost, $depth + 1);
    }

    return $body;
}

/**
 * Converte un URL relativo in assoluto rispetto a una base.
 */
function absolutizeUrl(string $base, string $relative): string
{
    // già assoluto (http, https, mailto, ecc.)
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*:~', $relative)) {
        return $relative;
    }

    // protocol-relative: //example.com/...
    if (strpos($relative, '//') === 0) {
        $baseParts = parse_url($base);
        $scheme = $baseParts['scheme'] ?? 'https';
        return $scheme . ':' . $relative;
    }

    $baseParts = parse_url($base);
    if (!$baseParts || !isset($baseParts['scheme'], $baseParts['host'])) {
        return $relative;
    }

    $scheme = $baseParts['scheme'];
    $host   = $baseParts['host'];
    $port   = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    $basePath = $baseParts['path'] ?? '/';

    // href che inizia con / -> relativo alla root del sito
    if (strpos($relative, '/') === 0) {
        $path = $relative;
    } else {
        // relativo al path della pagina
        if (substr($basePath, -1) !== '/') {
            $basePath = preg_replace('~/[^/]*$~', '/', $basePath);
        }
        $path = $basePath . $relative;
    }

    // normalizza /./ e /../
    $segments = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($segments);
        } else {
            $segments[] = $seg;
        }
    }
    $normalizedPath = '/' . implode('/', $segments);

    return sprintf('%s://%s%s%s', $scheme, $host, $port, $normalizedPath);
}


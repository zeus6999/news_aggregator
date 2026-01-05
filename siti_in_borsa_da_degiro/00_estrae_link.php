<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$pdo = getPDO();

function isSameOrSubdomain(string $host, string $baseHost): bool
{
    if ($baseHost === '') {
        return false;
    }
    if ($host === $baseHost) {
        return true;
    }
    return substr($host, -strlen('.' . $baseHost)) === '.' . $baseHost;
}

function loadPublicSuffixList(string $path): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [
        'rules'      => [],
        'wildcards'  => [],
        'exceptions' => [],
    ];

    if (!is_file($path)) {
        return $cache;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $cache;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '//') === 0) {
            continue;
        }

        if ($line[0] === '!') {
            $cache['exceptions'][substr($line, 1)] = true;
            continue;
        }

        if (strpos($line, '*.') === 0) {
            $cache['wildcards'][substr($line, 2)] = true;
            continue;
        }

        $cache['rules'][$line] = true;
    }

    return $cache;
}

function getPublicSuffixLength(string $host, array $psl): int
{
    $labels = explode('.', $host);
    $n = count($labels);
    if ($n <= 1) {
        return 1;
    }

    $matchLen = 0;
    $wildcardLen = 0;
    $exceptionLen = 0;

    for ($i = 0; $i < $n; $i++) {
        $suffix = implode('.', array_slice($labels, $i));
        $len = $n - $i;

        if (isset($psl['exceptions'][$suffix])) {
            if ($len > $exceptionLen) {
                $exceptionLen = $len;
            }
        }

        if (isset($psl['rules'][$suffix])) {
            if ($len > $matchLen) {
                $matchLen = $len;
            }
        }

        if ($i > 0 && isset($psl['wildcards'][$suffix])) {
            $wildcardCandidate = $len + 1;
            if ($wildcardCandidate > $wildcardLen) {
                $wildcardLen = $wildcardCandidate;
            }
        }
    }

    if ($exceptionLen > 0) {
        return max(1, $exceptionLen - 1);
    }

    $maxMatch = max($matchLen, $wildcardLen);
    return $maxMatch > 0 ? $maxMatch : 1;
}

function getRegistrableDomain(string $host, array $psl): string
{
    $host = strtolower(trim($host, '.'));
    if ($host === '') {
        return '';
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return $host;
    }

    $labels = explode('.', $host);
    if (count($labels) <= 1) {
        return $host;
    }

    $publicLen = getPublicSuffixLength($host, $psl);
    $labelsCount = count($labels);

    if ($publicLen >= $labelsCount) {
        return $host;
    }

    $start = $labelsCount - ($publicLen + 1);
    return implode('.', array_slice($labels, $start));
}


$downloadDir = '/home/cristian/manda.it/public/news_aggregator/download_index_page';

if (!is_dir($downloadDir)) {
    echo "Directory non trovata: {$downloadDir}" . PHP_EOL;
    exit;
}

$stmt = $pdo->query("
    SELECT id, website
    FROM 1_sites_titoli_da_degiro
    WHERE website IS NOT NULL
      AND website <> ''
");
$sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$sites) {
    echo "Nessun sito con website valorizzato." . PHP_EOL;
    exit;
}

$insertLink = $pdo->prepare("
    INSERT IGNORE INTO 0_homepage_links
        (site_id, url, anchor_text, host, source_file)
    VALUES
        (:site_id, :url, :anchor_text, :host, :source_file)
");

$psl = loadPublicSuffixList(__DIR__ . '/../config/public_suffix_list.dat');

foreach ($sites as $site) {
    $siteId = (int) $site['id'];
    $baseUrl = trim((string) $site['website']);

    if ($baseUrl === '') {
        continue;
    }

    $baseHost = parse_url($baseUrl, PHP_URL_HOST);
    if (!$baseHost) {
        echo "URL non valida per site_id {$siteId}: {$baseUrl}" . PHP_EOL;
        continue;
    }
    $baseHostNorm = normalizeHost($baseHost);
    $baseDomain = getRegistrableDomain($baseHostNorm, $psl) ?: $baseHostNorm;

    $filePath = $downloadDir . DIRECTORY_SEPARATOR . $siteId . '.html';
    if (!is_file($filePath)) {
        echo "File non trovato per site_id {$siteId}: {$filePath}" . PHP_EOL;
        continue;
    }

    $html = file_get_contents($filePath);
    if ($html === false) {
        echo "Errore lettura file per site_id {$siteId}: {$filePath}" . PHP_EOL;
        continue;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) {
        libxml_clear_errors();
        echo "Errore parsing HTML per site_id {$siteId}: {$filePath}" . PHP_EOL;
        continue;
    }
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $links = $xpath->query('//a[@href]');
    if ($links === false) {
        continue;
    }

    $inserted = 0;
    foreach ($links as $a) {
        $href = trim($a->getAttribute('href'));
        if ($href === '') {
            continue;
        }

        $abs = absolutizeUrl($baseUrl, $href);
        $linkHost = parse_url($abs, PHP_URL_HOST);
        if (!$linkHost) {
            continue;
        }

        $linkHostNorm = normalizeHost($linkHost);
        if (!isSameOrSubdomain($linkHostNorm, $baseDomain)) {
            continue;
        }

        $anchorText = trim($a->textContent);

        $insertLink->execute([
            ':site_id'    => $siteId,
            ':url'        => $abs,
            ':anchor_text'=> $anchorText,
            ':host'       => $linkHostNorm,
            ':source_file'=> basename($filePath),
        ]);
        $inserted++;
    }

    echo "site_id {$siteId}: link inseriti {$inserted}" . PHP_EOL;
}

echo "Estrazione completata." . PHP_EOL;

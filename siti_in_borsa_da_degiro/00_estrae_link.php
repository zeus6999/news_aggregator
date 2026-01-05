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
        if (!isSameOrSubdomain($linkHostNorm, $baseHostNorm)) {
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

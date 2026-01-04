<?php
// 01_scan_homepages.php
// Legge i siti in `sites` con stop = 0, scarica la homepage, cerca feed RSS/Atom/XML,
// inserisce i feed nella tabella `feeds`, e aggiorna i flag in `sites`:
// - stop = 1 (analizzato)
// - contiene_feed = 'si' se è stato trovato almeno un feed, altrimenti rimane 'no'.

require_once __DIR__ . '/../config/db.php';    

$pdo = getPDO();



/**
 * Trova tutti i feed RSS/Atom/XML nella homepage.
 */
function findFeedsOnHomepage(string $html, string $baseUrl): array
{
    $feeds = [];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) {
        libxml_clear_errors();
        return $feeds;
    }
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // <link rel="alternate" type="application/rss+xml" ...>
    $nodes = $xpath->query('//link[@rel="alternate"][@type]');
    foreach ($nodes as $node) {
        $type = strtolower((string) $node->getAttribute('type'));
        if (strpos($type, 'rss') !== false || strpos($type, 'atom') !== false || strpos($type, 'xml') !== false) {
            $href = $node->getAttribute('href');
            if ($href) {
                $feeds[] = absolutizeUrl($baseUrl, $href);
            }
        }
    }

    // <a href="..."> con indizi nel nome (rss, feed, .xml)
    $nodes = $xpath->query('//a[@href]');
    foreach ($nodes as $a) {
        $href = $a->getAttribute('href');
        $text = strtolower(trim($a->textContent));
        $hrefLower = strtolower($href);

        $isCandidate = false;

        if (strpos($hrefLower, 'rss') !== false ||
            strpos($hrefLower, 'feed') !== false ||
            preg_match('/\.xml($|\?)/', $hrefLower)
        ) {
            $isCandidate = true;
        }

        if (!$isCandidate && ($text === 'rss' || $text === 'feed')) {
            $isCandidate = true;
        }

        if ($isCandidate) {
            $feeds[] = absolutizeUrl($baseUrl, $href);
        }
    }

    $feeds = array_values(array_unique($feeds));

    return $feeds;
}

// -------- LOGICA PRINCIPALE --------

// Prendo i siti da analizzare (stop = 0)
$stmt = $pdo->query("SELECT id, name, url, contiene_feed FROM sites WHERE stop = 0");
$sites = $stmt->fetchAll();

if (!$sites) {
    echo "Nessun sito da analizzare (stop = 0)." . PHP_EOL;
    exit;
}

$insertFeed = $pdo->prepare("INSERT IGNORE INTO feeds (site_id, url, type, active, last_checked)
    VALUES (:site_id, :url, :type, 1, NOW())");

$updateSite = $pdo->prepare("UPDATE sites
    SET stop = 1,
        contiene_feed = :contiene_feed,
        last_scan = NOW()
    WHERE id = :id");

foreach ($sites as $site) {
    $siteId = (int) $site['id'];
    $homeUrl = $site['url'];

    echo "Analizzo homepage: {$homeUrl}" . PHP_EOL;
    logMsg("Analizzo homepage: {$homeUrl}");

    $html = httpGetLikeBrowser($homeUrl);

    if ($html === null) {
        echo "  Errore nel download della homepage." . PHP_EOL;
        logMsg("Errore HTTP scaricando homepage: {$homeUrl}");

        $updateSite->execute([
            ':contiene_feed' => 'no',
            ':id'            => $siteId
        ]);
        continue;
    }

  $feeds = findFeedsOnHomepage($html, $homeUrl);

if (empty($feeds)) {
    echo "  Nessun feed trovato." . PHP_EOL;
    logMsg("Nessun feed trovato per: {$homeUrl}");

    // nessun candidato feed -> contiene_feed resta 'no'
    $updateSite->execute([
        ':contiene_feed' => 'no',
        ':id'            => $siteId
    ]);
} else {
    $validFeedFound = false;

    foreach ($feeds as $feedUrl) {
        echo "  Trovato candidato feed: {$feedUrl}" . PHP_EOL;
        logMsg("Candidato feed per {$homeUrl}: {$feedUrl}");

        // 1) Scarico il contenuto del "feed"
        $xmlString = httpGetLikeBrowser($feedUrl);
        if ($xmlString === null) {
            echo "    -> Errore HTTP, salto." . PHP_EOL;
            logMsg("Errore HTTP sul candidato feed: {$feedUrl}");
            continue;
        }

        // 2) Controllo minimo: contiene tag tipici di feed?
        $test = ltrim($xmlString);
        if (
            stripos($test, '<rss') === false &&
            stripos($test, '<feed') === false &&
            stripos($test, '<rdf:RDF') === false
        ) {
            echo "    -> Il contenuto NON sembra un feed XML (probabile HTML), salto." . PHP_EOL;
            logMsg("Candidato NON feed (HTML?) per {$feedUrl}");
            continue;
        }

        // 3) Provo davvero a parsare XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        if ($xml === false) {
            libxml_clear_errors();
            echo "    -> XML non valido, salto." . PHP_EOL;
            logMsg("Parsing XML fallito per candidato feed: {$feedUrl}");
            continue;
        }
        libxml_clear_errors();

        // Se arrivo qui, è un feed VERO
        $type = 'xml';
        $lower = strtolower($feedUrl);
        if (strpos($lower, 'rss') !== false) {
            $type = 'rss';
        } elseif (strpos($lower, 'atom') !== false) {
            $type = 'atom';
        }

        echo "    -> Confermato feed valido, salvo in tabella." . PHP_EOL;
        logMsg("Feed VALIDO per {$homeUrl}: {$feedUrl}");

        $insertFeed->execute([
            ':site_id' => $siteId,
            ':url'     => $feedUrl,
            ':type'    => $type
        ]);

        $validFeedFound = true;
    }

    // Se ho trovato almeno un feed valido, segno contiene_feed = 'si'
    $updateSite->execute([
        ':contiene_feed' => $validFeedFound ? 'si' : 'no',
        ':id'            => $siteId
    ]);
}

}

echo "Analisi homepage completata." . PHP_EOL;

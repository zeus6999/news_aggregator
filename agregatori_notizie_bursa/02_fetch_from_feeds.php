<?php
// 02_fetch_from_feeds.php
// Legge i feed attivi dalla tabella `feeds`, scarica i feed RSS/Atom,
// estrae gli item e inserisce/aggiorna le notizie nella tabella `news`
// (solo meta-dati, status = 'new', senza contenuto completo).

require_once __DIR__ . '/../config/db.php';    

$pdo = getPDO();



/**
 * Parsing dei feed RSS/Atom/RDF in formato uniforme.
 */
function parseFeed(SimpleXMLElement $xml): array
{
    $items = [];

    // RSS 2.0
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $items[] = [
                'title' => (string) $item->title,
                'link'  => (string) $item->link,
                'desc'  => (string) $item->description,
                'date'  => !empty($item->pubDate)
                    ? date('Y-m-d H:i:s', strtotime((string) $item->pubDate))
                    : null,
            ];
        }
        return $items;
    }

    // Atom
    if (isset($xml->entry)) {
        foreach ($xml->entry as $entry) {
            $link = '';
            if (isset($entry->link['href'])) {
                $link = (string) $entry->link['href'];
            } elseif (isset($entry->link)) {
                $link = (string) $entry->link;
            }

            $summary = (string) $entry->summary;
            $content = (string) $entry->content;
            $desc    = $summary ?: $content;

            $dateStr = (string) ($entry->updated ?? $entry->published ?? '');
            $date    = $dateStr ? date('Y-m-d H:i:s', strtotime($dateStr)) : null;

            $items[] = [
                'title' => (string) $entry->title,
                'link'  => $link,
                'desc'  => $desc,
                'date'  => $date,
            ];
        }
        return $items;
    }

    // RSS 1.0 / RDF
    if (isset($xml->item)) {
        foreach ($xml->item as $item) {
            $items[] = [
                'title' => (string) $item->title,
                'link'  => (string) $item->link,
                'desc'  => (string) $item->description,
                'date'  => null,
            ];
        }
        return $items;
    }

    return $items;
}

// -------- LOGICA PRINCIPALE --------

// Prendo tutti i feed attivi
$stmt = $pdo->query("SELECT id, site_id, url, type FROM feeds WHERE active = 1");
$feeds = $stmt->fetchAll();

if (!$feeds) {
    echo "Nessun feed attivo trovato." . PHP_EOL;
    exit;
}

$insertNews = $pdo->prepare("INSERT INTO news
    (feed_id, site_id, source, title, url, host, summary, status, published_at, fetched_at, last_fetch_attempt)
    VALUES
    (:feed_id, :site_id, :source, :title, :url, :host, :summary, 'new', :published_at, NULL, NULL)
    ON DUPLICATE KEY UPDATE
        title = VALUES(title),
        summary = VALUES(summary),
        published_at = VALUES(published_at),
        site_id = VALUES(site_id),
        feed_id = VALUES(feed_id)"
);

$updateFeedCheck = $pdo->prepare("UPDATE feeds
    SET last_checked = NOW(), last_error = NULL, error_message = NULL
    WHERE id = :id");

$updateFeedError = $pdo->prepare("UPDATE feeds
    SET last_error = NOW(), error_message = :msg
    WHERE id = :id");

foreach ($feeds as $feedRow) {
    $feedId = (int) $feedRow['id'];
    $siteId = (int) $feedRow['site_id'];
    $feedUrl = $feedRow['url'];

    echo "Scarico feed: {$feedUrl}" . PHP_EOL;
    logMsg("Scarico feed: {$feedUrl}");

    $xmlString = httpGetLikeBrowser($feedUrl);
    if ($xmlString === null) {
        echo "  Errore nel download del feed." . PHP_EOL;
        logMsg("Errore nel download del feed: {$feedUrl}");
        $updateFeedError->execute([
            ':id'  => $feedId,
            ':msg' => 'Errore HTTP nel download del feed'
        ]);
        continue;
    }

    // Controllo minimo: sembra un feed XML?
$test = ltrim($xmlString);
if (
    stripos($test, '<rss') === false &&
    stripos($test, '<feed') === false &&
    stripos($test, '<rdf:RDF') === false
) {
    echo "  Il contenuto non sembra un feed XML (probabilmente HTML / cookie wall)." . PHP_EOL;
    logMsg("Contenuto non feed per {$feedUrl}");
    $updateFeedError->execute([
        ':id'  => $feedId,
        ':msg' => 'Non sembra un feed XML (contenuto HTML?)'
    ]);
    // opzionale: disattiva il feed così non ci riprova più
    // $pdo->prepare("UPDATE feeds SET active = 0 WHERE id = :id")->execute([':id' => $feedId]);
    continue;
}


    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if ($xml === false) {
        libxml_clear_errors();
        echo "  Errore nel parsing XML." . PHP_EOL;
        logMsg("Errore nel parsing XML del feed: {$feedUrl}");
        $updateFeedError->execute([
            ':id'  => $feedId,
            ':msg' => 'Errore nel parsing XML'
        ]);
        continue;
    }
    libxml_clear_errors();

    $items = parseFeed($xml);
    $updateFeedCheck->execute([':id' => $feedId]);

    if (empty($items)) {
        echo "  Nessun item nel feed." . PHP_EOL;
        continue;
    }

    foreach ($items as $item) {
        $title   = $item['title'];
        $link    = $item['link'];
        $summary = $item['desc'];
        $date    = $item['date'];

        if (!$link) {
            continue;
        }

        $host = parse_url($link, PHP_URL_HOST) ?: '';

        $insertNews->execute([
            ':feed_id'      => $feedId,
            ':site_id'      => $siteId,
            ':source'       => $feedUrl,
            ':title'        => $title,
            ':url'          => $link,
            ':host'         => $host,
            ':summary'      => $summary,
            ':published_at' => $date
        ]);
    }
}

echo "Fetch dai feed completato." . PHP_EOL;

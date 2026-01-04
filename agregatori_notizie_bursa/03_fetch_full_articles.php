<?php
// 03_fetch_full_articles.php
// Legge le notizie con status = 'new', le organizza in round-robin per feed,
// limita il numero di richieste per host, scarica il contenuto completo
// e lo salva in `news.content`, aggiornando lo status a 'fetched' o 'error'.

require_once __DIR__ . '/../config/db.php';    

$pdo = getPDO();

// Parametri di controllo
define('MAX_ARTICOLI_PER_RUN', 100); // massimo articoli da scaricare per esecuzione
define('MAX_PER_HOST', 10);          // massimo articoli per singolo dominio



/**
 * Estrae il contenuto principale da una pagina HTML.
 */
/**
 * Estrae il contenuto principale da una pagina HTML:
 * - rimuove header/footer e tag rumorosi
 * - rimuove commenti HTML
 * - trova <article> oppure il <div> con più testo
 * - mantiene HTML (p, h2, a, img, ecc.)
 * - NON elimina i link <a>, mantiene href (relativi -> assoluti)
 * - mantiene solo src/alt/title per le <img>
 * - comprime spazi e a capo ripetuti
 */
function fetchFullContent(string $url): ?string
{
    $html = httpGetLikeBrowser($url);
    if ($html === null) {
        logMsg("Errore HTTP scaricando: $url");
        return null;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) {
        libxml_clear_errors();
        logMsg("Impossibile parsare HTML di: $url");
        return null;
    }
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // 1) Rimuovo tag rumorosi globali
 $removeTags = [
    'script','style','svg','iframe','video','audio','noscript','button',
    'img','picture','source'
];
    foreach ($removeTags as $tag) {
        $nodes = $dom->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $n = $nodes->item($i);
            if ($n && $n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }
    }

    // 2) Rimuovo header e footer
    $nodesToRemove = $xpath->query('//header | //footer');
    if ($nodesToRemove !== false) {
        for ($i = $nodesToRemove->length - 1; $i >= 0; $i--) {
            $n = $nodesToRemove->item($i);
            if ($n && $n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }
    }

    // 3) Rimuovo tutti i commenti HTML
    removeComments($dom);

    // 4) Trovo nodo principale: <article> o <div> con più testo (come nel tuo vecchio codice)
    $mainNode = null;

    $articleNodes = $xpath->query('//article');
    if ($articleNodes !== false && $articleNodes->length > 0) {
        $mainNode = $articleNodes->item(0);
    } else {
        $divNodes = $xpath->query('//div');
        $bestNode = null;
        $bestLen  = 0;

        if ($divNodes !== false) {
            foreach ($divNodes as $div) {
                $text = trim($div->textContent);
                $len  = mb_strlen($text, 'UTF-8');
                if ($len > $bestLen) {
                    $bestLen  = $len;
                    $bestNode = $div;
                }
            }
        }

        $mainNode = $bestNode;
    }

    if (!$mainNode) {
        logMsg("Nessun nodo contenuto trovato per: $url");
        return null;
    }

    // 5) Prima: rendiamo assoluti tutti i link <a href="..."> dentro il nodo
    makeLinksAbsolute($mainNode, $url);

    // 6) Poi: puliamo gli attributi, ma teniamo href per <a> e src/alt/title per <img>
    stripAllAttributes($mainNode);

    // 7) Prendo l'HTML interno del nodo principale
    $cleanHtml = getInnerHTML($mainNode);
    $cleanHtml = trim($cleanHtml);

    if ($cleanHtml === '') {
        logMsg("Contenuto vuoto per: $url");
        return null;
    }

    // 8) Compatto spazi e a capo
    $cleanHtml = preg_replace('/\s+/', ' ', $cleanHtml);
    $cleanHtml = preg_replace('/>\s+</', '><', $cleanHtml);

    return trim($cleanHtml);
}

/**
 * Rimuove tutti i commenti HTML dal documento.
 */
function removeComments(DOMNode $node): void
{
    if (!$node->hasChildNodes()) {
        return;
    }

    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child->nodeType === XML_COMMENT_NODE) {
            if ($child->parentNode) {
                $child->parentNode->removeChild($child);
            }
        } else {
            removeComments($child);
        }
    }
}

/**
 * Trasforma tutti i link relativi (<a href="...">) in assoluti,
 * partendo dall'URL base della pagina.
 */
function makeLinksAbsolute(DOMNode $node, string $baseUrl): void
{
    if ($node->nodeType === XML_ELEMENT_NODE && $node instanceof DOMElement) {
        if (strtolower($node->tagName) === 'a' && $node->hasAttribute('href')) {
            $href = trim($node->getAttribute('href'));
            if ($href !== '') {
                $abs = absolutizeUrl($baseUrl, $href);
                $node->setAttribute('href', $abs);
            }
        }
    }

    if ($node->hasChildNodes()) {
        foreach ($node->childNodes as $child) {
            makeLinksAbsolute($child, $baseUrl);
        }
    }
}



/**
 * Rimuove tutti gli attributi da un nodo e dai discendenti,
 * ma preserva:
 *  - <a>: href
 *  - <img>: src, alt, title
 */
function stripAllAttributes(DOMNode $node): void
{
    if ($node->nodeType === XML_ELEMENT_NODE && $node instanceof DOMElement) {
        $tag = strtolower($node->tagName);

        $keep = [];
        if ($tag === 'a') {
            if ($node->hasAttribute('href')) {
                $keep['href'] = $node->getAttribute('href');
            }
        } elseif ($tag === 'img') {
            foreach (['src','alt','title'] as $attr) {
                if ($node->hasAttribute($attr)) {
                    $keep[$attr] = $node->getAttribute($attr);
                }
            }
        }

        // rimuovo tutti gli attributi
        while ($node->attributes->length > 0) {
            $node->removeAttributeNode($node->attributes->item(0));
        }

        // rimetto solo quelli che voglio tenere
        foreach ($keep as $name => $value) {
            $node->setAttribute($name, $value);
        }
    }

    if ($node->hasChildNodes()) {
        foreach ($node->childNodes as $child) {
            stripAllAttributes($child);
        }
    }
}

/**
 * Restituisce l'innerHTML di un nodo.
 */
function getInnerHTML(DOMNode $node): string
{
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return $html;
}


// -------- LOGICA PRINCIPALE --------

// Seleziono un po' di notizie "new"
$stmt = $pdo->prepare("SELECT id, feed_id, site_id, source, title, url, host
    FROM news
    WHERE status = 'new'
    ORDER BY published_at DESC
    LIMIT 1000");
$stmt->execute();
$rows = $stmt->fetchAll();

if (!$rows) {
    echo "Nessuna notizia con status = 'new'." . PHP_EOL;
    exit;
}

// Organizzo in code per feed_id
$queues = []; // feed_id => array di news
foreach ($rows as $row) {
    $feedId = (int) $row['feed_id'];
    if (!isset($queues[$feedId])) {
        $queues[$feedId] = [];
    }
    $queues[$feedId][] = $row;
}

$hostCount = []; // host => numero richieste
$processed = 0;

$updateNews = $pdo->prepare("UPDATE news
    SET content = :content,
        status = :status,
        fetched_at = IF(:content IS NOT NULL, NOW(), fetched_at),
        last_fetch_attempt = NOW()
    WHERE id = :id");

do {
    $allEmpty = true;

    foreach ($queues as $feedId => &$queue) {
        if (empty($queue)) {
            continue;
        }
        $allEmpty = false;

        if ($processed >= MAX_ARTICOLI_PER_RUN) {
            break 2;
        }

        $news = array_shift($queue);

        $id   = (int) $news['id'];
        $url  = $news['url'];
        $host = $news['host'];

        if (!$url) {
            continue;
        }

        if (!isset($hostCount[$host])) {
            $hostCount[$host] = 0;
        }

        if ($hostCount[$host] >= MAX_PER_HOST) {
            continue;
        }

        // Controllo se già esiste content (link già elaborato)
        $check = $pdo->prepare("SELECT content FROM news WHERE id = :id");
        $check->execute([':id' => $id]);
        $row = $check->fetch();

        if (!$row) {
            continue;
        }

        if (!empty($row['content'])) {
            // Già analizzato
            continue;
        }

        echo "Scarico contenuto articolo: {$url}" . PHP_EOL;
        logMsg("Scarico contenuto articolo: {$url}");

        $full = fetchFullContent($url);
        $status = $full ? 'fetched' : 'error';

        $updateNews->execute([
            ':content' => $full,
            ':status'  => $status,
            ':id'      => $id
        ]);

        $hostCount[$host]++;
        $processed++;

        usleep(300000); // 0.3 secondi
    }

} while (!$allEmpty);

echo "Fetch contenuti completato. Articoli elaborati: {$processed}." . PHP_EOL;

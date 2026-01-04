<?php
// -------------------------------------------------------------
// 01_scan_homepage_step12.php
//
// STEP 0:
//  - prende fino a N siti (stop = 0)
//  - per OGNI sito, appena lo seleziona -> stop = 1
//
// STEP 1 per ogni sito:
//  - scarica la homepage (solo se resto nello stesso dominio)
//  - estrae link + anchor text
//  - tiene SOLO link:
//      * stesso dominio del sito
//      * anchor text NON vuoto
//  - match parole chiave (URL + anchor)
//  - inserisce SOLO i link con almeno una keyword in 2_links_matches
//    (has_feed = 'no', feed_type = '', page_level = 'prima_pagina')
//
// STEP 2 per ogni sito:
//  - legge da 2_links_matches tutti i link di quel sito con has_feed = 'no'
//  - scarica ogni URL (bloccando redirect verso domini esterni)
//  - se è un feed reale (RSS/ATOM/XML), aggiorna has_feed = 'si' e feed_type
//
// STEP 2.5 (NUOVO):
//  - si attiva SOLO se:
//      * nessun feed trovato per questo site_id (has_feed = 'si')
//      * in homepage NON c'era nessun anchor con 'news'/'press'/'filing'
//  - prende tutte le URL con page_level = 'prima_pagina' da 2_links_matches
//  - le scarica (sempre stesso dominio)
//  - inserisce in 2_links_matches (page_level = 'seconda_pagina') SOLO i link
//        con anchor NON vuoto e contenente 'news'/'press'/'filing'
//
// STEP 2.6 (NUOVO):
//  - dopo STEP 2.5 controlla i link seconda_pagina:
//      * SELECT da 2_links_matches dove page_level='seconda_pagina' e has_feed='no'
//      * scarica URL
//      * se feed reale → has_feed='si', feed_type=tipo
//
// stop è già = 1 appena iniziata l'analisi del sito
// -------------------------------------------------------------

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php'; // httpGetLikeBrowser(), absolutizeUrl(), normalizeHost()

$pdo = getPDO();


// =============================================================
// FUNZIONE: Match parole chiave su URL + anchor text
// Ritorna array di keyword trovate (senza duplicati)
// =============================================================
function matchKeywordsOnLink(string $url, string $anchorText): array
{
    $urlLower    = strtolower($url);
    $anchorLower = strtolower($anchorText);

    $keywords = [
        'news',
        'press',
        'announc',   // announcement / announcements
        'investor',
        'filing',
        'sec',
        'rss',
        'feed',
        'xml',
        'atom'
    ];

    $found = [];

    foreach ($keywords as $key) {
        if (
            stripos($urlLower, $key) !== false ||
            stripos($anchorLower, $key) !== false
        ) {
            $found[] = $key;
        }
    }

    return array_values(array_unique($found));
}


// =============================================================
// FUNZIONE: elimina URL duplicati (unici per URL, tiene il primo anchor)
// =============================================================
function uniqueLinks(array $links): array
{
    $seen   = [];
    $unique = [];

    foreach ($links as $row) {
        $u = $row['url'];
        if (!isset($seen[$u])) {
            $seen[$u] = true;
            $unique[] = $row;
        }
    }

    return $unique;
}


// =============================================================
// FUNZIONE: Riconosce feed veri (RSS/ATOM) - bool
// =============================================================
function looksLikeRealFeed(string $xml): bool
{
    $test = strtolower(ltrim($xml));

    return (
        strpos($test, '<rss') !== false ||
        strpos($test, '<rdf:rdf') !== false ||
        strpos($test, '<feed') !== false ||
        strpos($test, '<rss2') !== false ||
        strpos($test, '<atom2') !== false ||
        (strpos($test, '<channel') !== false && strpos($test, '<item') !== false)
    );
}


// =============================================================
// FUNZIONE: Detect tipo feed (rss / atom / rdf / rss2 / atom2 / unknown)
// =============================================================
function detectFeedType(string $xml): string
{
    $test = strtolower(ltrim($xml));

    if (strpos($test, '<rdf:rdf') !== false) {
        return 'rdf';
    }
    if (strpos($test, '<rss2') !== false) {
        return 'rss2';
    }
    if (strpos($test, '<rss') !== false) {
        return 'rss';
    }
    if (strpos($test, '<atom2') !== false) {
        return 'atom2';
    }
    if (strpos($test, '<feed') !== false) {
        return 'atom';
    }
    if (strpos($test, '<channel') !== false && strpos($test, '<item') !== false) {
        return 'unknown';
    }

    return '';
}


// =============================================================
// FUNZIONE: parole chiave speciali SOLO su anchor (news, press, filing)
// =============================================================
function anchorKeywordsNPF(string $anchorText): array
{
    $anchorLower = strtolower($anchorText);
    $found = [];

    if (strpos($anchorLower, 'news') !== false) {
        $found[] = 'news';
    }
    if (strpos($anchorLower, 'press') !== false) {
        $found[] = 'press';
    }
    if (strpos($anchorLower, 'filing') !== false) {
        $found[] = 'filing';
    }

    return array_values(array_unique($found));
}


// =============================================================
// STEP 0: SELECT fino a N SITI DA ANALIZZARE (stop = 0)
// =============================================================
$stmt = $pdo->query("
    SELECT id, nome_azione, website
    FROM 1_sites_titoli_da_degiro
    WHERE stop = 0
    LIMIT 10
");

$sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$sites) {
    echo "Nessun sito con stop = 0<br>";
    exit;
}

// prepare UPDATE stop
$updateStop = $pdo->prepare("
    UPDATE 1_sites_titoli_da_degiro
    SET stop = 1
    WHERE id = :id
");

// prepare INSERT in 2_links_matches
$insertMatch = $pdo->prepare("
    INSERT IGNORE INTO 2_links_matches (
        site_id,
        url,
        anchor_text,
        page_level,
        matched_keywords,
        has_feed,
        feed_type
    ) VALUES (
        :site_id,
        :url,
        :anchor_text,
        :page_level,
        :matched_keywords,
        'no',
        ''
    )
");

// prepare SELECT & UPDATE per STEP 2
$matchesStmt = $pdo->prepare("
    SELECT id, url, matched_keywords, has_feed, feed_type
    FROM 2_links_matches
    WHERE site_id = :site_id
      AND has_feed = 'no'
");

$updateMatchFeed = $pdo->prepare("
    UPDATE 2_links_matches
    SET has_feed = :has_feed,
        feed_type = :feed_type
    WHERE id = :id
");

// per STEP 2.5: conta feed trovati
$checkFeedsStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM 2_links_matches
    WHERE site_id = :site_id
      AND has_feed = 'si'
");

// per STEP 2.5: seleziona pagine di prima_pagina
$selectLevel1Pages = $pdo->prepare("
    SELECT url
    FROM 2_links_matches
    WHERE site_id = :site_id
      AND page_level = 'prima_pagina'
");

// per STEP 2.6: seleziona link seconda_pagina con has_feed = 'no'
$selectSecondLevelNoFeed = $pdo->prepare("
    SELECT id, url
    FROM 2_links_matches
    WHERE site_id = :site_id
      AND page_level = 'seconda_pagina'
      AND has_feed = 'no'
");


// =============================================================
// LOOP sui siti selezionati (max N)
// =============================================================
foreach ($sites as $site) {

    $siteId  = (int)$site['id'];
    $homeUrl = $site['website'];

    echo "<hr><b>Analisi sito ID {$siteId}</b><br>";
    echo "URL: {$homeUrl}<br>";

    // STEP 0.1: appena selezionato il sito -> stop = 1
    $updateStop->execute([':id' => $siteId]);
    echo "STEP 0: stop impostato a 1 per ID {$siteId}<br><br>";

    if (empty($homeUrl)) {
        echo "Nessuna URL definita per questo sito, salto.<br>";
        continue;
    }

    // host base normalizzato (dominio principale)
    $baseHost = parse_url($homeUrl, PHP_URL_HOST);
    if (!$baseHost) {
        echo "Impossibile estrarre host da URL, salto.<br>";
        continue;
    }
    $baseHostNorm = normalizeHost($baseHost);

    // flag: homepage contiene già anchor con news/press/filing?
    $homeHasNPF = false;

    // =========================================================
    // STEP 1.2: Scarica homepage (bloccando redirect verso altri domini)
    // =========================================================
    $html = httpGetLikeBrowser($homeUrl, $baseHostNorm);

    if ($html === null) {
        echo "Errore: impossibile scaricare la homepage (o redirect fuori dominio).<br>";
        // stop è già = 1, quindi passo al prossimo sito
        continue;
    }

    // =========================================================
    // STEP 1.3: Estrae TUTTI i link <a> (URL + anchor text)
    //          - solo stesso dominio
    //          - solo anchor text NON vuoto
    // =========================================================
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath     = new DOMXPath($dom);
    $linkNodes = $xpath->query('//a[@href]');

    $allLinks = [];

    foreach ($linkNodes as $a) {
        $href = $a->getAttribute('href');
        if (!$href) {
            continue;
        }

        $abs        = absolutizeUrl($homeUrl, $href);
        $anchorText = trim($a->textContent);

        // anchor vuoto -> ignoriamo
        if ($anchorText === '') {
            continue;
        }

        // stesso dominio?
        $linkHost = parse_url($abs, PHP_URL_HOST);
        if (!$linkHost) {
            continue;
        }
        $linkHostNorm = normalizeHost($linkHost);

        if ($linkHostNorm !== $baseHostNorm) {
            // link verso dominio esterno -> ignoriamo
            continue;
        }

        // controllo anchor per NPF (solo homepage)
        $npf = anchorKeywordsNPF($anchorText);
        if (!empty($npf)) {
            $homeHasNPF = true;
        }

        $allLinks[] = [
            'url'    => $abs,
            'anchor' => $anchorText,
        ];
    }

    // =========================================================
    // STEP 1.4: Rimuove duplicati per URL
    // =========================================================
    $uniqueLinks = uniqueLinks($allLinks);

    echo "<b>Link trovati totali (stesso dominio, anchor non vuoto):</b> " . count($allLinks) . "<br>";
    echo "<b>Link unici (per URL):</b> " . count($uniqueLinks) . "<br>";
    echo "Homepage contiene anchor con [news/press/filing]? " . ($homeHasNPF ? 'SI' : 'NO') . "<br><br>";

    // =========================================================
    // STEP 1.5: Analizza SOLO link unici, inserisce SOLO quelli con keyword
    // =========================================================
    $inseriti = 0;

    foreach ($uniqueLinks as $row) {

        $abs        = $row['url'];
        $anchorText = $row['anchor'];

        $anchorHtml = htmlspecialchars($anchorText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo "URL trovato: <a href='{$abs}' target='_blank'>{$abs}</a><br>";
        echo "Anchor text: <i>{$anchorHtml}</i><br>";

        // match parole chiave (URL + anchor)
        $matched = matchKeywordsOnLink($abs, $anchorText);

        if (empty($matched)) {
            echo "&nbsp;&nbsp;→ Nessuna parola chiave trovata, NON inserisco in 2_links_matches<br><br>";
            continue; // niente INSERT
        }

        $matchedList = implode(', ', $matched);
        echo "&nbsp;&nbsp;→ MATCH parole chiave: {$matchedList}<br>";

        // INSERT in tabella (prima_pagina)
        $insertMatch->execute([
            ':site_id'          => $siteId,
            ':url'              => $abs,
            ':anchor_text'      => $anchorText,
            ':page_level'       => 'prima_pagina',
            ':matched_keywords' => implode(',', $matched),
        ]);

        $inseriti++;

        echo "&nbsp;&nbsp;→ Inserito in 2_links_matches (prima_pagina)<br><br>";
    }

    echo "<b>STEP 1 completato per ID {$siteId}.</b><br>";
    echo "Link inseriti in 2_links_matches: {$inseriti}<br><br>";


    // =========================================================
    // STEP 2: Controllo se i link nella tabella sono feed REALI
    // =========================================================
    echo "<b>STEP 2 - Controllo feed per i link salvati (ID sito {$siteId})</b><br>";

    $matchesStmt->execute([':site_id' => $siteId]);
    $rows = $matchesStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "Nessun link da controllare (has_feed = 'no') per questo sito.<br><br>";
    } else {

        echo "Link da controllare in 2_links_matches per questo sito: " . count($rows) . "<br><br>";

        $aggiornati = 0;

        foreach ($rows as $row) {

            $id  = (int)$row['id'];
            $url = $row['url'];

            echo "Controllo URL: <a href='{$url}' target='_blank'>{$url}</a><br>";

            // di nuovo: blocchiamo redirect verso altri domini
            $content = httpGetLikeBrowser($url, $baseHostNorm);

            if ($content === null) {
                echo "&nbsp;&nbsp;→ Errore scaricando il contenuto o redirect fuori dominio, lascio has_feed = 'no'<br><br>";
                continue;
            }

            $hasFeed  = 'no';
            $feedType = '';

            if (looksLikeRealFeed($content)) {
                $hasFeed  = 'si';
                $feedType = detectFeedType($content) ?: 'unknown';

                echo "&nbsp;&nbsp;→ ✓ FEED REALE TROVATO, tipo: {$feedType}<br>";
            } else {
                echo "&nbsp;&nbsp;→ ✗ Non sembra un feed reale<br>";
            }

            $updateMatchFeed->execute([
                ':has_feed'  => $hasFeed,
                ':feed_type' => $feedType,
                ':id'        => $id,
            ]);

            $aggiornati++;

            echo "&nbsp;&nbsp;→ Riga aggiornata in 2_links_matches (id = {$id})<br><br>";
        }

        echo "<b>STEP 2 completato per ID {$siteId}.</b><br>";
        echo "Righe aggiornate: {$aggiornati}<br><br>";
    }

    // ---------------------------------------------------------
    // STEP 2.5: se NON ho trovato feed e la homepage NON aveva
    //           anchor con news/press/filing, allora:
    //           - scarico le pagine di prima_pagina
    //           - inserisco link di seconda_pagina con anchor
    //             che contiene news/press/filing
    // ---------------------------------------------------------
    $checkFeedsStmt->execute([':site_id' => $siteId]);
    $feedCount = (int)$checkFeedsStmt->fetchColumn();

    if ($feedCount === 0 && !$homeHasNPF) {

        echo "<b>STEP 2.5 - Nessun feed e nessun link NPF in homepage. Scansiono pagine di prima_pagina...</b><br>";

        $selectLevel1Pages->execute([':site_id' => $siteId]);
        $pages = $selectLevel1Pages->fetchAll(PDO::FETCH_ASSOC);

        if (!$pages) {
            echo "Nessuna pagina prima_pagina in 2_links_matches per questo sito.<br><br>";
        } else {

            $insertedSecond = 0;

            foreach ($pages as $p) {

                $pageUrl = $p['url'];
                echo "Scarico pagina di primo livello: <a href='{$pageUrl}' target='_blank'>{$pageUrl}</a><br>";

                $pageHtml = httpGetLikeBrowser($pageUrl, $baseHostNorm);

                if ($pageHtml === null) {
                    echo "&nbsp;&nbsp;→ Errore scaricando la pagina o redirect fuori dominio, salto.<br><br>";
                    continue;
                }

                libxml_use_internal_errors(true);
                $pDom = new DOMDocument();
                $pDom->loadHTML($pageHtml);
                libxml_clear_errors();

                $pXpath  = new DOMXPath($pDom);
                $pLinks  = $pXpath->query('//a[@href]');

                foreach ($pLinks as $a2) {
                    $href2 = $a2->getAttribute('href');
                    if (!$href2) {
                        continue;
                    }

                    $abs2        = absolutizeUrl($pageUrl, $href2);
                    $anchor2     = trim($a2->textContent);

                    // anchor vuoto -> ignoriamo
                    if ($anchor2 === '') {
                        continue;
                    }

                    // stesso dominio?
                    $host2 = parse_url($abs2, PHP_URL_HOST);
                    if (!$host2) {
                        continue;
                    }
                    $host2Norm = normalizeHost($host2);
                    if ($host2Norm !== $baseHostNorm) {
                        continue;
                    }

                    // anchor deve contenere almeno una di [news, press, filing]
                    $npf2 = anchorKeywordsNPF($anchor2);
                    if (empty($npf2)) {
                        continue;
                    }

                    $anchor2Html = htmlspecialchars($anchor2, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    echo "&nbsp;&nbsp;Link seconda_pagina: <a href='{$abs2}' target='_blank'>{$abs2}</a><br>";
                    echo "&nbsp;&nbsp;Anchor: <i>{$anchor2Html}</i><br>";
                    echo "&nbsp;&nbsp;Parole (NPF) trovate nell'anchor: " . implode(', ', $npf2) . "<br>";

                    // INSERT seconda_pagina (se l'URL è già presente, IGNORE lo salta)
                    $insertMatch->execute([
                        ':site_id'          => $siteId,
                        ':url'              => $abs2,
                        ':anchor_text'      => $anchor2,
                        ':page_level'       => 'seconda_pagina',
                        ':matched_keywords' => implode(',', $npf2),
                    ]);

                    $insertedSecond++;
                    echo "&nbsp;&nbsp;→ Inserito in 2_links_matches (seconda_pagina)<br><br>";
                }
            }

            echo "<b>STEP 2.5 completato per ID {$siteId}.</b><br>";
            echo "Nuovi link seconda_pagina inseriti: {$insertedSecond}<br><br>";

            // -------------------------------------------------
            // STEP 2.6: Controllo FEED per i link seconda_pagina
            // -------------------------------------------------
            if ($insertedSecond > 0) {
                echo "<b>STEP 2.6 - Controllo feed per link seconda_pagina (ID sito {$siteId})</b><br>";

                $selectSecondLevelNoFeed->execute([':site_id' => $siteId]);
                $rows2 = $selectSecondLevelNoFeed->fetchAll(PDO::FETCH_ASSOC);

                if (!$rows2) {
                    echo "Nessun link seconda_pagina con has_feed = 'no' da controllare.<br><br>";
                } else {
                    echo "Link seconda_pagina da controllare: " . count($rows2) . "<br><br>";

                    $updated2 = 0;

                    foreach ($rows2 as $row2) {
                        $id2  = (int)$row2['id'];
                        $url2 = $row2['url'];

                        echo "Controllo URL seconda_pagina: <a href='{$url2}' target='_blank'>{$url2}</a><br>";

                        $content2 = httpGetLikeBrowser($url2, $baseHostNorm);

                        if ($content2 === null) {
                            echo "&nbsp;&nbsp;→ Errore scaricando il contenuto o redirect fuori dominio, lascio has_feed = 'no'<br><br>";
                            continue;
                        }

                        $hasFeed2  = 'no';
                        $feedType2 = '';

                        if (looksLikeRealFeed($content2)) {
                            $hasFeed2  = 'si';
                            $feedType2 = detectFeedType($content2) ?: 'unknown';

                            echo "&nbsp;&nbsp;→ ✓ FEED REALE TROVATO (seconda_pagina), tipo: {$feedType2}<br>";
                        } else {
                            echo "&nbsp;&nbsp;→ ✗ Non sembra un feed reale (seconda_pagina)<br>";
                        }

                        $updateMatchFeed->execute([
                            ':has_feed'  => $hasFeed2,
                            ':feed_type' => $feedType2,
                            ':id'        => $id2,
                        ]);

                        $updated2++;

                        echo "&nbsp;&nbsp;→ Riga seconda_pagina aggiornata in 2_links_matches (id = {$id2})<br><br>";
                    }

                    echo "<b>STEP 2.6 completato per ID {$siteId}.</b><br>";
                    echo "Righe seconda_pagina aggiornate: {$updated2}<br><br>";
                }
            }
        }
    } else {
        echo "<b>STEP 2.5 non necessario per ID {$siteId}</b> ";
        echo "(feed trovati: {$feedCount}, homepage NPF: " . ($homeHasNPF ? 'SI' : 'NO') . ")<br><br>";
    }
}

echo "<hr><b>Elaborazione completata per questo batch di siti.</b><br>";

?>

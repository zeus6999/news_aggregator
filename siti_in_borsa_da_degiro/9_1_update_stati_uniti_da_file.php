<?php
// update_stati_uniti_da_file.php
// - Scansiona TUTTI i file HTML nella cartella (oppure UNO se passato da CLI)
// - Per ogni file:
//   - Estrae ISIN + simbolo (span data-field="symbolIsin")
//   - Estrae Website / Settore / Industria / # dipendenti / Reporting Currency dal blocco <dl ...>
//   - Legge le icone check/cancel per lista_verde_dividendi_usa ('si' / 'no')
//   - Calcola country dal prefisso ISIN (mappa)
//   - Cerca la riga in stati_uniti SOLO per ISIN
//   - Aggiorna: simbolo, website, settore, industria, dipendenti, reporting_currency, lista_verde_dividendi_usa, country
//   - Rinomina il file in trovato_* o nontrovato_*

require_once __DIR__ . '/../config/db.php';

$pdo = getPDO();

// CONFIGURAZIONE CARTELLA
$baseDir = 'C:\xampp\htdocs\news_aggregator\siti_in_borsa_da_degiro\derigo';
// ---------- MAPPA PREFISSO ISIN -> PAESE ----------
$countryMap = [
    'US' => 'United States',
    'CA' => 'Canada',
    'BR' => 'Brazil',
    'MX' => 'Mexico',
    'AR' => 'Argentina',
    'CL' => 'Chile',
    'CO' => 'Colombia',
    'PE' => 'Peru',

    'GB' => 'United Kingdom',
    'IE' => 'Ireland',
    'FR' => 'France',
    'DE' => 'Germany',
    'NL' => 'Netherlands',
    'BE' => 'Belgium',
    'ES' => 'Spain',
    'PT' => 'Portugal',
    'CH' => 'Switzerland',
    'IT' => 'Italy',
    'AT' => 'Austria',
    'GR' => 'Greece',
    'PL' => 'Poland',
    'CZ' => 'Czech Republic',
    'HU' => 'Hungary',

    'SE' => 'Sweden',
    'NO' => 'Norway',
    'FI' => 'Finland',
    'DK' => 'Denmark',

    'RU' => 'Russia',
    'TR' => 'Turkey',
    'IL' => 'Israel',

    'JP' => 'Japan',
    'CN' => 'China',
    'HK' => 'Hong Kong',
    'KR' => 'South Korea',
    'SG' => 'Singapore',
    'IN' => 'India',
    'AU' => 'Australia',
    'NZ' => 'New Zealand',

    'ZA' => 'South Africa',
];

// ---------- FUNZIONI DI SUPPORTO ----------
function rinomina(string $fullPath, string $prefix): void
{
    $dir  = dirname($fullPath);
    $name = basename($fullPath);

    if (str_starts_with($name, 'trovato_') || str_starts_with($name, 'nontrovato_')) {
        return;
    }

    $newName = $prefix . $name;
    $newPath = $dir . DIRECTORY_SEPARATOR . $newName;

    if (@rename($fullPath, $newPath)) {
        echo "File rinominato in: {$newName}\n";
    } else {
        echo "ATTENZIONE: impossibile rinominare il file in {$newName}\n";
    }
}

function normalizeLabel(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtolower($s, 'UTF-8');
}

function countryFromIsin(string $isin, array $countryMap): ?string
{
    if (strlen($isin) < 2) {
        return null;
    }
    $prefix = substr($isin, 0, 2);
    return $countryMap[$prefix] ?? null;
}

/**
 * Elabora UN singolo file HTML.
 */
function processFile(string $fileToProcess, PDO $pdo, array $countryMap): void
{
    echo "\n==============================\n";
    echo "Elaboro file: {$fileToProcess}\n";

    $html = @file_get_contents($fileToProcess);
    if ($html === false) {
        echo "Errore nella lettura del file.\n";
        rinomina($fileToProcess, 'nontrovato_');
        return;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) {
        libxml_clear_errors();
        echo "Impossibile parsare HTML.\n";
        rinomina($fileToProcess, 'nontrovato_');
        return;
    }
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // --------- 1) SYMBOL + ISIN ---------
    $symbol = null;
    $isin   = null;

    $nodes = $xpath->query('//span[@data-field="symbolIsin" or @data-field=\'symbolIsin\']');
    if ($nodes && $nodes->length > 0) {
        $text = $nodes->item(0)->textContent;
        $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $parts = explode('|', $text);
        if (count($parts) >= 2) {
            $symbol = trim($parts[0]);
            $isin   = trim($parts[1]);
        }
    }

    if (empty($isin)) {
        echo "ISIN non trovato nel file.\n";
        rinomina($fileToProcess, 'nontrovato_');
        return;
    }

    echo "Trovato ISIN: {$isin}";
    if (!empty($symbol)) {
        echo " | SYMBOL: {$symbol}";
    }
    echo "\n";

    // --------- 2) lista_verde_dividendi_usa ---------
    $listaVerde = null;
    $checkNodes  = $xpath->query('//i[@data-type="check_circle"]');
    $cancelNodes = $xpath->query('//i[@data-type="cancel"]');

    if ($checkNodes && $checkNodes->length > 0) {
        $listaVerde = 'si';
    } elseif ($cancelNodes && $cancelNodes->length > 0) {
        $listaVerde = 'no';
    }

    echo "lista_verde_dividendi_usa da HTML: " . ($listaVerde ?? 'non rilevata') . "\n";

    // --------- 3) WEBSITE / SETTORE / INDUSTRIA / DIPENDENTI / REPORTING CURRENCY ---------
    $website           = null;
    $settore           = null;
    $industria         = null;
    $dipendenti        = null;
    $reportingCurrency = null;

    $blocks = $xpath->query('//dl[contains(@class, "_18fL3win")]//div[contains(@class, "_2SLvGchZ")]');
    if ($blocks && $blocks->length > 0) {
        foreach ($blocks as $block) {
            /** @var DOMElement $block */
            $dtNodeList = $xpath->query('.//dt', $block);
            $ddNodeList = $xpath->query('.//dd', $block);
            if (!$dtNodeList || $dtNodeList->length === 0 || !$ddNodeList || $ddNodeList->length === 0) {
                continue;
            }

            $dt = $dtNodeList->item(0);
            $dd = $ddNodeList->item(0);

            $labelRaw = $dt->textContent ?? '';
            $label    = normalizeLabel($labelRaw);

            $valueText = $dd->textContent ?? '';
            $valueText = html_entity_decode($valueText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $valueText = trim(preg_replace('/\s+/', ' ', $valueText));

            if ($label === 'website') {
                $aNodes = $xpath->query('.//a[@href]', $dd);
                if ($aNodes && $aNodes->length > 0) {
                    /** @var DOMElement $a */
                    $a = $aNodes->item(0);
                    $href = trim($a->getAttribute('href'));
                    if ($href !== '') {
                        $website = $href;
                    } elseif ($valueText !== '') {
                        $website = $valueText;
                    }
                } elseif ($valueText !== '') {
                    $website = $valueText;
                }

            } elseif ($label === 'industria') {
                if ($valueText !== '') {
                    $industria = $valueText;
                }

            } elseif ($label === 'settore') {
                if ($valueText !== '') {
                    $settore = $valueText;
                }

            } elseif (str_contains($label, 'dipendenti')) {
                $spanEmp = $xpath->query('.//span[@data-field="employees"]', $dd);
                $empText = null;
                if ($spanEmp && $spanEmp->length > 0) {
                    /** @var DOMElement $s */
                    $s = $spanEmp->item(0);
                    $empText = $s->getAttribute('title') ?: $s->textContent;
                } else {
                    $empText = $valueText;
                }

                $empText = html_entity_decode(trim($empText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $empText = str_replace('.', '', $empText);
                $empText = preg_replace('/[^\d]/', '', $empText);

                if ($empText !== '' && ctype_digit($empText)) {
                    $dipendenti = (int)$empText;
                }

            } elseif ($label === 'reporting currency') {
                if ($valueText !== '') {
                    $reportingCurrency = $valueText;
                }
            }
        }
    }

    echo "Website: "            . ($website           ?? 'N/D') . "\n";
    echo "Settore: "            . ($settore           ?? 'N/D') . "\n";
    echo "Industria: "          . ($industria         ?? 'N/D') . "\n";
    echo "# dipendenti: "       . ($dipendenti        ?? 'N/D') . "\n";
    echo "Reporting Currency: " . ($reportingCurrency ?? 'N/D') . "\n";

    // --------- 4) COUNTRY da ISIN ---------
    $country = countryFromIsin($isin, $countryMap);
    echo "Country (da ISIN): " . ($country ?? 'N/D') . "\n";

    // --------- 5) CERCO LA RIGA IN stati_uniti PER ISIN ---------
    $sqlSelect = "SELECT id FROM stati_uniti WHERE isin = :isin LIMIT 1";
    $stmt = $pdo->prepare($sqlSelect);
    $stmt->execute([':isin' => $isin]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo "Nessuna riga trovata in stati_uniti per ISIN = {$isin}\n";
        rinomina($fileToProcess, 'nontrovato_');
        return;
    }

    $idDb = (int)$row['id'];
    echo "Trovata riga id = {$idDb} in stati_uniti.\n";

    // --------- 6) UPDATE ---------
    $fields = [];
    $params = [':id' => $idDb];

    if (!empty($symbol)) {
        $fields[]           = 'simbolo = :simbolo';
        $params[':simbolo'] = $symbol;
    }

    if ($listaVerde !== null) {
        $fields[]         = 'lista_verde_dividendi_usa = :lista';
        $params[':lista'] = $listaVerde;
    }

    if (!empty($website)) {
        $fields[]             = 'website = :website';
        $params[':website']   = $website;
    }

    if (!empty($settore)) {
        $fields[]             = 'settore = :settore';
        $params[':settore']   = $settore;
    }

    if (!empty($industria)) {
        $fields[]               = 'industria = :industria';
        $params[':industria']   = $industria;
    }

    if ($dipendenti !== null) {
        $fields[]                = 'dipendenti = :dipendenti';
        $params[':dipendenti']   = $dipendenti;
    }

    if (!empty($reportingCurrency)) {
        $fields[]                       = 'reporting_currency = :rep';
        $params[':rep']                 = $reportingCurrency;
    }

    if (!empty($country)) {
        $fields[]             = 'country = :country';
        $params[':country']   = $country;
    }

    if (empty($fields)) {
        echo "Nessun campo da aggiornare.\n";
        rinomina($fileToProcess, 'trovato_');
        return;
    }

    $sqlUpdate = "UPDATE stati_uniti SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmtUp = $pdo->prepare($sqlUpdate);
    $stmtUp->execute($params);

    echo "UPDATE eseguito con successo.\n";
    rinomina($fileToProcess, 'trovato_');
}

// ---------- MODALITÀ ESECUZIONE ----------

// Se da CLI passo un file specifico -> solo quello
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    $candidate = $baseDir . DIRECTORY_SEPARATOR . $argv[1];
    if (!is_file($candidate)) {
        echo "File passato da CLI non trovato: {$candidate}\n";
        exit(1);
    }
    processFile($candidate, $pdo, $countryMap);
    exit(0);
}

// Altrimenti: TUTTI i file .html non marcati
$files = [];

$dh = opendir($baseDir);
if (!$dh) {
    echo "Impossibile aprire la cartella: {$baseDir}\n";
    exit(1);
}

while (($file = readdir($dh)) !== false) {
    if (!preg_match('/\.html?$/i', $file)) {
        continue;
    }
    if (str_starts_with($file, 'trovato_') || str_starts_with($file, 'nontrovato_')) {
        continue;
    }
    $files[] = $baseDir . DIRECTORY_SEPARATOR . $file;
}
closedir($dh);

if (empty($files)) {
    echo "Nessun file .html da elaborare.\n";
    exit(0);
}

// ordinamento per avere sempre stesso ordine
sort($files);

echo "Trovati " . count($files) . " file da elaborare.\n";

foreach ($files as $path) {
    processFile($path, $pdo, $countryMap);
}

echo "\nElaborazione completata.\n";

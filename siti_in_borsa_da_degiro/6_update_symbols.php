<?php
// 6_update_symbols.php
// Legge file HTML TradingView salvati in Trading_View e aggiorna:
// - borsa_tradingview
// - website_tradingview
// nella tabella 1_sites_titoli_da_degiro, matchando per simbolo.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$pdo = getPDO();
set_time_limit(0);

define('TV_DIR', 'C:\\xampp\\htdocs\\news_aggregator\\siti_in_borsa_da_degiro\\Trading_View');
define('TABLE_SITES', '1_sites_titoli_da_degiro');

// domini social da scartare (TradingView spesso mostra anche link social)
const SOCIAL_DOMAINS = [
  'twitter.com', 'x.com', 'facebook.com', 'linkedin.com', 'instagram.com',
  'youtube.com', 't.me', 'telegram.me', 'reddit.com', 'discord.gg'
];

function isSocialUrl(string $url): bool {
  $host = parse_url($url, PHP_URL_HOST);
  if (!$host) return false;
  $host = strtolower($host);
  foreach (SOCIAL_DOMAINS as $d) {
    if (str_contains($host, $d)) return true;
  }
  return false;
}

/**
 * Estrae il sito web "ufficiale" dall'HTML TradingView.
 * Strategia robusta:
 *  - cerca <a ... data-overflow-tooltip-text="domain" ... href="http...">
 *  - prende il primo non-social e non-tradingview
 */
function extractWebsiteFromTradingViewHtml(string $html): ?string {
  $pattern = '/data-overflow-tooltip-text="[^"]*"\s+class="[^"]*"\s+href="([^"]+)"/i';
  if (!preg_match_all($pattern, $html, $m)) {
    // fallback: cerca direttamente href con data-overflow-tooltip-text nello stesso tag
    $pattern2 = '/<a[^>]+data-overflow-tooltip-text="[^"]*"[^>]+href="([^"]+)"/i';
    if (!preg_match_all($pattern2, $html, $m2)) return null;
    $urls = $m2[1];
  } else {
    $urls = $m[1];
  }

  foreach ($urls as $u) {
    $u = html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match('~^https?://~i', $u)) continue;
    if (stripos($u, 'tradingview.com') !== false) continue;
    if (isSocialUrl($u)) continue;
    return $u;
  }
  return null;
}

/**
 * Dal filename (senza estensione) ricava:
 * - borsa_tradingview (se formato BORSA-SIMBOLO)
 * - simbolo
 *
 * Esempi:
 *  NASDAQ-AACG  => borsa=NASDAQ, simbolo=AACG
 *  AACG         => borsa=null,   simbolo=AACG
 */
function parseFilenameSymbol(string $baseName): array {
  $baseName = trim($baseName);

  // Se ci sono più '-' (raro), consideriamo:
  // prima parte = borsa, tutto il resto = simbolo
  if (strpos($baseName, '-') !== false) {
    $parts = explode('-', $baseName);
    $borsa = trim($parts[0]) ?: null;
    $simbolo = trim(implode('-', array_slice($parts, 1)));
    if ($simbolo === '') $simbolo = null;
    return [$borsa, $simbolo];
  }

  return [null, $baseName !== '' ? $baseName : null];
}

/**
 * Normalizza simbolo per confronto lato PHP (non sostituisce lato DB).
 */
function normalizeSymbol(string $s): string {
  // rimuove spazi e NBSP (UTF-8 C2A0)
  $s = str_replace("\xC2\xA0", '', $s);
  $s = str_replace(' ', '', $s);
  return trim($s);
}

/**
 * UPDATE sul DB usando confronto robusto sul campo simbolo:
 * REPLACE NBSP + spazi + TRIM.
 */
function updateRow(PDO $pdo, string $simbolo, ?string $borsaTv, ?string $websiteTv): int {
  $sql = "
    UPDATE " . TABLE_SITES . "
    SET
      borsa_tradingview = :borsa_tv,
      website_tradingview = :website_tv
    WHERE
      TRIM(REPLACE(REPLACE(simbolo, CHAR(194,160), ''), ' ', '')) = :sym
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':borsa_tv'   => $borsaTv,
    ':website_tv' => $websiteTv,
    ':sym'        => normalizeSymbol($simbolo),
  ]);
  return $st->rowCount();
}

/* -------------------------------------------------------------
   MAIN
------------------------------------------------------------- */

$dir = TV_DIR;
if (!is_dir($dir)) {
  die("ERRORE: cartella non trovata: $dir");
}

$files = glob($dir . DIRECTORY_SEPARATOR . '*.html');
sort($files);

$total = count($files);
$ok = 0;
$skip = 0;
$notFoundInDb = 0;
$noWebsite = 0;

echo "<pre>";
echo "Cartella: $dir\n";
echo "File .html trovati: $total\n\n";

foreach ($files as $i => $filePath) {
  $base = pathinfo($filePath, PATHINFO_FILENAME);
  [$borsa, $simbolo] = parseFilenameSymbol($base);

  if (!$simbolo) {
    $skip++;
    echo "[SKIP] filename non valido: $base\n";
    continue;
  }

  $html = @file_get_contents($filePath);
  if ($html === false) {
    $skip++;
    echo "[SKIP] non riesco a leggere: $filePath\n";
    continue;
  }

  $website = extractWebsiteFromTradingViewHtml($html);
  if (!$website) $noWebsite++;

  // Se borsa o website mancano, lasciamo NULL (come richiesto)
  $rows = updateRow($pdo, $simbolo, $borsa ?: null, $website ?: null);

  if ($rows > 0) {
    $ok++;
    echo "[OK] $base  => simbolo=$simbolo | borsa_tv=" . ($borsa ?: "NULL") . " | website_tv=" . ($website ?: "NULL") . "\n";
  } else {
    $notFoundInDb++;
    echo "[NO DB] $base  => simbolo=$simbolo (nessuna riga aggiornata)\n";
  }
}

echo "\n--- RISULTATO ---\n";
echo "Aggiornati: $ok\n";
echo "Nessun match DB: $notFoundInDb\n";
echo "File saltati: $skip\n";
echo "Senza website estratto (lasciato NULL): $noWebsite\n";
echo "</pre>";

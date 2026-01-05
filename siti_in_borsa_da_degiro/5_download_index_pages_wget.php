<?php
// 5_download_index_pages_wget_bg_only_this_script.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

// modulo puro wget
require_once __DIR__ . '/../config/wget_module.php';

$pdo = getPDO();

// =====================
// CONFIG DB (NON WGET)
// =====================
define('BATCH_SIZE', 1);
define('SKIP_IF_EXISTS', true);

// RUN_TAG solo per questa esecuzione (passato al modulo wget)
define('RUN_TAG', 'IDXBG_' . getmypid() . '_' . date('Ymd_His'));

$sel = $pdo->prepare("
    SELECT id, website
    FROM `1_sites_titoli_da_degiro`                    

    WHERE top='0'
    LIMIT " . (int)BATCH_SIZE
);

$markQueued = $pdo->prepare("
    UPDATE `1_sites_titoli_da_degiro`
    SET top='1', last_scan=NOW(), last_error=:err
    WHERE id=:id
");

while (true) {
    $sel->execute();
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "Nessuna riga top=0. Fine.\n";
        break;
    }

    foreach ($rows as $r) {
        $id     = (int)$r['id'];
        $urlRaw = (string)$r['website'];
        $url    = trim($urlRaw);

        if ($url === '') {
            $markQueued->execute([':id' => $id, ':err' => 'skip_empty_website']);
            echo "SKIP ID={$id} website vuoto\n";
            continue;
        }

        // file naming resta nello script DB (non nel modulo wget)
        $outFile = rtrim(WGET_OUTPUT_DIR, "/\\") . DIRECTORY_SEPARATOR . $id . ".html";
        $logFile = rtrim(WGET_LOG_DIR, "/\\") . DIRECTORY_SEPARATOR . $id . ".log";

        // SKIP esistenza file (questa è logica “script”, non wget)
        if (SKIP_IF_EXISTS && is_file($outFile) && filesize($outFile) > 0) {
            $markQueued->execute([':id' => $id, ':err' => 'skipped_file_exists']);
            echo "SKIP ID={$id} file esiste\n";
            continue;
        }

        // segna subito
        $markQueued->execute([':id' => $id, ':err' => 'queued_' . RUN_TAG]);

        // chiama SOLO wget (tutto quello che riguarda cookie/cartelle/permessi/throttle/UA è nel modulo)
        $res = wget_fetch($url, $outFile, $logFile, [
            'bg' => true,
            'run_tag' => RUN_TAG,
            // 'referer' => 'https://it.tradingview.com/', // se vuoi forzare
            // 'user_agent' => 'IndexFetcherBG/1.0',       // se vuoi UA base diversa
            'throttle' => true,
        ]);

        echo "QUEUED ID={$id} (bg=" . ($res['bg_count'] ?? 0) . ") URL=" . ($res['url'] ?? '') . "\n";
    }
}

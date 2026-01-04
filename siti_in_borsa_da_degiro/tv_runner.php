                                                                  <?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$pdo = getPDO();
header('X-Content-Type-Options: nosniff');

function nextRow(PDO $pdo): ?array {
    // transazione per evitare doppie prese (se apri 2 browser)
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("
            SELECT id, simbolo
            FROM `1_sites_titoli_da_degiro`
            WHERE top='0'
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute();
        $r = $st->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            $pdo->commit();
            return null;
        }

        $id  = (int)$r['id'];
        $sym = strtoupper(trim((string)$r['simbolo'])); // TRIM e basta

        // marca subito la riga come presa
        $upd = $pdo->prepare("
            UPDATE `1_sites_titoli_da_degiro`
            SET top='1', last_scan=NOW(), last_error=:err
            WHERE id=:id
        ");
        $upd->execute([
            ':id'  => $id,
            ':err' => ($sym === '' ? 'skip_empty_symbol' : 'sent_to_browser'),
        ]);

        $pdo->commit();

        if ($sym === '') return null;

        return [
            'id'  => $id,
            'sym' => $sym,
            'url' => 'https://it.tradingview.com/symbols/' . rawurlencode($sym) . '/',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ===== AJAX endpoint =====
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $row = nextRow($pdo);
        if (!$row) {
            echo json_encode(['ok' => 0, 'done' => 1], JSON_UNESCAPED_SLASHES);
            exit;
        }
        echo json_encode(['ok' => 1] + $row, JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => 0, 'error' => $e->getMessage()]);
        exit;
    }
}

// prima riga “preview” (solo per mostrare qualcosa in pagina)
$preview = null;
try {
    $st = $pdo->query("SELECT id, simbolo FROM `1_sites_titoli_da_degiro` WHERE top='0' ORDER BY id ASC LIMIT 1");
    $preview = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { /* ignore */ }

?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>TradingView runner</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: Arial, sans-serif; padding: 14px; }
    button { padding: 10px 14px; margin-right: 8px; }
    .box { margin-top: 12px; padding: 10px; border: 1px solid #ccc; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body>

<h2>TradingView runner</h2>

<div>
  <button id="btnStart">START</button>
  <button id="btnStop" disabled>STOP</button>
  <span class="mono">interval: <b id="ival">1000</b> ms</span>
</div>

<div class="box">
  <div>Stato: <b id="status">idle</b></div>
  <div>Ultimo: <span class="mono" id="last">-</span></div>
  <div>Preview top=0: <span class="mono">
    <?php
      if ($preview) {
        echo "ID={$preview['id']} SIMBOLO=" . htmlspecialchars((string)$preview['simbolo']);
      } else {
        echo "nessuna riga top=0";
      }
    ?>
  </span></div>
</div>

<script>
const INTERVAL_MS = 3000; // <<< ogni secondo
document.getElementById('ival').textContent = INTERVAL_MS;

let timer = null;
let win = null;

const statusEl = document.getElementById('status');
const lastEl = document.getElementById('last');
const btnStart = document.getElementById('btnStart');
const btnStop  = document.getElementById('btnStop');

function setStatus(s) { statusEl.textContent = s; }

async function tick() {
  try {
    const r = await fetch(location.pathname + '?ajax=1', { cache: 'no-store' });
    const j = await r.json();

    if (!j.ok) {
      if (j.done) {
        setStatus('FINITO (nessun top=0)');
        stop();
        return;
      }
      setStatus('ERRORE AJAX');
      return;
    }

    lastEl.textContent = `ID=${j.id} SYM=${j.sym} URL=${j.url}`;
    setStatus('RUN');

    if (!win || win.closed) {
      // se qualcuno chiude la finestra, la riapriamo
      win = window.open('about:blank', 'tv_blank');
    }
    // carica il link (equivale a “aggiornare” la pagina della finestra)
    win.location.href = j.url;

  } catch (e) {
    setStatus('ERRORE: ' + (e?.message || e));
  }
}

function start() {
  // popup deve aprirsi da click, altrimenti viene bloccato
  win = window.open('about:blank', 'tv_blank');
  if (!win) {
    alert('Popup bloccato. Consenti popup per questo sito e riprova.');
    return;
  }

  btnStart.disabled = true;
  btnStop.disabled = false;

  setStatus('START...');
  tick(); // subito
  timer = setInterval(tick, INTERVAL_MS);
}

function stop() {
  if (timer) clearInterval(timer);
  timer = null;
  btnStart.disabled = false;
  btnStop.disabled = true;
  setStatus('STOP');
}

btnStart.addEventListener('click', start);
btnStop.addEventListener('click', stop);
</script>

</body>
</html>

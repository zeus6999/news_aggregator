<script>
// Refresh automatico della pagina ogni 5 secondi
// (puoi cambiare il valore)

function autoRefreshPage() {
    // Ricarica la stessa pagina
    window.location.reload();
}

// Aspetta 3 secondi dopo l'apertura automatica delle schede
// per evitare conflitti con popup blocker
setTimeout(autoRefreshPage, 9sf000);
</script>
<?php



// stati_uniti_top10.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once __DIR__ . '/../config/db.php';    

$pdo = getPDO();

try {
    // 1) Seleziono 10 righe con top = '0' e le blocco (FOR UPDATE)
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT *
        FROM stati_uniti
        WHERE `top` = '0'
        ORDER BY id
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        $pdo->commit();
        echo "Nessuna riga con TOP = 0.\n";
        exit;
    }

    // 2) Aggiorno TOP = '1' per queste 10 righe
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $update = $pdo->prepare("
        UPDATE stati_uniti
        SET `top` = '1'
        WHERE id IN ($placeholders)
    ");
    $update->execute($ids);

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Errore DB: " . $e->getMessage());
}

// 3) Preparo array dei link DeGiro da usare in JS
$degiroLinks = [];
foreach ($rows as $row) {
    if (!empty($row['degiro_link'])) {
        $degiroLinks[] = $row['degiro_link'];
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>10 titoli Stati Uniti (TOP batch)</title>
</head>
<body>
    <h1>10 titoli Stati Uniti (TOP batch)</h1>

    <button type="button" onclick="openAllDegiroLinks()">
        Apri tutti i DeGiro link (max 10 schede)
    </button>

    <table border="1" cellpadding="5" cellspacing="0" style="margin-top:15px;">
        <thead>
        <tr>
            <th>ID</th>
            <th>Simbolo</th>
            <th>Nome azione</th>
            <th>Ultimo</th>
            <th>Borsa</th>
            <th>DeGiro</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['simbolo'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['nome_azione'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['ultimo'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['borsa'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?php if (!empty($r['degiro_link'])): ?>
                        <a href="<?= htmlspecialchars($r['degiro_link'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                            Apri
                        </a>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
      <!-- Metto i link in un blocco JSON leggibile da JS -->
    <script type="application/json" id="degiroLinks">
        <?= json_encode($degiroLinks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    </script>

    <script>
        // Funzione che apre automaticamente le schede
        function autoOpenDegiroLinks() {
            try {
                const json = document.getElementById('degiroLinks').textContent;
                const links = JSON.parse(json);

                links.forEach(url => {
                    if (url) {
                        window.open(url, '_blank');
                    }
                });
            } catch (e) {
                console.error("Errore apertura link: ", e);
            }
        }

        // Avviene appena tutta la pagina è caricata
        window.onload = function () {
            setTimeout(autoOpenDegiroLinks, 500); // piccolo delay per evitare blocco popup
        };
    </script>

</body>
</html>


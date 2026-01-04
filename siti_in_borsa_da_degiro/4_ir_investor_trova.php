                                                                             <?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php'; // httpGetLikeBrowser(), absolutizeUrl(), normalizeHost()

$pdo = getPDO();

define('LIMIT_PER_RUN', 40);
define('TIMEOUT_SEC', 12);
define('MAX_REDIRECTS', 7);
define('SLEEP_USEC', 120000); // 0.12s

// =====================
// Utils
// =====================

function normalizeUrl(string $url): string {
    $url = trim($url);
    if ($url === '' || $url === '—' || $url === '-' || $url === '–') return '';
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    return $url;
}

// dominio registrabile (abbastanza buono per il tuo dataset)
function registrableDomain(string $host): string {
    $host = strtolower($host);
    $host = preg_replace('~:\d+$~', '', $host);
    $host = preg_replace('~^www\.~', '', $host);

    $parts = array_values(array_filter(explode('.', $host)));
    $n = count($parts);
    if ($n < 2) return $host;

    $multi = [
        'co.uk','org.uk','ac.uk',
        'com.au','net.au','org.au',
        'co.jp','co.kr','co.in',
        'com.ar','com.br','com.mx','com.tr','com.cn','com.hk','com.sg','com.sa',
        'com.co','com.pe','com.cl',
    ];

    $last2 = $parts[$n-2] . '.' . $parts[$n-1];
    if (in_array($last2, $multi, true) && $n >= 3) {
        return $parts[$n-3] . '.' . $last2;
    }
    return $last2;
}

function sameRegistrableDomain(string $baseDomain, string $finalHost): bool {
    return registrableDomain($baseDomain) === registrableDomain($finalHost);
}

// Riconosce se URL è già IR (in modo più robusto: anche .htm, query, ecc.)
function isAlreadyIR(string $url): bool {
    $url = normalizeUrl($url);
    if ($url === '') return false;

    $p = parse_url($url);
    if (!$p || empty($p['host'])) return false;

    $host = strtolower($p['host']);
    $path = strtolower($p['path'] ?? '/');

    // sottodomini tipici IR (+ "ri." che nel mondo BR è comunissimo)
    if (preg_match('~^(ir|ri|investor|investors)\.~', $host)) return true;

    // provider IR comuni che compaiono nel tuo dataset
    $providerHosts = [
        'q4ir.com', 'q4web.com', 'gcs-web.com'
    ];
    foreach ($providerHosts as $ph) {
        if (str_ends_with($host, $ph)) return true;
    }

    // path IR (accetta /, fine, ., ?)
    // copre: /investor, /investors, /investor-relations, /ir, /investors/investor-relations
    if (preg_match('~/(investors?|investor-relations|ir)(/|$|\.|\?)~', $path)) return true;
    if (preg_match('~/investors/investor-relations(/|$|\.|\?)~', $path)) return true;

    return false;
}

function buildCandidates(string $baseDomain): array {
    $baseDomain = strtolower($baseDomain);
    $baseDomain = preg_replace('~^www\.~', '', $baseDomain);

    return [
        "https://ir.$baseDomain/",
        "https://$baseDomain/investor-relations/",
        "https://$baseDomain/investors/investor-relations/",
        "https://$baseDomain/ir/",
        "https://$baseDomain/investor",
        "https://$baseDomain/investors",
        "https://investor.$baseDomain/",
        "https://investors.$baseDomain/",
    ];
}

function checkCandidate(string $candidateUrl, string $baseDomain): array {
    $candidateUrl = normalizeUrl($candidateUrl);
    if ($candidateUrl === '') return ['ok'=>false,'status'=>0,'final_url'=>'','err'=>'empty'];

    // HEAD
    $ch = curl_init($candidateUrl);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => MAX_REDIRECTS,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => TIMEOUT_SEC,
        CURLOPT_TIMEOUT => TIMEOUT_SEC,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) IRScanner/1.1',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err      = curl_error($ch);
    curl_close($ch);

    // fallback GET se HEAD non va
    if ($status === 405 || $status === 0) {
        $ch = curl_init($candidateUrl);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => MAX_REDIRECTS,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => TIMEOUT_SEC,
            CURLOPT_TIMEOUT => TIMEOUT_SEC,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) IRScanner/1.1',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err      = curl_error($ch);
        curl_close($ch);
    }

    if ($finalUrl === '') $finalUrl = $candidateUrl;

    // Hard-fail: not found / server down
    if ($status === 404 || $status === 410 || $status === 500 || $status === 502 || $status === 503 || $status === 504 || $status === 0) {
        return ['ok'=>false,'status'=>$status,'final_url'=>$finalUrl,'err'=>$err ?: 'hard-fail'];
    }

    // Accetto anche 401/403 come "esiste ma blocca bot" (utile: in browser spesso va)
    if (!($status >= 200 && $status < 400) && !in_array($status, [401, 403], true)) {
        return ['ok'=>false,'status'=>$status,'final_url'=>$finalUrl,'err'=>'status-not-ok'];
    }

    $p2 = parse_url($finalUrl);
    $finalHost = $p2['host'] ?? '';
    if ($finalHost === '') {
        return ['ok'=>false,'status'=>$status,'final_url'=>$finalUrl,'err'=>'no-final-host'];
    }

    // Redirect accettato solo se resta nello stesso dominio registrabile
    if (!sameRegistrableDomain($baseDomain, $finalHost)) {
        return ['ok'=>false,'status'=>$status,'final_url'=>$finalUrl,'err'=>'redirect-outside-domain'];
    }

    return ['ok'=>true,'status'=>$status,'final_url'=>$finalUrl,'err'=>''];
}

// =====================
// MAIN
// =====================

$sql = "SELECT id, website, pagina_ir
        FROM `1_sites_titoli_da_degiro`
        WHERE website IS NOT NULL AND TRIM(website) <> ''
          AND website <> '—' AND website <> '-' AND website <> '–'
          AND (pagina_ir IS NULL OR TRIM(pagina_ir) = '')
        LIMIT " . LIMIT_PER_RUN;

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "Nessuna riga da processare.\n";
    exit;
}

$upd = $pdo->prepare("UPDATE `1_sites_titoli_da_degiro`
                      SET pagina_ir = :pagina_ir
                      WHERE id = :id");

// cache per non rifare 200 volte lo stesso dominio
$cache = []; // baseDomain => foundUrl|null

$ok = 0; $no = 0;

foreach ($rows as $r) {
    $id = (int)$r['id'];
    $website = normalizeUrl((string)$r['website']);
    if ($website === '') { $no++; continue; }

    $p = parse_url($website);
    $host = $p['host'] ?? '';
    if ($host === '' || !str_contains($host, '.')) { // es: banco.bradesco / home.barclays
        echo "ID=$id host non valido: {$r['website']}\n";
        $no++;
        continue;
    }

    // dominio base (IMPORTANTE: elimina sottodomini tipo portales., www2., ecc.)
    $baseDomain = registrableDomain($host);

    // Se website è già IR -> pagina_ir = website
    if (isAlreadyIR($website)) {
        $upd->execute([':pagina_ir' => $website, ':id' => $id]);
        echo "ID=$id già IR -> $website\n";
        $ok++;
        continue;
    }

    // Cache
    if (array_key_exists($baseDomain, $cache)) {
        if ($cache[$baseDomain] !== null) {
            $upd->execute([':pagina_ir' => $cache[$baseDomain], ':id' => $id]);
            echo "ID=$id cache HIT -> {$cache[$baseDomain]}\n";
            $ok++;
        } else {
            echo "ID=$id cache MISS -> nessuna IR per $baseDomain\n";
            $no++;
        }
        continue;
    }

    $found = null;
    foreach (buildCandidates($baseDomain) as $cand) {
        $res = checkCandidate($cand, $baseDomain);

        // fallback: prova http:// se https fallisce
        if (!$res['ok'] && str_starts_with($cand, 'https://')) {
            $candHttp = 'http://' . substr($cand, 8);
            $res2 = checkCandidate($candHttp, $baseDomain);
            if ($res2['ok']) $res = $res2;
        }

        if ($res['ok']) {
            $found = $res['final_url'];
            echo "ID=$id FOUND $cand -> $found (HTTP {$res['status']})\n";
            break;
        }
    }

    $cache[$baseDomain] = $found;

    if ($found !== null) {
        $upd->execute([':pagina_ir' => $found, ':id' => $id]);
        $ok++;
    } else {
        echo "ID=$id nessuna IR trovata per $baseDomain\n";
        $no++;
    }

    usleep(SLEEP_USEC);
}

echo "\nFatto. aggiornate: $ok | non trovate: $no\n";

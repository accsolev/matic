<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require '../../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">🚫 Anda belum login.</div>';
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'unknown';

function logActivity($pdo, $userId, $username, $action) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $username, $action, $now]);
}

// Ambil tipe user
$stmt = $pdo->prepare("SELECT type FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userType = $stmt->fetchColumn();

// Limit per user type
function getDomainCheckLimit($type) {
    $limits = [
        'trial'  => 1,
        'medium' => 3,
        'vip'    => 30,
        'vipmax' => 100
    ];
    return $limits[$type] ?? 1;
}

// Scraping TrustPositif
function getStatus(string $domain): array {
    $url = "https://trustpositif.komdigi.go.id/?domains=" . urlencode($domain);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, // optional, kadang error di ssl
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return ['⚠️', 'Gagal ambil data'];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//table//tr') as $row) {
        $cols = $xpath->query('td', $row);
        if ($cols->length >= 2) {
            $st = trim($cols->item(1)->nodeValue);
            return $st === 'Ada'
                ? ['❌', 'DIBLOKIR']
                : ['✅', 'AMAN'];
        }
    }
    return ['⚠️', 'TIDAK DITEMUKAN'];
}

$maxLimit = getDomainCheckLimit($userType);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['domains'])) {
    $lines = explode("\n", trim($_POST['domains']));
    $domains = array_map('trim', $lines);
    $domainCount = count($domains);

    if ($domainCount > $maxLimit) {
        echo "<div class='alert alert-warning'>⚠️ Akun <b>$userType</b> hanya dapat memeriksa maksimal <b>$maxLimit</b> domain.</div>";
        $domains = array_slice($domains, 0, $maxLimit);
    }

    $results = [];
    $checkedDomains = [];

    foreach ($domains as $domain) {
        if (!empty($domain)) {
            [$icon, $status] = getStatus($domain);
            $results[] = [$domain, "$icon $status"];
            $checkedDomains[] = "$domain: $icon $status";
            // biar gak di-banned TrustPositif, delay dikit
            usleep(300000); // 0.3 detik
        }
    }

    // ✅ Catat log aktivitas
    if (!empty($checkedDomains)) {
        logActivity($pdo, $userId, $username, "Cek domain TrustPositif:\n" . implode("\n", $checkedDomains));
    }

    echo '<div class="card"><div class="card-body"><h5 class="text-center">Hasil Pengecekan</h5><table class="table table-bordered text-center"><thead><tr><th>Domain</th><th>Status</th></tr></thead><tbody>';
    foreach ($results as $row) {
        $statusClass = (strpos($row[1], 'DIBLOKIR') !== false) ? 'text-danger fw-bold' : ((strpos($row[1], 'AMAN') !== false) ? 'text-success fw-bold' : 'text-warning');
        echo "<tr><td>" . htmlspecialchars($row[0]) . "</td><td class=\"$statusClass\">" . htmlspecialchars($row[1]) . "</td></tr>";
    }
    echo '</tbody></table></div></div>';
} else {
    echo '<div class="alert alert-warning">❗ Tidak ada domain yang dikirimkan.</div>';
}
?>

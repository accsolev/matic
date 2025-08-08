<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/ajax/ddos_protection.php';
session_start();
date_default_timezone_set('Asia/Jakarta');
require '../includes/auth.php';
require_login();
require '../includes/db.php';

// -- REDIS CACHE OTOMATIS (PASTIKAN EXT REDIS AKTIF) --
$useCache = true;
$redis = null;
try {
    if ($useCache) {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 0.2); // 200ms timeout biar gak delay
    }
} catch (Throwable $e) {
    $redis = null;
}

// -- SETUP & CEK CACHE KEY --
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    echo json_encode(['error'=>'Unauthorized']); exit;
}

$startDate = $_GET['start'] ?? date('Y-m-01');
$endDate   = $_GET['end'] ?? date('Y-m-d');
$startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ? $startDate : date('Y-m-01');
$endDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ? $endDate : date('Y-m-d');

// ============ REDIS DASHBOARD CACHE ==============
$cacheKey = "dashboard:$userId:$startDate:$endDate";
$cacheTtl = 300; // 5 menit

if ($redis && $redis->exists($cacheKey)) {
    header('Content-Type: application/json');
    echo $redis->get($cacheKey);
    exit;
}

// ============= ASLI PROSES QUERY ================
$output = [];

// Total Visit
$stmt = $pdo->prepare("SELECT COUNT(*) FROM analytics a JOIN links l ON a.link_id = l.id WHERE l.user_id = ? AND a.created_at BETWEEN ? AND ?");
$stmt->execute([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59']);
$output['totalClicks'] = (int)$stmt->fetchColumn();

// Total Shortlink
$stmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE user_id = ? AND created_at BETWEEN ? AND ?");
$stmt->execute([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59']);
$output['totalLinks'] = (int)$stmt->fetchColumn();

// Total Domains
$stmt = $pdo->prepare("SELECT COUNT(*) FROM list_domains WHERE user_id = ? AND created_at BETWEEN ? AND ?");
$stmt->execute([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59']);
$output['totalDomains'] = (int)$stmt->fetchColumn();

// Klik Hari Ini (hanya jika hari ini di dalam range)
$output['todayClicks'] = 0;
if ($startDate <= date('Y-m-d') && $endDate >= date('Y-m-d')) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM analytics a JOIN links l ON a.link_id = l.id WHERE l.user_id = ? AND DATE(a.created_at) = ?");
    $stmt->execute([$userId, date('Y-m-d')]);
    $output['todayClicks'] = (int)$stmt->fetchColumn();
}

// Recent Links
$recentLinksStmt = $pdo->prepare("
  SELECT
    l.id,
    l.short_code,
    d.domain,
    l.created_at,
    (SELECT COUNT(*) FROM redirect_urls WHERE link_id = l.id) as destTotal,
    (SELECT COUNT(*) FROM fallback_urls WHERE link_id = l.id) as fallbackTotal
  FROM links l
    JOIN domains d ON l.domain_id = d.id
  WHERE l.user_id = ? AND l.created_at BETWEEN ? AND ?
  ORDER BY l.created_at DESC LIMIT 6
");
$recentLinksStmt->execute([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59']);
$output['recentLinks'] = $recentLinksStmt->fetchAll(PDO::FETCH_ASSOC);

// Chart Daily (date => total)
$chartDates = [];
$period = (strtotime($endDate) - strtotime($startDate)) / 86400;
for ($i=0; $i <= $period; $i++) {
    $date = date('Y-m-d', strtotime($startDate . " +$i days"));
    $chartDates[$date] = 0;
}
$analyticsStmt = $pdo->prepare("SELECT DATE(a.created_at) AS date, COUNT(*) AS total FROM analytics a JOIN links l ON a.link_id = l.id WHERE l.user_id = ? AND a.created_at BETWEEN ? AND ? GROUP BY DATE(a.created_at)");
$analyticsStmt->execute([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59']);
foreach ($analyticsStmt->fetchAll() as $row) $chartDates[$row['date']] = (int)$row['total'];
$output['chartDates'] = $chartDates;

// Device
$deviceClicksStmt = $pdo->prepare("SELECT
    SUM(CASE WHEN LOWER(a.device) = 'desktop' THEN 1 ELSE 0 END) AS desktop,
    SUM(CASE WHEN LOWER(a.device) = 'mobile' THEN 1 ELSE 0 END) AS mobile,
    SUM(CASE WHEN LOWER(a.device) = 'tablet' THEN 1 ELSE 0 END) AS tablet,
    COUNT(*) AS total
  FROM analytics a JOIN links l ON a.link_id = l.id
  WHERE l.user_id = ? AND a.created_at BETWEEN ? AND ?
");
$deviceClicksStmt->execute([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59']);
$deviceRow = $deviceClicksStmt->fetch();
$output['desktopClicks'] = (int)$deviceRow['desktop'];
$output['mobileClicks']  = (int)$deviceRow['mobile'];
$output['tabletClicks']  = (int)$deviceRow['tablet'];
$output['unknownClicks'] = (int)$deviceRow['total'] - ($output['desktopClicks'] + $output['mobileClicks'] + $output['tabletClicks']);

// Referrer
$referrerStmt = $pdo->prepare("SELECT
    CASE WHEN referrer IS NULL OR referrer = '' THEN 'Direct'
         ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '://', -1), '/', 1)) END AS domain,
    COUNT(*) AS total
  FROM analytics a JOIN links l ON l.id = a.link_id
  WHERE l.user_id = ? AND a.created_at BETWEEN ? AND ?
  GROUP BY domain ORDER BY total DESC LIMIT 3
");
$referrerStmt->execute([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59']);
$topDomainsRaw = $referrerStmt->fetchAll(PDO::FETCH_ASSOC);
$output['topDomains'] = array_column($topDomainsRaw, 'domain');

// --- NORMALISASI DOMAIN UNTUK YKEYS DAN CHARTDATA ---
function norm($str) {
    return strtolower(preg_replace('/[^a-z0-9]+/', '_', $str));
}
$normDomains = array_map('norm', $output['topDomains']);
$output['ykeys'] = $normDomains; // pakai norm!

// Referrer Chart Data (range)
$days = [];
for ($i=0; $i <= $period; $i++) {
    $d = date('Y-m-d', strtotime($startDate . " +$i days"));
    $days[$d] = ['day' => date('D', strtotime($d))];
    foreach ($normDomains as $normDom) $days[$d][$normDom] = 0;
}

if (!empty($output['topDomains'])) {
    $placeholders = implode(',', array_fill(0, count($output['topDomains']), '?'));
    $sql2 = "
      SELECT
        CASE WHEN a.referrer IS NULL OR a.referrer = '' THEN 'Direct'
             ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(a.referrer, '://', -1), '/', 1)) END AS domain,
        DATE(a.created_at) AS d,
        COUNT(*) AS cnt
      FROM analytics a JOIN links l ON l.id = a.link_id
      WHERE l.user_id = ?
        AND a.created_at BETWEEN ? AND ?
        AND (
          CASE WHEN a.referrer IS NULL OR a.referrer = '' THEN 'Direct'
               ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(a.referrer, '://', -1), '/', 1)) END
        ) IN ($placeholders)
      GROUP BY domain, d
    ";
    $params = array_merge([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59'], $output['topDomains']);
    $stmt2 = $pdo->prepare($sql2); $stmt2->execute($params);
    foreach ($stmt2->fetchAll() as $r) {
        $normDom = norm($r['domain']);
        if (isset($days[$r['d']][$normDom])) $days[$r['d']][$normDom] = (int)$r['cnt'];
    }
    $chartData = array_values($days);
} else {
    $chartData = array_values($days);
}
$output['chartData'] = $chartData;

// --- Untuk label chart tetap tampil, kirim label asli juga (optional) ---
$output['ylabels'] = $output['topDomains'];

// Referrer Stats Table
$refStatsStmt = $pdo->prepare("
  SELECT
    CASE WHEN referrer IS NULL OR referrer = '' THEN 'Direct'
         ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '://', -1), '/', 1)) END AS domain,
    COUNT(*) AS clicks
  FROM analytics a
  JOIN links l ON l.id = a.link_id
  WHERE l.user_id = ? AND a.created_at BETWEEN ? AND ?
  GROUP BY domain
  ORDER BY clicks DESC
");
$refStatsStmt->execute([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59']);
$output['referrerStats'] = $refStatsStmt->fetchAll(PDO::FETCH_ASSOC);

// Country
$countryStmt = $pdo->prepare("SELECT a.country, COUNT(*) AS clicks FROM analytics a JOIN links l ON a.link_id = l.id WHERE l.user_id = ? AND a.created_at BETWEEN ? AND ? GROUP BY a.country ORDER BY clicks DESC");
$countryStmt->execute([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59']);
$output['countryStats'] = $countryStmt->fetchAll(PDO::FETCH_ASSOC);

// City
$cityStmt = $pdo->prepare("SELECT a.city, COUNT(*) AS clicks FROM analytics a JOIN links l ON a.link_id = l.id WHERE l.user_id = ? AND a.created_at BETWEEN ? AND ? GROUP BY a.city ORDER BY clicks DESC");
$cityStmt->execute([$userId, $startDate.' 00:00:00', $endDate.' 23:59:59']);
$output['cityStats'] = $cityStmt->fetchAll(PDO::FETCH_ASSOC);

// === SET CACHE DAN KIRIM RESPONSE ===
if ($redis) $redis->setex($cacheKey, $cacheTtl, json_encode($output));

header('Content-Type: application/json');
echo json_encode($output);
exit;
?>

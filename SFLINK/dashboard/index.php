<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once __DIR__.'/ajax/ddos_protection.php';

// --------- SESSION & INIT ----------
session_start();
date_default_timezone_set('Asia/Jakarta');
require '../includes/auth.php';
require_login();
require '../includes/db.php';

// ===== REDIS SETUP WITH FALLBACK =====
$redis = null;
try {
    if (file_exists('ajax/redis.php')) {
        require 'ajax/redis.php';
    } else {
        throw new Exception('Redis file not found');
    }
} catch (Exception $e) {
    // Fallback: Mock Redis untuk development
    class MockRedis {
        public function get($key) { return false; }
        public function set($key, $value, $ttl = 0) { return true; }
        public function del($key) { return true; }
    }
    $redis = new MockRedis();
    error_log("Using mock Redis: " . $e->getMessage());
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --------- FUNGSI DASAR ----------
function logActivity($pdo, $userId, $username, $action) {
    static $stmt = null;
    if ($stmt === null) $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
    $now = date('Y-m-d H:i:s');
    $stmt->execute([$userId, $username, $action, $now]);
}

function containsMaliciousPayload($text) {
    static $patterns = [
        '/<\?(php)?/i', '/eval\s*\(/i', '/base64_decode\s*\(/i', '/file_get_contents\s*\(/i', '/urldecode\s*\(/i',
        '/document\.write\s*\(/i', '/window\.location/i', '/onerror\s*=/i', '/<script/i', '/&lt;script/i',
        '/data:text\/html/i', '/javascript:/i'
    ];
    foreach ($patterns as $pattern) if (preg_match($pattern, $text)) return true;
    return false;
}

function isValidAlias($alias) {
    return preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $alias);
}

function isValidUrl($url) {
    static $forbidden = [
        'eval(', 'base64_', 'base64,', 'data:text', 'data:application', '<?php', '</script>', '<script', '<iframe',
        '<img', '<svg', '<body', 'onerror=', 'onload=', 'document.', 'window.', 'file_get_contents',
        'curl_exec', 'exec(', 'passthru(', 'shell_exec', 'system('
    ];
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $url = urldecode(strtolower($url));
    foreach ($forbidden as $bad) if (strpos($url, $bad) !== false) return false;
    return true;
}

function isValidDomainFormat($url) {
    $host = parse_url($url, PHP_URL_HOST);
    return (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host);
}

function getTelegramId($userId, $pdo) {
    static $stmt = null;
    if ($stmt === null) $stmt = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

function formatTanggalIndonesia($datetime) {
    static $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $timestamp = strtotime($datetime);
    $tgl = date('d', $timestamp); 
    $bln = $bulan[(int)date('m', $timestamp)]; 
    $thn = date('Y', $timestamp); 
    $jam = date('H:i', $timestamp);
    return "$tgl $bln $thn $jam";
}

function sendTelegramNotif($userId, $pdo, $message) {
    static $stmt = null;
    if ($stmt === null) $stmt = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $telegramId = $stmt->fetchColumn();
    if (!$telegramId || !preg_match('/^-?\d{6,}$/', $telegramId)) return;
    $token = '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw';
    $text = urlencode($message);
    @file_get_contents("https://api.telegram.org/bot$token/sendMessage?chat_id=$telegramId&text=$text");
}

function getLimitByType($type) {
    static $limits = ['trial' => 1, 'medium' => 3, 'vip' => PHP_INT_MAX];
    return $limits[$type] ?? 1;
}

function getUnpaidStatus($pdo, $username) {
    static $stmt = null;
    $out = [];
    $packages = ['medium','vip','vipmax'];
    $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    if ($stmt === null) $stmt = $pdo->prepare("SELECT expired_time FROM payments WHERE customer_name = ? AND package = ? AND status = 'UNPAID' ORDER BY created_at DESC LIMIT 1");
    foreach ($packages as $p) {
        $stmt->execute([$username, $p]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $dt = new DateTime($row['expired_time'], new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
            $out["hasUnpaid$p"] = true;
            $out["isExpired$p"] = ($now > $dt);
        } else {
            $out["hasUnpaid$p"] = false;
            $out["isExpired$p"] = false;
        }
    }
    return $out;
}

// --------- SESSION HANDLING ----------
if (!isset($_SESSION['user_id']) && isset($_COOKIE['rememberme'])) {
    $token = $_COOKIE['rememberme'];
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE remember_token = ?");
    $stmt->execute([$token]);
    if ($user = $stmt->fetch()) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
    }
}
if (!isset($_SESSION['user_id'])) { 
    header("Location: ../login/"); 
    exit; 
}
$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];

// --------- DOMAIN CACHE ----------
if (!isset($_SESSION['domains'])) {
    $_SESSION['domains'] = $pdo->query("SELECT domain FROM domains")->fetchAll(PDO::FETCH_COLUMN);
}
$domains = $_SESSION['domains'];

// --------- REDIS DASHBOARD CACHING -----------
$cacheTtl = 300;        // 5 menit
$heavyCacheTtl = 900;   // 15 menit

$mainCacheKey = "dashboard:main:{$userId}";
$heavyCacheKey = "dashboard:heavy:{$userId}"; 
$chartCacheKey = "dashboard:charts:{$userId}";

// Clear cache pada action
if (($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action']))) {
    $redis->del($mainCacheKey);
    $redis->del($heavyCacheKey);
    $redis->del($chartCacheKey);
}

// CEK MAIN CACHE
$mainCache = $redis->get($mainCacheKey);

if ($mainCache) {
    // ===== CACHE HIT =====
    extract(json_decode($mainCache, true));
    
    // Pastikan variabel unpaid status ada
    $hasUnpaidVip = $unpaidStatus['hasUnpaidvip'] ?? false;
    $isExpiredVip = $unpaidStatus['isExpiredvip'] ?? false;
    $hasUnpaidVipMax = $unpaidStatus['hasUnpaidvipmax'] ?? false;
    $isExpiredVipMax = $unpaidStatus['isExpiredvipmax'] ?? false;
    $hasUnpaidMedium = $unpaidStatus['hasUnpaidmedium'] ?? false;
    $isExpiredMedium = $unpaidStatus['isExpiredmedium'] ?? false;
    
} else {
    // ===== CACHE MISS - REBUILD DATA =====
    
    // Handle form submit
    $errorMessage = $successMessage = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
        include_once __DIR__ . '/ajax/shortlink_crud_backend.php';
    }

    // LOAD HEAVY DATA dengan cache terpisah
    $heavyCache = $redis->get($heavyCacheKey);
    if ($heavyCache) {
        $heavyData = json_decode($heavyCache, true);
        $desktopClicks = $heavyData['desktopClicks'];
        $mobileClicks = $heavyData['mobileClicks'];
        $tabletClicks = $heavyData['tabletClicks'];
        $unknownClicks = $heavyData['unknownClicks'];
        $topDomains = $heavyData['topDomains'];
        $chartData = $heavyData['chartData'];
        $ykeys = $heavyData['ykeys'];
    } else {
        // Query heavy data
        $deviceClicksStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN LOWER(a.device) = 'desktop' THEN 1 ELSE 0 END) AS desktop,
                SUM(CASE WHEN LOWER(a.device) = 'mobile' THEN 1 ELSE 0 END) AS mobile,
                SUM(CASE WHEN LOWER(a.device) = 'tablet' THEN 1 ELSE 0 END) AS tablet,
                COUNT(*) AS total
            FROM analytics a 
            WHERE a.link_id IN (SELECT id FROM links WHERE user_id = ?)
        ");
        $deviceClicksStmt->execute([$userId]);
        $deviceRow = $deviceClicksStmt->fetch();
        $desktopClicks = (int)($deviceRow['desktop'] ?? 0);
        $mobileClicks = (int)($deviceRow['mobile'] ?? 0);
        $tabletClicks = (int)($deviceRow['tablet'] ?? 0);
        $allClicks = (int)($deviceRow['total'] ?? 0);
        $unknownClicks = $allClicks - ($desktopClicks + $mobileClicks + $tabletClicks);

        // TOP 3 REFERRER
        $referrerStmt = $pdo->prepare("
            SELECT
              CASE WHEN referrer IS NULL OR referrer = '' THEN 'Direct'
                   ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '://', -1), '/', 1)) END AS domain,
              COUNT(*) AS total
            FROM analytics a 
            WHERE a.link_id IN (SELECT id FROM links WHERE user_id = ?)
            GROUP BY domain ORDER BY total DESC LIMIT 3
        ");
        $referrerStmt->execute([$userId]);
        $topDomains = array_column($referrerStmt->fetchAll(), 'domain');

        // REFERRER CHART DATA
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $days[$d] = ['day' => date('D', strtotime($d))];
            foreach ($topDomains as $dom) $days[$d][$dom] = 0;
        }
        
        $chartData = []; 
        $ykeys = [];
        
        if (!empty($topDomains)) {
            $placeholders = implode(',', array_fill(0, count($topDomains), '?'));
            $sql2 = "
              SELECT
                CASE WHEN a.referrer IS NULL OR a.referrer = '' THEN 'Direct'
                     ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(a.referrer, '://', -1), '/', 1)) END AS domain,
                DATE(a.created_at) AS d,
                COUNT(*) AS cnt
              FROM analytics a 
              WHERE a.link_id IN (SELECT id FROM links WHERE user_id = ?)
                AND DATE(a.created_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
                AND (
                  CASE WHEN a.referrer IS NULL OR a.referrer = '' THEN 'Direct'
                       ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(a.referrer, '://', -1), '/', 1)) END
                ) IN ($placeholders)
              GROUP BY domain, d
            ";
            $params = array_merge([$userId], $topDomains);
            $stmt2 = $pdo->prepare($sql2); 
            $stmt2->execute($params);
            foreach ($stmt2->fetchAll() as $r) {
                if (isset($days[$r['d']][$r['domain']])) {
                    $days[$r['d']][$r['domain']] = (int)$r['cnt'];
                }
            }
            $chartData = array_values($days); 
            $ykeys = array_values($topDomains);
        } else {
            $chartData = array_values($days); 
            $ykeys = [];
        }

        // Save heavy data to cache
        $heavyData = compact(
            'desktopClicks', 'mobileClicks', 'tabletClicks', 'unknownClicks',
            'topDomains', 'chartData', 'ykeys'
        );
        $redis->set($heavyCacheKey, json_encode($heavyData), $heavyCacheTtl);
    }

    // CHART DATA dengan cache terpisah
    $chartCache = $redis->get($chartCacheKey);
    if ($chartCache) {
        $chartCacheData = json_decode($chartCache, true);
        $chartDates = $chartCacheData['chartDates'];
        $weeklyChart = $chartCacheData['weeklyChart'];
        $monthlyChart = $chartCacheData['monthlyChart'];
    } else {
        // DAILY (7 Hari)
        $chartDates = [];
        for ($i = 6; $i >= 0; $i--) {
            $chartDates[date('Y-m-d', strtotime("-$i days"))] = 0;
        }
        $dateStart = date('Y-m-d', strtotime('-6 days'));
        $dateEnd = date('Y-m-d');
        
        $analyticsStmt = $pdo->prepare("
            SELECT DATE(a.created_at) AS date, COUNT(*) AS total
            FROM analytics a 
            WHERE a.link_id IN (SELECT id FROM links WHERE user_id = ?) 
            AND DATE(a.created_at) BETWEEN ? AND ?
            GROUP BY DATE(a.created_at)
            ORDER BY date
        ");
        $analyticsStmt->execute([$userId, $dateStart, $dateEnd]);
        foreach ($analyticsStmt->fetchAll() as $row) {
            $chartDates[$row['date']] = (int)$row['total'];
        }

        // WEEKLY (7 Minggu)
        $weeklyChart = [];
        $weeklyRange = [];
        for ($i = 6; $i >= 0; $i--) {
            $monday = date('Y-m-d', strtotime("monday -$i week"));
            $sunday = date('Y-m-d', strtotime("$monday +6 days"));
            $label = date('d M', strtotime($monday)) . ' - ' . date('d M', strtotime($sunday));
            $weeklyChart[$label] = 0;
            $weeklyRange[$label] = [$monday, $sunday];
        }
        $weeklyStmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM analytics a 
            WHERE a.link_id IN (SELECT id FROM links WHERE user_id = ?) 
            AND DATE(a.created_at) BETWEEN ? AND ?
        ");
        foreach ($weeklyRange as $label => $range) {
            $weeklyStmt->execute([$userId, $range[0], $range[1]]);
            $weeklyChart[$label] = (int)$weeklyStmt->fetchColumn();
        }

        // MONTHLY (12 Bulan)
        $monthlyChart = [];
        for ($i = 11; $i >= 0; $i--) {
            $monthlyChart[date('M Y', strtotime("-$i month"))] = 0;
        }
        $monthlyStmt = $pdo->prepare("
            SELECT DATE_FORMAT(a.created_at, '%b %Y') AS bulan, COUNT(*) AS total
            FROM analytics a 
            WHERE a.link_id IN (SELECT id FROM links WHERE user_id = ?)
            AND a.created_at >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01')
            GROUP BY bulan
            ORDER BY MIN(a.created_at)
        ");
        $monthlyStmt->execute([$userId]);
        foreach ($monthlyStmt->fetchAll() as $row) {
            $monthlyChart[$row['bulan']] = (int)$row['total'];
        }

        // Save chart data
        $chartCacheData = compact('chartDates', 'weeklyChart', 'monthlyChart');
        $redis->set($chartCacheKey, json_encode($chartCacheData), $heavyCacheTtl);
    }

    // DATA RINGAN (user stats, recent links, dll)
    $userDataStmt = $pdo->prepare("
        SELECT u.type, u.telegram_id,
            COALESCE(link_stats.total_links, 0) as total_links,
            COALESCE(link_stats.total_clicks, 0) as total_clicks,
            COALESCE(link_stats.today_clicks, 0) as today_clicks,
            COALESCE(domains.total_domains, 0) as total_domains
        FROM users u
        LEFT JOIN (
            SELECT user_id, COUNT(*) AS total_links, SUM(clicks) AS total_clicks,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN clicks ELSE 0 END) AS today_clicks
            FROM links WHERE user_id = ? GROUP BY user_id
        ) link_stats ON u.id = link_stats.user_id
        LEFT JOIN (
            SELECT user_id, COUNT(*) as total_domains
            FROM list_domains WHERE user_id = ? GROUP BY user_id
        ) domains ON u.id = domains.user_id
        WHERE u.id = ?
    ");
    $userDataStmt->execute([$userId, $userId, $userId]);
    $userData = $userDataStmt->fetch(PDO::FETCH_ASSOC);
    
    $totalLinks = (int)($userData['total_links'] ?? 0);
    $totalClicks = (int)($userData['total_clicks'] ?? 0);
    $todayClicks = (int)($userData['today_clicks'] ?? 0);  // <=== INI SUDAH BENAR!
    $totalDomains = (int)($userData['total_domains'] ?? 0);
    $userType = $userData['type'] ?? 'trial';
    $userTelegramId = $userData['telegram_id'] ?? '';

    // Recent Links
    $recentLinksStmt = $pdo->prepare("
        SELECT l.id, l.short_code, d.domain, l.created_at
        FROM links l JOIN domains d ON l.domain_id = d.id
        WHERE l.user_id = ? ORDER BY l.created_at DESC LIMIT 6
    ");
    $recentLinksStmt->execute([$userId]);
    $recentLinks = $recentLinksStmt->fetchAll();

    // Top Shortlink
    $topShortlinkStmt = $pdo->prepare("
        SELECT CONCAT(d.domain, '/', l.short_code) AS full_url, l.clicks
        FROM links l JOIN domains d ON l.domain_id = d.id
        WHERE l.user_id = ? ORDER BY l.clicks DESC LIMIT 1
    ");
    $topShortlinkStmt->execute([$userId]);
    $topShortlinkData = $topShortlinkStmt->fetch();
    $topShortlinkUrl = $topShortlinkData['full_url'] ?? 'Belum Ada';
    $topShortlinkClicks = (int)($topShortlinkData['clicks'] ?? 0);

    // Activity Logs
    $activityLogsStmt = $pdo->prepare("
        SELECT action, created_at 
        FROM activity_logs 
        WHERE user_id = ? 
        ORDER BY created_at DESC LIMIT 10
    ");
    $activityLogsStmt->execute([$userId]);
    $activityLogs = $activityLogsStmt->fetchAll();

    // User Cron
    $stmt = $pdo->prepare("SELECT interval_minute, status FROM list_domains WHERE user_id = ? GROUP BY user_id LIMIT 1");
    $stmt->execute([$userId]);
    $userCron = $stmt->fetch(PDO::FETCH_ASSOC);
    $interval = $userCron['interval_minute'] ?? 5;
    $status = $userCron['status'] ?? 0;

    // Payments
    $paymentsStmt = $pdo->prepare("
        SELECT reference, package, method, amount, status, checkout_url, created_at, expired_time
        FROM payments WHERE customer_name = ? ORDER BY created_at DESC LIMIT 20
    ");
    $paymentsStmt->execute([$username]);
    $payments = $paymentsStmt->fetchAll();
    $unpaidStatus = getUnpaidStatus($pdo, $username);

    $hasUnpaidVip = $unpaidStatus['hasUnpaidvip'] ?? false;
    $isExpiredVip = $unpaidStatus['isExpiredvip'] ?? false;
    $hasUnpaidVipMax = $unpaidStatus['hasUnpaidvipmax'] ?? false;
    $isExpiredVipMax = $unpaidStatus['isExpiredvipmax'] ?? false;
    $hasUnpaidMedium = $unpaidStatus['hasUnpaidmedium'] ?? false;
    $isExpiredMedium = $unpaidStatus['isExpiredmedium'] ?? false;

    $isVIP = ($userType === 'vip' || $userType === 'vipmax');
    $menu = isset($_GET['menu']) ? $_GET['menu'] : null;

    // STORE TO MAIN CACHE
    $mainCacheData = compact(
        'errorMessage','successMessage','userData',
        'totalLinks','totalClicks','todayClicks','totalDomains','userType','userTelegramId',
        'recentLinks','topShortlinkUrl','topShortlinkClicks',
        'chartDates','weeklyChart','monthlyChart',
        'activityLogs','desktopClicks','mobileClicks','tabletClicks','unknownClicks',
        'topDomains','chartData','ykeys',
        'interval','status','payments','unpaidStatus','isVIP','menu'
    );
    $redis->set($mainCacheKey, json_encode($mainCacheData), $cacheTtl);
}

// ===== JANGAN ADA QUERY LAGI DI SINI! =====
// Variable $todayClicks sudah di-set dari cache atau database di atas

?>
<!DOCTYPE html>

<html
  lang="id"
  class="light-style layout-navbar-fixed layout-menu-fixed layout-compact"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template">
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />

    <title>SFLINK.ID | Dashboard Page</title>

    <meta name="description" content="" />

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../img/favico.png" />


    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Rubik:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet" />

    <!-- Icons -->
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/fonts/fontawesome.css" />
    <link rel="stylesheet" href="../assets/vendor/fonts/flag-icons.css" />

    <!-- Core CSS -->
    <link rel="stylesheet" href="../assets/vendor/css/rtl/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/rtl/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />

    <!-- Vendors CSS -->
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/typeahead-js/typeahead.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />

    <!-- Page CSS -->

    <!-- Helpers -->
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!--! Template customizer & Theme config files MUST be included after core stylesheets and helpers.js in the <head> section -->
    <!--? Template customizer: To hide customizer set displayCustomizer value false in config.js.  -->
    <script src="../assets/vendor/js/template-customizer.js"></script>
    <!--? Config:  Mandatory theme config file contain global vars & default theme options, Set your preferred theme option in this file.  -->
    <script src="../assets/js/config.js"></script>
	    <style>
	    :root {
  --primary: #5a8dee;    /* Warna biru/light */

}
[data-theme-version="dark"] {
  --primary: #5a8dee;    /* Boleh pakai biru soft untuk dark */
}



  .login-logo {
    display: block;
padding:10px;

    max-width: 90%;
    max-height: 56px;
    border-radius: 12px;
    transition: all 0.2s;
    object-fit: contain;
  }
		  .app-brand-link {
    width: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 0;
  }
  
#userDomainList tr {
  cursor: move;
}
.morris-hover .morris-hover-point {
    color: #000000 !important;
}
	.badge-overlay-container {
  position: relative;
}
.badge-overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  pointer-events: none;
  z-index: 3;
}
.badge-line {
  position: absolute;
  right: 10px;
  font-size: 0.85em;
}
.textarea-overlay {
  font-family: inherit;
  white-space: pre-wrap;
  visibility: hidden;
  line-height: 1.5;
}
	  /* Hanya border-bottom per item, kecuali item terakhir */
  #recentAjaxDashboard .list-group-item {
    border: none;
    border-bottom: 1px solid #e9ecef;
    padding: .8rem 1rem;
  }
  #recentAjaxDashboard .list-group-item:last-child {
    border-bottom: none;
  }
	    /* ukurannya sesuaikan, misal 12×12px */
.badge-pulse {
  display: inline-block;
  width: 12px;
  height: 12px;
  padding: 0;
  background-color: #dc3545; /* bg-danger */
  animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
  0% {
    transform: scale(1);
    box-shadow: 0 0 0 rgba(220, 53, 69, 0.7);
  }
  50% {
    transform: scale(1.4);
    box-shadow: 0 0 8px rgba(220, 53, 69, 0.4);
  }
  100% {
    transform: scale(1);
    box-shadow: 0 0 0 rgba(220, 53, 69, 0);
  }
  
  .sidebar-upgrade-container {
  display: flex;
  justify-content: center;
  align-items: center;
  /* Agar ke bawah, bisa pakai margin-bottom besar atau position absolute jika sidebar fixed */
  margin-top: 40px;
  margin-bottom: 36px;
  min-height: 64px;
}

.btn-upgrade-akun {
  background: linear-gradient(90deg, #3b82f6 10%, #6366f1 100%);
  color: #fff !important;
  border: none;
  border-radius: 14px;
  padding: 12px 32px;
  font-size: 1.1rem;
  box-shadow: 0 4px 16px #4f8cff22;
  transition: background 0.22s, box-shadow 0.22s, color 0.14s;
}
.btn-upgrade-akun:hover {
  background: linear-gradient(90deg, #6366f1 10%, #3b82f6 100%);
  color: #fff !important;
  box-shadow: 0 8px 28px #4f8cff33;
}



    </style>
  </head>

  <body>
      
      
      
      <!-- Loader Overlay -->
<div id="modalShortSubdoLoader" style="display:none;position:fixed;z-index:2000;top:0;left:0;width:100vw;height:100vh;background:rgba(255,255,255,0.75);backdrop-filter:blur(1px)">
  <div class="d-flex flex-column justify-content-center align-items-center h-100">
    <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem"></div>
    <div class="fw-bold text-dark">Mengambil data domain…</div>
  </div>
</div>
      <!-- Modal Generate Subdo -->
<div class="modal fade" id="generateShortlinkSubdoModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form id="formShortlinkSubdo" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Buat Shortlink ke Subdomain Random</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="shortSubdoMsg"></div>
        <div class="mb-2">
          <label>Domain Shortlink</label>
          <select class="form-select" id="selectBaseDomainShortSubdo"></select>
        </div>
        <div class="mb-2">
          <label>Domain Subdo Target</label>
          <select class="form-select" id="selectDomainShortSubdo"></select>
        </div>
        <div class="mb-2">
  <label>Tambahan Path Referal (opsional)</label>
  <input type="text" class="form-control" id="inputReferalPath" placeholder="Tambahan Path Referal (opsional)">

  <small class="text-muted">Akan ditambahkan di akhir URL subdo, misal: <code>/register?ref=slotgacor</code></small>
</div>
        <div class="mb-2">
          <label>Nama Shortlink</label>
          <input type="text" class="form-control" id="inputShortCodeSubdo" placeholder="Contoh: pongkedo, mcb2025, topcer">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-dark" type="submit">Buat Shortlink Subdo</button>
           
      </div>

 <div id="shortSubdoList" class="mt-3"></div>
    </form>
  </div>
</div>


      <!-- Toast Container (letakkan 1x saja di layout utama) -->
<div id="mainToast" class="toast align-items-center text-white bg-primary border-0 position-fixed bottom-0 end-0 m-3" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
  <div class="d-flex">
    <div class="toast-body" id="mainToastBody"></div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
  </div>
</div>

      <!-- Modal Edit Config -->
<div class="modal fade" id="modalEditConfig" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-info">Edit Config: <span id="editConfigDomain"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <textarea id="editConfigTextarea" style="width:100%;height:340px;padding:10px;color:#23d5ab;background:#111;border-radius:7px;font-size:15px;"></textarea>
        <div id="editConfigNotif" class="mt-2 text-danger"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-success" id="btnSaveConfig">Save</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>
      <!-- Modal Add Subdo -->
<div class="modal fade" id="addSubdoModal" tabindex="-1" aria-labelledby="addSubdoModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="formAddSubdo" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addSubdoModalLabel">Add Subdomain for <span id="addSubdoDomain"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="addSubdoMsg"></div>
        <input type="hidden" id="addSubdoDomainId" name="domain_id">
        <div class="mb-3">
          <label for="inputSubdo" class="form-label">Subdomain</label>
          <input type="text" class="form-control" id="inputSubdo" name="subdo" placeholder="contoh: blog / app / dev" required>
          <div class="form-text">Tanpa domain, cukup subnya saja. Misal <b>blog</b> → blog.domain.com</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Add Subdo</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>
<!-- Modal Proxy (Bootstrap 5) -->
<div class="modal fade" id="proxyModal" tabindex="-1" aria-labelledby="proxyModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="proxyForm" autocomplete="off">
      <input type="hidden" name="domain_id" id="proxy_domain_id">
      <div class="modal-header">
        <h5 class="modal-title" id="proxyModalLabel">Set Subdo Proxy untuk <span id="proxyDomainLabel"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="proxyFormMsg"></div>
        <div class="mb-2 d-none">
          <label class="form-label">Proxy Name</label>
          <input type="text" class="form-control" name="proxy_name" placeholder="proxyXXXX" hidden>
        </div>
        <div class="mb-2 d-none">
          <label class="form-label">Proxy Dir</label>
          <input type="text" class="form-control" name="proxy_dir" value="/" placeholder="/" required hidden>
        </div>
        <div class="mb-2">
          <label class="form-label">Target URL</label>
         <input type="text" class="form-control" id="inputTargetUrl" name="target_url" placeholder="http://target.com/" required>

          <small>Gunakan URL Tujuan seperti ini https://tujuan.com </small>
        </div>
        <div class="mb-2 d-none">
          <label class="form-label">Replace From</label>
          <input type="text" class="form-control" name="replace_from" placeholder="Ganti isi (opsional)" hidden>
        </div>
        <div class="mb-2 d-none">
          <label class="form-label">Replace To</label>
          <input type="text" class="form-control" name="replace_to" placeholder="Menjadi apa (opsional)"hidden>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" type="submit">Simpan Proxy</button>
      </div>
    </form>
  </div>
</div>




<div class="modal fade" id="mainDomainModal" tabindex="-1" aria-labelledby="mainDomainLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mainDomainLabel">
          <i class="fa fa-server me-2"></i> Kelola Main Domain Anda
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Form tambah domain -->
        <form id="addMainDomainForm" class="row g-2 align-items-center mb-3">
          <div class="col-md-5">
            <input type="text" name="domain" id="mainDomainInput" class="form-control" placeholder="Main domain, misal: namadomain.com" required>
          </div>
          <div class="col-md-5">
            <input type="text" name="fallback_domain" id="fallbackDomainInput" class="form-control" placeholder="Fallback domain (opsional)">
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Tambah</button>
          </div>
        </form>
        <div id="mainDomainMsg" class="mb-3"></div>
        <!-- Table list domain -->
        <div class="table-responsive">
          <table class="table table-bordered table-sm align-middle">
            <thead>
              <tr>
                <th>Main Domain</th>
                <th>Fallback Domain</th>
                <th>Date</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="mainDomainList">
              <!-- Data by JS -->
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>



     <!-- Modal Keterangan Status User -->
<div class="modal fade" id="userTypeModal" tabindex="-1" aria-labelledby="userTypeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content animate__animated animate__fadeIn">
      <div class="modal-header">
        <h5 class="modal-title" id="userTypeModalLabel">
          <?= strtoupper($userType) ?> Account
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
 <?php if ($userType === 'trial'): ?>
  <p>🔐 Anda dalam masa <strong>TRIAL</strong>. Akun dibatasi hanya dapat membuat <strong>1 entri</strong> (Shortlink, Trust Check Domain, Auto Check Domain).</p>
  <p>✨ Upgrade ke <strong>MEDIUM</strong> atau <strong>VIP</strong> untuk mendapatkan akses penuh tanpa batas!</p>
<?php elseif ($userType === 'medium'): ?>
  <p>🔓 Anda dalam masa <strong>MEDIUM</strong>. Akun dibatasi hanya dapat membuat <strong>3 entri</strong> (Shortlink, Trust Check Domain, Auto Check Domain).</p>
  <p>✨ Upgrade ke <strong>VIP</strong> untuk membuka semua fitur tanpa batas!</p>
<?php elseif ($userType === 'vip'): ?>
  <p>🚀 Anda adalah pengguna <strong>VIP</strong>. Semua fitur telah terbuka limit max 30 entri semua fitur.</p>
<?php elseif ($userType === 'vipmax'): ?>
  <p>👑 Anda adalah pengguna <strong>VIP MAX</strong>. Nikmati semua fitur premium + prioritas support + bebas request tambah fitur!!.</p>
<?php endif; ?>
      </div>
      <div class="modal-footer">
        <?php if ($userType === 'trial'): ?>
          <a  href="javascript:void(0)" onclick="showUpgradeModal()"class="btn btn-primary">
            <i class="fa-solid fa-crown"></i> Upgrade ke VIP
          </a>
        <?php endif; ?>
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
        <!-- Modal Kritik & Saran -->
<div class="modal fade" id="kritikSaranModal" tabindex="-1" aria-labelledby="kritikSaranModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content animate__animated animate__fadeIn">
      <div class="modal-header">
        <h5 class="modal-title" id="kritikSaranModalLabel">
          <i class="fa-solid fa-comments"></i> Kritik & Saran
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div id="kritikSaranMsg" class="mt-2"></div>
        <!-- Form Kirim Kritik -->
        <form id="kritikSaranForm">
          <div class="mb-3">
            <label for="kritik_saran" class="form-label">Tulis Kritik & Saran Anda</label>
            <textarea id="kritik_saran" name="kritik_saran" rows="5" class="form-control" placeholder="Tulis di sini..." required></textarea>
          </div>
          <button type="submit" class="btn btn-primary w-100 mb-3">
            <i class="fa-solid fa-paper-plane"></i> Kirim
          </button>
        </form>

        <hr class="my-4">

        <!-- Daftar Kritik User -->
        <h5 class="mb-3"><i class="fa-solid fa-list"></i> Riwayat Kritik & Saran</h5>
        <div id="kritikList" class="overflow-auto" style="max-height: 300px;">
          <div class="text-center text-muted">
            <div class="spinner-border text-primary" role="status"></div>
            <div class="mt-2">Memuat riwayat...</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Modal Tutorial Telegram ID -->
<div class="modal fade" id="telegramTutorialModal" tabindex="-1" aria-labelledby="telegramTutorialModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content animate__animated animate__fadeInDown">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title" id="telegramTutorialModalLabel">
          <i class="fa-brands fa-telegram"></i> Cara Menambahkan Telegram ID
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <ol class="list-group list-group-numbered">
          <li class="list-group-item">Buka aplikasi <strong>Telegram</strong> Anda.</li>
          <li class="list-group-item">Cari dan buka bot kami: <strong>@sflinkid_bot</strong>.</li>
          <li class="list-group-item">Ketik perintah <code>/id</code> di dalam chat bot tersebut.</li>
          <li class="list-group-item">Bot akan membalas ID Telegram kamu, contoh: <code>123456789</code>.</li>
          <li class="list-group-item">Salin ID tersebut dan paste di kolom Telegram ID di atas.</li>
          <li class="list-group-item">Klik tombol <strong>Simpan</strong> di bawah untuk menyimpan perubahan.</li>
        </ol>
        <div class="text-center mt-3">
          <a href="https://t.me/sflinkid_bot" target="_blank" class="btn btn-primary">
            <i class="fa-brands fa-telegram"></i> Buka Bot Telegram
          </a>
        </div>
      </div>
    </div>
  </div>
</div>
    <!-- Toast Container -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 1100">
  <div id="upgradeToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
        Permintaan upgrade berhasil dikirim!
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<!-- Modal Upgrade -->
<div class="modal fade" id="upgradeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content animate__animated animate__fadeIn">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-crown"></i> Upgrade Akun</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Pilih tipe upgrade akun Anda:</p>
        <div class="d-grid gap-2">
          <button class="btn btn-outline-primary" onclick="startUpgrade('medium')">
            💎 Upgrade ke MEDIUM - $18.88/month
          </button>
          <button class="btn btn-outline-success" onclick="startUpgrade('vip')">
            👑 Upgrade ke VIP - $37.77/month
          </button>
        </div>
        <hr>
        <div id="paymentSection" class="d-none">
          <h6 class="mt-3">Silakan Scan via QRIS</h6>
          <p id="priceConverted" class="fw-bold text-center text-success fs-5"></p>
          <p class="text-muted text-center">Setelah transfer, klik tombol di bawah untuk konfirmasi.</p>
          <button class="btn btn-primary w-100 g-3 mb-4" onclick="confirmPayment()">Konfirmasi Pembayaran</button>
        </div>
        <div id="upgradeMsg" class="mt-3 g-3 mb-4"></div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Standby Upgrade -->
<div class="modal fade" id="standbyModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content animate__animated animate__fadeIn">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title"><i class="fa-solid fa-clock"></i> Proses Upgrade</h5>
      </div>
      <div class="modal-body text-center">
        <p class="fs-5">⏳ Pengajuan upgrade akun Anda sedang diproses.</p>
        <p>Mohon tunggu konfirmasi dari admin melalui Telegram.</p>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
<!-- Toast container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
  <div
    id="copyToast"
    class="toast align-items-center text-bg-success border-0 fade"
    role="alert"
    aria-live="assertive"
    aria-atomic="true"
    data-bs-autohide="true"
    data-bs-delay="2000"
  >
    <div class="d-flex">
      <div class="toast-body">Link disalin!</div>
      <button
        type="button"
        class="btn-close btn-close-white me-2 m-auto"
        data-bs-dismiss="toast"
        aria-label="Close"
      ></button>
    </div>
  </div>
</div>

    <!-- Layout wrapper -->
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <!-- Menu -->

        <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
          <div class="app-brand demo">
  <a href="/" class="app-brand-link">
    <img src="https://sflink.id/logo.png" alt="SFLINK.ID" class="login-logo">
  </a>
  <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
    <i class="bx menu-toggle-icon d-none d-xl-block fs-4 align-middle"></i>
    <i class="bx bx-x d-block d-xl-none bx-sm align-middle"></i>
  </a>
</div>

          <div class="menu-divider mt-0"></div>

          <div class="menu-inner-shadow"></div>
<ul class="menu-inner py-1">

  <!-- Dashboard -->
  <li class="menu-item">
    <a href="?menu=dashboard" class="menu-link">
      <i class="menu-icon tf-icons bx bx-home-circle"></i>
      <div data-i18n="Dashboard">Dashboard</div>
    </a>
  </li>

  <!-- Section: BUAT SHORTLINK -->
  <li class="menu-header small text-uppercase">BUAT SHORTLINK</li>
  <li class="menu-item">
    <a href="?menu=shorten-link" class="menu-link">
      <i class="menu-icon tf-icons bx bx-link-alt"></i>
      <div data-i18n="Shorten Link">Shorten Link</div>
    </a>
  </li>

  <!-- Section: RIWAYAT SHORTLINK -->
  <li class="menu-header small text-uppercase">RIWAYAT SHORTLINK</li>
  <li class="menu-item">
    <a href="?menu=recent-links" class="menu-link">
      <i class="menu-icon tf-icons bx bx-history"></i>
      <div data-i18n="Recent Links">Recent Links</div>
    </a>
  </li>

  <!-- Section: STATISTIK SHORTLINK -->
  <li class="menu-header small text-uppercase">STATISTIK SHORTLINK</li>
  <li class="menu-item">
    <a href="?menu=analytics" class="menu-link">
      <i class="menu-icon tf-icons bx bx-bar-chart-alt-2"></i>
      <div data-i18n="Analytics">Analytics</div>
    </a>
  </li>

  <!-- Section: DOMAIN CHECK -->
  <li class="menu-header small text-uppercase">TAMBAH DOMAIN CHECK</li>
  <li class="menu-item">
    <a href="?menu=add-domain" class="menu-link">
      <i class="menu-icon tf-icons bx bx-plus-circle"></i>
      <div data-i18n="Add Domains">Add Domains</div>
    </a>
  </li>

  <!-- Section: SETTING WAKTU BOT -->
  <li class="menu-header small text-uppercase">SETTING WAKTU BOT</li>
  <li class="menu-item">
    <a href="?menu=set-timer" class="menu-link">
      <i class="menu-icon tf-icons bx bx-time"></i>
      <div data-i18n="Set Timer">Set Timer</div>
    </a>
  </li>

  <!-- Section: DA/PA UMUR CHECK -->
  <li class="menu-header small text-uppercase">DA/PA UMUR CHECK</li>
  <li class="menu-item">
    <a href="?menu=dapa-checker" class="menu-link">
      <i class="menu-icon tf-icons bx bx-search-alt"></i>
      <div data-i18n="DA/PA Checker">DA/PA Checker</div>
    </a>
  </li>

  <!-- Section: Check Domain Manual -->
  <li class="menu-header small text-uppercase">CHECK DOMAIN MANUAL</li>
  <li class="menu-item">
    <a href="?menu=check-domain" class="menu-link">
      <i class="menu-icon tf-icons bx bx-globe"></i>
      <div data-i18n="Domain Trust Checker">Domain Trust Checker</div>
    </a>
  </li>

<li class="menu-header small text-uppercase">ADD SUBDO DEWA</li>
<li class="menu-item">
  <a href="?menu=reserve-subdo" class="menu-link">
    <i class="menu-icon tf-icons bx bx-cloud"></i>
    <div data-i18n="Reserve Subdo">Reserve Subdo</div>
  </a>
</li>


  <!-- Section: Daftar Harga -->
  <li class="menu-header small text-uppercase">DAFTAR HARGA</li>
  <li class="menu-item">
    <a href="?menu=daftar-harga" class="menu-link">
      <i class="menu-icon tf-icons bx bx-purchase-tag"></i>
      <div data-i18n="Price List">Price List</div>
    </a>
  </li>
  

  
</ul>
<div class="sidebar-upgrade-container mt-4 mb-4">
  <a href="?menu=daftar-harga"
    class="btn-upgrade-akun d-flex align-items-center justify-content-center fw-bold">
    <i class="fa-solid fa-arrow-up-right-dots me-2"></i>
    Upgrade Akun
  </a>
</div>


<!-- Live Support / Customer Service Section -->
<div class="sidebar-support mt-auto py-3 px-3 text-center" style="position: sticky; bottom: 0; z-index:99;">
  <div class="rounded shadow-sm bg-white bg-opacity-75 d-flex align-items-center justify-content-center gap-2" style="min-height:44px;">
    <i class="bx bx-headphone fs-4 text-primary"></i>
    <div class="d-flex flex-column text-start">
      <span class="fw-bold text-primary" style="font-size:13px;">Live Support</span>
      <a href="https://t.me/sflinkid" target="_blank" class="text-decoration-none text-dark small">Chat CS Telegram</a>
    </div>
  </div>
</div>
</aside>
        <!-- Layout container -->
        <div class="layout-page">
          <!-- Navbar -->

          <nav class="layout-navbar navbar navbar-expand-xl align-items-center bg-navbar-theme" id="layout-navbar">
            <div class="container-xxl">
              <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
                <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
                  <i class="bx bx-menu bx-sm"></i>
                </a>
              </div>

              <div class="navbar-nav-right d-flex align-items-center" id="navbar-collapse">


                <ul class="navbar-nav flex-row align-items-center ms-auto">
       

                  <!-- Style Switcher -->
                  <li class="nav-item dropdown-style-switcher dropdown me-2 me-xl-0">
                    <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
                      <i class="bx bx-sm"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-styles">
                      <li>
                        <a class="dropdown-item" href="javascript:void(0);" data-theme="light">
                          <span class="align-middle"><i class="bx bx-sun me-2"></i>Light</span>
                        </a>
                      </li>
                      <li>
                        <a class="dropdown-item" href="javascript:void(0);" data-theme="dark">
                          <span class="align-middle"><i class="bx bx-moon me-2"></i>Dark</span>
                        </a>
                      </li>
                      <li>
                        <a class="dropdown-item" href="javascript:void(0);" data-theme="system">
                          <span class="align-middle"><i class="bx bx-desktop me-2"></i>System</span>
                        </a>
                      </li>
                    </ul>
                  </li>
                  <!-- / Style Switcher-->


    <!-- Notification -->
<li class="nav-item dropdown-notifications navbar-dropdown dropdown me-3 me-xl-2">
  <a
    class="nav-link dropdown-toggle hide-arrow"
    href="javascript:void(0);"
    data-bs-toggle="dropdown"
    data-bs-auto-close="outside"
    aria-expanded="false">
    <i class="bx bx-bell bx-sm"></i>
    <?php
      // Hitung jumlah activity log sebagai badge
      $notifCount = isset($activityLogs) && is_array($activityLogs) ? count($activityLogs) : 0;
      if ($notifCount > 0): ?>
      <span class="badge bg-danger rounded-pill badge-notifications"><?= $notifCount ?></span>
    <?php endif; ?>
  </a>
  <ul class="dropdown-menu dropdown-menu-end py-0">
    <li class="dropdown-menu-header border-bottom">
      <div class="dropdown-header d-flex align-items-center py-3">
        <h5 class="text-body mb-0 me-auto">Notification</h5>
        <a
          href="javascript:void(0)"
          class="dropdown-notifications-all text-body"
          data-bs-toggle="tooltip"
          data-bs-placement="top"
          title="Mark all as read">
          <i class="bx fs-4 bx-envelope-open"></i>
        </a>
      </div>
    </li>
    <li class="dropdown-notifications-list scrollable-container" style="max-height: 350px;overflow-y: auto;">
      <ul class="list-group list-group-flush">
        <?php if (!empty($activityLogs)): ?>
          <?php foreach ($activityLogs as $log): ?>
            <?php
              $action   = htmlspecialchars($log['action'], ENT_QUOTES);
              $time     = htmlspecialchars(formatTanggalIndonesia($log['created_at']), ENT_QUOTES);

              // Mapping warna icon (label) sesuai aksi
              $labelClass = 'bg-label-info';
              $icon       = 'bx-info-circle';
              if (str_contains(strtolower($action), 'hapus')) {
                $labelClass = 'bg-label-danger';
                $icon       = 'bx-trash';
              } else if (
                str_contains(strtolower($action), 'edit') ||
                str_contains(strtolower($action), 'ubah')
              ) {
                $labelClass = 'bg-label-warning';
                $icon       = 'bx-edit-alt';
              }
            ?>
            <li class="list-group-item list-group-item-action dropdown-notifications-item">
              <div class="d-flex">
                <div class="flex-shrink-0 me-3">
                  <span class="avatar-initial rounded-circle <?= $labelClass ?>">
                    <i class="bx <?= $icon ?>"></i>
                  </span>
                </div>
                <div class="flex-grow-1">
                  <h6 class="mb-1"><?= $action ?></h6>
                  <small class="text-muted"><?= $time ?></small>
                </div>
                <!-- Optional: Add mark as read/archive icons if needed -->
              </div>
            </li>
          <?php endforeach; ?>
        <?php else: ?>
          <li class="list-group-item text-center text-muted py-4">
            <em>Belum ada aktivitas terbaru</em>
          </li>
        <?php endif; ?>
      </ul>
    </li>
    <li class="dropdown-menu-footer border-top">
      <a href="?menu=notification" class="dropdown-item d-flex justify-content-center p-3">
        Lihat semua notifikasi
      </a>
    </li>
  </ul>
</li>
<!--/ Notification -->


                  <!-- User -->
              <!-- User Profile Dropdown Frest + Custom -->
<li class="nav-item navbar-dropdown dropdown-user dropdown">
  <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
    <div class="avatar avatar-online">
      <img src="<?= htmlspecialchars($userAvatarUrl ?? '../assets/img/avatars/1.webp', ENT_QUOTES) ?>" alt="User Avatar" class="rounded-circle" />
    </div>
  </a>
  <ul class="dropdown-menu dropdown-menu-end">
    <li>
      <a class="dropdown-item" href="?menu=user-profile">
        <div class="d-flex">
          <div class="flex-shrink-0 me-3">
            <div class="avatar avatar-online">
              <img src="<?= htmlspecialchars($userAvatarUrl ?? '../assets/img/avatars/1.webp', ENT_QUOTES) ?>" alt="User Avatar" class="rounded-circle" />
            </div>
          </div>
          <div class="flex-grow-1">
            <span class="fw-medium d-block lh-1"><?= htmlspecialchars($username, ENT_QUOTES) ?></span>
            <small><?= htmlspecialchars(strtoupper($userType . ' User'), ENT_QUOTES) ?></small>
          </div>
        </div>
      </a>
    </li>
    <li><div class="dropdown-divider"></div></li>

    <li>
      <a class="dropdown-item" href="?menu=user-profile" id="nav-profile">
        <i class="bx bx-user me-2"></i>
        <span class="align-middle">User Profile</span>
      </a>
    </li>
    <li>
      <a class="dropdown-item" href="?menu=notification" id="nav-notification">
        <i class="bx bx-bell me-2"></i>
        <span class="align-middle">Notification</span>
      </a>
    </li>
    <li>
      <a class="dropdown-item" data-bs-toggle="modal" data-bs-target="#kritikSaranModal">
        <i class="bx bx-message-alt-detail me-2"></i>
        <span class="align-middle">Kritik & Saran</span>
      </a>
    </li>

    <?php if (!in_array($userType, ['vip', 'vipmax'])): ?>
      <li>
        <a class="dropdown-item" href="?menu=daftar-harga">
          <i class="bx bx-crown me-2"></i>
          <span class="align-middle">Upgrade Akun</span>
        </a>
      </li>
    <?php endif; ?>

    <?php if (isset($_SESSION['username']) && $_SESSION['username'] === 'admin'): ?>
      <li>
        <a class="dropdown-item" href="admin" title="Admin Mode">
          <i class="bx bx-shield-quarter me-2"></i>
          <span class="align-middle">Admin Akses</span>
        </a>
      </li>
    <?php endif; ?>

    <li>
      <a class="dropdown-item" href="?menu=riwayat-pembayaran" id="nav-riwayatpembayaran">
        <i class="bx bx-wallet me-2"></i>
        <span class="align-middle">Riwayat Pembayaran</span>
      </a>
    </li>
    <li><div class="dropdown-divider"></div></li>
    <li>
      <a class="dropdown-item" href="/logout">
        <i class="bx bx-power-off me-2"></i>
        <span class="align-middle">Logout</span>
      </a>
    </li>
  </ul>
</li>
<!--/ User -->
                </ul>
              </div>

              <!-- Search Small Screens -->
              <div class="navbar-search-wrapper search-input-wrapper container-xxl d-none">
                <input
                  type="text"
                  class="form-control search-input border-0"
                  placeholder="Search..."
                  aria-label="Search..." />
                <i class="bx bx-x bx-sm search-toggler cursor-pointer"></i>
              </div>
            </div>
          </nav>

          <!-- / Navbar -->

          <!-- Content wrapper -->
          <div class="content-wrapper">
            <!-- Content -->
			
            <div class="container-xxl flex-grow-1 container-p-y">

      <!-- Section: Dashboard -->
<div id="dashboardSection" class="section">
  
  <div class="container-fluid px-0">

    <!-- Filter Button -->
    <div class="row mb-3">
      <div class="col-12 d-flex justify-content-end">
        <button class="btn btn-outline-primary btn-sm" type="button" id="toggleFilter">
          <i class="bi bi-funnel"></i> Filter Data
        </button>
      </div>
    </div>

    <!-- Filter Form -->
    <div class="row mb-3" id="filterFormRangeRow" style="display:none;">
      <div class="col-12">
        <form id="dashboardFilterForm" class="card card-body p-3 mb-0 shadow-sm border-0">
          <div class="row g-2 align-items-center flex-nowrap">
            <div class="col-4">
              <input type="date" name="start" id="filter-range" class="form-control" style="min-width:135px;">
            </div>
            <div class="col-auto fw-bold">-</div>
            <div class="col-4">
              <input type="date" name="end" class="form-control" style="min-width:135px;">
            </div>
            <div class="col-2">
              <button class="btn btn-primary btn-sm w-100" type="submit">Filter</button>
            </div>
            <div class="col-2">
              <button class="btn btn-outline-secondary btn-sm w-100" type="button" id="resetFilterBtn">Reset</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
      <div class="col-xl-3 col-sm-6 col-12 mb-3">
        <div class="card">
          <div class="card-body d-flex justify-content-between">
            <div class="card-menu">
              <span>Total Visit</span>
              <h2 class="mb-0" data-id="totalClicks">...</h2>
            </div>
            <div class="icon-box icon-box-lg bg-primary-light">
              <!-- Eye icon -->
              <svg width="50" height="50" viewBox="0 0 24 24" fill="none">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" stroke="var(--primary)" stroke-width="2" fill="none"/>
                <circle cx="12" cy="12" r="4" stroke="var(--primary)" stroke-width="2" fill="none"/>
              </svg>
            </div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-sm-6 col-12 mb-3">
        <div class="card">
          <div class="card-body d-flex justify-content-between">
            <div class="card-menu">
              <span>Total Shortlink</span>
              <h2 class="mb-0" data-id="totalLinks">...</h2>
            </div>
            <div class="icon-box icon-box-lg bg-primary-light">
              <!-- Chain/Link icon -->
              <svg width="50" height="50" viewBox="0 0 24 24" fill="none">
                <path d="M10.59 13.41a2 2 0 010-2.82l2-2a2 2 0 012.82 2l-.88.88" stroke="var(--primary)" stroke-width="2" fill="none"/>
                <path d="M13.41 10.59a2 2 0 012.82 0l2 2a2 2 0 01-2 2l-.88-.88" stroke="var(--primary)" stroke-width="2" fill="none"/>
                <path d="M14.83 9.17l-5.66 5.66" stroke="var(--primary)" stroke-width="2" fill="none"/>
              </svg>
            </div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-sm-6 col-12 mb-3">
        <div class="card">
          <div class="card-body d-flex justify-content-between">
            <div class="card-menu">
              <span>Total Domains</span>
              <h2 class="mb-0" data-id="totalDomains">...</h2>
            </div>
            <div class="icon-box icon-box-lg bg-primary-light">
              <!-- Globe icon -->
              <svg width="50" height="50" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="var(--primary)" stroke-width="2" fill="none"/>
                <path d="M2 12h20M12 2v20M5.64 5.64l12.72 12.72M5.64 18.36l12.72-12.72" stroke="var(--primary)" stroke-width="2"/>
              </svg>
            </div>
          </div>
        </div>
      </div>
      <div class="col-xl-3 col-sm-6 col-12 mb-3">
        <div class="card">
          <div class="card-body d-flex justify-content-between">
            <div class="card-menu">
              <span>Klik Hari Ini</span>
              <h2 class="mb-0" data-id="todayClicks">...</h2>
            </div>
            <div class="icon-box icon-box-lg bg-primary-light">
              <!-- Kalender icon -->
              <svg width="50" height="50" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="var(--primary)" stroke-width="2" fill="none"/>
                <rect x="7" y="10" width="10" height="8" stroke="var(--primary)" stroke-width="2" fill="none"/>
                <line x1="9" y1="6" x2="9" y2="8" stroke="var(--primary)" stroke-width="2"/>
                <line x1="15" y1="6" x2="15" y2="8" stroke="var(--primary)" stroke-width="2"/>
              </svg>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Statistik & Recent Link -->
    <div class="row mb-4">
      <div class="col-xl-8 custome-width mb-3">
        <div class="card h-auto">
          <div class="card-header border-0 pb-0 d-flex justify-content-between align-items-center">
            <h6 class="h-title mb-0">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="me-2">
                <path d="M3 17V21H7V17" stroke="#5b6d81" stroke-width="2" stroke-linecap="round"/>
                <path d="M9 13V21H15V13" stroke="#5b6d81" stroke-width="2" stroke-linecap="round"/>
                <path d="M17 8V21H21V8" stroke="#5b6d81" stroke-width="2" stroke-linecap="round"/>
              </svg>
              Statistik
            </h6>
            <ul class="revnue-tab nav nav-tabs" id="statistikTab" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="daily-tab" data-bs-toggle="tab" data-bs-target="#daily" type="button" role="tab" aria-controls="daily" aria-selected="true">Daily</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="weekly-tab" data-bs-toggle="tab" data-bs-target="#weekly" type="button" role="tab" aria-controls="weekly" aria-selected="false">Weekly</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="monthly-tab" data-bs-toggle="tab" data-bs-target="#monthly" type="button" role="tab" aria-controls="monthly" aria-selected="false">Monthly</button>
              </li>
            </ul>
          </div>
          <div class="card-body">
            <div class="tab-content" id="statistikTabContent">
              <div class="tab-pane fade show active" id="daily" role="tabpanel" aria-labelledby="daily-tab">
                <div id="statistikDailyChart" style="min-height: 280px;"></div>
              </div>
              <div class="tab-pane fade" id="weekly" role="tabpanel" aria-labelledby="weekly-tab">
                <div id="statistikWeeklyChart" style="min-height: 280px;"></div>
              </div>
              <div class="tab-pane fade" id="monthly" role="tabpanel" aria-labelledby="monthly-tab">
                <div id="statistikMonthlyChart" style="min-height: 280px;"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-xl-4 custome-width mb-3">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center py-2">
            <h6 class="mb-0">
              <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" stroke="#5b6d81" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2" viewBox="0 0 24 24">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                <path d="M5 15H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v2"/>
              </svg>
              Recent Link
            </h6>
            <span class="badge bg-secondary" id="recentCount">0 Links</span>
          </div>
          <ul class="list-group" id="recentAjaxDashboard"></ul>
        </div>
      </div>
    </div>

    
    
    
    <!-- Click by Device & Clicks by Location -->
    <div class="row g-4 align-items-stretch mb-4">
      <div class="col-xl-6 d-flex mb-3">
        <div class="card flex-fill h-100">
          <div class="card-header border-0 pb-0 flex-wrap">
            <h6 class="h-title mb-0 me-4">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="me-2" xmlns="http://www.w3.org/2000/svg">
                <rect x="2" y="6" width="13" height="10" rx="2" stroke="#5b6d81" stroke-width="2"/>
                <rect x="8" y="18" width="3" height="2" rx="1" stroke="#5b6d81" stroke-width="2"/>
                <rect x="17" y="7" width="5" height="8" rx="1" stroke="#5b6d81" stroke-width="2"/>
                <circle cx="19.5" cy="14.5" r="0.7" fill="#5b6d81"/>
                <rect x="13" y="9" width="3" height="6" rx="1" stroke="#5b6d81" stroke-width="2"/>
                <circle cx="14.5" cy="14.5" r="0.6" fill="#5b6d81"/>
              </svg>
              Click By Device
            </h6>
          </div>
          <div class="card-body px-0 d-flex flex-column align-items-center" style="min-height: 320px;">
            <div id="morris_donut" style="height:220px; width:300px;" class="mb-3"></div>
            <div class="w-100">
              <table class="table table-striped mb-0">
                <thead>
                  <tr><th>Device</th><th>Clicks</th></tr>
                </thead>
                <tbody>
                  <tr><td>Desktop</td><td data-id="desktopClicks">0</td></tr>
                  <tr><td>Mobile</td><td data-id="mobileClicks">0</td></tr>
                  <tr><td>Tablet</td><td data-id="tabletClicks">0</td></tr>
                  <tr><td>Unknown</td><td data-id="unknownClicks">0</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <div class="col-xl-6 d-flex mb-3">
        <div class="card flex-fill h-100">
          <div class="card-header border-0 pb-0 flex-wrap">
            <h6 class="h-title mb-0 me-4">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="me-2" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="10" r="4" stroke="#5b6d81" stroke-width="2"/>
                <path d="M12 22C12 22 20 14.36 20 10C20 5.58 16.42 2 12 2C7.58 2 4 5.58 4 10C4 14.36 12 22 12 22Z" stroke="#5b6d81" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              Clicks by Location
            </h6>
          </div>
          <div class="card-body px-0 pt-3 d-flex flex-column" style="min-height: 320px;">
            <ul class="nav nav-tabs mb-3" id="locTabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-country" data-bs-toggle="tab" data-bs-target="#pane-country" type="button" role="tab">Countries</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-city" data-bs-toggle="tab" data-bs-target="#pane-city" type="button" role="tab">Cities</button>
              </li>
            </ul>
            <div class="tab-content" style="padding-top:14px;padding-bottom:10px;">
              <div class="tab-pane fade show active" id="pane-country" role="tabpanel">
                <div class="table-responsive" style="padding:6px 12px 12px 12px;">
                  <table id="tbl-country" class="table table-sm table-striped mb-2">
                    <thead><tr><th>#</th><th>Country</th><th>Clicks</th><th>%</th></tr></thead>
                    <tbody></tbody>
                  </table>
                  <nav><ul id="pagination-country" class="pagination pagination-sm mb-0"></ul></nav>
                </div>
              </div>
              <div class="tab-pane fade" id="pane-city" role="tabpanel">
                <div class="table-responsive">
                  <table id="tbl-city" class="table table-sm table-striped mb-2">
                    <thead><tr><th>#</th><th>City</th><th>Clicks</th><th>%</th></tr></thead>
                    <tbody></tbody>
                  </table>
                  <nav><ul id="pagination-city" class="pagination pagination-sm mb-0"></ul></nav>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

<div class="text-center mb-4">
  <button id="btnShowMoreDashboard" class="btn btn-primary px-4 fw-bold">
    <i class="fa-solid fa-eye me-1"></i> Show More Data
  </button>
</div>

<div class="row g-4 mt-2 mb-3" id="extraDashboardRow" style="display:none;">
  <!-- Kiri: Click by Referrer -->
  <div class="col-lg-6 col-12">
    <div class="card flex-fill h-100 w-100">
      <div class="card-header border-0 pb-0 flex-wrap">
        <h6 class="h-title mb-0 me-4">
          <!-- (SVG Icon) -->
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="me-2" xmlns="http://www.w3.org/2000/svg">
            <path d="M8.5 15.5L7.1 16.9C5.3 18.7 2.7 18.7 0.9 16.9C-0.9 15.1 -0.9 12.5 0.9 10.7L4.6 7C6.4 5.2 9 5.2 10.8 7" stroke="#5b6d81" stroke-width="2" stroke-linecap="round"/>
            <path d="M15.5 8.5L16.9 7.1C18.7 5.3 21.3 5.3 23.1 7.1C24.9 8.9 24.9 11.5 23.1 13.3L19.4 17C17.6 18.8 15 18.8 13.2 17" stroke="#5b6d81" stroke-width="2" stroke-linecap="round"/>
            <path d="M8 16L16 8" stroke="#5b6d81" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Click by Referrer
        </h6>
      </div>
      <div class="card-body px-0 d-flex flex-column justify-content-between" style="min-height: 320px;">
        <div id="morris_referrer" class="w-100" style="height:220px;"></div>
       <div id="referrer-list"></div>
<div class="text-center py-2">
  <button id="showMoreReferrer" class="btn btn-outline-primary btn-sm" style="display:none;">
    <i class="fa-solid fa-chevron-down me-1"></i> View More
  </button>
  <button id="showLessReferrer" class="btn btn-outline-secondary btn-sm" style="display:none;">
    <i class="fa-solid fa-chevron-up me-1"></i> View Less
  </button>
</div>
         
      </div>
    </div>
  </div>
  <!-- Kanan: Live Recent Activity -->
  <div class="col-lg-6 col-12">
    <div class="card border-0 h-100">
      <div class="card-header border-0 pb-0 d-flex align-items-center justify-content-between">
        <h6 class="mb-3 mb-0">
          <i class="fa-solid fa-clock-rotate-left"></i> Live Recent Activity
        </h6>
        <button class="btn btn-outline-primary btn-sm" id="btnViewAllActivity">
          <i class="fa-solid fa-list me-1"></i> View All
        </button>
      </div>
      <div class="card-body p-0">
       <div id="recentActivityList"></div>
<div class="text-center py-2">
  <button id="showMoreActivity" class="btn btn-outline-primary btn-sm" style="display:none;">
    <i class="fa-solid fa-chevron-down me-1"></i> View More
  </button>
  <button id="showLessActivity" class="btn btn-outline-secondary btn-sm" style="display:none;">
    <i class="fa-solid fa-chevron-up me-1"></i> View Less
  </button>
</div>
        </div>
      </div>
    </div>
  </div>




  </div><!-- .container-fluid -->
</div><!-- #dashboardSection -->

<div id="shortenLinkSection" class="section">
  <div class="row">
    <div class="col-xl-12 custome-width">
      <div id="app-sections">
        <!-- FORM SHORTEN NEW LINK -->
        <div class="card mb-4">
          <div class="card-body">
            <h4 class="mb-3">
              <i class="fa-solid fa-plus-circle"></i> Shorten a New Link
            </h4>

            <!-- Success & Error Alert -->
            <?php if (!empty($successMessage)): ?>
              <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                <?= $successMessage ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
            <?php endif; ?>

            <?php if (!empty($errorMessage)): ?>
              <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
                <?= $errorMessage ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
            <?php endif; ?>

            <!-- Shorten Form -->
            <form method="POST" autocomplete="off">
              <div class="mb-3">
                <label for="destUrls">Destination URLs <small>(pisahkan dengan baris)</small></label>
                <textarea name="urls" class="form-control" rows="5" required placeholder="https://example1.com&#10;https://example2.com&#10;https://example3.com"></textarea>
              </div>

              <!-- Advanced Options (toggle) -->
              <div id="advancedOptions" class="d-none">
                <div class="mb-3">
                  <label>Custom Alias <small>(opsional)</small></label>
                  <input type="text" name="alias" class="form-control" placeholder="Contoh: linkgacor">
                </div>
                <div class="mb-3">
                  <label>Pilih Domain</label>
                  <select name="domain" class="form-select">
                    <?php foreach ($domains as $domain): ?>
                      <option value="<?= htmlspecialchars($domain) ?>"><?= htmlspecialchars($domain) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-3">
                  <label>Fallback URLs <small>(satu URL per baris)</small></label>
                  <textarea name="fallback_urls" class="form-control" rows="5" placeholder="https://example1.com&#10;https://example2.com"></textarea>
                  <small class="text-muted">URL ini akan dipakai jika semua destination URL tidak aktif.</small>
                </div>

                <!-- Geotarget & Device (toggle) -->
                <div id="expertGeoLang" class="d-none mt-4">
                  <h4>
                    <i class="fa-solid fa-robot"></i> FITUR CLOACKING 
                    <small>(Kosongin Jika Tidak Diperlukan)</small>
                  </h4>
                  <div class="mb-3">
                    <label>
                      White Page URL
                      <span data-bs-toggle="tooltip" title="Pengunjung dari negara yang diblokir atau tidak diizinkan akan diarahkan ke halaman ini.">
                        <i class="bi bi-question-circle-fill" style="color:#007bff;cursor:pointer;font-size:1rem;"></i>
                      </span>
                    </label>
                    <input type="url" name="white_page" class="form-control" placeholder="https://example.com/whitepage">
                  </div>
                  <div class="mb-3">
                    <label>
                      Allowed Country Codes
                      <span data-bs-toggle="tooltip" title="Masukkan kode negara yang diizinkan (misal: ID,US,MY). Pisahkan dengan koma. Negara yang dimasukan disini akan di arahkan ke url destination/fallback url.">
                        <i class="bi bi-question-circle-fill" style="color:#007bff;cursor:pointer;font-size:1rem;"></i>
                      </span>
                    </label>
                    <input type="text" name="allowed_countries" class="form-control" placeholder="ID,US,MY">
                  </div>
                  <div class="mb-3">
                    <label>
                      Blocked Country Codes
                      <span data-bs-toggle="tooltip" title="Semua kode negara yang dimasukkan di sini akan langsung diarahkan ke White Page.">
                        <i class="bi bi-question-circle-fill" style="color:#007bff;cursor:pointer;font-size:1rem;"></i>
                      </span>
                    </label>
                    <input type="text" name="blocked_countries" class="form-control" placeholder="RU,CN">
                  </div>
                  <div class="mb-3">
                    <label>
                      Device Targeting
                      <span data-bs-toggle="tooltip" title="Atur URL tujuan berbeda untuk Mobile, Desktop, atau Tablet.">
                        <i class="bi bi-question-circle-fill" style="color:#007bff;cursor:pointer;font-size:1rem;"></i>
                      </span>
                    </label>
                    <small class="form-text text-muted">Redirect sesuai perangkat (Mobile, Desktop, Tablet).</small>
                    <div id="deviceList"></div>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addDeviceRow()">+ Add Device Rule</button>
                  </div>
                </div>
              </div>
              <!-- /Advanced Options -->

              <div class="d-flex justify-content-between flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">
                  <i class="fa-solid fa-plus"></i> Shorten Link
                </button>
                <button type="button" class="btn btn-secondary"
                  onclick="
                    document.getElementById('advancedOptions').classList.toggle('d-none');
                    document.getElementById('expertGeoLang').classList.toggle('d-none');
                  ">
                  <i class="fa-solid fa-gear"></i> Expert Mode
                </button>
              </div>
            </form>
          </div><!-- .card-body -->
        </div><!-- .card -->
      </div><!-- #app-sections -->
    </div><!-- .col -->
  </div><!-- .row -->
</div><!-- #shortenLinkSection -->

<!-- Recent Ajax Section -->
<div id="recentAjaxSection" class="section">
  <div class="row">
    <div class="col-xl-12 custome-width">
      <div class="card mb-4">
        <div class="card-body">
          <h4 class="mb-3"><i class="fa-solid fa-clock"></i> Recent Links</h4>
          <div class="input-group search-area mb-3">
            <input type="text" class="form-control" id="searchRecentAjax" placeholder="Search here...">
            <span class="input-group-text">
              <a href="javascript:void(0)">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><g clip-path="url(#clip0_1_450)"><path opacity="0.3" d="M14.2929 16.7071C13.9024 16.3166 13.9024 15.6834 14.2929 15.2929C14.6834 14.9024 15.3166 14.9024 15.7071 15.2929L19.7071 19.2929C20.0976 19.6834 20.0976 20.3166 19.7071 20.7071C19.3166 21.0976 18.6834 21.0976 18.2929 20.7071L14.2929 16.7071Z" fill="#452B90"></path><path d="M11 16C13.7614 16 16 13.7614 16 11C16 8.23859 13.7614 6.00002 11 6.00002C8.23858 6.00002 6 8.23859 6 11C6 13.7614 8.23858 16 11 16ZM11 18C7.13401 18 4 14.866 4 11C4 7.13402 7.13401 4.00002 11 4.00002C14.866 4.00002 18 7.13402 18 11C18 14.866 14.866 18 11 18Z" fill="#452B90"></path></g><defs><clipPath id="clip0_1_450"><rect width="24" height="24" fill="white"></rect></clipPath></defs></svg>
              </a>
            </span>
          </div>
          <div id="recentAjaxLoader" class="text-center d-none">
            <div class="spinner-border text-primary" role="status"></div>
            <div class="mt-2 text-muted">Memuat data recent links...</div>
          </div>
          <div id="recentAjaxContent"></div>
          <nav class="mt-3">
            <ul class="pagination justify-content-center" id="paginationAjaxRecent"></ul>
          </nav>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- End Recent Ajax Section -->

<!-- Analytics Section -->
<div id="analyticsSection" class="section">
  <div class="row">
    <div class="col-xl-12 custome-width">
      <div class="card mb-4">
        <div class="card-body">
          <h4 class="mb-4"><i class="fa-solid fa-chart-pie"></i> Analytics Overview</h4>
          <div class="input-group search-area mb-3">
            <input type="text" class="form-control" id="analyticsSearchInput" placeholder="Search here...">
            <span class="input-group-text">
              <a href="javascript:void(0)">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><g clip-path="url(#clip0_1_450)"><path opacity="0.3" d="M14.2929 16.7071C13.9024 16.3166 13.9024 15.6834 14.2929 15.2929C14.6834 14.9024 15.3166 14.9024 15.7071 15.2929L19.7071 19.2929C20.0976 19.6834 20.0976 20.3166 19.7071 20.7071C19.3166 21.0976 18.6834 21.0976 18.2929 20.7071L14.2929 16.7071Z" fill="#452B90"></path><path d="M11 16C13.7614 16 16 13.7614 16 11C16 8.23859 13.7614 6.00002 11 6.00002C8.23858 6.00002 6 8.23859 6 11C6 13.7614 8.23858 16 11 16ZM11 18C7.13401 18 4 14.866 4 11C4 7.13402 7.13401 4.00002 11 4.00002C14.866 4.00002 18 7.13402 18 11C18 14.866 14.866 18 11 18Z" fill="#452B90"></path></g><defs><clipPath id="clip0_1_450"><rect width="24" height="24" fill="white"></rect></clipPath></defs></svg>
              </a>
            </span>
          </div>
          <div id="analyticsContent">
            <div class="text-center text-muted">
              <div class="spinner-border text-primary" role="status"></div>
              <div class="mt-2">Memuat data analytics...</div>
            </div>
          </div>

          <nav id="analyticsPagination" class="mt-3" aria-label="Analytics Page Navigation">
            <ul class="pagination justify-content-center mb-0"></ul>
          </nav>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- End Analytics Section -->


 <div id="adddomainSection" class="section">
      	<div class="row">
       <div class="col-xl-12 custome-width">
      <div class="card mb-4">
  <div class="card-body">
    <h5 class="mb-3"><i class="fa-solid fa-plus"></i> Tambahkan Domain</h5>
        <div class="alert alert-info" role="alert">
      📢 <strong>Panduan:</strong> Untuk melakukan pengecekan domain per menit:<br>
      1️⃣ Tambahkan <strong>ID Telegram</strong> kamu di halaman <a href="?menu=user-profile" id="nav-profile">User Profil</a><br>
      2️⃣ Tambahkan domain yang ingin dicek secara otomatis di bawah ini (bisa multiple, 1 per baris)<br>
      3️⃣ Atur interval dan status di menu <a href="?menu=set-timer" id="nav-settimer"><strong>Cron Job</strong></a><br>
      ✅ Setelah itu, bot akan mengirim hasil cek ke Telegram kamu sesuai pengaturan interval.
    </div>
     <div id="addDomainsMsg" class="mt-3"></div>
    <form id="addDomainsForm">
<textarea name="domains" id="inputDomainCheck" class="form-control" rows="5" placeholder="example.com&#10;example2.net" required></textarea>
<small class="text-muted">Tambahkan domain tanpa <code>https://</code> atau <code>http://</code></small>
<ul id="domainCheckMsg" class="list-group list-group-flush mt-2 small"></ul>
      <button class="btn btn-primary mt-2" type="submit">
        <i class="fa-solid fa-paper-plane"></i> Simpan Domain
      </button>
    </form>

    <hr>
<div class="d-flex align-items-center justify-content-between mt-4 mb-2">
  <h5 class="mb-0">
    <i class="fa-solid fa-list"></i>
    Daftar Domain Anda (Status Bot: <span id="statusbot"></span>)
  </h5>
  <div>
    <button type="button" id="toggleBotBtn" class="btn btn-success">Aktifkan Bot Check</button>
  </div>
</div>
<!-- Ganti UL dengan table -->
<div class="white-block">

<div class="table-responsive">
  <button id="bulkDeleteDomains" class="btn btn-danger btn-sm mb-2" style="display:none;">Hapus Terpilih</button>
  <table class="table table-bordered">
    <thead class="users-table-info">
      <tr>
        <th style="width:34px;">
          <input type="checkbox" id="selectAllDomains">
        </th>
        <th>
          <div class="d-flex align-items-center justify-content-between">
            <span>Domain</span>
            <button id="copyAllDomainsBtn" type="button" class="btn btn-sm btn-outline-primary ms-2" title="Copy semua domain">
              <i class="fa fa-copy"></i> Copy All
            </button>
          </div>
        </th>
        <th>Status</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody id="userDomainList">
      <!-- Baris domain akan di-render di sini -->
    </tbody>
  </table>
</div>

</div>
  </div>
</div></div></div></div>




 <div id="settimerSection" class="section">
      	<div class="row">
       <div class="col-xl-12 custome-width">
      <div class="card mb-4">
<div class="card-body">
    <h4 class="mb-3"><i class="fa-solid fa-clock-rotate-left"></i> Pengaturan Cron Job</h4>
     <div class="alert alert-info" role="alert">
             INFO!! Agar hasil lebih optimal dan aman sebaiknya pengecekan minimal 3 menit sekali
             </div>
    <form id="cronSettingsForm">
      <div class="row mb-3">
        <div class="col-md-4">
          <label for="interval_minute" class="form-label">Interval Pengecekan (menit)</label>
          <input type="number" name="interval_minute" id="interval_minute" class="form-control" min="1" required value="<?= htmlspecialchars($interval) ?>">
        </div>
        <div class="col-md-4">
          <label for="status" class="form-label">Status Cron Job</label>
          <select name="status" id="status" class="form-select" required>
            <option value="1" <?= $status == 1 ? 'selected' : '' ?>>✅ Aktif</option>
            <option value="0" <?= $status == 0 ? 'selected' : '' ?>>⛔ Nonaktif</option>
          </select>
        </div>
    
        <div class="col-md-4 g-3 d-flex align-items-end">
          <button type="submit" class="btn btn-primary w-100">
            <i class="fa-solid fa-save"></i> Simpan Pengaturan
          </button>
        </div>
      </div>
    </form>

    <div id="cronSettingMsg" class="mt-2"></div>
  </div>
</div></div></div></div>

 <div id="dapacheckerSection" class="section">
  <div class="row">
    <div class="col-xl-12">
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0"><i class="fa-solid fa-magnifying-glass"></i> Cek DA, PA, Spam Score & Whois</h6>
        </div>
 <div class="card-body">
    <form id="formCheckDA">
      <textarea name="domain" rows="6" class="form-control mb-3" placeholder="example.com&#10;domain.id" required></textarea>
      <button type="submit" class="btn btn-primary w-100">Cek Sekarang</button>
    </form>
    <div id="resultDA" class="mt-4"></div>
  </div>
</div></div></div></div>

<div id="domainCfSection" class="section" style="display:none;">
  <div class="container-fluid px-0">

    <!-- Form Tambah Domain -->
    <div class="card shadow mb-4">
      <div class="card-header bg-primary text-white">Tambah Domain ke Cloudflare</div>
      <div class="card-body ">
        <form id="formAddDomain" class="row g-2 align-items-end mb-3">
          <div class="col-12 col-md-8">
            <label class="form-label">Nama Domain</label>
            <input type="text" id="inputDomain" name="domain" class="form-control" placeholder="contoh: domainkamu.com" required>
          </div>
          <div class="col-12 col-md-4">
            <button type="submit" class="btn btn-primary w-100">Tambah Domain</button>
          </div>
        </form>
        <!-- Info Penjelasan Tambah Domain -->
<div class="alert alert-info mb-3 shadow-sm" style="font-size:15px;">
  <b>Penjelasan Penggunaan:</b>
  <ol class="mb-1 mt-2 text-dark" style="padding-left: 1.2em;">
    <li>
      <b>Step 1:</b> Tambahkan domain Anda terlebih dahulu. Setelah itu, <b>ubah Nameserver</b> domain di Namecheap atau layanan domain Anda <b>sesuai yang tertera</b> pada panel.
    </li>
    <li>
      <b>Step 2:</b> Jika domain sudah <b>ACTIVE</b>, klik <b>Set Tujuan URL</b>, masukkan nama bebas (alias) dan tujuan URL ke moneysite Anda masing-masing, lalu simpan.
    </li>
    <li>
      <b>Step 3:</b> Tes akses domain/subdomain Anda. Jika terkendala Cloudflare/SSL, langsung klik <b>Set SSL</b> dan tunggu beberapa detik sampai SSL aktif otomatis.
    </li>
    <li>
      <b>Step 4:</b> Jika sudah bisa diakses, Anda bisa menambahkan subdomain bebas. Semua subdomain akan diarahkan otomatis ke URL tujuan yang sudah di-set.
    </li>
  </ol>
  <div class="text-primary mt-2">
    Semua proses otomatis. Jika ada error, cek pesan yang muncul atau pastikan Nameserver sudah benar.
  </div>
</div>

        <div id="domainAddResult" class="mt-2"></div>
      </div>
    </div>

    <!-- List Domain Table -->
<div class="card shadow">
  <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
    <span>List Domain</span>
    <button class="btn btn-danger btn-sm" onclick="generateShortlinkSubdoModal()">
      Generate Subdo Random
    </button>
  </div>
  <div class="card-body p-0 table-responsive">
      <div id="autoSSLProgress" style="margin-bottom:15px;"></div>
    <table class="table table-bordered table-hover mb-0" id="domainCfTable">
      <thead class="table-light">
        <tr>
          <th width="35">ID</th>
          <th>Domain</th>
          <th>Status</th>
          <th>NS1</th>
          <th>NS2</th>
          <th width="140">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <tr><td colspan="6" class="text-center text-secondary">Memuat data...</td></tr>
      </tbody>
    </table>
  </div>
</div>

  </div>
</div>


 <!-- Daftar Harga Paket Section -->
<div id="daftarhargaSection" class="section">
  <div class="row justify-content-center">
    <div class="col-xl-12">
      <!-- Paket Cards -->
      <div class="card mb-4">
        <div class="card-body">
          <h4 class="mb-4"><i class="fa-solid fa-tags me-2"></i>Daftar Harga Paket</h4>
          <div class="row gx-4 gy-4">
            <!-- Paket Medium -->
            <div class="col-md-4">
              <div class="card h-100 border-primary shadow-sm">
                <div class="card-body d-flex flex-column">
            <h5 class="card-title text-primary">Paket Medium</h5>
                  <p class="card-text fs-4 fw-bold">
                    <span class="text-success">Rp 350.000</span>
                    <span class="text-decoration-line-through text-muted ms-2">Rp 700.000</span>
                    <span class="badge bg-danger ms-2">-50%</span>
                  </p>
                  <small class="text-danger mb-3">Untuk 1 Bulan <span id="countdownMedium">/ Montly</span></small>
                  <ul class="list-unstyled flex-grow-1 mb-3">
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Total Shortlink yang bisa kamu buat.">Create Short Links</span>
                      <span class="fw-bold">3</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Total Click">Link Clicks</span>
                      <span class="fw-bold">Unlimited</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Auto Rotator Links</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Cloacking via Negara</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                        <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Fallback URL / Cadangan</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                                        <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Custom Alias</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                                                            <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Device Targeting</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Dapat melakukan pengecekan domain sendiri">Check Domains</span>
                      <span class="fw-bold">3 Domains</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Melakukan Pengecekan secara otomatis.">Auto Check Domains</span>
                      <span class="fw-bold">3 Domains</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Cron Job Merupakan sistem bot untuk melakukan pengecekan.">Cron Job</span>
                      <span class="fw-bold">5 Minute Min</span>
                    </li>
                  </ul>
<?php if ($userType === 'medium' || $userType === 'vip' || $userType === 'vipmax'): ?>
  <button class="btn btn-secondary mt-auto" disabled>Current Plan</button>

<?php elseif ($hasUnpaidMedium && ! $isExpiredMedium): ?>
  <!-- Ada pembayaran belum selesai dan belum expired -->
  <button class="btn btn-warning mt-auto" disabled>
    Selesaikan Pembayaran . . .
  </button>

<?php else: ?>
  <!-- Tidak ada unpaid aktif (baik karena belum bayar sama sekali atau sudah expired) -->
  <button
    id="selectMedium"
    class="btn btn-primary mt-auto select-pkg-btn"
    data-pkg="medium"
  >
    Pilih Paket Medium
  </button>
<?php endif; ?>
                </div>
                <div class="card-footer text-center bg-light">
                  <small class="text-muted">*Semua pembayaran diproses otomatis</small>
                </div>
              </div>
            </div>
            <!-- Paket VIP -->
            <div class="col-md-4">
              <div class="card h-100 border-success shadow-sm">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-success">Paket VIP</h5>
                  <p class="card-text fs-4 fw-bold">
                    <span class="text-success">Rp 650.000</span>
                    <span class="text-decoration-line-through text-muted ms-2">Rp 1.500.000</span>
                    <span class="badge bg-danger ms-2">-55%</span>
                  </p>
                 <small class="text-danger mb-3">Untuk 1 Bulan <span id="countdownMedium">/ Montly</span></small>
                  <ul class="list-unstyled flex-grow-1 mb-3">
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Total Shortlink yang bisa kamu buat.">Create Short Links</span>
                      <span class="fw-bold">30</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Total Click">Link Clicks</span>
                      <span class="fw-bold">Unlimited</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Auto Rotator Links</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Cloacking via Negara</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                        <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Fallback URL / Cadangan</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                                                            <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Custom Alias</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                                                            <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Device Targeting</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Dapat melakukan pengecekan domain sendiri">Check Domains</span>
                      <span class="fw-bold">30 Domains</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Melakukan Pengecekan secara otomatis.">Auto Check Domains</span>
                      <span class="fw-bold">30 Domains</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Cron Job Merupakan sistem bot untuk melakukan pengecekan.">Cron Job</span>
                      <span class="fw-bold">1 Minute Min</span>
                    </li>
                  </ul>
<!-- Paket VIP -->
<?php if ($userType === 'vip' || $userType === 'vipmax'): ?>
  <button class="btn btn-secondary mt-auto" disabled>Current Plan</button>

<?php elseif ($hasUnpaidVip && ! $isExpiredVip): ?>
  <button class="btn btn-warning mt-auto" disabled>Selesaikan Pembayaran . . .</button>

<?php else: ?>
  <button
    id="selectVIP"
    class="btn btn-success mt-auto select-pkg-btn"
    data-pkg="vip"
  >
    Pilih Paket VIP
  </button>
<?php endif; ?>
                </div>
                <div class="card-footer text-center bg-light">
                  <small class="text-muted">*Semua pembayaran diproses otomatis</small>
                </div>
              </div>
            </div>
            
             <div class="col-md-4">
              <div class="card h-100 border-success shadow-sm">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-danger">Paket VIP MAX</h5>
                  <p class="card-text fs-4 fw-bold">
                    <span class="text-success">Rp 1.200.000</span>
                    <span class="text-decoration-line-through text-muted ms-2">Rp 2.200.000</span>
                    <span class="badge bg-danger ms-2">-60%</span>
                  </p>
                  <small class="text-danger mb-3">Untuk 2 Bulan </small>
                  <ul class="list-unstyled flex-grow-1 mb-3">
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Total Shortlink yang bisa kamu buat.">Create Short Links</span>
                      <span class="fw-bold">Unlimited</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Total Click">Link Clicks</span>
                      <span class="fw-bold">Unlimited</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Auto Rotator Links</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Cloacking via Negara</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                        <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Fallback URL / Cadangan</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                                                            <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Custom Alias</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                                                            <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Auto rotator akan bekerja ketika url destination anda sudah tidak ada / diblokir semua.">Device Targeting</span>
                      <i class="fa fa-check text-success"></i>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Dapat melakukan pengecekan domain sendiri">Check Domains</span>
                      <span class="fw-bold">Unlimited</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Melakukan Pengecekan secara otomatis.">Auto Check Domains</span>
                      <span class="fw-bold">Unlimited</span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                      <span data-bs-toggle="tooltip" title="Cron Job Merupakan sistem bot untuk melakukan pengecekan.">Cron Job</span>
                      <span class="fw-bold">1 Minute Min</span>
                    </li>
                  </ul>
<!-- Paket VIP MAX -->
<?php if ($userType === 'vipmax'): ?>
  <button class="btn btn-secondary mt-auto" disabled>Current Plan</button>

<?php elseif ($hasUnpaidVipMax && ! $isExpiredVipMax): ?>
  <button class="btn btn-warning mt-auto" disabled>Selesaikan Pembayaran . . .</button>

<?php else: ?>
  <button
    id="selectVIPMAX"
    class="btn btn-success mt-auto select-pkg-btn"
    data-pkg="vipmax"
  >
    Pilih Paket VIP MAX
  </button>
<?php endif; ?>
                </div>
                <div class="card-footer text-center bg-light">
                  <small class="text-muted">*Semua pembayaran diproses otomatis</small>
                </div>
              </div>
            </div>
            
            
          </div> <!-- /.row -->
          <!-- Opsi Pembayaran -->
      <div id="paymentOptions" class="mb-4 d-none">
  <div class="card-body text-center">
    <h5 id="pkgTitle" class="mb-2"></h5>
    <p id="pkgPrice" class="fs-5 mb-4"></p>
    <p class="mb-2">Pilih Metode Pembayaran:</p>
    <div class="d-flex flex-wrap justify-content-center gap-3 mb-4">
      <button class="btn btn-outline-secondary method-btn" data-method="QRIS">
        <img src="/assets/logos/qris.png" alt="QRIS" width="24" class="me-1">QRIS
      </button>

      <button class="btn btn-outline-secondary method-btn" data-method="BNIVA">
        <img src="/assets/logos/bni.png" alt="BNI VA" width="24" class="me-1">BNI Virtual Account
      </button>
      <button class="btn btn-outline-secondary method-btn" data-method="BRIVA">
        <img src="/assets/logos/bri.png" alt="BRI VA" width="24" class="me-1">BRI Virtual Account
      </button>
      <button class="btn btn-outline-secondary method-btn" data-method="MANDIRIVA">
        <img src="/assets/logos/mandiri.png" alt="Mandiri VA" width="24" class="me-1">Mandiri Virtual Account
      </button>
      <button class="btn btn-outline-secondary method-btn" data-method="PERMATAVA">
        <img src="/assets/logos/permata.png" alt="Permata VA" width="24" class="me-1">Permata Virtual Account
      </button>
      <button class="btn btn-outline-secondary method-btn" data-method="MUAMALATVA">
        <img src="/assets/logos/muamalat.png" alt="Muamalat VA" width="24" class="me-1">Muamalat Virtual Account
      </button>

      <button class="btn btn-outline-secondary method-btn" data-method="BSIVA">
        <img src="/assets/logos/bsi.png" alt="BSI VA" width="24" class="me-1">BSI Virtual Account
      </button>

    </div>
    <button id="payBtn" class="btn btn-primary w-100" disabled>Bayar Sekarang</button>
  </div>
</div>


      </div>
</div>
    </div> <!-- /.col -->
  </div> <!-- /.row -->
</div> <!-- /#daftarhargaSection -->


<!-- Riwayat Pembayaran Section -->
<div id="riwayatPembayaranSection" class="section">
  <div class="row justify-content-center">
    <div class="col-xl-12">
      <div class="card mb-4">
        <div class="card-body">
          <h4 class="mb-4">
            <!-- SVG Wallet Icon -->
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 10H3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2z"></path>
              <path d="M1 12h22"></path>
              <circle cx="18" cy="14" r="1"></circle>
            </svg>
            Riwayat Pembayaran
          </h4>
          <?php if (empty($payments)): ?>
            <p class="text-center text-muted">Belum ada riwayat pembayaran.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Tanggal</th>
                    <th>Referensi</th>
                    <th>Paket</th>
                    <th>Metode</th>
                    <th>Jumlah</th>
                    <th>Status</th>
                    <th>Aksi</th>
                  </tr>
                </thead>
           <tbody>
  <?php
    // Set timezone ke Jakarta
    date_default_timezone_set('Asia/Jakarta');
    $now = new DateTime;

    foreach ($payments as $p):
      // Parse created_at sebagai UTC lalu convert ke WIB
      $dtCreated = new DateTime($p['created_at'], new DateTimeZone('UTC'));

      // Parse expired_time langsung sebagai WIB
      $dtExpired = new DateTime($p['expired_time'], new DateTimeZone('Asia/Jakarta'));
  ?>
    <tr>
      <td><?= $dtCreated->format('d M Y H:i') ?></td>
      <td><code><?= htmlspecialchars($p['reference'], ENT_QUOTES) ?></code></td>
      <td><?= ucfirst(htmlspecialchars($p['package'], ENT_QUOTES)) ?></td>
      <td><?= htmlspecialchars($p['method'], ENT_QUOTES) ?></td>
      <td>Rp <?= number_format($p['amount'], 0, ',', '.') ?></td>
      <td>
        <?php if ($p['status'] === 'PAID'): ?>
          <span class="badge bg-success">PAID</span>
        <?php elseif ($p['status'] === 'EXPIRED'): ?>
          <span class="badge bg-danger">EXPIRED</span>
        <?php elseif ($now > $dtExpired): ?>
          <span class="badge bg-danger">EXPIRED</span>
        <?php else: ?>
          <span class="badge bg-warning text-dark">
            <span class="spinner-border spinner-border-sm text-dark me-1" role="status"></span>
            UNPAID
          </span>
        <?php endif; ?>
      </td>
      <td>
        <?php if (!empty($p['checkout_url'])): ?>
          <a href="<?= htmlspecialchars($p['checkout_url'], ENT_QUOTES) ?>"
             target="_blank"
             class="btn btn-sm btn-primary">
            View Detail
          </a>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
</tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>




<div id="userprofileSection" class="section">
  <div class="row">
    <div class="col-xl-12 custome-width">
      <div class="card mb-4">
        <div class="card-body">
          <?php
          function getTelegramGroupId($userId, $pdo) {
            $stmt = $pdo->prepare("SELECT telegram_group_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetchColumn();
          }

          function getNotifPrefs($userId, $pdo) {
            $stmt = $pdo->prepare("SELECT notif_to_personal, notif_to_group FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
          }

          // Get user type
          $stmt = $pdo->prepare("SELECT type FROM users WHERE id = ?");
          $stmt->execute([$_SESSION['user_id']]);
          $userType = $stmt->fetchColumn();

          // Get subscription info
          $subscriptionInfo = $pdo->prepare("
            SELECT upgraded_at, expires_at 
            FROM upgrade_requests 
            WHERE user_id = ? AND status = 'confirmed' 
            ORDER BY expires_at DESC 
            LIMIT 1
          ");
          $subscriptionInfo->execute([$_SESSION['user_id']]);
          $subscription = $subscriptionInfo->fetch(PDO::FETCH_ASSOC);

          // Calculate days left
          $daysLeft = 0;
          $isExpired = false;
          if ($subscription && $subscription['expires_at']) {
            $expiryDate = new DateTime($subscription['expires_at']);
            $today = new DateTime();
            $diff = $today->diff($expiryDate);
            $daysLeft = $diff->invert ? -$diff->days : $diff->days;
            $isExpired = $daysLeft < 0;
          }

          $notifPrefs = getNotifPrefs($_SESSION['user_id'], $pdo);
          ?>
          
          <!-- Header with User Type Badge -->
          <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
              <h4 class="mb-2">
                <i class="fa-solid fa-user-circle"></i> User Profile
                <span 
                  class="badge <?=
                    $userType === 'vip' ? 'bg-success' :
                    ($userType === 'vipmax' ? 'bg-danger' :
                    ($userType === 'medium' ? 'bg-info' : 'bg-warning text-dark'))
                  ?> ms-2"
                  role="button"
                  data-bs-toggle="modal"
                  data-bs-target="#userTypeModal"
                  style="cursor: pointer;"
                >
                  <?= strtoupper($userType) ?>
                </span>
              </h4>
              
              <!-- Subscription Info -->
              <?php if ($userType !== 'trial' && $subscription): ?>
                <div class="subscription-info">
                  <div class="d-flex flex-wrap gap-3">
                    <?php if ($subscription['upgraded_at']): ?>
                      <small class="text-muted">
                        <i class="fa-solid fa-calendar-plus text-success"></i>
                        <strong>Upgraded:</strong> <?= date('d M Y', strtotime($subscription['upgraded_at'])) ?>
                      </small>
                    <?php endif; ?>
                    
                    <?php if ($subscription['expires_at']): ?>
                      <small class="<?= $isExpired ? 'text-danger fw-bold' : ($daysLeft <= 7 ? 'text-warning fw-bold' : 'text-success') ?>">
                        <i class="fa-solid fa-calendar-xmark"></i>
                        <strong>Expires:</strong> <?= date('d M Y', strtotime($subscription['expires_at'])) ?>
                      </small>
                      
                      <?php if ($isExpired): ?>
                        <span class="badge bg-danger">
                          <i class="fa-solid fa-times-circle"></i> EXPIRED
                        </span>
                      <?php elseif ($daysLeft == 0): ?>
                        <span class="badge bg-danger animate__animated animate__flash animate__infinite">
                          <i class="fa-solid fa-clock"></i> Expires Today!
                        </span>
                      <?php elseif ($daysLeft <= 3): ?>
                        <span class="badge bg-danger">
                          <i class="fa-solid fa-exclamation-triangle"></i> <?= $daysLeft ?> hari lagi
                        </span>
                      <?php elseif ($daysLeft <= 7): ?>
                        <span class="badge bg-warning text-dark">
                          <i class="fa-solid fa-clock"></i> <?= $daysLeft ?> hari lagi
                        </span>
                      <?php else: ?>
                        <span class="badge bg-success">
                          <i class="fa-solid fa-check-circle"></i> Active (<?= $daysLeft ?> hari)
                        </span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php elseif ($userType !== 'trial'): ?>
                <div class="alert alert-warning py-1 px-3 mt-2 mb-0">
                  <small>
                    <i class="fa-solid fa-exclamation-triangle"></i>
                    No subscription data found
                  </small>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <hr class="my-4">

          <div id="profileMessage" class="mt-3"></div>
          
          <form id="profileForm">

            
 <div class="mb-3">
  <label for="username" class="form-label fw-bold mb-3">Account Details</label>
  <div class="bg-light rounded p-3">
    <div class="d-flex align-items-center justify-content-between">
      <div>
        <h5 class="mb-1">
          <i class="fa-solid fa-user-circle text-primary"></i> 
          <?= htmlspecialchars($_SESSION['username']) ?>
        </h5>
        <small class="text-muted">Your username</small>
      </div>
      <div class="text-end">
        <?php if ($userType !== 'trial' && $subscription): ?>
          <span class="badge bg-success mb-1">
            <i class="fa-solid fa-check-circle"></i> Premium Active
          </span>
          <br>
          <small class="text-muted">
            <?= $daysLeft > 0 ? $daysLeft . ' days left' : 'Expired' ?>
          </small>
        <?php else: ?>
          <span class="badge bg-warning text-dark">
            <i class="fa-solid fa-hourglass-half"></i> Trial Account
          </span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

            <hr class="my-4">

            <!-- Telegram Settings Section -->
            <div class="section-title mb-3">
              <h5 class="text-muted">
                <i class="fa-brands fa-telegram"></i> Telegram Settings
                <button type="button" class="btn btn-sm btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#telegramTutorialModal">
                  <i class="fa-solid fa-book-open"></i> Tutorial
                </button>
              </h5>
            </div>

            <div class="row">
              <div class="col-md-6 mb-3">
                <label for="telegram_id" class="form-label fw-bold">Telegram ID</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-brands fa-telegram"></i></span>
                  <input type="text" id="telegram_id" name="telegram_id" class="form-control" 
                         value="<?= htmlspecialchars(getTelegramId($_SESSION['user_id'], $pdo) ?? '') ?>" 
                         placeholder="Masukkan Telegram ID">
                </div>
                <small class="text-muted">
                  <i class="fa-solid fa-info-circle"></i> Tambahkan bot <a href="https://t.me/sflinkid_bot" class="text-primary" target="_blank">@sflinkid_bot</a> 
                  dan gunakan <code>/id</code>
                </small>
              </div>

              <div class="col-md-6 mb-3">
                <label for="telegram_group_id" class="form-label fw-bold">
                  Group ID <small class="text-muted fw-normal">(Optional)</small>
                </label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-users"></i></span>
                  <input type="text" id="telegram_group_id" name="telegram_group_id" class="form-control" 
                         value="<?= htmlspecialchars(getTelegramGroupId($_SESSION['user_id'], $pdo) ?? '') ?>" 
                         placeholder="Masukkan Group ID">
                </div>
                <small class="text-muted">
                  <i class="fa-solid fa-info-circle"></i> Gunakan <code>/id</code> di grup setelah bot ditambahkan
                </small>
              </div>
            </div>

            <!-- Notification Preferences -->
            <div class="mb-4">
              <label class="form-label fw-bold d-block mb-3">Notification Preferences</label>
              <div class="d-flex flex-wrap gap-3">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="notif_to_personal" name="notif_to_personal" 
                         value="1" <?= $notifPrefs['notif_to_personal'] ? 'checked' : '' ?> onchange="updateNotifIndicators()">
                  <label class="form-check-label" for="notif_to_personal">
                    <span id="badgePersonal" class="badge <?= $notifPrefs['notif_to_personal'] ? 'bg-success' : 'bg-secondary' ?>">
                      <i class="fa-solid fa-user"></i> Personal Chat
                    </span>
                  </label>
                </div>

                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="notif_to_group" name="notif_to_group" 
                         value="1" <?= $notifPrefs['notif_to_group'] ? 'checked' : '' ?> onchange="updateNotifIndicators()">
                  <label class="form-check-label" for="notif_to_group">
                    <span id="badgeGroup" class="badge <?= $notifPrefs['notif_to_group'] ? 'bg-success' : 'bg-secondary' ?>">
                      <i class="fa-solid fa-users"></i> Group Chat
                    </span>
                  </label>
                </div>
              </div>
            </div>

            <hr class="my-4">

            <!-- Domain & Security Section -->
            <div class="section-title mb-3">
              <h5 class="text-muted"><i class="fa-solid fa-shield-halved"></i> Domain & Security</h5>
            </div>

            <div class="mb-4">
              <button type="button" class="btn btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#mainDomainModal">
                <i class="fa fa-server"></i> Kelola Main Domain
              </button>
              
              <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordForm()">
                <i class="fa-solid fa-key"></i> Ubah Password
              </button>
            </div>

            <!-- Password Change Fields -->
            <div id="passwordFields" class="d-none animate__animated animate__fadeIn">
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="new_password" class="form-label">Password Baru</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" id="new_password" name="new_password" class="form-control" 
                           placeholder="Masukkan password baru">
                  </div>
                </div>
                <div class="col-md-6 mb-3">
                  <label for="confirm_password" class="form-label">Konfirmasi Password</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-lock-open"></i></span>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                           placeholder="Ulangi password baru">
                  </div>
                </div>
              </div>
            </div>

            <hr class="my-4">

            <!-- Submit Button -->
            <div class="text-end">
              <button type="button" class="btn btn-primary btn-lg" id="triggerSubmit">
                <i class="fa-solid fa-save"></i> Simpan Perubahan
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
.section-title {
  border-bottom: 2px solid #e9ecef;
  padding-bottom: 8px;
}

.subscription-info {
  background-color: rgba(0,0,0,0.02);
  padding: 8px 12px;
  border-radius: 8px;
  margin-top: 8px;
}

.form-check-input:checked {
  background-color: #198754;
  border-color: #198754;
}

#passwordFields {
  background-color: #f8f9fa;
  padding: 15px;
  border-radius: 8px;
  margin-bottom: 20px;
}

@media (max-width: 768px) {
  .subscription-info .d-flex {
    flex-direction: column;
    align-items: start !important;
  }
}
</style>
<?php
// (di controller sebelum output HTML)
$logsStmt = $pdo->prepare("
    SELECT action, created_at 
    FROM activity_logs 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$logsStmt->execute([$userId]);
$activityLogsall = $logsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div id="notificationSection" class="section">
  <div class="row">
    <div class="col-xl-12">
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Notifikasi Aktivitas</h6>
          <span class="badge bg-primary"><?= count($activityLogsall) ?> Log</span>
        </div>
        <div class="card-body p-0">
          <?php if ($activityLogsall): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($activityLogsall as $log): ?>
                <li class="list-group-item">
                  <div class="d-flex justify-content-between align-items-center">
                    <span><?= htmlspecialchars($log['action'], ENT_QUOTES) ?></span>
                    <small class="text-muted"><?= htmlspecialchars(formatTanggalIndonesia($log['created_at']), ENT_QUOTES) ?></small>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-center py-4 text-muted">
              <em>Belum ada aktivitas tercatat.</em>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="checkdomainSection" class="section">
  <div class="row">
    <div class="col-xl-12">
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Cek Status Domain (TrustPositif)</h6>
        </div>
       <div class="card-body">
    <form id="checkDomainForm">
      <div class="mb-3">
        <label for="domains" class="form-label">Masukkan Domain (Pisahkan dengan Enter)</label>
        <textarea class="form-control" id="domains" name="domains" rows="5" placeholder="Max 50 URL" required></textarea>
      </div>
      <button type="submit" class="btn btn-primary w-100">Cek Sekarang</button>
    </form>
    <div id="checkResult" class="mt-4"></div>
  </div>
</div></div></div></div>


 
 </div>

 <!-- / Content -->


            <!-- Footer -->
            <footer class="content-footer footer bg-footer-theme">
              <div class="container-xxl d-flex flex-wrap justify-content-between py-2 flex-md-row flex-column">
                <div class="mb-2 mb-md-0">
                  ©
                  <script>
                    document.write(new Date().getFullYear());
                  </script>
                ❤️ by
                  <a href="#" target="_blank" class="footer-link fw-medium">SFLINK.ID</a>
                </div>
                <div class="d-none d-lg-inline-block">
                 <small>Protect your link with us</small>
                </div>
              </div>
            </footer>
            <!-- / Footer -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1080">
 <div id="msgToast" class="toast align-items-center border-0 fade" role="alert"
     aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="3000">
  <div class="d-flex">
    <div class="toast-body"></div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto"
            data-bs-dismiss="toast" aria-label="Close"></button>
  </div>
</div>
</div>

<!-- Modal -->
<div class="modal fade" id="shortlinkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content animate__animated animate__fadeIn">
      <form method="POST" onsubmit="return saveUpdatedLink(event)">
        <input type="hidden" name="edit_use_main_domain" id="modalUseMainDomain" value="0">
        <input type="hidden" name="edit_main_domain_id" id="modalMainDomainId" value="">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa-solid fa-pen-to-square"></i> Edit Shortlink</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <div id="editAlertBox" class="d-none"></div>
          <p><strong>Shortlink:</strong> <span id="modalShortlinkText"></span></p>
          <p><strong>Tanggal Buat:</strong> <span id="modalCreated"></span></p>

          <div class="mb-3">
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyShortlink()">
              <i class="fa-solid fa-copy"></i> Copy Shortlink
            </button>
          </div>

          <!-- ====== MAIN DOMAIN SETTER CARD ====== -->
          <div class="card mb-4 border-info shadow-sm">
            <div class="card-body p-3">
              <div class="d-flex align-items-center mb-2">
                <i class="fa fa-globe fa-lg text-info me-2"></i>
                <div>
                  <b>Arahkan Destination & Fallback ke Main Domain</b>
                  <div class="small text-muted">Semua URL akan otomatis mengarah ke domain utama + path (misal: <code>https://domainUtama.com/path-url</code>).</div>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary w-100" id="setToMainDomainBtn">
                <i class="fa fa-link"></i> Pilih / Set Main Domain
              </button>
              <div id="currentMainDomainStatus" class="mt-2" style="display:none"></div>
            </div>
          </div>
          <!-- ====== END MAIN DOMAIN CARD ====== -->

          <label>Destination URLs:</label>
          <div class="badge-overlay-container mb-3 position-relative">
            <textarea name="edit_urls" id="modalUrls" rows="4" class="form-control" required></textarea>
            <div id="modalUrlsBadges" class="badge-overlay"></div>
          </div>

          <!-- FALLBACK -->
          <label>Fallback URLs:</label>
          <div class="badge-overlay-container mb-3">
            <textarea name="edit_fallbacks" id="modalFallbacks" rows="4" class="form-control"></textarea>
            <div id="modalFallbacksBadges" class="badge-overlay"></div>
          </div>

          <h4><i class="fa-solid fa-robot"></i> FITUR CLOACKING <small>(Kosongin Jika Tidak Diperlukan)</small></h4>
          <!-- WHITE PAGE -->
          <div class="mb-3">
            <label>White Page URL</label>
            <input type="url" name="edit_white_page" id="modalWhitePage" class="form-control" placeholder="https://example.com/whitepage">
            <small class="text-muted">Pengunjung dari negara yang diblokir akan diarahkan ke URL ini.</small>
          </div>

          <!-- ALLOWED COUNTRY -->
          <div class="mb-3">
            <label>Allowed Country Codes (mis. ID, US, MY)</label>
            <input type="text" name="edit_allowed_countries" id="modalAllowedCountries" class="form-control" placeholder="Pisahkan dengan koma, contoh: ID,US,MY">
            <small class="text-muted">Hanya negara-negara ini yang bisa mengakses destination/fallback. Sisanya ke White Page.</small>
          </div>

          <!-- BLOCKED COUNTRY -->
          <div class="mb-3">
            <label>Blocked Country Codes (mis. RU, CN)</label>
            <input type="text" name="edit_blocked_countries" id="modalBlockedCountries" class="form-control" placeholder="Pisahkan dengan koma, contoh: RU,CN">
            <small class="text-muted">Negara-negara ini akan langsung diarahkan ke White Page.</small>
          </div>

          <!-- DEVICE TARGETING -->
          <div class="mb-3">
            <label>Device Targeting</label>
            <small class="form-text text-muted">Redirect sesuai perangkat (Mobile, Desktop, Tablet).</small>
            <div id="modalDeviceList"></div>
            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addDeviceRowEdit()">+ Add Device Rule</button>
          </div>

          <input type="hidden" name="edit_id" id="modalEditId">
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Simpan</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toast Notification -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1055">
  <div id="toastNotif" class="toast align-items-center text-bg-success border-0 animate__animated" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
        Perubahan berhasil disimpan!
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>
            <div class="content-backdrop fade"></div>
          </div>
          <!-- Content wrapper -->
        </div>
        <!-- / Layout page -->
      </div>

      <!-- Overlay -->
      <div class="layout-overlay layout-menu-toggle"></div>

      <!-- Drag Target Area To SlideIn Menu On Small Screens -->
      <div class="drag-target"></div>
    </div>
    <!-- / Layout wrapper -->

    <!-- Core JS -->
    <!-- build:js assets/vendor/js/core.js -->

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/libs/hammer/hammer.js"></script>
    <script src="../assets/vendor/libs/i18n/i18n.js"></script>
    <script src="../assets/vendor/libs/typeahead-js/typeahead.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>

    <!-- endbuild -->

    <!-- Vendors JS -->
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>

    <!-- Main JS -->
    <script src="../assets/js/main.js"></script>

    <!-- Page JS -->
    <script src="../assets/js/app-ecommerce-dashboard.js"></script>
 
	  <script>
		var swiper = new Swiper(".mySwiper", {
		  slidesPerView: 5,
		  //spaceBetween: 30,
		  pagination: {
			el: ".swiper-pagination",
			clickable: true,
		  },
		  breakpoints: {
			
		  300: {
			slidesPerView: 1,
			spaceBetween: 20,
		  },
		  416: {
			slidesPerView: 2,
			spaceBetween: 20,
		  },
		   768: {
			slidesPerView: 3,
			spaceBetween: 20,
		  },
		   1280: {
			slidesPerView: 4,
			spaceBetween: 10,
		  },
		  1788: {
			slidesPerView: 5,
			spaceBetween: 20,
		  },
		},
		});
  </script>
  
<!-- Load ChartJS hanya di halaman analytics -->
<?php if ($menu == 'analytics'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php endif; ?>

	<script>
// Data dari PHP ke JS
const labels = <?= json_encode($labels) ?>;
const data = <?= json_encode($data) ?>;

const ctx = document.getElementById('myChart').getContext('2d');
const myChart = new Chart(ctx, {
    type: 'line', // Bisa line, bar, area, etc.
    data: {
        labels: labels,
        datasets: [{
            label: 'Visitors per Day',
            data: data,
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            borderColor: '#4CAF50',
            backgroundColor: 'rgba(76, 175, 80, 0.1)',
            pointBackgroundColor: '#4CAF50',
            pointBorderColor: '#fff',
            pointRadius: 4,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        },
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: '#333',
                titleColor: '#fff',
                bodyColor: '#fff'
            }
        }
    }
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function() {
  const hamburger = document.querySelector('.nav-control .hamburger');
  const brandLogo = document.getElementById('brandLogo');

  // baca sekali saja
  const desktopSrc = brandLogo.dataset.desktop;
  const mobileSrc  = brandLogo.dataset.mobile;

  hamburger.addEventListener('click', () => {
    // ambil src dari atribut, bukan properti .src
    const current = brandLogo.getAttribute('src');
    if (current === desktopSrc) {
      brandLogo.setAttribute('src', mobileSrc);
    } else {
      brandLogo.setAttribute('src', desktopSrc);
    }
  });
});
</script>
<script>
// daftar semua section ID yang kita punya
const sections = [
  'dashboardSection',
  'shortenLinkSection',
    'recentAjaxSection',
  'analyticsSection',
  'adddomainSection',
  'settimerSection',
  'dapacheckerSection',
  'daftarhargaSection',
  'userprofileSection',
  'notificationSection',
  'checkdomainSection',
    'domainCfSection',
  'riwayatPembayaranSection',
  // … lainnya
];

function showSectionByName(name) {
  sections.forEach(id => {
    document.getElementById(id).style.display = (id === name) ? 'block' : 'none';
  });
}

// baca URL param ?menu=…
function getMenuParam() {
  return new URLSearchParams(window.location.search).get('menu');
}

// mapping antara param ke section ID
const menuMap = {
  'shorten-link': 'shortenLinkSection',
  'recent-links':     'recentAjaxSection',
  'analytics':     'analyticsSection',
  'add-domain':     'adddomainSection',
  'set-timer':     'settimerSection',
  'reserve-subdo': 'domainCfSection',
  'dapa-checker':     'dapacheckerSection',
  'daftar-harga':     'daftarhargaSection',
  'user-profile':     'userprofileSection',
  'notification':     'notificationSection',
  'check-domain':     'checkdomainSection',
  'riwayat-pembayaran':'riwayatPembayaranSection',
  // … dst
  null:            'dashboardSection'
};

document.addEventListener('DOMContentLoaded', () => {
  // 1) otomatis tunjukkan section berdasarkan URL saat pertama load
  const menu = getMenuParam();
  showSectionByName(menuMap[menu] || menuMap.null);

  // 2) hijack click link menu agar tidak reload
  document.querySelectorAll('a.nav-link[href^="?menu="]').forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      const href = new URL(a.href);
      const m = href.searchParams.get('menu');
      // show section
      showSectionByName(menuMap[m] || menuMap.null);
      // update URL tanpa reload
      history.pushState({menu:m}, '', '?menu='+m);
    });
  });

  // 3) handle back/forward browser
  window.addEventListener('popstate', ev => {
    const m = (ev.state && ev.state.menu) || getMenuParam();
    showSectionByName(menuMap[m] || menuMap.null);
  });

  // 4) AJAX submit form shortlink
  document.getElementById('shortenForm').addEventListener('submit', ev => {
    ev.preventDefault();
    const form = ev.target;
    const data = new FormData(form);
    fetch('index.php', {
      method: 'POST',
      body: data
    }).then(r=>r.json()).then(j=>{
      const out = document.getElementById('shortenResult');
      if (j.success) {
        out.innerHTML = `<div class="alert alert-success">${j.message}</div>`;
        form.reset();
      } else {
        out.innerHTML = `<div class="alert alert-danger">${j.message}</div>`;
      }
    });
  });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const toastEl   = document.getElementById('msgToast');
  const toastBody = toastEl.querySelector('.toast-body');
  const bsToast   = new bootstrap.Toast(toastEl);

  // pasang click listener ke setiap elemen dengan kelas .copy-link
  document.querySelectorAll('.copy-link').forEach(el => {
    el.addEventListener('click', function(e) {
      e.preventDefault();
      const url = this.dataset.url;
      navigator.clipboard.writeText(url).then(() => {
        // pakai innerHTML supaya HTML (code tag) dirender
        toastBody.innerHTML = `Berhasil Dicopy: <code>${url}</code>`;
        toastEl.classList.remove('text-bg-danger');
        toastEl.classList.add('text-bg-success');
        bsToast.show();
      }).catch(err => {
        toastBody.textContent = 'Gagal menyalin link';
        toastEl.classList.remove('text-bg-success');
        toastEl.classList.add('text-bg-danger');
        bsToast.show();
        console.error('Gagal copy:', err);
      });
    });
  });
});

</script>
  <script>
document.addEventListener('DOMContentLoaded', () => {
  const toastEl   = document.getElementById('msgToast');
  const toastBody = toastEl.querySelector('.toast-body');
  const bsToast   = new bootstrap.Toast(toastEl);

  <?php if ($successMessage): ?>
    // pakai innerHTML agar HTML-tag-nya dirender
    toastBody.innerHTML = <?= json_encode($successMessage, JSON_UNESCAPED_SLASHES) ?>;
    toastEl.classList.add('text-bg-success');
    bsToast.show();
  <?php endif; ?>

  <?php if ($errorMessage): ?>
    toastBody.innerHTML = <?= json_encode($errorMessage) ?>;
    toastEl.classList.add('text-bg-danger');
    bsToast.show();
  <?php endif; ?>
});
  </script>


<script>
function showModal(btn) {
    [
  'modalShortlinkText','modalCreated','modalEditId','modalWhitePage','modalAllowedCountries',
  'modalBlockedCountries','modalUrls','modalFallbacks','modalDeviceList','modalUseMainDomain',
  'modalMainDomainId','setToMainDomainBtn','modalUrlsBadges','modalFallbacksBadges'
].forEach(id => {
  if (!document.getElementById(id)) {
    console.warn('ID modal TIDAK ADA:', id);
  }
});
  const id = btn.getAttribute('data-id');
  const short = btn.getAttribute('data-short');
  const created = btn.getAttribute('data-created');
  const urls = (btn.getAttribute('data-urls') || '').split('|||').join('\n');
  const fallbacks = (btn.getAttribute('data-fallback') || '').split('|||').join('\n');
  const whitePage = btn.getAttribute('data-white') || '';
  const allowedCountries = btn.getAttribute('data-allowed') || '';
  const blockedCountries = btn.getAttribute('data-blocked') || '';
  const deviceData = btn.getAttribute('data-device') || '[]';

  // PATCH: detect main domain mode
  const useMainDomain = btn.getAttribute('data-usemaindomain') == "1";
  const mainDomainId  = btn.getAttribute('data-maindomainid');
  const mainDomain    = btn.getAttribute('data-maindomain');
  const pathUrlJson   = btn.getAttribute('data-pathurl');
  const fallbackPathJson = btn.getAttribute('data-fallbackpathurl');
  let mainPaths = [];
  let fallbackPaths = [];

  // Coba parse path/fallback jika ada
  if (useMainDomain) {
    try { mainPaths = JSON.parse(pathUrlJson || '[]'); } catch(e) { mainPaths = []; }
    try { fallbackPaths = JSON.parse(fallbackPathJson || '[]'); } catch(e) { fallbackPaths = []; }
  }

  document.getElementById('modalShortlinkText').innerText = short;
  document.getElementById('modalShortlinkText').setAttribute('data-url', short);
  document.getElementById('modalCreated').innerText = created;
  document.getElementById('modalEditId').value = id;
  document.getElementById('modalWhitePage').value = whitePage;
  document.getElementById('modalAllowedCountries').value = allowedCountries;
  document.getElementById('modalBlockedCountries').value = blockedCountries;

  // PATCH: isi textarea (mode main domain)
  if (useMainDomain) {
    document.getElementById('modalUrls').value = (mainPaths && mainPaths.length) ? mainPaths.join('\n') : '';
    document.getElementById('modalFallbacks').value = (fallbackPaths && fallbackPaths.length) ? fallbackPaths.join('\n') : '';
    // Set hidden input modalUseMainDomain/mainDomainId
    if (document.getElementById('modalUseMainDomain')) document.getElementById('modalUseMainDomain').value = "1";
    if (document.getElementById('modalMainDomainId')) document.getElementById('modalMainDomainId').value = mainDomainId;

    // Ganti tombol ke mode AKTIF
    const btnSet = document.getElementById('setToMainDomainBtn');
    btnSet.className = 'btn btn-success';
    btnSet.innerHTML = `<i class="fa fa-link"></i> Terkait ke Main Domain: <b>${mainDomain}</b>`;
  } else {
    // Mode biasa (bukan main domain)
    document.getElementById('modalUrls').value = urls;
    document.getElementById('modalFallbacks').value = fallbacks;
    if (document.getElementById('modalUseMainDomain')) document.getElementById('modalUseMainDomain').value = '';
    if (document.getElementById('modalMainDomainId')) document.getElementById('modalMainDomainId').value = '';
    // Balikin tombol
    const btnSet = document.getElementById('setToMainDomainBtn');
    btnSet.className = 'btn btn-outline-primary';
    btnSet.innerHTML = `<i class="fa fa-link"></i> Arahkan ke Main Domain`;
  }

  // Device Targeting (tetap)
  let devices = [];
  try { devices = JSON.parse(deviceData); } catch (e) {}
  const container = document.getElementById('modalDeviceList');
  container.innerHTML = '';
  if (devices.length) {
    devices.forEach(d => addDeviceRowEdit(d.device_type, d.url));
  }

  renderBadgeOverlay('modalUrls', 'modalUrlsBadges');
  renderBadgeOverlay('modalFallbacks', 'modalFallbacksBadges');

  const modal = new bootstrap.Modal(document.getElementById('shortlinkModal'));
  modal.show();
}


function addDeviceRowEdit(type = '', url = '') {
  const container = document.getElementById('modalDeviceList');
  const div = document.createElement('div');
  div.className = 'input-group mb-2';
  div.innerHTML = `
    <select name="edit_device_type[]" class="form-select" style="max-width:150px">
      <option value="">Pilih Device</option>
      <option value="mobile" ${type === 'mobile' ? 'selected' : ''}>Mobile</option>
      <option value="desktop" ${type === 'desktop' ? 'selected' : ''}>Desktop</option>
      <option value="tablet" ${type === 'tablet' ? 'selected' : ''}>Tablet</option>
    </select>
    <input type="text" name="edit_device_url[]" class="form-control" placeholder="https://example.com/device" value="${url || ''}">
    <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()">×</button>
  `;
  container.appendChild(div);
}

// Render badge status per baris
function renderBadgeOverlay(textareaId, badgeContainerId) {
  const textarea = document.getElementById(textareaId);
  const badgeBox = document.getElementById(badgeContainerId);
  const lines = textarea.value.split('\n');
  badgeBox.innerHTML = '';

  setTimeout(() => {
    lines.forEach((line, index) => {
      if (!line.trim()) return;

      const lineHeight = parseFloat(getComputedStyle(textarea).lineHeight || 24);
      const top = index * lineHeight + 10;

      const badge = document.createElement('div');
      badge.className = 'badge-line';
      badge.style.top = top + 'px';

      badgeBox.appendChild(badge);
      checkTrustStatus(line.trim(), badge);
    });
  }, 50);
}

// Ekstrak domain dari URL
function extractDomain(url) {
  return url.replace(/^https?:\/\//i, '').split('/')[0].toLowerCase();
}

// Cek status TrustPositif
function checkTrustStatus(url, badge) {
  const domain = extractDomain(url);
  fetch('/dashboard/ajax/check_trust.php?domain=' + encodeURIComponent(domain))
    .then(res => res.json())
    .then(data => {
      if (data.status === 'blocked') {
        badge.textContent = '❌ Diblokir';
        badge.className = 'badge-line badge bg-danger';
      } else if (data.status === 'safe') {
        badge.textContent = 'Aman';
        badge.className = 'badge-line badge bg-success';
      } else {
        badge.textContent = '⚠️ Tidak Diketahui';
        badge.className = 'badge-line badge bg-warning';
      }
    })
    .catch(() => {
      badge.textContent = '❌ Gagal Cek';
      badge.className = 'badge-line badge bg-dark';
    });
}


function saveUpdatedLink(e) {
  e.preventDefault();

  const id = document.getElementById('modalEditId').value;
  const urls = document.getElementById('modalUrls').value.trim();
  const fallbacks = document.getElementById('modalFallbacks').value.trim();
  const whitePage = document.getElementById('modalWhitePage').value.trim();
  const allowedCountries = document.getElementById('modalAllowedCountries').value.trim();
  const blockedCountries = document.getElementById('modalBlockedCountries').value.trim();

  // Device Targeting collect
  let device_type = [];
  let device_url = [];
  document.querySelectorAll('#modalDeviceList .input-group').forEach(row => {
    const t = row.querySelector('select').value;
    const u = row.querySelector('input').value;
    if (t && u) {
      device_type.push(t);
      device_url.push(u);
    }
  });

  const alertBox = document.getElementById('editAlertBox');
  alertBox.innerHTML = '';
  alertBox.className = 'd-none';

  if (!urls) {
    alertBox.innerHTML = '❌ URL tidak boleh kosong.';
    alertBox.className = 'alert alert-danger mt-2';
    return;
  }

  const formData = new URLSearchParams();
  formData.append('edit_id', id);
  formData.append('edit_urls', urls);
  formData.append('edit_fallbacks', fallbacks);
  formData.append('edit_white_page', whitePage);
  formData.append('edit_allowed_countries', allowedCountries);
  formData.append('edit_blocked_countries', blockedCountries);

  // === Tambahan: Sinkronisasi Main Domain ===
  formData.append('modalUseMainDomain', document.getElementById('modalUseMainDomain').value);
  formData.append('modalMainDomainId', document.getElementById('modalMainDomainId').value);

  // Device targeting
  device_type.forEach(t => formData.append('edit_device_type[]', t));
  device_url.forEach(u => formData.append('edit_device_url[]', u));
console.log('modalUseMainDomain', document.getElementById('modalUseMainDomain').value);
console.log('modalMainDomainId', document.getElementById('modalMainDomainId').value);
  fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (!data.success) {
      alertBox.innerHTML = data.message;
      alertBox.className = 'alert alert-danger mt-2';
      return;
    }
    bootstrap.Modal.getInstance(document.getElementById('shortlinkModal')).hide();
    const toast = document.getElementById('toastNotif');
    toast.querySelector('.toast-body').textContent = data.message;
    toast.classList.remove('text-bg-danger');
    toast.classList.add('text-bg-success');
    new bootstrap.Toast(toast).show();
    setTimeout(() => window.location.reload(), 1000);
  })
  .catch(() => {
    alertBox.innerHTML = '❌ Gagal menyimpan perubahan.';
    alertBox.className = 'alert alert-danger mt-2';
  });

  return false;
}



function copyShortlink() {
  const fullUrl = document.getElementById('modalShortlinkText').getAttribute('data-url');
  navigator.clipboard.writeText("https://" + fullUrl).then(() => {
    const toast = document.getElementById('toastNotif');
    toast.querySelector('.toast-body').textContent = '✅ Shortlink berhasil disalin!';
    toast.classList.remove('text-bg-danger');
    toast.classList.add('text-bg-success');
    new bootstrap.Toast(toast).show();
  }).catch(() => {
    const toast = document.getElementById('toastNotif');
    toast.querySelector('.toast-body').textContent = '❌ Gagal menyalin link';
    toast.classList.remove('text-bg-success');
    toast.classList.add('text-bg-danger');
    new bootstrap.Toast(toast).show();
  });
}
</script>
<script>
let recentPage = 1;
let recentKeyword = "";

function loadRecentLinksAjax(page = 1, search = "") {
  const loader = document.getElementById('recentAjaxLoader');
  const content = document.getElementById('recentAjaxContent');
  const pagination = document.getElementById('paginationAjaxRecent');

  recentPage = page;
  recentKeyword = search;

  loader.classList.remove('d-none');
  content.classList.add('d-none');
  pagination.innerHTML = "";

  fetch(`/dashboard/ajax/get-recent-links.php?page=${page}&search=${encodeURIComponent(search)}`)
    .then(res => res.json())
    .then(data => {
      loader.classList.add('d-none');
      content.classList.remove('d-none');
      content.innerHTML = "";

      if (!data.success) {
        content.innerHTML = `<div class="alert alert-danger">❌ Gagal memuat recent links.</div>`;
        return;
      }

      if (data.total === 0) {
        // ✅ Kalau belum ada data sama sekali
        content.innerHTML = `<div class="alert alert-info">ℹ️ Belum ada shortlink yang dibuat.</div>`;
        pagination.innerHTML = "";
        return;
      }

      if (!data.links.length) {
        // ✅ Kalau data ada, tapi hasil pencarian kosong
        content.innerHTML = `<div class="alert alert-warning">❌ Tidak ada hasil ditemukan untuk pencarian ini.</div>`;
        pagination.innerHTML = "";
        return;
      }

    data.links.forEach(link => {
  content.innerHTML += `
    <div class="border rounded p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <i class="fa-solid fa-link"></i> <strong>${link.full_url}</strong><br>
          <small class="text-muted">${link.created_at_formatted}</small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-primary"
    data-id="${link.id}"
    data-short="${link.full_url}"
    data-created="${link.created_at_formatted}"
    data-urls="${(link.destinations||[]).join('|||')}"
    data-fallback="${(link.fallbacks||[]).join('|||')}"
    data-white="${link.white_page || ''}"
    data-allowed="${(link.allowed_countries || []).join(',')}"
    data-blocked="${(link.blocked_countries || []).join(',')}"
    data-device='${JSON.stringify(link.device_targets || [])}'

    data-usemaindomain="${link.use_main_domain ? 1 : 0}"
    data-maindomainid="${link.main_domain_id || ''}"
    data-maindomain="${link.main_domain || ''}"
    data-pathurl='${JSON.stringify(link.path_url || [])}'
    data-fallbackpathurl='${JSON.stringify(link.fallback_path_url || [])}'

    onclick="showModal(this)">
    <i class="fa-solid fa-edit"></i> Edit
  </button>
          <button class="btn btn-sm btn-danger" onclick="deleteShortlink(${link.id}, '${link.full_url}', this)">
            <i class="fa-solid fa-trash"></i>
          </button>
        </div>
      </div>
    </div>`;
});

      renderPagination(data.total, data.per_page, data.page);
    })
    .catch(() => {
      loader.classList.add('d-none');
      content.classList.remove('d-none');
      content.innerHTML = `<div class="alert alert-danger">❌ Gagal memuat recent links.</div>`;
    });
}

function renderPagination(total, perPage, currentPage) {
  const totalPages = Math.ceil(total / perPage);
  const pagination = document.getElementById('paginationAjaxRecent');
  pagination.innerHTML = "";

  for (let i = 1; i <= totalPages; i++) {
    const li = document.createElement('li');
    li.className = 'page-item' + (i === currentPage ? ' active' : '');
    li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
    li.querySelector('a').addEventListener('click', function (e) {
      e.preventDefault();
      loadRecentLinksAjax(i, recentKeyword);
    });
    pagination.appendChild(li);
  }
}
document.addEventListener('DOMContentLoaded', loadRecentLinksAjax);
document.getElementById('searchRecentAjax').addEventListener(
  'input',
  debounce(function() {
    recentKeyword = this.value.trim();
    loadRecentLinksAjax(1, recentKeyword);
  }, 350) // delay biar ga spam AJAX
);

</script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const userTelegramId = <?= json_encode($userTelegramId) ?>;
  const params = new URLSearchParams(window.location.search);
  const currentMenu = params.get('menu');

  // kalau sudah di halaman user-profile, jangan paksa modal
  if (currentMenu === 'user-profile') {
    return;
  }

  // selain itu, kalau belum isi Telegram ID → paksa modal
  if (!userTelegramId || userTelegramId.trim() === '') {
    const forceModalEl = document.getElementById('forceTelegramModal');
    const forceModal = new bootstrap.Modal(forceModalEl);
    forceModal.show();

    // disable semua tombol/link kecuali yang di modal dan yang menuju menu user-profile
    document.querySelectorAll('a, button, input[type="submit"]').forEach(el => {
      const inModal = el.closest('#forceTelegramModal');
      const isProfileLink = el.matches('a[href*="?menu=user-profile"]');
      if (!inModal && !isProfileLink) {
        el.disabled = true;
        el.classList.add('disabled');
      }
    });

    // kalau user klik tombol di modal (Isi Telegram ID), enable kembali dan sembunyikan modal
    forceModalEl.querySelector('a').addEventListener('click', () => {
      document.querySelectorAll('a.disabled, button.disabled, input[type="submit"].disabled')
        .forEach(el => {
          el.disabled = false;
          el.classList.remove('disabled');
        });
      forceModal.hide();
    });
  }
});
</script>
<script>
function loadAnalytics() {
  const container      = document.getElementById('analyticsContent');
  const pagination     = document.getElementById('analyticsPagination');
  const paginationList = pagination.querySelector('.pagination');
  const searchInput    = document.getElementById('analyticsSearchInput');

  // 1) show loader
  container.innerHTML = `
    <div class="text-center text-muted">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="mt-2">Memuat data analytics...</div>
    </div>`;
  pagination.style.display = 'none';
  paginationList.innerHTML = '';

  fetch('/dashboard/ajax/get-analytics.php')
    .then(res => {
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      return res.json();
    })
    .then(data => {
      // 2) collect all shortlink keys
      const keys = new Set();
      data.perDay   .forEach(r => keys.add(`${r.domain}/${r.short_code}`));
      data.byCountry.forEach(r => keys.add(`${r.domain}/${r.short_code}`));
      data.byDevice .forEach(r => keys.add(`${r.domain}/${r.short_code}`));

      // 3) init group for each
      const group = {};
      keys.forEach(k => {
        group[k] = { perDay: [], byCountry: [], byDevice: [] };
      });

      // 4) distribute into buckets
      data.perDay   .forEach(r => group[`${r.domain}/${r.short_code}`].perDay.push(r));
      data.byCountry.forEach(r => group[`${r.domain}/${r.short_code}`].byCountry.push(r));
      data.byDevice .forEach(r => group[`${r.domain}/${r.short_code}`].byDevice.push(r));

      const fullEntries = Object.entries(group);
      if (fullEntries.length === 0) {
        container.innerHTML = `<div class="alert alert-warning">⚠️ Tidak ada data analytics.</div>`;
        return;
      }

      // pagination & rendering
      const itemsPerPage = 7;
      let currentPage    = 1;
      let filtered       = fullEntries;

      function renderPage(page) {
        const start = (page - 1) * itemsPerPage;
        const slice = filtered.slice(start, start + itemsPerPage);

        container.innerHTML = slice.map(([shortlink, stat], i) => `
          <div class="border rounded mb-4 p-3">
            <div class="d-flex justify-content-between align-items-center">
              <div><strong><i class="fa-solid fa-link"></i> ${shortlink}</strong></div>
              <button class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="collapse"
                      data-bs-target="#detail-${page}-${i}">
                Lihat Detail
              </button>
            </div>
            <div class="collapse mt-3" id="detail-${page}-${i}">
              <div class="table-responsive mb-3">
                <h6>📅 Klik Per Hari</h6>
                <table class="table table-sm table-bordered">
                  <thead><tr><th>Tanggal</th><th>Jumlah Klik</th></tr></thead>
                  <tbody>
                    ${stat.perDay.map(r => `<tr><td>${r.date}</td><td>${r.clicks}</td></tr>`).join('')}
                  </tbody>
                </table>
              </div>
              <div class="table-responsive mb-3">
                <h6>🌍 Berdasarkan Negara</h6>
                <table class="table table-sm table-bordered">
                  <thead><tr><th>Negara</th><th>Jumlah Klik</th></tr></thead>
                  <tbody>
                    ${stat.byCountry.map(r => `<tr><td>${r.country||'Tidak diketahui'}</td><td>${r.clicks}</td></tr>`).join('')}
                  </tbody>
                </table>
              </div>
              <div class="table-responsive">
                <h6>📱 Berdasarkan Perangkat</h6>
                <table class="table table-sm table-bordered">
                  <thead><tr><th>Perangkat</th><th>Jumlah Klik</th></tr></thead>
                  <tbody>
                    ${stat.byDevice.map(r => `<tr><td>${r.device||'Tidak diketahui'}</td><td>${r.clicks}</td></tr>`).join('')}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        `).join('');

        // render pagination controls
        const totalPages = Math.ceil(filtered.length / itemsPerPage);
        pagination.style.display = totalPages > 1 ? 'block' : 'none';
        paginationList.innerHTML = '';
        for (let i = 1; i <= totalPages; i++) {
          const li = document.createElement('li');
          li.className = 'page-item' + (i === page ? ' active' : '');
          li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
          li.querySelector('a').addEventListener('click', e => {
            e.preventDefault();
            currentPage = i;
            renderPage(i);
          });
          paginationList.appendChild(li);
        }
      }

      // search filter
      searchInput.addEventListener('input', () => {
        const kw = searchInput.value.toLowerCase().trim();
        filtered = fullEntries.filter(([k]) => k.toLowerCase().includes(kw));
        renderPage(currentPage = 1);
      });

      // initial render
      renderPage(1);
    })
    .catch(err => {
      console.error('Gagal load analytics:', err);
      container.innerHTML = `<div class="alert alert-danger">❌ Gagal memuat data analytics.</div>`;
      pagination.style.display = 'none';
    });
}

// fire immediately on page load
document.addEventListener('DOMContentLoaded', loadAnalytics);
</script>

<script>
document.getElementById('inputDomainCheck').addEventListener('input', function () {
  const textarea = this;
  const lines = textarea.value.split('\n').map(x => x.trim()).filter(Boolean);
  const resultBox = document.getElementById('domainCheckMsg');

  if (lines.length === 0) {
    resultBox.innerHTML = '';
    return;
  }

  const formData = new FormData();
  formData.append('domains', lines.join('\n'));

  fetch('/dashboard/ajax/check-multiple-domains.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (!data.success) throw new Error('Gagal');

    resultBox.innerHTML = '';
    data.results.forEach(item => {
      const li = document.createElement('li');
      li.className = 'list-group-item d-flex justify-content-between align-items-center';

      li.innerHTML = `
        <span>${item.domain}</span>
        <span class="badge ${item.exists ? 'bg-warning text-dark' : 'bg-success'}">
          ${item.exists ? 'Sudah Terdaftar' : 'Belum Terdaftar'}
        </span>
      `;
      resultBox.appendChild(li);
    });
  })
  .catch(() => {
    resultBox.innerHTML = `<li class="list-group-item text-danger">❌ Gagal mengecek domain.</li>`;
  });
});
</script>
<script>
document.getElementById('checkDomainForm').addEventListener('submit', function (e) {
  e.preventDefault();

  const textarea = document.getElementById('domains');
  const resultBox = document.getElementById('checkResult');

  // Bersihkan http(s)://, www., dan slash di akhir
  const cleanedDomains = textarea.value
    .split('\n')
    .map(line => line.trim()
      .replace(/^https?:\/\//i, '')     // hapus http:// atau https://
      .replace(/^www\./i, '')           // hapus www.
      .replace(/\/+$/, '')              // hapus / di akhir
    )
    .filter(line => line !== '')
    .join('\n');

  // (Optional) Update isi textarea biar user lihat hasil bersih
  textarea.value = cleanedDomains;

  const formData = new FormData();
  formData.append('domains', cleanedDomains);

  resultBox.innerHTML = `
    <div class="text-center text-muted my-3">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="mt-2">Memeriksa domain...</div>
    </div>
  `;

  fetch('/dashboard/ajax/check-domains.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.text())
  .then(html => {
    resultBox.innerHTML = html;
  })
  .catch(() => {
    resultBox.innerHTML = `<div class="alert alert-danger">❌ Gagal memeriksa domain.</div>`;
  });
});
</script>

<script>
  // Utility: ambil hostname saja
  function extractDomain(url) {
    return url.trim()
      .replace(/^https?:\/\//i, '')
      .replace(/^www\./i, '')
      .replace(/\/.*$/, '');
  }

  // Cek TrustPositif via checklist.php, update teks di kolom Status
  function checkListStatus(domain, cell) {
    const d = extractDomain(domain);
    fetch('/dashboard/ajax/checklist.php?domain=' + encodeURIComponent(d))
      .then(res => res.json())
      .then(data => {
        let txt, color;
        if (data.status === 'blocked') {
          txt   = 'Diblokir';
          color = 'red';
        } else if (data.status === 'safe') {
          txt   = 'Aman';
          color = 'green';
        } else {
          txt   = 'Tidak diketahui';
          color = 'orange';
        }
        cell.textContent      = txt;
        cell.style.color      = color;
        cell.style.fontWeight = 'bold';
      })
      .catch(() => {
        cell.textContent      = 'Gagal cek';
        cell.style.color      = 'darkred';
        cell.style.fontWeight = 'bold';
      });
  }

document.getElementById('addDomainsForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  const msgBox = document.getElementById('addDomainsMsg');
  const textarea = form.querySelector('textarea[name="domains"]');
  const inputLines = textarea.value.split('\n').map(x => x.trim()).filter(Boolean);

  // === Regex domain dan IPv4 ===
  const validDomainOrIpRegex = /^([a-z0-9\-\.]+\.[a-z0-9\-\.]+|(?:\d{1,3}\.){3}\d{1,3})$/i;

  // Cek input, support domain TLD apapun & IPv4
  const invalidEntries = inputLines.filter(entry => {
    const cleaned = extractDomain(entry);
    return !validDomainOrIpRegex.test(cleaned);
  });

  if (invalidEntries.length > 0) {
    msgBox.className = 'alert alert-danger';
    msgBox.innerHTML = '❌ Hanya domain atau IP address yang dapat didaftarkan!<br>Kesalahan pada:<br><code>' + invalidEntries.join('</code><br><code>') + '</code>';
    return;
  }

  fetch('/dashboard/ajax/add-domains.php', {
    method: 'POST',
    body: formData
  })
  .then(async res => {
    const contentType = res.headers.get("content-type");
    if (!res.ok || !contentType || !contentType.includes("application/json")) {
      throw new Error("Bukan response JSON");
    }
    return res.json();
  })
  .then(data => {
    msgBox.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger');
    msgBox.innerHTML = data.message;
    if (data.success) {
      form.reset();
      loadUserDomains();
    }
  })
  .catch(err => {
    console.error(err);
    msgBox.className = 'alert alert-danger';
    msgBox.textContent = '❌ Terjadi kesalahan jaringan/server.';
  });
});

function loadUserDomains() {
  fetch('/dashboard/ajax/get-domains.php')
    .then(res => res.json())
    .then(data => {
      const tbody = document.getElementById('userDomainList');
      tbody.innerHTML = '';

      if (data.success && data.domains.length) {
        data.domains.forEach(d => {
          const tr = document.createElement('tr');
          tr.setAttribute('data-id', d.id);

          // Kolom Checkbox
          const tdCheck = document.createElement('td');
          tdCheck.innerHTML = `<input type="checkbox" class="domain-checkbox" value="${d.id}">`;

          // Kolom Domain + Tombol Copy
          const tdDomain = document.createElement('td');
          tdDomain.className = "d-flex align-items-center gap-2";
          tdDomain.textContent = d.domain;

          // Tombol Copy
          const copyBtn = document.createElement('button');
          copyBtn.type = "button";
          copyBtn.className = "btn btn-sm btn-outline-primary ms-2";
          copyBtn.innerHTML = '<i class="fa fa-copy"></i>';
          copyBtn.title = "Copy domain";
          copyBtn.onclick = () => {
            navigator.clipboard.writeText(d.domain)
              .then(() => {
                copyBtn.innerHTML = '<i class="fa fa-check"></i>';
                setTimeout(() => {
                  copyBtn.innerHTML = '<i class="fa fa-copy"></i>';
                }, 1200);
              })
              .catch(() => alert("Gagal menyalin domain"));
          };
          tdDomain.appendChild(copyBtn);

          // Status placeholder
          const tdStatus = document.createElement('td');
          tdStatus.textContent = 'Loading…';

          // Aksi (delete)
          const tdAksi = document.createElement('td');
          const btn = document.createElement('button');
          btn.type      = 'button';
          btn.className = 'btn btn-sm btn-danger';
          btn.innerHTML = '<i class="fa-solid fa-trash"></i>';
          btn.onclick   = () => deleteDomain(d.id, tr);
          tdAksi.appendChild(btn);

          tr.append(tdCheck, tdDomain, tdStatus, tdAksi);
          tbody.appendChild(tr);

          checkListStatus(d.domain, tdStatus);
        });

        // --- Checkbox logic ---
        const allCheckbox = document.querySelectorAll('.domain-checkbox');
        allCheckbox.forEach(cb => cb.addEventListener('change', updateBulkDeleteBtn));
        const selectAll = document.getElementById('selectAllDomains');
        if (selectAll) {
          selectAll.checked = false;
          selectAll.onchange = function() {
            allCheckbox.forEach(cb => cb.checked = this.checked);
            updateBulkDeleteBtn();
          };
        }
        updateBulkDeleteBtn();

        // Destroy Sortable sebelumnya
        if (window.domainSortable) {
          window.domainSortable.destroy();
        }
        window.domainSortable = Sortable.create(tbody, {
          animation: 150,
          onEnd: function () {
            const ids = [...tbody.querySelectorAll('tr')].map(tr => tr.getAttribute('data-id'));
            fetch('/dashboard/ajax/save-domain-order.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ order: ids })
            });
          }
        });

      } else {
        tbody.innerHTML = `
          <tr>
            <td colspan="4" class="text-center text-muted">
              Belum ada domain.
            </td>
          </tr>`;
      }
    })
    .catch(() => {
      document.getElementById('userDomainList').innerHTML = `
        <tr>
          <td colspan="4" class="text-center text-danger">
            Gagal memuat domain.
          </td>
        </tr>`;
    });
}

// Hapus domain satuan
function deleteDomain(id, row) {
  if (!confirm('Hapus domain ini?')) return;
  fetch('/dashboard/ajax/delete-domain.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body:    'id=' + encodeURIComponent(id)
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      row.remove();
      updateBulkDeleteBtn();
    } else {
      alert(data.message);
    }
  })
  .catch(() => alert('Gagal menghapus domain.'));
}

// Hapus bulk
function handleBulkDelete() {
  const checked = [...document.querySelectorAll('.domain-checkbox:checked')];
  if (!checked.length) return;
  if (!confirm('Hapus semua domain terpilih?')) return;

  const ids = checked.map(cb => cb.value);
  fetch('/dashboard/ajax/delete-domain.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body:    'ids=' + encodeURIComponent(JSON.stringify(ids))
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      checked.forEach(cb => cb.closest('tr').remove());
      updateBulkDeleteBtn();
    } else {
      alert(data.message || 'Gagal menghapus domain.');
    }
  })
  .catch(() => alert('Gagal menghapus domain.'));
}

function updateBulkDeleteBtn() {
  const bulkDeleteBtn = document.getElementById('bulkDeleteDomains');
  const anyChecked = !!document.querySelector('.domain-checkbox:checked');
  if (bulkDeleteBtn) bulkDeleteBtn.style.display = anyChecked ? 'inline-block' : 'none';
}

// Copy ALL domains
document.addEventListener('DOMContentLoaded', () => {
  loadUserDomains();

  const copyAllBtn = document.getElementById('copyAllDomainsBtn');
  if (copyAllBtn) {
    copyAllBtn.onclick = () => {
      const domains = [...document.querySelectorAll('#userDomainList tr')].map(tr =>
        tr.querySelector('td:nth-child(2)')?.childNodes[0]?.textContent?.trim() || ""
      ).filter(Boolean);
      if (!domains.length) return;
      navigator.clipboard.writeText(domains.join('\n'))
        .then(() => {
          copyAllBtn.innerHTML = '<i class="fa fa-check"></i> Copied!';
          setTimeout(() => {
            copyAllBtn.innerHTML = '<i class="fa fa-copy"></i> Copy All';
          }, 1200);
        })
        .catch(() => alert("Gagal menyalin semua domain"));
    };
  }

  // Bulk delete
  const bulkDeleteBtn = document.getElementById('bulkDeleteDomains');
  if (bulkDeleteBtn) {
    bulkDeleteBtn.onclick = handleBulkDelete;
  }
});


</script>


<script>
function deleteShortlink(id, shortlinkText, btn) {
  if (!confirm(`Yakin ingin menghapus shortlink ini?\n${shortlinkText}`)) return;

  fetch('/dashboard/ajax/delete-link.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${encodeURIComponent(id)}`
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      btn.closest('.border.rounded.p-3').remove(); // Hapus elemen card
      showToast(data.message, 'success');
    } else {
      showToast(data.message, 'danger');
    }
  })
  .catch(() => {
    showToast('❌ Gagal menghapus shortlink.', 'danger');
  });
}

function showToast(msg, type = 'success') {
  const toast = document.createElement('div');
  toast.className = `toast align-items-center text-bg-${type} border-0 show`;
  toast.setAttribute('role', 'alert');
  toast.setAttribute('aria-live', 'assertive');
  toast.setAttribute('aria-atomic', 'true');
  toast.style.zIndex = 9999;
  toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${msg}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
  const container = document.querySelector('.toast-container') || createToastContainer();
  container.appendChild(toast);
  new bootstrap.Toast(toast).show();
}
function createToastContainer() {
  const div = document.createElement('div');
  div.className = 'toast-container position-fixed top-0 end-0 p-3';
  document.body.appendChild(div);
  return div;
}

function createToastContainer() {
  const container = document.createElement('div');
  container.className = 'toast-container position-fixed top-0 end-0 p-3';
  container.style.zIndex = 1055;
  document.body.appendChild(container);
  return container;
}

</script>



<script>
document.getElementById('cronSettingsForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);

  fetch('/dashboard/ajax/save-cron.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    const msg = document.getElementById('cronSettingMsg');
    msg.className = data.success ? 'alert alert-success' : 'alert alert-danger';
    msg.innerHTML = data.message;
  })
  .catch(() => {
    const msg = document.getElementById('cronSettingMsg');
    msg.className = 'alert alert-danger';
    msg.innerHTML = '❌ Gagal menyimpan pengaturan';
  });
});
</script>

<script>
const isVIP = <?= json_encode($isVIP); ?>;

document.getElementById('formCheckDA').addEventListener('submit', async function(e) {
  e.preventDefault();
  if (!isVIP) {
    Swal.fire({
      icon: 'warning',
      title: 'Akses Ditolak',
      text: 'Maaf, hanya user VIP/VIPMAX yang dapat melakukan pengecekan DR/UR & Umur Domain.',
      confirmButtonText: 'Oke'
    });
    return;
  }

  const domains = this.domain.value
    .split('\n')
    .map(d => d.trim())
    .filter(Boolean);

  const resultBox = document.getElementById('resultDA');
  if (domains.length === 0) {
    resultBox.innerHTML = '<div class="alert alert-danger">❌ Tidak ada domain untuk dicek.</div>';
    return;
  }

  resultBox.innerHTML = `
    <div class="text-center my-4" id="loadingSpinner">
      <div class="spinner-border text-primary"></div>
      <div class="mt-2">Mengecek ${domains.length} domain...</div>
    </div>
    <div id="progressDA" class="mt-2 alert alert-info"></div>
    <div class="table-responsive">
      <table class="table table-bordered align-middle bg-white rounded shadow overflow-hidden">
        <thead class="table-light">
          <tr>
            <th>Domain</th>
            <th>DR</th>
            <th>UR</th>
            <th>Backlink</th>
            <th>Ref</th>
            <th>Traffic</th>
            <th>Value</th>
            <th>Top KW</th>
            <th>Pos</th>
            <th>History</th>
            <th>Negara</th>
          </tr>
        </thead>
        <tbody id="tableBodyDA"></tbody>
      </table>
    </div>
  `;

  const tableBody = document.getElementById('tableBodyDA');
  const progressDA = document.getElementById('progressDA');
  const loadingSpinner = document.getElementById('loadingSpinner');

  function fmt(val) {
    if (isNaN(val)) return '-';
    if (val >= 1e6) return (val/1e6).toFixed(1) + 'M';
    if (val >= 1e3) return (val/1e3).toFixed(1) + 'K';
    return val;
  }
  function fmt$(val) {
    if (!val) return '-';
    return '$' + Math.round(val).toLocaleString();
  }
  function getTopCountry(tc = []) {
    if (!tc.length) return '-';
    const utama = tc[0];
    let nm = utama.country?.toUpperCase();
    // Translate code (optional)
    if (nm === 'ID') nm = '🇮🇩 ID';
    else if (nm === 'US') nm = '🇺🇸 US';
    else if (nm === 'KH') nm = '🇰🇭 KH';
    else if (nm === 'SG') nm = '🇸🇬 SG';
    else if (nm === 'AF') nm = '🇦🇫 AF';
    else if (nm === 'MY') nm = '🇲🇾 MY';
    return nm + ' (' + (utama.share ? utama.share.toFixed(1) : 0) + '%)';
  }

  for (let i = 0; i < domains.length; i++) {
    const domain = domains[i];
    progressDA.textContent = `🔄 Memeriksa domain (${i+1}/${domains.length}): ${domain}`;
    try {
      const formData = new FormData();
      formData.append('domain', domain);

      const response = await fetch('/dashboard/ajax/check-da-pa.php', {
        method: 'POST',
        body: formData
      });
      const json = await response.json();

      if (!json.success) {
        tableBody.innerHTML += `<tr>
          <td>${domain}</td>
          <td colspan="10" class="text-danger">❌ Gagal: Data tidak ditemukan / Error API</td>
        </tr>`;
        continue;
      }

      // AHREFS DATA
      const d = json.domain || {};
      const page = (json.ahrefs && json.ahrefs.page) ? json.ahrefs.page : {};
      const dom = (json.ahrefs && json.ahrefs.domain) ? json.ahrefs.domain : {};
      const t = json.traffic || {};
      const hist = (t.traffic_history || []).length + " bulan";
      const topKW = (t.top_keywords && t.top_keywords.length) ? t.top_keywords[0] : null;
      const topCountry = getTopCountry(t.top_countries);

      tableBody.innerHTML += `
        <tr>
          <td><b>${domain.replace(/^https?:\/\//,'')}</b></td>
          <td class="fw-bold text-primary">${dom.domainRating ?? '-'}</td>
          <td>${page.urlRating ?? '-'}</td>
          <td>${fmt(page.backlinks ?? dom.backlinks)}</td>
          <td>${fmt(page.refDomains ?? dom.refDomains)}</td>
          <td>${fmt(t.trafficMonthlyAvg ?? dom.traffic)}</td>
          <td>${fmt$(t.costMonthlyAvg ?? dom.trafficValue)}</td>
          <td>${topKW ? topKW.keyword : '-'}</td>
          <td>${topKW ? topKW.position : '-'}</td>
          <td>${hist}</td>
          <td>${topCountry}</td>
        </tr>
      `;
    } catch (error) {
      tableBody.innerHTML += `<tr>
        <td>${domain}</td>
        <td colspan="10" class="text-danger">❌ Gagal memeriksa: ${error.message}</td>
      </tr>`;
    }
  }

  loadingSpinner.remove();
  progressDA.remove();
});
</script>


<script>
let selectedType = '';
const priceMap = {
  medium: 18.88,
  vip: 37.77
};

function showUpgradeModal() {
  const modal = new bootstrap.Modal(document.getElementById('upgradeModal'));
  modal.show();
}

function startUpgrade(type) {
  selectedType = type;
  const priceUSD = priceMap[type];
  const paymentSection = document.getElementById('paymentSection');
  const priceConverted = document.getElementById('priceConverted');
  const upgradeMsg = document.getElementById('upgradeMsg');

  upgradeMsg.innerHTML = '';
  paymentSection.classList.remove('d-none');

  // Loading indikator
  priceConverted.innerHTML = `
    <div class="d-flex flex-column align-items-center">
      <div class="spinner-border text-success mb-2" role="status">
        <span class="visually-hidden">Loading...</span>
      </div>
      <div class="text-muted">Mengambil kurs & QR Code...</div>
    </div>
  `;

  fetch("/dashboard/ajax/get-rate.php")
    .then(res => res.json())
    .then(data => {
      if (data.success && data.rate > 0) {
        const rate = data.rate;
        const idr = Math.round(priceUSD * rate);
        priceConverted.innerHTML = `
          <p class="fw-bold text-center text-primary fs-5">
            💱 Kurs Saat Ini: <strong>1 USD = Rp${rate.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong><br>
            💰 Total Bayar: <strong>Rp${idr.toLocaleString('id-ID')}</strong>
          </p>
          <p class="fw-bold text-center text-dark fs-5">Top Laundry Express</p>
          <img src="/assets/qris-dana.jpg" class="img-fluid rounded border mb-3" alt="QRIS DANA">
        `;
      } else {
        priceConverted.innerHTML = `<span class="text-danger">Gagal mendapatkan kurs dari server.</span>`;
      }
    })
    .catch(() => {
      priceConverted.innerHTML = `<span class="text-danger">❌ Gagal mengambil kurs dari server.</span>`;
    });
}

function confirmPayment() {
  const formData = new FormData();
  formData.append('upgrade_type', selectedType);

  fetch('/dashboard/ajax/confirm-upgrade.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    const msg = document.getElementById('upgradeMsg');
    msg.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger');
    msg.innerHTML = data.message;
    if (data.success) setTimeout(() => location.reload(), 3000);
  });
}

function confirmPayment() {
  const formData = new FormData();
  formData.append('upgrade_type', selectedType);

  fetch('/dashboard/ajax/confirm-upgrade.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    const msg = document.getElementById('upgradeMsg');
    msg.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger');
    msg.innerHTML = data.message;

    if (data.success) {
      // Tutup modal upgrade
      const modal = bootstrap.Modal.getInstance(document.getElementById('upgradeModal'));
      modal.hide();

      // Tampilkan toast
      setTimeout(() => {
        const toast = new bootstrap.Toast(document.getElementById('upgradeToast'));
        toast.show();
      }, 300);

      // Tampilkan modal standby
      setTimeout(() => {
        const standbyModal = new bootstrap.Modal(document.getElementById('standbyModal'));
        standbyModal.show();

        const btnUpgrade = document.getElementById('btnUpgradeAkun');
        if (btnUpgrade) {
          btnUpgrade.classList.add('disabled');
          btnUpgrade.innerHTML = '🔒 Upgrade dalam Proses';
        }
      }, 1000);
    }
  });
}
// Cek status pending saat dashboard dibuka
document.addEventListener('DOMContentLoaded', () => {
  fetch('/dashboard/ajax/check-upgrade-status.php')
    .then(res => res.json())
    .then(status => {
      if (status.success && status.pending) {
        // Disable tombol upgrade
        const btn = document.getElementById('btnUpgradeAkun');
        if (btn) {
          btn.classList.add('disabled');
          btn.innerHTML = '🔒 Upgrade dalam Proses';
        }

        // Auto tampilkan modal standby
        const standbyModal = new bootstrap.Modal(document.getElementById('standbyModal'));
        standbyModal.show();
      }
    });
});
</script>
<script>
      new MetisMenu('#menu');
    </script>
    
    <!-- skrip untuk auto-hide sidebar di mobile -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const navScroll = document.querySelector('.deznav-scroll');
      const navMenu   = document.querySelector('.deznav-scroll .metismenu');
      const hamburger = document.querySelector('.nav-control .hamburger');
  
      document.querySelectorAll('.deznav .nav-link').forEach(link => {
        link.addEventListener('click', () => {
          if (window.matchMedia('(max-width: 991.98px)').matches) {
            navScroll && navScroll.classList.remove('mm-active');
            navMenu   && navMenu.classList.remove('mm-show');
            // jika ada overlay/backdrop
            document.body.classList.remove('menu-open');
            // reset hamburger icon
            hamburger && hamburger.classList.remove('is-active');
          }
        });
      });
    });
    </script>
    <script>
document.getElementById('triggerSubmit').addEventListener('click', function () {
  const form = document.getElementById('profileForm');
  const formData = new FormData(form);

  // Submit via AJAX
  fetch('/dashboard/ajax/update-profile.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    const msg = document.getElementById('profileMessage');
    msg.className = data.success ? 'alert alert-success mt-3' : 'alert alert-danger mt-3';
    msg.innerHTML = data.message;

    if (data.success) {
      setTimeout(() => location.reload(), 2000);
    }
  })
  .catch(() => {
    const msg = document.getElementById('profileMessage');
    msg.className = 'alert alert-danger mt-3';
    msg.innerHTML = '❌ Gagal mengirim permintaan.';
  });
});

// Tombol toggle untuk form password
function togglePasswordForm() {
  const section = document.getElementById('passwordFields');
  section.classList.toggle('d-none');
}

// Update warna badge checkbox
function updateNotifIndicators() {
  const personal = document.getElementById('notif_to_personal');
  const group = document.getElementById('notif_to_group');
  const badgePersonal = document.getElementById('badgePersonal');
  const badgeGroup = document.getElementById('badgeGroup');

  badgePersonal.className = personal.checked ? 'badge bg-success' : 'badge bg-secondary';
  badgeGroup.className = group.checked ? 'badge bg-success' : 'badge bg-secondary';
}

document.addEventListener('DOMContentLoaded', updateNotifIndicators);
</script>


<script>
function copyToClipboard(text) {
  navigator.clipboard.writeText('https://' + text).then(() => {
    alert('✅ Link berhasil disalin!');
  }, err => {
    alert('❌ Gagal menyalin link');
  });
}

</script>

<script>
document.getElementById('kritikSaranForm').addEventListener('submit', function (e) {
  e.preventDefault();

  const form = e.target;
  const kritik = form.kritik_saran.value.trim();
  const msgBox = document.getElementById('kritikSaranMsg');

  if (!kritik) {
    msgBox.className = 'alert alert-danger';
    msgBox.textContent = '❌ Kritik & Saran tidak boleh kosong.';
    return;
  }

  const formData = new FormData();
  formData.append('kritik_saran', kritik);

  fetch('/dashboard/ajax/save-kritik.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    msgBox.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger');
    msgBox.textContent = data.message;
    if (data.success) {
      form.reset();
      // ❌ JANGAN loadKritikList() disini bro, cukup reset form
    }
  })
  .catch(() => {
    msgBox.className = 'alert alert-danger';
    msgBox.textContent = '❌ Gagal mengirim kritik & saran.';
  });
});

// 🔥 fungsi load kritik, dipanggil saat klik menu
function loadKritikList() {
  const container = document.getElementById('kritikList');
  container.innerHTML = `
    <div class="text-center text-muted">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="mt-2">Memuat riwayat...</div>
    </div>
  `;

  fetch('/dashboard/ajax/get-kritik.php')
    .then(res => res.json())
    .then(data => {
      if (!data.success) {
        container.innerHTML = `<div class="alert alert-danger">❌ Gagal memuat data kritik.</div>`;
        return;
      }

      if (data.kritik.length === 0) {
        container.innerHTML = `<div class="alert alert-warning">⚠️ Anda belum pernah mengirim kritik atau saran.</div>`;
        return;
      }

      container.innerHTML = `
        <ul class="list-group">
          ${data.kritik.map(k => `
            <li class="list-group-item">
              <p class="mb-1">${k.kritik_saran}</p>
              <small class="text-muted">Dikirim pada: ${k.created_at}</small>
            </li>
          `).join('')}
        </ul>
      `;
    })
    .catch(() => {
      container.innerHTML = `<div class="alert alert-danger">❌ Gagal memuat data kritik.</div>`;
    });
}
document.addEventListener('DOMContentLoaded', loadKritikList);
</script>

<script>
  document.getElementById('sidebarSearch')
    .addEventListener('keydown', function(e) {
      if (e.key !== 'Enter') return;
      const q = this.value.trim().toLowerCase();
      if (!q) return;

      let found = null;

      // 1) Cek di <a.nav-link>
      document.querySelectorAll('.deznav .nav-link').forEach(a => {
        if (found) return;
        const text     = a.textContent.trim().toLowerCase();
        const kws      = (a.getAttribute('data-search') || '').toLowerCase();
        if (text.includes(q) || kws.includes(q)) {
          found = a;
        }
      });

      // 2) Kalau belum ketemu, cek di <li class="menu-title">
      if (!found) {
        document.querySelectorAll('.deznav .menu-title').forEach(title => {
          if (found) return;
          const text     = title.textContent.trim().toLowerCase();
          const kws      = (title.getAttribute('data-search') || '').toLowerCase();
          if (text.includes(q) || kws.includes(q)) {
            // ambil <a.nav-link> pertama setelah title
            let sib = title.nextElementSibling;
            while (sib && !sib.classList.contains('nav-item')) sib = sib.nextElementSibling;
            if (sib) {
              found = sib.querySelector('a.nav-link');
            }
          }
        });
      }

      if (found) {
        // navigasi
        found.click();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } else {
        alert(`Menu "${this.value}" tidak ditemukan.`);
      }
      this.value = '';
    });
</script>



<script>
document.addEventListener('DOMContentLoaded', function(){
  // --- DAILY DATA (DARI PHP) ---
  const dailyLabels = <?= json_encode(array_keys($chartDates)) ?>;
  const dailyClicks = <?= json_encode(array_values($chartDates)) ?>;

  // --- WEEKLY DATA (SIAPIN DARI PHP JUGA) ---
  const weeklyLabels = <?= json_encode(array_keys($weeklyChart ?? [])) ?>; // ['Minggu 1', 'Minggu 2', ...]
  const weeklyClicks = <?= json_encode(array_values($weeklyChart ?? [])) ?>;

  // --- MONTHLY DATA (SIAPIN DARI PHP JUGA) ---
  const monthlyLabels = <?= json_encode(array_keys($monthlyChart ?? [])) ?>; // ['Jan', 'Feb', ...]
  const monthlyClicks = <?= json_encode(array_values($monthlyChart ?? [])) ?>;

  // FORMAT TANGGAL HARIAN Biar "22 Apr"
  function fmtDaily(d) {
    const dt = new Date(d);
    return dt.toLocaleDateString('id-ID', { day:'numeric', month:'short' });
  }

  // Theme detection
  function getThemeColors() {
    const isDark = document.body.getAttribute('data-theme-version') === 'dark';
    return {
      text: isDark ? '#333' : '#a1b0cb',
      grid: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
      theme: isDark ? 'dark' : 'light'
    }
  }

  // Chart render helper
  function renderStatistikChart(target, labels, data, isBar = true, labelFormat = t=>t) {
    const theme = getThemeColors();
    const options = {
      series: [{ name: 'Total Klik', data: data }],
      chart: {
        type: isBar ? 'bar' : 'area',
        height: '100%',
        toolbar: { show: false },
        background: 'transparent'
      },
      plotOptions: {
        bar: {
          borderRadius: 4,
          horizontal: false,
          columnWidth: '55%'
        }
      },
      dataLabels: { enabled: false },
      xaxis: {
        categories: labels.map(labelFormat),
        labels: { style: { colors: Array(labels.length).fill(theme.text) } },
        axisBorder: { show: false },
        axisTicks: { show: false }
      },
      yaxis: {
        labels: { style: { colors: theme.text } }
      },
      grid: { borderColor: theme.grid },
      tooltip: { theme: theme.theme },
      theme: { mode: theme.theme, palette: 'palette1' },
      colors: [ 'var(--primary)' ]
    };
    new ApexCharts(document.querySelector(target), options).render();
  }

  // Render all statistik charts
  renderStatistikChart('#statistikDailyChart', dailyLabels, dailyClicks, true, fmtDaily);
  renderStatistikChart('#statistikWeeklyChart', weeklyLabels, weeklyClicks, true, t=>t);
  renderStatistikChart('#statistikMonthlyChart', monthlyLabels, monthlyClicks, true, t=>t);

  // Re-render on theme switch (if any)
  document.getElementById('theme_version')?.addEventListener('change', () => {
    document.querySelectorAll('#statistikDailyChart, #statistikWeeklyChart, #statistikMonthlyChart').forEach(el => {
      el.innerHTML = '';
    });
    renderStatistikChart('#statistikDailyChart', dailyLabels, dailyClicks, true, fmtDaily);
    renderStatistikChart('#statistikWeeklyChart', weeklyLabels, weeklyClicks, true, t=>t);
    renderStatistikChart('#statistikMonthlyChart', monthlyLabels, monthlyClicks, true, t=>t);
  });
});
</script>


<script>
  const packages = {
    medium: { title: 'Paket Medium', price: 350000 },
    vip:    { title: 'Paket VIP',    price: 650000 },
    vipmax: { title: 'Paket VIP MAX', price: 1200000 }
  };
  let selectedPackage = null;
  let selectedPkgKey  = null; // <-- Tambahkan ini!
  let selectedMethod  = null;

  function showOptions(pkgKey) {
    selectedPkgKey  = pkgKey;                    // <-- Tambahkan ini!
    selectedPackage = packages[pkgKey];
    const container = document.getElementById('paymentOptions');
    container.classList.remove('d-none');
    container.innerHTML = `
      <div class="text-center py-5">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-3">Memuat opsi pembayaran...</p>
      </div>
    `;
    setTimeout(() => {
      container.innerHTML = `
        <div class="card-body text-center">
          <h5 id="pkgTitle">${selectedPackage.title}</h5>
          <p id="pkgPrice" class="fs-5 mb-4">
            Rp ${selectedPackage.price.toLocaleString('id-ID')} /month
          </p>
          <p class="mb-2">Pilih Metode Pembayaran:</p>
          <div class="d-flex flex-wrap justify-content-center gap-3 mb-4">
            <button class="btn btn-outline-secondary method-btn" data-method="QRIS">
              <img src="/assets/logos/qris.png" width="24" class="me-1">QRIS
            </button>
            <button class="btn btn-outline-secondary method-btn" data-method="BNIVA">
              <img src="/assets/logos/bni.png" width="24" class="me-1">BNI Virtual Account
            </button>
            <button class="btn btn-outline-secondary method-btn" data-method="BRIVA">
              <img src="/assets/logos/bri.png" width="24" class="me-1">BRI Virtual Account
            </button>
            <button class="btn btn-outline-secondary method-btn" data-method="MANDIRIVA">
              <img src="/assets/logos/mandiri.png" width="24" class="me-1">Mandiri Virtual Account
            </button>
            <button class="btn btn-outline-secondary method-btn" data-method="PERMATAVA">
              <img src="/assets/logos/permata.png" width="24" class="me-1">Permata Virtual Account
            </button>
            <button class="btn btn-outline-secondary method-btn" data-method="MUAMALATVA">
              <img src="/assets/logos/muamalat.png" width="24" class="me-1">Muamalat Virtual Account
            </button>
            <button class="btn btn-outline-secondary method-btn" data-method="BSIVA">
              <img src="/assets/logos/bsi.png" width="24" class="me-1">BSI Virtual Account
            </button>
          </div>
          <button id="payBtn" class="btn btn-primary w-100" disabled>Bayar Sekarang</button>
        </div>
      `;

      document.querySelectorAll('.method-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          selectedMethod = btn.dataset.method;
          document.getElementById('payBtn').disabled = false;
        });
      });
      document.getElementById('payBtn').addEventListener('click', () => {
        if (!selectedPackage || !selectedMethod) return;
        const form = document.createElement('form');
        form.method = 'POST'; form.action = 'payments/index.php';
        const fields = {
          customer_name: '<?= htmlspecialchars($username, ENT_QUOTES) ?>',
          amount: selectedPackage.price,
          package: selectedPkgKey,  // <-- Fix: langsung pake key, bukan split title!
          method: selectedMethod
        };
        for (let k in fields) {
          const inp = document.createElement('input');
          inp.type='hidden'; inp.name=k; inp.value=fields[k];
          form.appendChild(inp);
        }
        document.body.appendChild(form);
        form.submit();
      });
    }, 500);
  }

  document.querySelectorAll('.select-pkg-btn').forEach(btn => {
    btn.addEventListener('click', () => showOptions(btn.dataset.pkg));
  });
</script>

<link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.css">
<script src="//cdnjs.cloudflare.com/ajax/libs/raphael/2.2.7/raphael.min.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.min.js"></script>
<script>

function addDeviceRow() {
  const wrapper = document.createElement('div');
  wrapper.className = 'input-group mb-1';
  wrapper.innerHTML = `
    <select name="device_type[]" class="form-select" style="max-width:150px">
      <option value="">Pilih Device</option>
      <option value="mobile">Mobile</option>
      <option value="desktop">Desktop</option>
      <option value="tablet">Tablet</option>
    </select>
    <input type="url" name="device_url[]" class="form-control" placeholder="https://example.com/device">
    <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()">×</button>
  `;
  document.getElementById('deviceList').append(wrapper);
}
</script>
<script>
let botStatus = <?= (int)$status ?>; // dari PHP, 0 = off, 1 = on

function updateToggleButton() {
  const btn = document.getElementById('toggleBotBtn');
  const statusSpan = document.getElementById('statusbot');
  if (botStatus === 1) {
    btn.innerHTML = `<i class="fa fa-stop me-1"></i> Matikan Bot Check`;
    btn.className = "btn btn-danger";
    statusSpan.textContent = "ON";
    statusSpan.className = "text-success fw-bold";
  } else {
    btn.innerHTML = `<i class="fa fa-play me-1"></i> Aktifkan Bot Check`;
    btn.className = "btn btn-success";
    statusSpan.textContent = "OFF";
    statusSpan.className = "text-danger fw-bold";
  }
}
// Fungsi untuk toggle status via AJAX
document.getElementById('toggleBotBtn').onclick = function() {
  const btn = this;
  btn.disabled = true;
  fetch('/dashboard/ajax/toggle-bot-status.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'status=' + (botStatus ? 0 : 1)
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      botStatus = botStatus ? 0 : 1;
      updateToggleButton();
      showToast('Bot Pengecekan Status: ' + (botStatus ? '(ACTIVE) | Sebentar lagi bot akan melakukan pengecekan ke telegram!' : '(NONAKTIF) | Bot tidak akan melakukan pengecekan lagi!') + '.');
    } else {
      alert(data.message || "Gagal mengubah status.");
    }
  })
  .catch(() => alert("Gagal koneksi server."))
  .finally(() => btn.disabled = false);
};

// Inisialisasi awal tombol
updateToggleButton();
</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>


<script>
function loadMainDomainList() {
  fetch('/dashboard/ajax/main-domain.php')
    .then(res => res.json())
    .then(data => {
      const tbody = document.getElementById('mainDomainList');
      tbody.innerHTML = '';
      if (data.success && data.domains.length) {
        data.domains.forEach(row => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>
              <span class="main-domain-label">${row.domain}</span>
              <input type="text" class="form-control form-control-sm d-none main-domain-input" value="${row.domain}">
            </td>
            <td>
              <span class="fallback-domain-label">${row.fallback_domain || '-'}</span>
              <input type="text" class="form-control form-control-sm d-none fallback-domain-input" value="${row.fallback_domain || ''}" placeholder="Fallback domain">
            </td>
            <td>${row.created_at}</td>
            <td>
              <button class="btn btn-sm btn-warning me-2" onclick="renameMainDomain(${row.id}, this)">
                <i class="fa fa-edit"></i> Rename
              </button>
              <button class="btn btn-sm btn-danger" onclick="deleteMainDomain(${row.id}, this)">
                <i class="fa fa-trash"></i>
              </button>
              <button class="btn btn-sm btn-success d-none" onclick="saveRenameMainDomain(${row.id}, this)">
                <i class="fa fa-check"></i> Simpan
              </button>
              <button class="btn btn-sm btn-secondary d-none" onclick="cancelRenameMainDomain(this)">
                <i class="fa fa-times"></i> Batal
              </button>
            </td>
          `;
          tbody.appendChild(tr);
        });
      } else {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center">Belum ada main domain.</td></tr>`;
      }
    });
}

// Tambah domain
document.getElementById('addMainDomainForm').onsubmit = function(e) {
  e.preventDefault();
  const domain = document.getElementById('mainDomainInput').value.trim();
  const fallback = document.getElementById('fallbackDomainInput').value.trim();
  const msg = document.getElementById('mainDomainMsg');
  msg.innerHTML = '';
  fetch('/dashboard/ajax/main-domain.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'domain=' + encodeURIComponent(domain) + '&fallback_domain=' + encodeURIComponent(fallback)
  })
  .then(res => res.json())
  .then(data => {
    msg.innerHTML = data.message;
    msg.className = data.success ? 'alert alert-success' : 'alert alert-danger';
    if (data.success) {
      document.getElementById('mainDomainInput').value = '';
      document.getElementById('fallbackDomainInput').value = '';
      loadMainDomainList();
    }
  });
};

// Rename/edit (domain/fallback) UI handler
function renameMainDomain(id, btn) {
  const tr = btn.closest('tr');
  tr.querySelector('.main-domain-label').classList.add('d-none');
  tr.querySelector('.main-domain-input').classList.remove('d-none');
  tr.querySelector('.fallback-domain-label').classList.add('d-none');
  tr.querySelector('.fallback-domain-input').classList.remove('d-none');
  tr.querySelector('.btn-warning').classList.add('d-none');
  tr.querySelector('.btn-danger').classList.add('d-none');
  tr.querySelector('.btn-success').classList.remove('d-none');
  tr.querySelector('.btn-secondary').classList.remove('d-none');
}
function saveRenameMainDomain(id, btn) {
  const tr = btn.closest('tr');
  const domain = tr.querySelector('.main-domain-input').value.trim();
  const fallback_domain = tr.querySelector('.fallback-domain-input').value.trim();
  fetch('/dashboard/ajax/main-domain.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'id=' + id + '&domain=' + encodeURIComponent(domain) + '&fallback_domain=' + encodeURIComponent(fallback_domain) + '&rename=1'
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      loadMainDomainList();
    } else {
      alert(data.message);
    }
  });
}
function cancelRenameMainDomain(btn) {
  loadMainDomainList();
}
function deleteMainDomain(id, btn) {
  if (!confirm('Yakin hapus main domain ini?')) return;
  fetch('/dashboard/ajax/main-domain.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'id=' + id + '&delete=1'
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      loadMainDomainList();
    } else {
      alert(data.message);
    }
  });
}

// Setiap modal main domain dibuka, reload daftar
document.getElementById('mainDomainModal').addEventListener('show.bs.modal', loadMainDomainList);

// ===== Tambahan: fetch domain untuk fitur "Arahkan ke Main Domain" di modal edit =====
async function fetchMainDomains() {
  // Kembalikan array of {id, domain}
  let res = await fetch('/dashboard/ajax/main-domain.php');
  let data = await res.json();
  return data.domains || [];
}


document.getElementById('setToMainDomainBtn').addEventListener('click', async function() {
    // Ambil list main domain
    const mainDomains = await fetch('/dashboard/ajax/main-domain.php').then(r => r.json());
    let options = mainDomains.domains.map(md =>
        `<option value="${md.id}">${md.domain}</option>`
    ).join('');
    let html = `
        <label>Pilih Main Domain:</label>
        <select id="chooseMainDomain" class="form-select mb-3">${options}</select>
        <div class="alert alert-info mb-2">
            Semua Destination & Fallback akan mengarah ke main domain ini.
        </div>
        <button class="btn btn-primary w-100" id="applyMainDomainBtn">Set Main Domain</button>
    `;

    // Modal simple (tanpa plugin)
    let popup = document.createElement('div');
    popup.innerHTML = `
        <div class="modal-backdrop fade show"></div>
        <div class="modal d-block" style="background:rgba(0,0,0,0.2)">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header py-2">
                <strong>Pilih Main Domain</strong>
                <button type="button" class="btn-close" aria-label="Tutup"></button>
              </div>
              <div class="modal-body py-3">${html}</div>
            </div>
          </div>
        </div>
    `;
    document.body.appendChild(popup);

    // Tombol close (pojok kanan atas)
    popup.querySelector('.btn-close').onclick = () => popup.remove();
    // Klik backdrop juga close
    popup.querySelector('.modal-backdrop').onclick = () => popup.remove();

    document.getElementById('applyMainDomainBtn').onclick = function() {
        let mainDomainId = document.getElementById('chooseMainDomain').value;
        let mainDomainText = document.getElementById('chooseMainDomain').selectedOptions[0].text;

        // Ubah semua textarea menjadi hanya path saja
        ['modalUrls','modalFallbacks'].forEach(id => {
            let ta = document.getElementById(id);
            ta.value = ta.value
              .split('\n')
              .map(url => {
                  url = url.trim();
                  if (!url) return '';
                  // Extract path/query/hash dari URL
                  try {
                      let u = new URL(url, 'https://dummy.com'); // fallback jika user cuma nulis path
                      let path = u.pathname + (u.search || '') + (u.hash || '');
                      return path;
                  } catch {
                      return url.replace(/^https?:\/\/[^/]+/i,''); // fallback jika URL manual
                  }
              })
              .filter(Boolean).join('\n');
        });
        // Set hidden input untuk backend
        document.getElementById('modalUseMainDomain').value = '1';
        document.getElementById('modalMainDomainId').value = mainDomainId;

        // Ubah tampilan tombol
        document.getElementById('setToMainDomainBtn').classList.replace('btn-outline-primary','btn-success');
        document.getElementById('setToMainDomainBtn').innerHTML = `<i class="fa fa-link"></i> Terkait ke Main Domain: <b>${mainDomainText}</b>`;
        // Tutup popup
        popup.remove();
    };
});


function showSectionByName(name) {
  sections.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    if (id === name) {
      el.style.display = 'block';
      // Tampilkan loader dulu
      showSectionLoader(true, id);

      // Simulasi delay data (atau tunggu AJAX selesai)
      setTimeout(() => {
        showSectionLoader(false, id);
        // Lazy load data jika ada
        if (sectionLoaders[id]) sectionLoaders[id]();
      }, 400); // <-- delay loader 400ms, bisa diganti sesuai kebutuhan

    } else {
      el.style.display = 'none';
    }
  });
}

function showSectionLoader(show, sectionId) {
  const section = document.getElementById(sectionId);
  if (!section) return;
  let loader = section.querySelector('.section-loader');
  if (!loader) {
    loader = document.createElement('div');
    loader.className = 'section-loader text-center py-5';
    loader.innerHTML = `<div class="spinner-border text-primary" role="status"></div>
    <div class="mt-2">Loading...</div>`;
    section.insertBefore(loader, section.firstChild);
  }
  loader.classList.toggle('d-none', !show);

  // Sembunyikan konten lain kalau loader muncul
  Array.from(section.children).forEach(child => {
    if (child !== loader) child.style.display = show ? 'none' : '';
  });
}



</script>

<script>var chartDaily = null, chartWeekly = null, chartMonthly = null, chartDonut = null, chartReferrer = null;

// Set default tanggal hari ini di filter (cuma waktu awal, bukan reset)
$(function(){
  let today = new Date().toISOString().substr(0,10);
  if (!$('#filter-range').val()) $('#filter-range').val(today);
  if (!$('[name="end"]').val()) $('[name="end"]').val(today);
});

$(function() {
    chartDaily = Morris.Area({
        element: 'statistikDailyChart', data: [], xkey: 'date', ykeys: ['total'], labels: ['Clicks'],
        parseTime: false, lineColors: ['#1921fa'], fillOpacity: 0.2, hideHover: 'auto', resize: true
    });
    chartWeekly = Morris.Area({
        element: 'statistikWeeklyChart', data: [], xkey: 'date', ykeys: ['total'], labels: ['Clicks'],
        parseTime: false, lineColors: ['#10ca93'], fillOpacity: 0.2, hideHover: 'auto', resize: true
    });
    chartMonthly = Morris.Area({
        element: 'statistikMonthlyChart', data: [], xkey: 'date', ykeys: ['total'], labels: ['Clicks'],
        parseTime: false, lineColors: ['#ff5c00'], fillOpacity: 0.2, hideHover: 'auto', resize: true
    });
    chartDonut = Morris.Donut({
        element: 'morris_donut', data: [
            {label:"Desktop", value: 0},{label:"Mobile", value: 0},{label:"Tablet", value: 0},{label:"Unknown", value: 0}
        ], colors: ['#1921fa','#10ca93','#ff5c00','#f2c037'], resize: true
    });

    // --- INISIALISASI DUMMY AGAR LANGSUNG MUNCUL ---
    chartReferrer = Morris.Bar({
        element: 'morris_referrer',
        data: [{day: '', direct: 0}],
        xkey: 'day',
        ykeys: ['direct'],
        labels: ['Direct'],
        stacked: true,
        barColors: ['#1921fa','#10ca93','#ff5c00'],
        hideHover: 'auto',
        resize: true
    });

    loadDashboard(); // Load data awal (all time/default)
});

function renderReferrerTable(refStats) {
    let $refList = $('#referrer-list').empty();
    if(refStats && refStats.length){
        $refList.append('<table class="table table-sm mb-0"><thead><tr><th>#</th><th>Referrer</th><th>Clicks</th></tr></thead><tbody></tbody></table>');
        let $tb = $refList.find('tbody');
        let seen = {};
        let nomor = 1;
        refStats.forEach(function(row){
            if(seen[row.domain]) return;
            seen[row.domain]=1;
            $tb.append(`<tr class="referrer-row" data-index="${nomor-1}" style="${nomor>4 ? 'display:none' : ''}">
                <td>${nomor++}</td>
                <td>${row.domain||'Unknown'}</td>
                <td>${row.clicks}</td>
            </tr>`);
        });

        // Show More/Less logic
        let total = Object.keys(seen).length;
        const $showMore = $('#showMoreReferrer');
        const $showLess = $('#showLessReferrer');
        if (total > 4) {
            const expanded = $showMore.data('expanded') === true;
            if (expanded) {
                $('#referrer-list .referrer-row').show();
                $showMore.hide();
                $showLess.show();
            } else {
                $('#referrer-list .referrer-row').each(function(idx) {
                    if (idx > 3) $(this).hide(); else $(this).show();
                });
                $showMore.show();
                $showLess.hide();
            }
            $showMore.off('click').on('click', function() {
                $('#referrer-list .referrer-row').show();
                $showMore.data('expanded', true).hide();
                $showLess.show();
            });
            $showLess.off('click').on('click', function() {
                $('#referrer-list .referrer-row').each(function(idx) {
                    if (idx > 3) $(this).hide(); else $(this).show();
                });
                $showMore.data('expanded', false).show();
                $showLess.hide();
            });
        } else {
            $showMore.hide();
            $showLess.hide();
        }
    } else {
        $('#referrer-list').html('<center><em class="text-muted">Belum ada data referrer.</em></center>');
        $('#showMoreReferrer').hide();
        $('#showLessReferrer').hide();
    }
}

function formatTanggalIndonesia(dt) {
    const bulan = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    let d = new Date(dt.replace(' ', 'T'));
    return d.getDate() + ' ' + bulan[d.getMonth()] + ' ' + d.getFullYear() + ' ' +
        d.toLocaleTimeString().slice(0,5);
}

function renderTableWithPagination(data, $tbody, $pagination, columns, pageSize = 10) {
    let currentPage = 1;
    let totalPages = Math.ceil(data.length / pageSize);

    function renderPage(page) {
        $tbody.empty();
        let start = (page - 1) * pageSize;
        let end = start + pageSize;
        let totalClicks = data.reduce((sum, row) => sum + parseInt(row.clicks || 0), 0);
        data.slice(start, end).forEach(function(row, i){
            let pct = totalClicks ? ((row.clicks/totalClicks)*100).toFixed(1)+'%' : '0%';
            $tbody.append(
                `<tr>
                    <td>${start + i + 1}</td>
                    <td>${row[columns[0]] || 'Unknown'}</td>
                    <td>${row.clicks}</td>
                    <td>${pct}</td>
                </tr>`
            );
        });
    }

    function renderPagination() {
        $pagination.empty();
        if(totalPages <= 1) return;
        $pagination.append(`<li class="page-item${currentPage == 1 ? ' disabled' : ''}">
            <a class="page-link" href="#" data-page="${currentPage-1}">&laquo;</a>
        </li>`);
        for(let i=1; i<=totalPages; i++) {
            $pagination.append(`<li class="page-item${i === currentPage ? ' active' : ''}">
                <a class="page-link" href="#" data-page="${i}">${i}</a></li>`);
        }
        $pagination.append(`<li class="page-item${currentPage == totalPages ? ' disabled' : ''}">
            <a class="page-link" href="#" data-page="${currentPage+1}">&raquo;</a>
        </li>`);
        $pagination.find('a').off('click').on('click', function(e){
            e.preventDefault();
            let sel = parseInt($(this).data('page'));
            if(sel !== currentPage && sel > 0 && sel <= totalPages) {
                currentPage = sel;
                renderPage(currentPage);
                renderPagination();
            }
        });
    }

    renderPage(currentPage);
    renderPagination();
}

// -- loadDashboard MODIF: gunakan pagination untuk Country dan City --
function loadDashboard(start=null, end=null) {
    $('#loaderSection').removeClass('d-none');
    let params = {};
    if(start) params.start = start;
    if(end) params.end = end;

    $.getJSON('dashboard_backend.php', params, function(res) {
        $('#loaderSection').addClass('d-none');
        if(res.error) return alert(res.error);

        // Update summary
        $('[data-id="totalClicks"]').text(res.totalClicks.toLocaleString());
        $('[data-id="totalLinks"]').text(res.totalLinks.toLocaleString());
        $('[data-id="totalDomains"]').text(res.totalDomains.toLocaleString());
        $('[data-id="todayClicks"]').text(res.todayClicks.toLocaleString());

        // Recent Links
        let $list = $('#recentAjaxDashboard').empty();
        $('#recentCount').text((res.recentLinks ? res.recentLinks.length : 0) + " Links");
        if (res.recentLinks && res.recentLinks.length) {
            res.recentLinks.forEach(function(link){
                $list.append(
                  `<li class="list-group-item p-2">
                    <div class="d-flex justify-content-between copy-link" data-url="${link.domain}/${link.short_code}">
                      <div class="text-truncate" style="max-width: 60%;">
                        <strong>${link.domain}/${link.short_code}</strong><br>
                        <small class="text-muted">Dibuat: ${formatTanggalIndonesia(link.created_at)}</small>
                      </div>
                      <div class="text-end">
                        <span class="badge rounded-pill bg-primary px-3">${link.destTotal||0} URL</span>
                        <span class="badge rounded-pill bg-info px-3">${link.fallbackTotal||0} Fallback</span>
                      </div>
                    </div>
                  </li>`
                );
            });
        } else {
            $list.html(`<li class="list-group-item text-center text-muted">
              <strong>Belum ada link</strong><br>
              <small>Mulai buat shortlink baru 🚀</small>
            </li>`);
        }

        // Device
        $('[data-id="desktopClicks"]').text(res.desktopClicks);
        $('[data-id="mobileClicks"]').text(res.mobileClicks);
        $('[data-id="tabletClicks"]').text(res.tabletClicks);
        $('[data-id="unknownClicks"]').text(res.unknownClicks);

        // COUNTRY PAGINATION
        let $ct = $('#tbl-country tbody');
        let $ctPg = $('#pagination-country');
        renderTableWithPagination(
            res.countryStats || [],
            $ct, $ctPg,
            ['country'],
            10
        );

        // CITY PAGINATION
        let $ci = $('#tbl-city tbody');
        let $ciPg = $('#pagination-city');
        renderTableWithPagination(
            res.cityStats || [],
            $ci, $ciPg,
            ['city'],
            10
        );

        // CHART AREA
        var dailyData = [];
        if (res.chartDates) {
            Object.entries(res.chartDates).forEach(([date, total]) => {
                dailyData.push({date: date, total: total});
            });
        }
        if (chartDaily) chartDaily.setData(dailyData);
        if (chartWeekly && res.chartWeekly) chartWeekly.setData(res.chartWeekly);
        if (chartMonthly && res.chartMonthly) chartMonthly.setData(res.chartMonthly);

        // Donut device chart, anti-double!
        if (chartDonut) {
            chartDonut.setData([
                {label:"Desktop", value: res.desktopClicks},
                {label:"Mobile", value: res.mobileClicks},
                {label:"Tablet", value: res.tabletClicks},
                {label:"Unknown", value: res.unknownClicks}
            ]);
        }

        // Referrer Chart
        if (chartReferrer) {
            var ykeys = res.ykeys && res.ykeys.length ? res.ykeys : ['direct'];
            var labels = ykeys.map(x => x.charAt(0).toUpperCase() + x.slice(1));
            chartReferrer.options.ykeys = ykeys;
            chartReferrer.options.labels = labels;
            chartReferrer.setData(res.chartData && res.chartData.length ? res.chartData : [{day:'', direct:0}]);
            chartReferrer.redraw();
        }

        // Referrer table (no duplicate, unique domain)
renderReferrerTable(res.referrerStats);
    });
}

// ==== FORM FILTER & RESET: ANTI BUG, LANGSUNG KERJA ====

// SUBMIT filter (by tanggal)
$('#dashboardFilterForm').off('submit').on('submit', function(e) {
    e.preventDefault();
    let start = $(this).find('[name="start"]').val();
    let end = $(this).find('[name="end"]').val();
    loadDashboard(start, end); // filter sesuai tanggal
    return false;
});

// RESET filter (tampilkan semua data)
$('#resetFilterBtn').off('click').on('click', function() {
    $('#dashboardFilterForm')[0].reset(); // kosongkan form
    loadDashboard(); // tampilkan semua data
});
</script>


<script>
document.getElementById('toggleFilter').onclick = function() {
  var row = document.getElementById('filterFormRangeRow');
  row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'block' : 'none';
};
</script>

<script>
let recentActivityTimer = null;
let recentActivityLastIds = [];

function getFlag(country) {
  if (!country) return `<span>🌐</span>`;
  let c = String(country).toLowerCase();
  if (c.includes('indo')) return '🇮🇩';
  if (c.includes('cambodia')) return '🇰🇭';
  if (c.includes('malaysia')) return '🇲🇾';
  if (c.includes('singapore')) return '🇸🇬';
  // Tambahin mapping sesuai kebutuhan
  return `<span>🌐</span>`;
}
function getDeviceIcon(device) {
  if (!device) return '<i class="bi bi-device-unknown"></i>';
  device = String(device).toLowerCase();
  if (device.includes('android')) return '<i class="fa-brands fa-android"></i>';
  if (device.includes('iphone') || device.includes('ios')) return '<i class="fa-brands fa-apple"></i>';
  if (device.includes('desktop') || device.includes('windows')) return '<i class="fa-solid fa-desktop"></i>';
  return '<i class="bi bi-phone"></i>';
}
function getBrowserIcon(browser) {
  if (!browser) return '<i class="bi bi-globe"></i>';
  browser = String(browser).toLowerCase();
  if (browser.includes('chrome')) return '<i class="fa-brands fa-chrome"></i>';
  if (browser.includes('firefox')) return '<i class="fa-brands fa-firefox-browser"></i>';
  if (browser.includes('safari')) return '<i class="fa-brands fa-safari"></i>';
  if (browser.includes('edge')) return '<i class="fa-brands fa-edge"></i>';
  if (browser.includes('opera')) return '<i class="fa-brands fa-opera"></i>';
  return '<i class="bi bi-globe"></i>';
}
function timeAgo(dateString) {
  if (!dateString) return '';
  const d = new Date(dateString.replace(' ', 'T'));
  if (isNaN(d)) return '-';
  const now = new Date();
  const diff = Math.floor((now - d) / 1000);
  if (diff < 60) return `${diff} seconds ago`;
  if (diff < 3600) return `${Math.floor(diff/60)} minutes ago`;
  if (diff < 86400) return `${Math.floor(diff/3600)} hours ago`;
  return `${d.getDate()}/${d.getMonth()+1}/${d.getFullYear()}`;
}
function capitalize(txt) {
  if (!txt) return '';
  txt = String(txt);
  return txt.charAt(0).toUpperCase() + txt.slice(1);
}

// LIVE Recent Activity loader (auto-refresh every 3s, no flicker)
function loadRecentActivity(isRepeat = false) {
  const $list = document.getElementById('recentActivityList');
  if (!$list) return;
  if (!isRepeat) {
    $list.innerHTML = `<div class="text-center text-muted py-4">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="mt-2">Waiting activity...</div>
    </div>`;
  }
  fetch('/dashboard/ajax/get-recent-activity.php')
    .then(res => res.json())
    .then(arr => {
      if (!Array.isArray(arr)) throw new Error("Data backend tidak valid!");
      // cek id unique biar gak update terus
      const ids = arr.map(r => ((r.created_at||'') + (r.short_code||'')));
      if (JSON.stringify(ids) === JSON.stringify(recentActivityLastIds)) return;
      recentActivityLastIds = ids;
      if (!arr.length) {
        $list.innerHTML = `<div class="alert alert-secondary text-center mb-0 py-4">Tidak ada aktivitas terbaru.</div>`;
        $('#showMoreActivity').hide();
        $('#showLessActivity').hide();
        return;
      }
      // *** Tampilkan 5 data dulu ***
      $list.innerHTML = arr.map((row, i) => {
        // Antisipasi data undefined/null
        const city = row.city ?? '';
        const country = row.country ?? '';
        const device = row.device ?? '';
        const browser = row.browser ?? '';
        const domain = row.domain ?? '-';
        const short_code = row.short_code ?? '';
        const created_at = row.created_at ?? '';
        const referrer = row.referrer ?? '';

        const loc = `${city ? city + ', ' : ''}${country}`;
        let refText = 'Direct, email or others';
        let refIcon = `<i class="bi bi-globe"></i>`;
        if (referrer && referrer.match(/^https?:\/\//)) {
          let shortRef = referrer.replace(/^https?:\/\//, '');
          refText = `<a href="${referrer}" target="_blank" class="text-info text-decoration-underline">${shortRef}</a>`;
          refIcon = `<i class="bi bi-link-45deg"></i>`;
        }
        return `
         <div class="activity-item card mb-2 border-0 shadow-sm" data-index="${i}" style="${i > 4 ? 'display:none' : ''}">
            <div class="card-body py-3 px-3 text-dark">
              <div class="d-flex flex-wrap align-items-center mb-2 gap-2">
                <span>${getFlag(country)}</span>
                <span class="fw-bold">${loc}</span>
                <span class="mx-2">${getDeviceIcon(device)}</span>
                <span>${capitalize(device)}</span>
                <span class="mx-2">${getBrowserIcon(browser)}</span>
                <span>${capitalize(browser)}</span>
              </div>
              <div class="d-flex flex-wrap align-items-center mb-2 gap-2">
                <i class="fa-solid fa-link"></i>
                <span class="fw-bold">${domain}/${short_code}</span>
                <span class="mx-2">${refIcon}</span>
                <span>${refText}</span>
              </div>
              <div class="text-muted small"><i class="fa-regular fa-clock"></i> ${timeAgo(created_at)}</div>
            </div>
          </div>
        `;
      }).join('');

      // Show More/Less logic
      setTimeout(function () {
        const total = arr.length;
        const $showMore = $('#showMoreActivity');
        const $showLess = $('#showLessActivity');
        if (total > 5) {
          // cek jika sedang view more, tampilkan/biarkan semua item tampil
          const expanded = $showMore.data('expanded') === true;
          if (expanded) {
            $('#recentActivityList .activity-item').show();
            $showMore.hide();
            $showLess.show();
          } else {
            $('#recentActivityList .activity-item').each(function(idx) {
              if (idx > 4) $(this).hide(); else $(this).show();
            });
            $showMore.show();
            $showLess.hide();
          }
        } else {
          $showMore.hide();
          $showLess.hide();
        }

        // Handler
        $showMore.off('click').on('click', function() {
          $('#recentActivityList .activity-item').show();
          $showMore.data('expanded', true).hide();
          $showLess.show();
        });
        $showLess.off('click').on('click', function() {
          $('#recentActivityList .activity-item').each(function(idx) {
            if(idx > 4) $(this).hide(); else $(this).show();
          });
          $showMore.data('expanded', false).show();
          $showLess.hide();
        });
      }, 10);
    })
    .catch(err => {
      $list.innerHTML = `<div class="alert alert-danger text-center">Gagal load activity.<br><code>${err && err.message ? err.message : err}</code></div>`;
      $('#showMoreActivity').hide();
      $('#showLessActivity').hide();
      console.error('GAGAL LOAD:', err);
    })
    .finally(() => {
      if (recentActivityTimer) clearTimeout(recentActivityTimer);
      recentActivityTimer = setTimeout(() => loadRecentActivity(true), 3000);
    });
}

document.addEventListener('DOMContentLoaded', function () {
  loadRecentActivity();
});
</script>



<script>
// === Helper Notifikasi Inline ===
function showDomainResult(msg, type='success') {
  $('#domainAddResult').html(`<div class="alert alert-${type}">${msg}</div>`);
  setTimeout(()=>$('#domainAddResult').html(''), 6000);
}

// === AUTO CEK & SET SSL SEMUA DOMAIN/SUBDOMAIN SAAT HALAMAN DIBUKA ===
function autoSetAllSSL() {
  $('#autoSSLProgress').html('<div class="alert alert-info text-center">Sedang memeriksa domain & subdomain yang belum SSL...</div>');
  $.post('ajax/domain_cf_backend.php', {action:'get_list'}, function(res){
    if (!res.success || !res.list.length) {
      $('#autoSSLProgress').html('<div class="alert alert-warning text-center">Tidak ada domain terdaftar.</div>');
      setTimeout(()=>$('#autoSSLProgress').html(''), 3000); // <-- otomatis hilang
      return;
    }
    let tasks = [];
    res.list.forEach(function(d){
      if (d.is_ssl_enabled != 1 && d.status == 'active') {
        tasks.push({ domain: d.domain, type: 'Domain' });
      }
      if (d.subdomains && d.subdomains.length) {
        d.subdomains.forEach(function(sub){
          let subName = typeof sub === 'object' ? sub.name : sub;
          let subSsl = typeof sub === 'object' ? sub.is_ssl_enabled : 0;
          if (subSsl != 1) {
            tasks.push({ domain: subName, type: 'Subdomain' });
          }
        });
      }
    });

    if (tasks.length === 0) {
      $('#autoSSLProgress').html('<div class="alert alert-success text-center">Semua domain & subdomain sudah SSL.</div>');
      setTimeout(()=>$('#autoSSLProgress').html(''), 3000); // <-- otomatis hilang
      return;
    }

    let html = `<div class="alert alert-info text-center">Total <b>${tasks.length}</b> domain/subdomain akan di-set SSL:<ul id="autoSSLList"></ul></div>`;
    $('#autoSSLProgress').html(html);
    let $list = $('#autoSSLList');
    tasks.forEach((task, idx) => {
      $list.append(`<li id="autoSSLItem${idx}">[${task.type}] ${task.domain} <span class="badge bg-secondary">pending</span></li>`);
    });

    function runSSL(idx) {
      if (idx >= tasks.length) {
        $('#autoSSLProgress').append('<div class="alert alert-success mt-2  text-center">Selesai! Semua SSL sudah diproses.</div>');
        setTimeout(()=>$('#autoSSLProgress').html(''), 3000); // <-- otomatis hilang
        refreshDomainList();
        return;
      }
      let t = tasks[idx];
      $(`#autoSSLItem${idx} .badge`).removeClass('bg-secondary bg-success bg-danger').addClass('bg-warning').text('processing...');
      $.post('ajax/set_ssl.php', { domain: t.domain }, function(sslRes){
        if(sslRes && sslRes.success){
          $(`#autoSSLItem${idx} .badge`).removeClass('bg-warning').addClass('bg-success').text('OK');
        } else {
          $(`#autoSSLItem${idx} .badge`).removeClass('bg-warning').addClass('bg-danger').text('gagal');
        }
        setTimeout(()=>runSSL(idx+1), 800);
      },'json').fail(function(){
        $(`#autoSSLItem${idx} .badge`).removeClass('bg-warning').addClass('bg-danger').text('error');
        setTimeout(()=>runSSL(idx+1), 800);
      });
    }
    runSSL(0);
  },'json');
}

// === TAMPIL LIST DOMAIN (dengan subdo child, Add Subdo aktif jika sudah set URL, toggle Aktif/Nonaktif Domut, tombol Set SSL) ===
function refreshDomainList() {
  $('#domainCfTable tbody').html(
    `<tr>
      <td colspan="6" class="text-center text-secondary py-4">
        <div class="d-flex flex-column align-items-center">
          <div class="spinner-border text-primary mb-2" role="status" style="width:2.5rem;height:2.5rem;">
            <span class="visually-hidden">Loading...</span>
          </div>
          <div>Memuat data domain...</div>
        </div>
      </td>
    </tr>`
  );
  $.post('ajax/domain_cf_backend.php', {action:'get_list'}, function(res){
    if (!res.success || !res.list.length) {
      $('#domainCfTable tbody').html('<tr><td colspan="6" class="text-center text-danger">Belum ada domain.</td></tr>');
      return;
    }
    let html = '';
    res.list.forEach(function(d){
      let statusBadge = (d.status=='active') ?
        '<span class="badge bg-success">active</span>' :
        (d.status=='pending' ? '<span class="badge bg-warning text-dark">pending</span>' :
        `<span class="badge bg-secondary">${d.status}</span>`);

      // Tombol set URL
      let setUrlBtn = '';
      if (parseInt(d.proxy_set) === 1) {
        setUrlBtn = `<button class="btn btn-outline-danger btn-sm mb-2" onclick="deleteProxy(${d.id}, '${d.domain}')">Delete URL Proxy</button>`;
      } else if (d.status === 'active') {
        setUrlBtn = `<button class="btn btn-info btn-sm mb-2" onclick="showProxyModal({id: ${d.id}, domain: '${d.domain}'})">Set URL</button>`;
      } else {
        setUrlBtn = `<button class="btn btn-info btn-sm mb-2" onclick="alert('Domain Anda belum active di Cloudflare! Silakan tunggu sampai status ACTIVE, lalu set URL nya.')" title="Harus ACTIVE dulu" disabled>Set URL</button>`;
      }

      // Add Subdo button, aktif kalau sudah set URL
      let addSubdoBtn = '';
      if (parseInt(d.proxy_set) === 1) {
        addSubdoBtn = `<button class="btn btn-success btn-sm mb-2" onclick="showAddSubdoModal(${d.id}, '${d.domain}')">Add Subdo</button>`;
      } else {
        addSubdoBtn = `<button class="btn btn-success btn-sm mb-2" disabled title="Set URL dulu!">Add Subdo</button>`;
      }

      // Tombol Toggle Aktif/Nonaktif Domain Utama (Matikan Domut duluan diurutkan paling atas)
      let mainDomainBtn = '';
      if (d.is_main_domain_active) {
        // Jika sudah aktif, tampilkan Matikan Domut (secondary)
        mainDomainBtn = `<button class="btn btn-secondary btn-sm mb-2" onclick="toggleMainDomain(${d.id},'${d.domain}', false)">Matikan Domut</button>`;
      } else {
        // Jika belum aktif, tampilkan Aktifkan Domut (success)
        mainDomainBtn = `<button class="btn btn-success btn-sm mb-2" onclick="toggleMainDomain(${d.id},'${d.domain}', true)">Aktifkan Domut</button>`;
      }

      // Tombol SSL per domain
      let setSslBtn = '';
      if (d.is_ssl_enabled == 1) {
        setSslBtn = `<button class="btn btn-success btn-sm mb-2" disabled>SSL Enabled</button>`;
      } else {
        setSslBtn = `<button class="btn btn-outline-primary btn-sm mb-2" onclick="setSSL('${d.domain}')">Set SSL</button>`;
      }

      html += `<tr>
        <td>${d.id}</td>
        <td>${d.domain}</td>
        <td width="10px">${statusBadge}</td>
        <td>${d.ns1 || '-'}</td>
        <td>${d.ns2 || '-'}</td>
        <td style="min-width:250px;">
          ${mainDomainBtn}
          <button class="btn btn-danger btn-sm mb-2" onclick="deleteDomainCf(${d.id})">Delete</button>
          ${(d.status=='pending') ? `<button class="btn btn-warning btn-sm mb-2" onclick="refreshStatus(${d.id})">Refresh</button>` : ''}
          ${setUrlBtn}
          ${setSslBtn}

        </td>
      </tr>`;

      // --- Tampilkan subdomain child (jika ada)
      if (d.subdomains && d.subdomains.length) {
        d.subdomains.forEach(function(sub){
          // Support object {name,is_ssl_enabled}
          let subName = typeof sub === 'object' ? sub.name : sub;
          let subSsl = typeof sub === 'object' ? sub.is_ssl_enabled : 0;
          let subSslBtn = '';
          if (subSsl == 1) {
            subSslBtn = `<button class="btn btn-success btn-sm mb-2" disabled>SSL Enabled</button>`;
          } else {
            subSslBtn = `<button class="btn btn-primary btn-sm mb-2" onclick="setSSL('${subName}', this)">Set SSL</button>`;
          }

          html += `<tr class="table-light">
            <td></td>
            <td class="ps-4">
              <i class="fa fa-level-up-alt fa-rotate-90 me-1 text-primary"></i>
              <b>${subName}</b>
            </td>
            <td colspan="3" class="text-muted">Subdomain</td>
            <td>
              <button class="btn btn-outline-danger btn-sm mb-2" onclick="deleteSubdo('${subName}', ${d.id})">Delete Subdo</button>
              ${subSslBtn}
            </td>
          </tr>`;
        });
      }
    });
    $('#domainCfTable tbody').html(html);
  },'json');
}

// Handler Set SSL (panggil ke AJAX/progress)
function setSSL(domain, btn) {
  if (!confirm('Set SSL untuk: ' + domain + ' ?')) return;
  $(btn).prop('disabled', true).text('Processing...');
  $.post('ajax/set_ssl.php', { domain: domain }, function(res){
    if(res && typeof res === 'object'){
      showToast(res.msg || (res.success ? 'SSL request berhasil' : 'SSL request gagal'), res.success ? 'success' : 'danger');
      if(res.success){
        $(btn)
          .removeClass('btn-primary btn-outline-primary')
          .addClass('btn-success')
          .prop('disabled', true)
          .text('SSL Enabled');
      } else {
        $(btn).prop('disabled', false).text('Set SSL');
      }
    } else {
      showToast('Response error/tidak valid!', 'danger');
      $(btn).prop('disabled', false).text('Set SSL');
    }
  }, 'json')
  .fail(function(xhr) {
    showToast('Request gagal: ' + (xhr.responseText || xhr.statusText), 'danger');
    $(btn).prop('disabled', false).text('Set SSL');
  });
}

// === PANGGIL SAAT HALAMAN DOMAIN DIBUKA ===
document.addEventListener('DOMContentLoaded', function(){
  if (document.getElementById('domainCfSection')) {
    autoSetAllSSL();
    refreshDomainList();
  }
});


// === Toggle Matikan/Aktifkan Domain Utama ===
function toggleMainDomain(domain_id, domain, enable) {
  let txt = enable ? 'Aktifkan Domain Utama Untuk Diakses' : 'Matikan Domain Utama Untuk Diakses';
  if (!confirm(`${txt}  ${domain}?`)) return;
  $.post('ajax/domain_cf_backend.php', {
    action: 'toggle_main_domain',
    domain_id: domain_id,
    enable: enable ? 1 : 0
  }, function(res){
    showDomainResult(res.msg, res.success ? 'success' : 'danger');
    if (res.success) refreshDomainList();
  }, 'json');
}


// === SUBMIT TAMBAH DOMAIN ===
$('#formAddDomain').on('submit', function(e){
  e.preventDefault();
  let domain = $('#inputDomain').val().trim();
  if (!domain) return showDomainResult('Domain tidak boleh kosong!', 'danger');
  $('#formAddDomain button[type=submit]').prop('disabled', true).text('Memproses...');
  $.post('ajax/domain_cf_backend.php', {
    action: 'add_domain',
    domain: domain
  }, function(res){
    $('#formAddDomain button[type=submit]').prop('disabled', false).text('Tambah Domain');
    if (res.success) {
      showDomainResult(res.msg + '<br><span id="autoSSLMsg"></span>', 'success');
      $('#formAddDomain')[0].reset();
      refreshDomainList();

      // --- AUTO SET SSL jika status sudah ACTIVE ---
      // Kamu bisa juga cek field res.status kalau backend return field itu
      let domainName = domain;
      // Cek status ACTIVE dari pesan backend
      if (res.msg && res.msg.toLowerCase().includes('active')) {
        $('#autoSSLMsg').html('Request SSL otomatis...');
        $.post('ajax/set_ssl.php', { domain: domainName }, function(sslRes){
          let sslMsg = sslRes && sslRes.success
            ? '<span class="text-success">SSL berhasil diaktifkan!</span>'
            : `<span class="text-danger">SSL gagal: ${(sslRes && sslRes.msg) || 'Gagal request SSL'}</span>`;
          $('#autoSSLMsg').html(sslMsg);
          refreshDomainList();
        }, 'json').fail(function(){
          $('#autoSSLMsg').html('<span class="text-danger">SSL gagal: error server.</span>');
        });
      } else {
        $('#autoSSLMsg').html('<span class="text-warning">Status belum ACTIVE, SSL akan otomatis bisa di-set setelah domain aktif.</span>');
      }
    } else {
      showDomainResult(res.msg, 'danger');
    }
  },'json').fail(function(){
    showDomainResult('Terjadi error pada server!', 'danger');
    $('#formAddDomain button[type=submit]').prop('disabled', false).text('Tambah Domain');
  });
});




function deleteSubdo(subdomain, domain_id) {
  if(!confirm('Yakin hapus subdomain: ' + subdomain + ' ?')) return;
  $.post('ajax/domain_cf_backend.php', {
    action: 'delete_subdo',
    subdomain: subdomain,
    domain_id: domain_id
  }, function(res){
    showDomainResult(res.msg, res.success?'success':'danger');
    if(res.success) refreshDomainList();
  },'json');
}


// === DELETE DOMAIN ===
function deleteDomainCf(id) {
  if(!confirm('Yakin hapus domain ini ?')) return;
  $.post('ajax/domain_cf_backend.php', {action:'delete_domain', id:id}, function(res){
    showDomainResult(res.msg, res.success?'success':'danger');
    if(res.success) refreshDomainList();
  },'json');
}

// === DELETE PROXY/URL ===
function deleteProxy(id, domain) {
  if(!confirm('Yakin hapus URL/proxy untuk domain: ' + domain + '?')) return;
  $.post('ajax/domain_cf_backend.php', {
    action: 'delete_proxy',
    id: id,
    domain: domain
  })
  .done(function(res) {
    if (typeof res === 'string') {
      try { res = JSON.parse(res); } catch(e) { res = {}; }
    }
    if(res && res.success) {
      showDomainResult(res.msg, 'success');
      refreshDomainList();
    } else {
      showDomainResult(res?.msg || 'Gagal menghapus proxy', 'danger');
    }
  })
  .fail(function(xhr, status, error) {
    showDomainResult('Error: ' + (xhr.responseJSON?.msg || error), 'danger');
    console.error('Delete proxy error:', status, error);
  });
}

// === REFRESH STATUS ===
function refreshStatus(id) {
  $.post('ajax/domain_cf_backend.php', {action:'refresh_status', id:id}, function(res){
    showDomainResult(res.msg + '<br><span id="autoSSLMsgRefresh"></span>', res.success ? 'success' : 'danger');
    if(res.success) {
      refreshDomainList();
      // === AUTO REQUEST SSL jika status ACTIVE ===
      // Bisa cek dari msg atau field res.status (pakai yang paling mudah di backendmu)
      let match = /domain <b>([\w\.\-]+)<\/b>/i.exec(res.msg);
      let domainName = match ? match[1] : '';
      // Cek apakah status ACTIVE
      if (
        (res.msg && res.msg.toLowerCase().includes('active')) &&
        domainName
      ) {
        $('#autoSSLMsgRefresh').html('Request SSL otomatis...');
        $.post('ajax/set_ssl.php', { domain: domainName }, function(sslRes){
          let sslMsg = sslRes && sslRes.success
            ? '<span class="text-success">SSL berhasil diaktifkan!</span>'
            : `<span class="text-danger">SSL gagal: ${(sslRes && sslRes.msg) || 'Gagal request SSL'}</span>`;
          $('#autoSSLMsgRefresh').html(sslMsg);
          refreshDomainList();
        }, 'json').fail(function(){
          $('#autoSSLMsgRefresh').html('<span class="text-danger">SSL gagal: error server.</span>');
        });
      }
    }
  },'json');
}
// === MODAL ADD SUBDO ===
let addSubdoModal;
function showAddSubdoModal(domain_id, domain) {
  $('#addSubdoDomain').text(domain);
  $('#addSubdoDomainId').val(domain_id);
  $('#inputSubdo').val('');
  $('#addSubdoMsg').html('');
  if (!addSubdoModal) addSubdoModal = new bootstrap.Modal(document.getElementById('addSubdoModal'));
  addSubdoModal.show();
}

// === SUBMIT ADD SUBDO ===
document.addEventListener('DOMContentLoaded', function(){
  // Add Subdo
 $('#formAddSubdo').on('submit', function(e){
  e.preventDefault();
  let domain_id = $('#addSubdoDomainId').val();
  let subdo = $('#inputSubdo').val().trim();
  let btnSubmit = $('#formAddSubdo button[type=submit]');
  let parentDomain = $('#addSubdoDomain').text().trim();
  let subDomainName = subdo + '.' + parentDomain;

  if(!subdo) {
    $('#addSubdoMsg').html('<div class="alert alert-danger">Subdomain tidak boleh kosong!</div>');
    return;
  }

  btnSubmit.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processing...');

  // 1. Tambah subdo
  $.post('ajax/domain_cf_backend.php', {
    action: 'add_subdo',
    domain_id: domain_id,
    subdo: subdo
  }, function(res){
    if(res.success){
      $('#addSubdoMsg').html(`<div class="alert alert-success mb-2">${res.msg}<br>Auto request SSL...</div>`);

      // 2. Request SSL
      $.post('ajax/set_ssl.php', { domain: subDomainName }, function(sslRes){
        let sslMsg = sslRes && sslRes.success
          ? '<span class="text-success">SSL berhasil diaktifkan!</span>'
          : `<span class="text-danger">SSL gagal: ${(sslRes && sslRes.msg) || 'Gagal request SSL'}</span>`;
        $('#addSubdoMsg').append('<br>' + sslMsg);

        if(sslRes && sslRes.success){
          // 3. Ambil config proxy domain utama
          $('#addSubdoMsg').append('<br>Salin config proxy utama...');
          $.post('ajax/domain_cf_backend.php', {
            action: 'get_config',
            domain: parentDomain,
            is_sub: 0
          }, function(cfgRes){
            if(cfgRes.success && cfgRes.config){
              // 4. Generate config baru jika mau modif, atau pakai as-is
              let oldConfig = cfgRes.config;
              // (opsional) modif config untuk subdo
              let newConfig = oldConfig.replaceAll(parentDomain, subDomainName); // jika ingin ganti semua parentDomain jadi subDomainName

              // 5. Simpan ke subdo
              $.post('ajax/domain_cf_backend.php', {
                action: 'save_config',
                domain: subDomainName,
                is_sub: 1,
                config: newConfig
              }, function(saveRes){
                let copyMsg = saveRes.success
                  ? '<span class="text-success">Config proxy berhasil dicopy & diupdate ke subdo!</span>'
                  : '<span class="text-danger">Gagal update config proxy ke subdo.</span>';
                $('#addSubdoMsg').append('<br>' + copyMsg);
                btnSubmit.prop('disabled', false).html('Add Subdo');
                setTimeout(() => {
                  addSubdoModal.hide();
                  refreshDomainList();
                }, 1800);
              },'json');
            } else {
              $('#addSubdoMsg').append('<br><span class="text-danger">Gagal mengambil config proxy domain utama.</span>');
              btnSubmit.prop('disabled', false).html('Add Subdo');
              setTimeout(() => {
                addSubdoModal.hide();
                refreshDomainList();
              }, 1600);
            }
          },'json');
        } else {
          btnSubmit.prop('disabled', false).html('Add Subdo');
          setTimeout(() => {
            addSubdoModal.hide();
            refreshDomainList();
          }, 1600);
        }
      }, 'json').fail(function(){
        $('#addSubdoMsg').append('<br><span class="text-danger">SSL gagal: error server.</span>');
        btnSubmit.prop('disabled', false).html('Add Subdo');
        setTimeout(() => {
          addSubdoModal.hide();
          refreshDomainList();
        }, 1600);
      });

    } else {
      btnSubmit.prop('disabled', false).html('Add Subdo');
      $('#addSubdoMsg').html('<div class="alert alert-danger">'+res.msg+'</div>');
    }
  },'json').fail(function(){
    btnSubmit.prop('disabled', false).html('Add Subdo');
    $('#addSubdoMsg').html('<div class="alert alert-danger">Terjadi error pada server!</div>');
  });
});



  // === PROXY MODAL & FORM ===
  if (document.getElementById('proxyModal')) {
    proxyModal = new bootstrap.Modal(document.getElementById('proxyModal'));
  }

  // --- AUTO ISI DEFAULT 'http://' pada Target URL ---
  const inputTargetUrl = document.getElementById('inputTargetUrl');
  if (inputTargetUrl) {
    inputTargetUrl.addEventListener('focus', function(){
      if (this.value === '') this.value = 'http://';
    });
    inputTargetUrl.addEventListener('blur', function(){
      if (this.value.trim() === '') this.value = 'http://';
    });
  }

  // === SUBMIT PROXY FORM ===
if(document.getElementById('proxyForm')) {
  document.getElementById('proxyForm').onsubmit = function(e){
    e.preventDefault();
    // PATCH: Ambil tombol submit
    let submitBtn = this.querySelector('button[type=submit]');
    submitBtn.disabled = true;
    let oldBtnText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Processing...';

    let form = e.target;
    let formData = new FormData(form);
    formData.append('action', 'add_proxy');
    fetch('ajax/domain_cf_backend.php', {
      method: 'POST',
      body: formData
    }).then(r=>r.json()).then(j=>{
      document.getElementById('proxyFormMsg').innerHTML = `<div class="alert alert-${j.success?'success':'danger'}">${j.msg}</div>`;
      if (j.success) {
        // === PATCH AUTO SET CUSTOM CONFIG (sama kayak jawaban sebelumnya) ===
        let targetUrl = document.getElementById('inputTargetUrl').value.trim();
        let domain = document.getElementById('proxyDomainLabel').textContent.trim();
        let tujuan, scheme = 'https';
        try {
          let u = new URL(targetUrl);
          tujuan = u.hostname;
          scheme = u.protocol.replace(':','');
        } catch(e) {
          tujuan = targetUrl.replace(/^https?:\/\//,'').split('/')[0];
          if (targetUrl.startsWith('http://')) scheme = 'http';
        }
        fetch('ajax/domain_cf_backend.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: `action=get_config&domain=${encodeURIComponent(domain)}`
        })
        .then(resp => resp.json())
        .then(cfg => {
          let customConfig = generateCustomProxyConfig(tujuan, scheme);
          fetch('ajax/domain_cf_backend.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=save_config&domain=${encodeURIComponent(domain)}&config=${encodeURIComponent(customConfig)}`
          })
          .then(r => r.json())
          .then(saveres => {
            if(saveres.success){
              showDomainResult('URL berhasil di-set & config sudah diganti!', 'success');
            } else {
              showDomainResult('OK, tapi ganti config gagal!<br>'+ (saveres.message||''), 'danger');
            }
            setTimeout(()=>{
              proxyModal.hide();
              refreshDomainList();
              // Kembalikan tombol
              submitBtn.disabled = false;
              submitBtn.innerHTML = oldBtnText;
            }, 1800);
          });
        });
      } else {
        setTimeout(()=>{
          proxyModal.hide();
          refreshDomainList();
          submitBtn.disabled = false;
          submitBtn.innerHTML = oldBtnText;
        }, 1200);
      }
    }).catch(()=>{
      submitBtn.disabled = false;
      submitBtn.innerHTML = oldBtnText;
    });
  };
}

  // === AKTIFKAN LIST SAAT SECTION ===
  if (document.getElementById('domainCfSection')) {
    refreshDomainList();
  }
});
function generateCustomProxyConfig(targetDomain, scheme='https') {
  return `#PROXY-START/

location /
{
    proxy_pass ${scheme}://${targetDomain}/;
    proxy_set_header Host ${targetDomain};
    proxy_set_header Referer $scheme://${targetDomain}$request_uri;
    proxy_set_header User-Agent $http_user_agent;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Accept-Encoding "";

    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_http_version 1.1;
    proxy_ssl_server_name on;

    add_header X-Cache $upstream_cache_status;

    # Optional: Remove Cache (usually safer)
    add_header Cache-Control no-cache;

    #Set Nginx Cache

    set $static_fileg6eC9Q1d 0;
    if ( $uri ~* "\\.(gif|png|jpg|css|js|woff|woff2)$" )
    {
      set $static_fileg6eC9Q1d 1;
      expires 1m;
    }
    if ( $static_fileg6eC9Q1d = 0 )
    {
      add_header Cache-Control no-cache;
    }
}

#PROXY-END/`;
}

// === SHOW PROXY MODAL (SET URL) ===
function showProxyModal({id, domain}) {
  document.getElementById('proxy_domain_id').value = id;
  document.querySelector('#proxyForm [name="proxy_name"]').value = '';
  document.querySelector('#proxyForm [name="proxy_dir"]').value = '/';
  let inputTarget = document.getElementById('inputTargetUrl');
  if (inputTarget) inputTarget.value = 'https://';
  document.querySelector('#proxyForm [name="replace_from"]').value = '';
  document.querySelector('#proxyForm [name="replace_to"]').value = '';
  document.getElementById('proxyDomainLabel').textContent = domain;
  document.getElementById('proxyFormMsg').innerHTML = '';
  proxyModal.show();
}


let lastConfigDomain = '', lastConfigIsSub = false;

function editConfigModal(domain, isSub) {
  lastConfigDomain = domain;
  lastConfigIsSub = isSub;
  $('#editConfigDomain').text(domain);
  $('#editConfigTextarea').val('Loading...');
  $('#editConfigNotif').text('');
  $('#modalEditConfig').modal('show');

  // Ambil config via AJAX
  $.post('ajax/domain_cf_backend.php', {
    action: 'get_config',
    domain: domain,
    is_sub: isSub ? 1 : 0
  }, function(res) {
    if (res.success && res.config) {
      $('#editConfigTextarea').val(res.config);
    } else {
      $('#editConfigTextarea').val('');
      $('#editConfigNotif').text('Gagal mengambil config: ' + (res.message || 'Unknown error'));
    }
  }, 'json');
}

// Save Config
$('#btnSaveConfig').click(function() {
  let config = $('#editConfigTextarea').val();
  $('#editConfigNotif').removeClass('text-success').addClass('text-danger').text('Menyimpan...');
  $.post('ajax/domain_cf_backend.php', {
    action: 'save_config',
    domain: lastConfigDomain,
    is_sub: lastConfigIsSub ? 1 : 0,
    config: config
  }, function(res) {
    if (res.success) {
      $('#editConfigNotif').removeClass('text-danger').addClass('text-success').text('Config berhasil disimpan!');
      setTimeout(()=>$('#modalEditConfig').modal('hide'), 900);
    } else {
      $('#editConfigNotif').removeClass('text-success').addClass('text-danger').text('Gagal menyimpan: ' + (res.message || 'Unknown error'));
    }
  }, 'json');
});


function showToast(msg, type='info') {
  var $toast = $('#mainToast');
  var $body = $('#mainToastBody');
  // Ganti warna sesuai type
  $toast.removeClass('bg-success bg-danger bg-warning bg-info bg-primary');
  if (type === 'success') $toast.addClass('bg-success');
  else if (type === 'danger' || type === 'error') $toast.addClass('bg-danger');
  else if (type === 'warning') $toast.addClass('bg-warning text-dark');
  else if (type === 'info') $toast.addClass('bg-info');
  else $toast.addClass('bg-primary');
  $body.html(msg);
  var bsToast = bootstrap.Toast.getOrCreateInstance($toast[0]);
  bsToast.show();
}


// === MODAL BUAT SHORTLINK SUBDOMAIN ACAK ===
function generateShortlinkSubdoModal(domainDefault = '', subdoDefault = '') {
  // ⬇️ TAMPILKAN LOADER!
  $('#modalShortSubdoLoader').show();

  // Ambil list domain base shortlink (table: domains)
  $.post('ajax/domain_cf_backend.php', {action:'get_shortlink_domains'}, function(res){
    if(!res.success || !res.list.length) {
      $('#modalShortSubdoLoader').hide();
      showToast('Gagal load domain shortlink!', 'danger');
      return;
    }
    let $baseSel = $('#selectBaseDomainShortSubdo').empty();
    $baseSel.append('<option value="">- Pilih domain shortlink -</option>');
    res.list.forEach(function(d){
      $baseSel.append(`<option value="${d.domain}"${d.domain==domainDefault?' selected':''}>${d.domain}</option>`);
    });

    // Ambil list domain subdo target (table: domains_cf/user)
    $.post('ajax/domain_cf_backend.php', {action:'get_list'}, function(res2){
      let $subdoSel = $('#selectDomainShortSubdo').empty();
      $subdoSel.append('<option value="">- Pilih domain subdo target -</option>');
      if (res2.success && res2.list.length) {
        res2.list.forEach(function(d){
          $subdoSel.append(`<option value="${d.domain}"${d.domain==subdoDefault?' selected':''}>${d.domain}</option>`);
        });
      }
      $('#inputShortCodeSubdo').val('');
      $('#shortSubdoMsg').html('');
      // HIDE LOADER & show modal
      $('#modalShortSubdoLoader').hide();
      $('#generateShortlinkSubdoModal').modal('show');
      // ⬇️ Panggil list shortlink subdo (langsung tampil saat modal dibuka)
      loadShortSubdoList();
    },'json').fail(function(){
      $('#modalShortSubdoLoader').hide();
      showToast('Gagal load domain subdo!', 'danger');
    });
  },'json').fail(function(){
    $('#modalShortSubdoLoader').hide();
    showToast('Gagal konek ke server!', 'danger');
  });
}
// On submit modal
$('#formShortlinkSubdo').on('submit', function(e){
  e.preventDefault();
  let basedomain = $('#selectBaseDomainShortSubdo').val();
  let subdodom   = $('#selectDomainShortSubdo').val();
  let short      = $('#inputShortCodeSubdo').val().replace(/[^a-zA-Z0-9_-]/g, '').toLowerCase();
let referal = $('#inputReferalPath').val()
  .replace(/[^a-zA-Z0-9_\-\/\?\=\&\%\.\,]/g,'')
  .replace(/^\/+/,'')
  .replace(/\/+$/,'');

  if(!basedomain || !subdodom || !short) {
    $('#shortSubdoMsg').html('<div class="alert alert-danger">Pilih domain shortlink, domain subdo target & isikan endpoint!</div>');
    return;
  }
  // Kirim ke backend
  $.post('ajax/domain_cf_backend.php', {
    action: 'add_shortlink_subdo',
    basedomain: basedomain,
    subdodom: subdodom,
    shortcode: short,
    referal: referal     // <-- ini tambahan referal/path
  }, function(res){
    // Tampilkan hasil seperti biasa
    let ref = referal ? '/' + referal : '';
    let url = window.location.origin.replace(/\/\/.*?\//, '//'+basedomain+'/') + short;
    $('#shortSubdoMsg').html(
      res.success
      ? `<div class="alert alert-success">
          Shortlink <b>/${short}</b> aktif di <b>${basedomain}</b>!<br>
          Setiap akses <b>https://${basedomain}/${short}</b> akan redirect ke subdo acak di <b>${subdodom}${ref}</b>.<br>
          <b><a href="https://${basedomain}/${short}" target="_blank">https://${basedomain}/${short}</a></b>
        </div>`
      : '<div class="alert alert-danger">'+res.msg+'</div>'
    );
  },'json');
});

function loadShortSubdoList() {
  $('#shortSubdoList').html('<div class="text-muted text-center py-3">Memuat data...</div>');
  $.post('ajax/domain_cf_backend.php', {action:'get_shortlink_subdo_list'}, function(res){
    if (!res.success || !res.list.length) {
      $('#shortSubdoList').html('<div class="alert alert-warning text-center">Belum ada shortlink subdo yang dibuat.</div>');
      return;
    }
    let html = `
      <div class="mb-2 fw-bold text-primary text-center" style="font-size:1.05rem;">
        <i class="fa fa-random me-1"></i> Daftar Shortlink Subdo Random
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover align-middle mb-0 shadow-sm" style="background:white; border-radius:15px;overflow:hidden">
          <thead class="table-dark text-center">
            <tr>
              <th>Target Subdo</th>
              <th>Dibuat</th>
              <th>URL</th>
            </tr>
          </thead>
          <tbody class="text-center">
    `;
    res.list.forEach(function(row){
      let url = 'https://' + row.base_domain + '/' + row.short_code;
      html += `<tr>
        <td class="fw-bold text-success">${row.domain_subdo}</td>
        <td><span class="text-muted" style="font-size:.93em">${row.created_at}</span></td>
        <td>
          <a href="${url}" class="btn btn-link btn-sm px-1" style="font-size:.98em;" target="_blank">${url}</a>
          <button class="btn btn-outline-secondary btn-sm py-0 px-2 ms-1" onclick="navigator.clipboard.writeText('${url}');this.innerHTML='Tersalin!';setTimeout(()=>this.innerHTML='<i class=\\'fa fa-copy\\'></i>',1500)" title="Copy URL"><i class="fa fa-copy"></i></button>
        </td>
      </tr>`;
    });
    html += `
          </tbody>
        </table>
      </div>
    `;
    $('#shortSubdoList').html(html);
  },'json');
}

</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const $extraRow = document.getElementById('extraDashboardRow');
  const $btn = document.getElementById('btnShowMoreDashboard');
  let isShown = false;
  $btn.addEventListener('click', function() {
    isShown = !isShown;
    $extraRow.style.display = isShown ? '' : 'none';
    $btn.innerHTML = isShown
      ? '<i class="fa-solid fa-eye-slash me-1"></i> Hide Data'
      : '<i class="fa-solid fa-eye me-1"></i> Show More Data';
  });
});
</script>

</body>
</html>
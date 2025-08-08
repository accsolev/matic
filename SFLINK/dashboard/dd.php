<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ─── ANTI-DDoS PROTECTION ───────────────────────────────────────────────────────
// konfigurasi
$rateLimitPerMin  = 40;
$challengeSeconds = 1;
$cookieKey        = 'ddos_verified_' . md5($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

// Cloudflare API untuk multi-zone blocking
define('CF_API_KEY',   '1dbe11f48040907075c9e3903509dae6087d4');
define('CF_EMAIL',     'accsolev9@gmail.com');
$zoneIds = [
    '76f1282dfe5207402bb8a8c7383f7a79',
    '82cf35e43e0d4fc1e283d022590d8b62',
    '257744e851b3f35bdd301be5dab0c933'
];

// koneksi Redis untuk rate-limit
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

// 1) Blok User-Agent bot/headless
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (!$ua || preg_match('/(curl|wget|bot|spider|python|headless|scrapy|axios)/i', $ua)) {
    http_response_code(403);
    exit('Blocked bot UA');
}

// 2) Rate-limit per IP
$ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$key = "ddos:rate:$ip";
$cnt = $redis->incr($key);
if ($cnt === 1) {
    $redis->expire($key, 60);
}
if ($cnt > $rateLimitPerMin) {
    // block via Cloudflare API
    foreach ($zoneIds as $zoneId) {
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/firewall/access_rules/rules");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "X-Auth-Email: ".CF_EMAIL,
                "X-Auth-Key: ".CF_API_KEY,
                "Content-Type: application/json",
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'mode'          => 'block',
                'configuration' => ['target'=>'ip','value'=>$ip],
                'notes'         => 'Auto-block via PHP Anti-DDoS',
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    http_response_code(429);
    exit('Rate limit exceeded');
}

// 3) Simple JS-challenge untuk memastikan browser normal
if (!isset($_COOKIE[$cookieKey])) {
    $token = bin2hex(random_bytes(6));
    setcookie($cookieKey, $token, time()+1800, '/');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Just a moment...</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    html, body {
      background: #fff;
      color: #000;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 0;
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      text-align: center;
    }

    .dot-loader {
      display: flex;
      gap: 8px;
      margin-bottom: 24px;
    }

    .dot-loader div {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background-color: #0088ff;
      animation: dotPulse 1.2s infinite ease-in-out;
    }

    .dot-loader div:nth-child(2) {
      animation-delay: 0.2s;
    }

    .dot-loader div:nth-child(3) {
      animation-delay: 0.4s;
    }

    @keyframes dotPulse {
      0%, 80%, 100% {
        transform: scale(0.8);
        opacity: 0.3;
      }
      40% {
        transform: scale(1.4);
        opacity: 1;
      }
    }

    .title {
      font-size: 1.4rem;
      font-weight: 600;
      margin-bottom: 8px;
    }

    .subtitle {
      font-size: 0.95rem;
      color: #9ca3af;
    }

    .brand {
      position: fixed;
      bottom: 16px;
      font-size: 0.8rem;
      color: #666;
      opacity: 0.3;
    }
  </style>
  <script>
    setTimeout(() => {
      document.cookie = "{$cookieKey}={$token}; path=/";
      location.reload();
    }, {$challengeSeconds} * 1000);
  </script>
</head>
<body>
  <div class="dot-loader">
    <div></div>
    <div></div>
    <div></div>
  </div>
  <div class="title">Verifying your browser...</div>
  <div class="subtitle">Please wait while we confirm you're not a bot.</div>
  <div class="brand">Security by SFLINK.ID ™</div>
</body>
</html>
HTML;
    exit;
}
session_start();
date_default_timezone_set('Asia/Jakarta');
require '../includes/auth.php';
require_login();
require '../includes/db.php';
function logActivity($pdo, $userId, $username, $action) {
  $now = date('Y-m-d H:i:s'); // format waktu Asia/Jakarta (sudah diset timezone di atas)
  $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
  $stmt->execute([$userId, $username, $action, $now]);
}

function containsMaliciousPayload($text) {
    $patterns = [
        '/<\?(php)?/i',
        '/eval\s*\(/i',
        '/base64_decode\s*\(/i',
        '/file_get_contents\s*\(/i',
        '/urldecode\s*\(/i',
        '/document\.write\s*\(/i',
        '/window\.location/i',
        '/onerror\s*=/i',
        '/<script/i',
        '/&lt;script/i',
        '/data:text\/html/i',
        '/javascript:/i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }

    return false;
}

// Fungsi validasi
function isValidAlias($alias) {
    return preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $alias);
}

function isValidUrl($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

    $url = urldecode(strtolower($url));

    $forbidden = [
        'eval(', 'base64_', 'base64,', 'data:text', 'data:application',
        '<?php', '</script>', '<script', '<iframe', '<img', '<svg', '<body',
        'onerror=', 'onload=', 'document.', 'window.', 'file_get_contents',
        'curl_exec', 'exec(', 'passthru(', 'shell_exec', 'system('
    ];

    foreach ($forbidden as $bad) {
        if (strpos($url, $bad) !== false) {
            return false;
        }
    }

    return true;
}
function isValidDomainFormat($url) {
    $host = parse_url($url, PHP_URL_HOST);
    return (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host);
}


if (isset($_POST['edit_id']) && isset($_POST['edit_urls'])) {
    $edit_id = intval($_POST['edit_id']);
    $new_urls = array_filter(array_map('trim', explode("\n", $_POST['edit_urls'])));
    $fallback_urls = array_filter(array_map('trim', explode("\n", $_POST['edit_fallbacks'] ?? '')));

    $errorMessage = '';
    $successMessage = '';

    if (empty($new_urls)) {
        $errorMessage = "❌ URL tidak boleh kosong.";
    } else {
        $isMalicious = false;

        // 🔥 Validasi redirect URLs
        foreach ($new_urls as $url) {
            $original = $url;
            if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";

            if (!isValidUrl($url)) {
                $errorMessage = "❌ URL redirect tidak valid: <code>$original</code>";
                $isMalicious = true;
                break;
            }
            if (containsMaliciousPayload($url)) {
                $errorMessage = "❌ URL redirect mengandung kode berbahaya: <code>$original</code>";
                $isMalicious = true;
                break;
            }
            if (!isValidDomainFormat($url)) {
                $errorMessage = "❌ URL destination bukan domain yang sah: <code>$original</code>";
                $isMalicious = true;
                break;
            }
        }

        // 🔥 Validasi fallback URLs
        if (!$isMalicious) {
            foreach ($fallback_urls as $url) {
                $original = $url;
                if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";

                if (!isValidUrl($url)) {
                    $errorMessage = "❌ Fallback URL tidak valid: <code>$original</code>";
                    $isMalicious = true;
                    break;
                }
                if (containsMaliciousPayload($url)) {
                    $errorMessage = "❌ Fallback URL mengandung kode berbahaya: <code>$original</code>";
                    $isMalicious = true;
                    break;
                }
                if (!isValidDomainFormat($url)) {
                    $errorMessage = "❌ Fallback URL bukan domain yang sah: <code>$original</code>";
                    $isMalicious = true;
                    break;
                }
            }
        }

        // 🔄 Update jika aman
        if (!$isMalicious) {
            $pdo->prepare("DELETE FROM redirect_urls WHERE link_id = ?")->execute([$edit_id]);
            foreach ($new_urls as $url) {
                if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";
                $pdo->prepare("INSERT INTO redirect_urls (link_id, url) VALUES (?, ?)")->execute([$edit_id, $url]);
            }

            $pdo->prepare("DELETE FROM fallback_urls WHERE link_id = ?")->execute([$edit_id]);
            foreach ($fallback_urls as $url) {
                if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";
                $pdo->prepare("INSERT INTO fallback_urls (link_id, url) VALUES (?, ?)")->execute([$edit_id, $url]);
            }

            $successMessage = "✅ Link tujuan & fallback berhasil diupdate!";
            logActivity($pdo, $_SESSION['user_id'], $_SESSION['username'], "Mengedit link ID #$edit_id (redirect & fallback URL)");
        }
    }

    echo json_encode([
        'success' => !$errorMessage,
        'message' => $errorMessage ?: $successMessage
    ]);
    exit;
}
function getTelegramId($userId, $pdo) {
  $stmt = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
  $stmt->execute([$userId]);
  return $stmt->fetchColumn();
}


if (!isset($_SESSION['user_id']) && isset($_COOKIE['rememberme'])) {
    $token = $_COOKIE['rememberme'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
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



function formatTanggalIndonesia($datetime) {
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $timestamp = strtotime($datetime);
    $tgl = date('d', $timestamp);
    $bln = $bulan[(int)date('m', $timestamp)];
    $thn = date('Y', $timestamp);
    $jam = date('H:i', $timestamp);
    return "$tgl $bln $thn $jam";
}

$successMessage = $errorMessage = '';

// ⬇️ Tambahkan fungsi ini di bawah fungsi-fungsi lainnya
function sendTelegramNotif($userId, $pdo, $message) {
    $stmt = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $telegramId = $stmt->fetchColumn();

    if (!$telegramId) return;

    $token = '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw'; // Ganti dengan token bot kamu
    $text = urlencode($message);
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?chat_id=$telegramId&text=$text");
}

function getLimitByType($type) {
    $limits = [
        'trial' => 1,
        'medium' => 3,
        'vip' => PHP_INT_MAX
    ];
    return $limits[$type] ?? 1;
}

// Hapus shortlink
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $stmt = $pdo->prepare("SELECT l.short_code, d.domain FROM links l JOIN domains d ON l.domain_id = d.id WHERE l.id = ? AND l.user_id = ?");
    $stmt->execute([$id, $userId]);
    $link = $stmt->fetch();

    if ($link) {
        $fullUrl = $link['domain'] . '/' . $link['short_code'];

        $stmt = $pdo->prepare("DELETE FROM links WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);

        $successMessage = "Shortlink berhasil dihapus: <code>$fullUrl</code>";
        sendTelegramNotif($userId, $pdo, "🗑️ Shortlink dihapus: https://$fullUrl");
        logActivity($pdo, $userId, $username, "Menghapus shortlink: https://$fullUrl");
    } else {
        $errorMessage = "❌ Shortlink tidak ditemukan atau bukan milik Anda.";
    }
}


// 🔒 CEK TYPE USER: TRIAL atau VIP
$stmt = $pdo->prepare("SELECT type FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userType = $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['edit_id'])) {
// Batasan berdasarkan tipe user
$limitMap = [
    'trial' => 1,
    'medium' => 3,
    'vip' => PHP_INT_MAX
];

$maxLinkAllowed = $limitMap[$userType] ?? 0;


$stmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE user_id = ?");
$stmt->execute([$userId]);
$linkCount = $stmt->fetchColumn();

if ($linkCount >= $maxLinkAllowed) {
    $errorMessage = match ($userType) {
        'trial' => "Akun trial hanya bisa membuat 1 shortlink. Upgrade untuk akses lebih banyak.",
        'medium' => "Akun medium hanya bisa membuat maksimal 3 shortlink. Upgrade ke VIP untuk akses penuh.",
        default => "❌ Batas pembuatan shortlink telah tercapai."
    };
}

if (!$errorMessage) {
    $urls = array_filter(array_map('trim', explode("\n", $_POST['urls'] ?? '')));
    $fallbackUrls = array_filter(array_map('trim', explode("\n", $_POST['fallback_urls'] ?? '')));
    $alias = trim($_POST['alias'] ?? '');
    $domain = trim($_POST['domain'] ?? '');

    if (empty($urls) && empty($fallbackUrls)) {
        $errorMessage = "❌ URL tujuan dan fallback URL tidak boleh kosong semua.";
    } elseif ($alias && !isValidAlias($alias)) {
        $errorMessage = "❌ Alias tidak valid! Hanya huruf, angka, - dan _ (3-30 karakter).";
    } elseif (!in_array($domain, array_column($pdo->query("SELECT domain FROM domains")->fetchAll(), 'domain'))) {
        $errorMessage = "❌ Domain tidak valid.";
    } else {
        if ($alias) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE short_code = ? AND domain_id = (SELECT id FROM domains WHERE domain = ?)");
            $stmt->execute([$alias, $domain]);
            if ($stmt->fetchColumn()) {
                $errorMessage = "❌ Alias <b>$alias</b> sudah digunakan pada domain <b>$domain</b>.";
            }
        }

        if (!$errorMessage) {
            $isMalicious = false;

            // 🔍 Validasi redirect URLs
            foreach ($urls as $url) {
                $original = $url;
                if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";

                if (!isValidUrl($url)) {
                    $errorMessage = "URL tujuan tidak valid <code>$original</code>";
                    $isMalicious = true;
                    break;
                }

                if (containsMaliciousPayload($url)) {
                    $errorMessage = "URL tujuan mengandung kode berbahaya <code>$original</code>";
                    $isMalicious = true;
                    break;
                }

                if (!isValidDomainFormat($url)) {
                    $errorMessage = "URL tujuan bukan domain yang sah <code>$original</code>";
                    $isMalicious = true;
                    break;
                }
            }

            // 🔍 Validasi fallback URLs
            if (!$isMalicious) {
                foreach ($fallbackUrls as $fbUrl) {
                    $original = $fbUrl;
                    if (!preg_match("~^(?:f|ht)tps?://~i", $fbUrl)) $fbUrl = "http://" . $fbUrl;

                    if (!isValidUrl($fbUrl)) {
                        $errorMessage = "Fallback URL tidak valid <code>$original</code>";
                        $isMalicious = true;
                        break;
                    }

                    if (containsMaliciousPayload($fbUrl)) {
                        $errorMessage = "Fallback URL mengandung kode berbahaya <code>$original</code>";
                        $isMalicious = true;
                        break;
                    }

                    if (!isValidDomainFormat($fbUrl)) {
                        $errorMessage = "Fallback URL bukan domain yang sah <code>$original</code>";
                        $isMalicious = true;
                        break;
                    }
                }
            }

            if (!$isMalicious) {
                $domainStmt = $pdo->prepare("SELECT id FROM domains WHERE domain = ?");
                $domainStmt->execute([$domain]);
                $domainData = $domainStmt->fetch();
                $domainId = $domainData['id'];

                $shortCode = $alias ?: substr(md5(uniqid()), 0, 6);
                $created_at = date("Y-m-d H:i:s");

                $pdo->prepare("INSERT INTO links (user_id, short_code, domain_id, created_at) VALUES (?, ?, ?, ?)")
                    ->execute([$userId, $shortCode, $domainId, $created_at]);
                $linkId = $pdo->lastInsertId();

                foreach ($urls as $url) {
                    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";
                    $pdo->prepare("INSERT INTO redirect_urls (link_id, url) VALUES (?, ?)")->execute([$linkId, $url]);
                }

                foreach ($fallbackUrls as $fbUrl) {
                    if (!preg_match("~^(?:f|ht)tps?://~i", $fbUrl)) $fbUrl = "http://$fbUrl";
                    $pdo->prepare("INSERT INTO fallback_urls (link_id, url) VALUES (?, ?)")->execute([$linkId, $fbUrl]);
                }

                $successMessage = "Shortlink berhasil dibuat: <code>https://$domain/$shortCode</code>";
                sendTelegramNotif($userId, $pdo, "🔗 Anda telah membuat shortlink: https://$domain/$shortCode");
                logActivity($pdo, $userId, $username, "Membuat shortlink: https://$domain/$shortCode");
            }
        }
    }
}
}



// Helper fetch column as int
function fetchCount($pdo, $sql, $params) {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn();
}

// =================== 1. Data Stat Utama ===================
$stmt = $pdo->prepare("
    SELECT 
        u.type,
        u.telegram_id,
        (SELECT COUNT(*) FROM links WHERE user_id = u.id) AS total_links,
        (SELECT SUM(clicks) FROM links WHERE user_id = u.id) AS total_clicks,
        (SELECT COUNT(*) FROM list_domains WHERE user_id = u.id) AS total_domains
    FROM users u 
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

$totalLinks   = $userData['total_links']   ?? 0;
$totalClicks  = $userData['total_clicks']  ?? 0;
$totalDomains = $userData['total_domains'] ?? 0;
$userType     = $userData['type'] ?? '';
$userTelegramId = $userData['telegram_id'] ?? '';

$isVIP = ($userType === 'vip');

// =============== 2. Statistic Growth Shortlink ===============
$last7  = fetchCount($pdo, "SELECT COUNT(*) FROM links WHERE user_id=? AND created_at>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)", [$userId]);
$prev7  = fetchCount($pdo, "SELECT COUNT(*) FROM links WHERE user_id=? AND created_at>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) AND created_at<DATE_SUB(CURDATE(),INTERVAL 7 DAY)", [$userId]);
if ($prev7 > 0) $percentChange = round((($last7 - $prev7) / $prev7) * 100);
else $percentChange = $last7 > 0 ? 100 : 0;
$sign        = $percentChange > 0 ? '+' : '';
$trendIcon   = $percentChange >= 0 ? 'trending-up' : 'trending-down';
$trendClass  = $percentChange >= 0 ? 'txt-success' : 'txt-danger';

// ============= 3. Total Visits & Growth (analytics) =============
$totalVisits = fetchCount($pdo, "SELECT COUNT(*) FROM analytics a JOIN links l ON a.link_id=l.id WHERE l.user_id=?", [$userId]);
$last7Visits = fetchCount($pdo, "SELECT COUNT(*) FROM analytics a JOIN links l ON a.link_id=l.id WHERE l.user_id=? AND a.created_at>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)", [$userId]);
$prev7Visits = fetchCount($pdo, "SELECT COUNT(*) FROM analytics a JOIN links l ON a.link_id=l.id WHERE l.user_id=? AND a.created_at>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) AND a.created_at<DATE_SUB(CURDATE(),INTERVAL 7 DAY)", [$userId]);
if ($prev7Visits > 0) $percentChangeVisits = round((($last7Visits - $prev7Visits) / $prev7Visits) * 100);
else $percentChangeVisits = $last7Visits > 0 ? 100 : 0;
$signVisits      = $percentChangeVisits > 0 ? '+' : '';
$trendIconVisits = $percentChangeVisits >= 0 ? 'trending-up' : 'trending-down';
$trendClassVisits= $percentChangeVisits >= 0 ? 'txt-success' : 'txt-danger';

// ============ 4. Klik Hari Ini & Growth ============
$todayClicks     = fetchCount($pdo, "SELECT COUNT(*) FROM analytics a JOIN links l ON a.link_id=l.id WHERE DATE(a.created_at)=CURDATE() AND l.user_id=?", [$userId]);
$yesterdayClicks = fetchCount($pdo, "SELECT COUNT(*) FROM analytics a JOIN links l ON a.link_id=l.id WHERE DATE(a.created_at)=DATE_SUB(CURDATE(),INTERVAL 1 DAY) AND l.user_id=?", [$userId]);
if ($yesterdayClicks > 0) $percentClickChange = round((($todayClicks - $yesterdayClicks) / $yesterdayClicks) * 100);
else $percentClickChange = $todayClicks > 0 ? 100 : 0;
$signClick       = $percentClickChange >= 0 ? '+' : '';
$trendIconClick  = $percentClickChange >= 0 ? 'trending-up' : 'trending-down';
$trendClassClick = $percentClickChange >= 0 ? 'txt-success' : 'txt-danger';

// ============ 5. Growth Domains ============
$last7dDom = fetchCount($pdo, "SELECT COUNT(*) FROM list_domains WHERE user_id=? AND created_at>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)", [$userId]);
$prev7dDom = fetchCount($pdo, "SELECT COUNT(*) FROM list_domains WHERE user_id=? AND created_at>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) AND created_at<DATE_SUB(CURDATE(),INTERVAL 7 DAY)", [$userId]);
if ($prev7dDom > 0) $percentChangeDom = round((($last7dDom - $prev7dDom) / $prev7dDom) * 100);
else $percentChangeDom = $last7dDom > 0 ? 100 : 0;
$signDom       = $percentChangeDom > 0 ? '+' : '';
$trendIconDom  = $percentChangeDom >= 0 ? 'trending-up' : 'trending-down';
$trendClassDom = $percentChangeDom >= 0 ? 'txt-success' : 'txt-danger';

// =================== 6. Top Shortlink ===================
$topShortlinkStmt = $pdo->prepare("
    SELECT CONCAT(d.domain, '/', l.short_code) AS full_url, l.clicks
    FROM links l
    JOIN domains d ON l.domain_id = d.id
    WHERE l.user_id = ?
    ORDER BY l.clicks DESC LIMIT 1
");
$topShortlinkStmt->execute([$userId]);
$topShortlinkData = $topShortlinkStmt->fetch(PDO::FETCH_ASSOC);
$topShortlinkUrl = $topShortlinkData['full_url'] ?? 'Belum Ada';
$topShortlinkClicks = $topShortlinkData['clicks'] ?? 0;

// ============== 7. Top Hour Klik Terbanyak ==============
$topHourStmt = $pdo->prepare("
    SELECT HOUR(a.created_at) AS hour, COUNT(*) AS total
    FROM analytics a
    JOIN links l ON a.link_id = l.id
    WHERE l.user_id = ?
    GROUP BY hour
    ORDER BY total DESC LIMIT 1
");
$topHourStmt->execute([$userId]);
$topHourData = $topHourStmt->fetch(PDO::FETCH_ASSOC);
$topHour = isset($topHourData['hour']) ? $topHourData['hour'] . ':00' : 'Belum Ada';

// =================== 8. Chart Analytics 7 Hari ===================
$chartDates = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartDates[$date] = 0;
}
$analyticsStmt = $pdo->prepare("
    SELECT DATE(a.created_at) AS date, COUNT(*) AS total
    FROM analytics a
    JOIN links l ON a.link_id = l.id
    WHERE l.user_id = ?
      AND a.created_at >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(a.created_at)
    ORDER BY DATE(a.created_at) ASC
");
$analyticsStmt->execute([$userId]);
foreach ($analyticsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $chartDates[$row['date']] = $row['total'];
}
$labels = array_keys($chartDates);
$data = array_values($chartDates);

// ================== 9. Recent Links ===================
$recentLinksStmt = $pdo->prepare("
    SELECT l.id, l.short_code, d.domain, l.created_at
    FROM links l 
    JOIN domains d ON l.domain_id = d.id
    WHERE l.user_id = ? 
    ORDER BY l.created_at DESC
    LIMIT 6
");
$recentLinksStmt->execute([$userId]);
$recentLinks = $recentLinksStmt->fetchAll(PDO::FETCH_ASSOC);

// ============== 10. Activity Log ====================
$activityLogsStmt = $pdo->prepare("
    SELECT action, created_at
    FROM activity_logs
    WHERE user_id = ?
    ORDER BY created_at DESC LIMIT 5
");
$activityLogsStmt->execute([$userId]);
$activityLogs = $activityLogsStmt->fetchAll(PDO::FETCH_ASSOC);

// ============== 11. Analytics Device Breakdown =================
$stmt = $pdo->prepare("
    SELECT a.device, COUNT(*) AS cnt
    FROM analytics a
    JOIN links l ON a.link_id = l.id
    WHERE l.user_id = ?
    GROUP BY a.device
");
$stmt->execute([$userId]);
$deviceStats = ['desktop'=>0, 'mobile'=>0, 'tablet'=>0];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dev = strtolower($row['device']);
    if (isset($deviceStats[$dev])) $deviceStats[$dev] = (int)$row['cnt'];
}
$totalDevClicks = array_sum($deviceStats);
$totalClicksAll = fetchCount($pdo, "SELECT COUNT(*) FROM analytics a JOIN links l ON a.link_id = l.id WHERE l.user_id=?", [$userId]);
$unknownClicks = $totalClicksAll - $totalDevClicks;
$desktopClicks = $deviceStats['desktop'];
$mobileClicks  = $deviceStats['mobile'];
$tabletClicks  = $deviceStats['tablet'];

// ============ 12. Referrer Stats =============
$sql = "
  SELECT
    CASE
      WHEN referrer IS NULL OR referrer = '' THEN 'Direct'
      ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '://', -1), '/', 1))
    END AS domain,
    COUNT(*) AS total
  FROM analytics a
  JOIN links l ON l.id = a.link_id
  WHERE l.user_id = ?
  GROUP BY domain
  ORDER BY total DESC
  LIMIT 3
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$topDomains = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'domain');

// Domain hit 7 hari terakhir
$days = [];
for($i = 6; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} days"));
  $days[$d] = ['day' => date('D', strtotime($d))];
  foreach($topDomains as $dom) $days[$d][$dom] = 0;
}

if (count($topDomains) > 0) {
  $placeholders = implode(',', array_fill(0, count($topDomains), '?'));
  $sql2 = "
    SELECT
      CASE
        WHEN a.referrer IS NULL OR a.referrer = '' THEN 'Direct'
        ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(a.referrer, '://', -1), '/', 1))
      END AS domain,
      DATE(a.click_date) AS d,
      COUNT(*) AS cnt
    FROM analytics a
    JOIN links l ON l.id = a.link_id
    WHERE l.user_id = ?
      AND DATE(a.click_date) BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
      AND (
        CASE
          WHEN a.referrer IS NULL OR a.referrer = '' THEN 'Direct'
          ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(a.referrer, '://', -1), '/', 1))
        END
      ) IN ($placeholders)
    GROUP BY domain, d
  ";
  $params = array_merge([$userId], $topDomains);
  $stmt2 = $pdo->prepare($sql2);
  $stmt2->execute($params);
  foreach($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($days[$r['d']][$r['domain']])) $days[$r['d']][$r['domain']] = (int)$r['cnt'];
  }
}
$chartData = array_values($days);
$ykeys     = array_values($topDomains);

// =========== 13. Payment History & Cron =============
$stmt = $pdo->prepare("SELECT interval_minute, status FROM list_domains WHERE user_id=? GROUP BY user_id LIMIT 1");
$stmt->execute([$userId]);
$userCron = $stmt->fetch(PDO::FETCH_ASSOC);
$interval = $userCron['interval_minute'] ?? 5;
$status = $userCron['status'] ?? 0;

$stmt = $pdo->prepare("
  SELECT reference, package, method, amount, status, checkout_url, created_at, expired_time
  FROM payments
  WHERE customer_name = ?
  ORDER BY created_at DESC
");
$stmt->execute([$_SESSION['username']]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
foreach (['medium', 'vip'] as $pkg) {
    ${"hasUnpaid" . ucfirst($pkg)} = false; ${"isExpired" . ucfirst($pkg)} = false;
    $stmt2 = $pdo->prepare("
        SELECT expired_time FROM payments
        WHERE customer_name = ? AND package = ? AND status = 'UNPAID'
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt2->execute([$_SESSION['username'], $pkg]);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $dt = new DateTime($row['expired_time'], new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        ${"hasUnpaid" . ucfirst($pkg)}  = true;
        ${"isExpired" . ucfirst($pkg)} = ($now > $dt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Cuba admin is super flexible, powerful, clean &amp; modern responsive bootstrap 5 admin template with unlimited possibilities.">
    <meta name="keywords" content="admin template, Cuba admin template, dashboard template, flat admin template, responsive admin template, web app">
    <meta name="author" content="pixelstrap">
    <link rel="icon" href="../assets/images/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="../assets/images/favicon.png" type="image/x-icon">
    <!-- Google font-->
    <link href="https://fonts.googleapis.com/css?family=Rubik:400,400i,500,500i,700,700i&amp;display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,300i,400,400i,500,500i,700,700i,900&amp;display=swap" rel="stylesheet">
    <!-- Font Awesome-->
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/fontawesome.css">
    <!-- ico-font-->
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/icofont.css">
    <!-- Themify icon-->
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/themify.css">
    <!-- Flag icon-->
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/flag-icon.css">
    <!-- Feather icon-->
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/feather-icon.css">
    <!-- Plugins css start-->
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/slick.css">
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/slick-theme.css">
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/scrollbar.css">
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/animate.css">
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/jquery.dataTables.css">
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/select.bootstrap5.css">
    <!-- Plugins css Ends-->
    <!-- Bootstrap css-->
    <link rel="stylesheet" type="text/css" href="../assets/css/vendors/bootstrap.css">
    <!-- App css-->
    <link rel="stylesheet" type="text/css" href="../assets/css/style.css">
    <link id="color" rel="stylesheet" href="../assets/css/color-1.css" media="screen">
    <!-- Responsive css-->
    <link rel="stylesheet" type="text/css" href="../assets/css/responsive.css">
    <script defer src="../assets/css/color-1.js"></script>
    <script defer src="../assets/css/color-2.js"></script>
    <script defer src="../assets/css/color-3.js"></script>
    <script defer src="../assets/css/color-4.js"></script>
    <script defer src="../assets/css/color-5.js"></script>
    <script defer src="../assets/css/color-6.js"></script>
    <script defer src="../assets/css/responsive.js"></script>
    <script defer src="../assets/css/style.js"></script>
    <link href="../assets/css/color-1.css" rel="stylesheet">
    <link href="../assets/css/color-2.css" rel="stylesheet">
    <link href="../assets/css/color-3.css" rel="stylesheet">
    <link href="../assets/css/color-4.css" rel="stylesheet">
    <link href="../assets/css/color-5.css" rel="stylesheet">
    <link href="../assets/css/color-6.css" rel="stylesheet">
    <link href="../assets/css/responsive.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
  </head>
  <body onload="startTime()" class="dark-sidebar">
    <!-- tap on top starts-->
    <div class="tap-top">
      <i data-feather="chevrons-up"></i>
    </div>
    <!-- tap on tap ends-->
    <!-- page-wrapper Start-->
    <div class="page-wrapper compact-wrapper" id="pageWrapper">
      <!-- Page Header Start-->
      <div class="page-header">
        <div class="header-wrapper row m-0">
          <form class="form-inline search-full col" action="#" method="get">
            <div class="form-group w-100">
              <div class="Typeahead Typeahead--twitterUsers">
                <div class="u-posRelative">
                  <input class="demo-input Typeahead-input form-control-plaintext w-100" type="text" placeholder="Search Anything Here..." name="q" title="" autofocus>
                  <div class="spinner-border Typeahead-spinner" role="status">
                    <span class="sr-only">Loading...</span>
                  </div>
                  <i class="close-search" data-feather="x"></i>
                </div>
                <div class="Typeahead-menu"></div>
              </div>
            </div>
          </form>
          <div class="header-logo-wrapper col-auto p-0">
            <div class="logo-wrapper">
              <a href="index.html">
                <img class="img-fluid for-light" src="https://sflink.id/images/logo.png" alt="">
                <img class="img-fluid for-dark" src="https://sflink.id/images/logo.png" alt="">
              </a>
            </div>
            <div class="toggle-sidebar">
              <i class="status_toggle middle sidebar-toggle" data-feather="align-center"></i>
            </div>
          </div>

          <div class="nav-right col-xxl-7 col-xl-6 col-md-7 col-8 pull-right right-header p-0 ms-auto">
            <ul class="nav-menus">
             
              <li class="fullscreen-body">
                <span>
                  <svg id="maximize-screen">
                    <use href="../assets/svg/icon-sprite.svg#full-screen"></use>
                  </svg>
                </span>
              </li>

             <li class="onhover-dropdown">
  <div class="notification-box position-relative">
    <svg>
      <use href="../assets/svg/icon-sprite.svg#notification"></use>
    </svg>
    <?php $notifCount = count($activityLogs); ?>
    <?php if ($notifCount > 0): ?>
      <span class="badge rounded-pill badge-success"><?= $notifCount ?></span>
    <?php endif; ?>
  </div>
  <div class="onhover-show-div notification-dropdown">
    <h6 class="f-18 mb-0 dropdown-title">Notifications</h6>
    <ul style="max-height: 380px; overflow-y: auto;">
      <?php if (!empty($activityLogs)): ?>
        <?php foreach ($activityLogs as $log): ?>
          <?php
            $action = htmlspecialchars($log['action'], ENT_QUOTES);
            $time   = htmlspecialchars(formatTanggalIndonesia($log['created_at']), ENT_QUOTES);
            // Mapping warna border by action type:
            $color = 'primary';
            if (str_contains(strtolower($action), 'hapus'))   $color = 'danger';
            if (str_contains(strtolower($action), 'edit') ||
                str_contains(strtolower($action), 'ubah'))    $color = 'warning';
            if (str_contains(strtolower($action), 'order'))   $color = 'success';
            if (str_contains(strtolower($action), 'ticket'))  $color = 'secondary';
            if (str_contains(strtolower($action), 'delivery'))$color = 'info';
          ?>
          <li class="b-l-<?= $color ?> border-4 toast default-show-toast align-items-center text-dark border-0 fade show mb-2"
              aria-live="assertive" aria-atomic="true" data-bs-autohide="false">
            <div class="d-flex justify-content-between">
              <div class="toast-body">
                <div class="fw-bold"><?= $action ?></div>
                <div class="small text-muted"><?= $time ?></div>
              </div>
              <button class="btn-close btn-close-white me-2 m-auto" type="button" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
          </li>
        <?php endforeach; ?>
      <?php else: ?>
        <li class="toast border-0 align-items-center text-light fade show mb-2" aria-live="assertive" aria-atomic="true" data-bs-autohide="false">
          <div class="d-flex justify-content-between">
            <div class="toast-body">
              <strong>Belum ada aktivitas terbaru</strong>
            </div>
          </div>
        </li>
      <?php endif; ?>
    </ul>
    <a class="dropdown-item text-center mt-2 fw-bold" href="?menu=notification">
      Lihat semua notifikasi <i class="ti-arrow-end"></i>
    </a>
  </div>
</li>
              <li class="profile-nav onhover-dropdown pe-0 py-0">
                <div class="d-flex profile-media">
                  <img class="b-r-10" src="../assets/images/dashboard/profile.png" alt="">
                  <div class="flex-grow-1">
                    <span>Emay Walter</span>
                    <p class="mb-0">Admin <i class="middle fa-solid fa-angle-down"></i>
                    </p>
                  </div>
                </div>
                <ul class="profile-dropdown onhover-show-div">
                  <li>
                    <a href="sign-up.html">
                      <i data-feather="user"></i>
                      <span>Account </span>
                    </a>
                  </li>
                  <li>
                    <a href="mail-box.html">
                      <i data-feather="mail"></i>
                      <span>Inbox</span>
                    </a>
                  </li>
                  <li>
                    <a href="task.html">
                      <i data-feather="file-text"></i>
                      <span>Taskboard</span>
                    </a>
                  </li>
                  <li>
                    <a href="add-user.html">
                      <i data-feather="settings"></i>
                      <span>Settings</span>
                    </a>
                  </li>
                  <li>
                    <a href="login.html">
                      <i data-feather="log-in"></i>
                      <span>Log out</span>
                    </a>
                  </li>
                </ul>
              </li>
            </ul>
          </div>
          <script class="result-template" type="text/x-handlebars-template"> <div class="ProfileCard u-cf">
																																																			<div class="ProfileCard-avatar">
																																																				<svg
																																																					xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-airplay m-0">
																																																					<path d="M5 17H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-1"></path>
																																																					<polygon points="12 15 17 21 7 21 12 15"></polygon>
																																																				</svg>
																																																			</div>
																																																			<div class="ProfileCard-details">
																																																				<div class="ProfileCard-realName">{{name}}</div>
																																																			</div>
																																																		</div>
																																																	</script>
          <script class="empty-template" type="text/x-handlebars-template"> <div class="EmptyMessage">Your search turned up 0 results. This most likely means the backend is down, yikes!</div>
																																																	</script>
        </div>
      </div>
      <!-- Page Header Ends                              -->
      <!-- Page Body Start-->
      <div class="page-body-wrapper">
        <!-- Page Sidebar Start-->
        <div class="sidebar-wrapper" data-sidebar-layout="stroke-svg">
          <div>
            <div class="logo-wrapper">
              <a href="index.html">
                <img class="img-fluid for-light" src="https://sflink.id/images/logo.png" width="180"alt="">
                <img class="img-fluid for-dark" src="https://sflink.id/images/logo.png" width="180"alt="">
              </a>
              <div class="back-btn">
                <i class="fa-solid fa-angle-left"></i>
              </div>
              <div class="toggle-sidebar">
                <i class="status_toggle middle sidebar-toggle" data-feather="grid"></i>
              </div>
            </div>
            <div class="logo-icon-wrapper">
              <a href="index.html">
                <img class="img-fluid" src="https://sflink.id/images/logo-mobile.png"width="40"  alt="">
              </a>
            </div>
            <nav class="sidebar-main">
              <div class="left-arrow" id="left-arrow">
                <i data-feather="arrow-left"></i>
              </div>
              <div id="sidebar-menu">
                <ul class="sidebar-links" id="simple-bar">
                  <li class="back-btn">
                    <a href="index.html">
                      <img class="img-fluid" src="https://sflink.id/images/logo-mobile.png" width="40" alt="">
                    </a>
                    <div class="mobile-back text-end">
                      <span>Back</span>
                      <i class="fa-solid fa-angle-right ps-2" aria-hidden="true"></i>
                    </div>
                  </li>
                  <li class="pin-title sidebar-main-title">
                    <div>
                      <h6>Pinned</h6>
                    </div>
                  </li>
                  <li class="sidebar-main-title">
                    <div>
                      <h6 class="lan-1">General</h6>
                    </div>
                  </li>
                 <li class="sidebar-list">
    <i class="fa-solid fa-thumbtack"></i>
    <label class="badge badge-light-primary">13</label>
    <a class="sidebar-link sidebar-title" href="#">
      <svg class="stroke-icon">
        <use href="../assets/svg/icon-sprite.svg#stroke-home"></use>
      </svg>
      <svg class="fill-icon">
        <use href="../assets/svg/icon-sprite.svg#fill-home"></use>
      </svg>
      <span class="lan-3">Dashboard</span>
    </a>
    <ul class="sidebar-submenu">
      <li>
        <a class="lan-4 nav-link" href="?menu=dashboard">
          Home
        </a>
      </li>
      <li>
        <a class="lan-5 nav-link" href="?menu=shorten-link">
          Create Shortlink
        </a>
      </li>
      <li>
        <a class="nav-link" href="?menu=analytics">Analytics</a>
      </li>
      <li>
        <a class="nav-link" href="?menu=add-domain">Add Domain</a>
      </li>
      <li>
        <a class="nav-link" href="?menu=daftar-harga">
          <label class="badge badge-light-success">New</label>
          Pricing
        </a>
      </li>
      <li>
        <a class="nav-link" href="?menu=notification">Notifications</a>
      </li>
      <li>
        <a class="nav-link" href="?menu=user-profile">User Profile</a>
      </li>
      <li>
        <a class="nav-link" href="?menu=check-domain">Domain Status</a>
      </li>
      <li>
        <a class="nav-link" href="?menu=riwayat-pembayaran">Payment History</a>
      </li>
      <!-- ...tambah sub menu lain sesuai kebutuhan -->
    </ul>
  </li>
    
                </ul>
              </div>
              <div class="right-arrow" id="right-arrow">
                <i data-feather="arrow-right"></i>
              </div>
            </nav>
          </div>
        </div>
        <!-- Page Sidebar Ends-->
        <div class="page-body">
          <div class="container-fluid">
            <div class="page-title">
              <div class="row">
                <div class="col-sm-6">
                  <h3>Dashboard </h3>
                </div>
                <div class="col-sm-6">
                  <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                      <a href="index.html">
                        <svg class="stroke-icon">
                          <use href="../assets/svg/icon-sprite.svg#stroke-home"></use>
                        </svg>
                      </a>
                    </li>
                    <li class="breadcrumb-item">Dashboard</li>
                    <li class="breadcrumb-item active">SFLINK.ID</li>
                  </ol>
                </div>
              </div>
            </div>
          </div>
          <!-- Container-fluid starts-->

          <div class="container-fluid default-dashboard" id="dashboardSection">
            <div class="row widget-grid">
              <div class="col-xxl-6 col-sm-6 box-col-6">
                <div class="card profile-box">
                  <div class="card-body">
                    <div class="d-flex media-wrapper justify-content-between">
                      <div class="flex-grow-1">
                        <div class="greeting-user">
                          <h2 class="f-w-600" id="analytics-title"></h2>
                          <p id="analytics-text"></p>
                          <div class="whatsnew-btn">
                            <a class="purchase-btn btn btn-primary btn-hover-effect f-w-500" href="https://1.envato.market/3GVzd" target="_blank">Upgrade Account</a>
                          </div>
                        </div>
                      </div>
                      <div>
                        <div class="clockbox">
                          <svg id="clock" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600">
                            <g id="face">
                              <circle class="circle" cx="300" cy="300" r="253.9"></circle>
                              <path class="hour-marks" d="M300.5 94V61M506 300.5h32M300.5 506v33M94 300.5H60M411.3 107.8l7.9-13.8M493 190.2l13-7.4M492.1 411.4l16.5 9.5M411 492.3l8.9 15.3M189 492.3l-9.2 15.9M107.7 411L93 419.5M107.5 189.3l-17.1-9.9M188.1 108.2l-9-15.6"></path>
                              <circle class="mid-circle" cx="300" cy="300" r="16.2"></circle>
                            </g>
                            <g id="hour">
                              <path class="hour-hand" d="M300.5 298V142"></path>
                              <circle class="sizing-box" cx="300" cy="300" r="253.9"></circle>
                            </g>
                            <g id="minute">
                              <path class="minute-hand" d="M300.5 298V67"></path>
                              <circle class="sizing-box" cx="300" cy="300" r="253.9"></circle>
                            </g>
                            <g id="second">
                              <path class="second-hand" d="M300.5 350V55"></path>
                              <circle class="sizing-box" cx="300" cy="300" r="253.9"></circle>
                            </g>
                          </svg>
                        </div>
                        <div class="badge f-10 p-0" id="txt"></div>
                      </div>
                    </div>
                    <div class="cartoon">
                      <img class="img-fluid" src="../assets/images/dashboard/cartoon.svg" alt="vector women with leptop">
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-xxl-auto col-xl-3 col-sm-6 box-col-3">
                <div class="row">
                  <div class="col-xl-12">
                    <div class="card widget-1">
                      <div class="card-body">
                        <div class="widget-content">
                          <div class="widget-round secondary">
                            <div class="bg-round">
                                         <svg xmlns="http://www.w3.org/2000/svg"
     fill="#007bff"
     width="24" height="24"
     viewBox="0 0 24 24"
     aria-hidden="true">
  <path d="M10.59 13.41L9.17 12l1.42-1.41 a2 2 0 1 1 2.83-2.83l.59.59 1.41-1.41 -.59-.59a4 4 0 0 0-5.66 5.66L10.59 13.41z"/>
  <path d="M12 14.83l1.41 1.41 a4 4 0 1 0 5.66-5.66l-.59-.59-1.41 1.41 .59.59a2 2 0 1 1-2.83 2.83L12 14.83z"/>
</svg>
                                <svg class="half-circle svg-fill">
                                  <use href="../assets/svg/icon-sprite.svg#halfcircle"></use>
                                </svg>
                            </div>
                          </div>
                          <div>
                            <h4><?= $totalLinks ?>
                            </h4>
                            <span class="f-light">Total Shortlink</span>
                          </div>
                        </div>
                       <span class="common-align gap-1 justify-content-start">
              <i class="<?= $trendClass ?>" data-feather="<?= $trendIcon ?>"></i>
              <span class="<?= $trendClass ?> f-w-500">
                <?= $sign . $percentChange . '%' ?>
              </span>
            </span>
                      </div>
                    </div>
                    <div class="col-xl-12">
                      <div class="card widget-1">
                        <div class="card-body">
                          <div class="widget-content">
                            <div class="widget-round success">
                              <div class="bg-round">
                                           <svg xmlns="http://www.w3.org/2000/svg"
                 width="24" height="24"
                 viewBox="0 0 24 24"
                 fill="none"
                 stroke="#007bff" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="2" y1="12" x2="22" y2="12"></line>
              <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
            </svg>
                                <svg class="half-circle svg-fill">
                                  <use href="../assets/svg/icon-sprite.svg#halfcircle"></use>
                                </svg>
                              </div>
                            </div>
                           
                            <div>
                              <h4>
                                <?= $totalDomains ?></span>
                              </h4>
                              <span class="f-light">Total Domain</span>
                            </div>
                          </div>
                            <i class="bookmark-search me-1" data-feather="trending-down"></i>
                                 <span class="common-align gap-1 justify-content-start">
              <i class="<?= $trendClassDom ?>" data-feather="<?= $trendIconDom ?>"></i>
              <span class="<?= $trendClassDom ?> f-w-500">
                <?= $signDom . $percentChangeDom . '%' ?>
              </span>
            </span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-xxl-auto col-xl-3 col-sm-6 box-col-3">
                <div class="row">
                    
                    
                  <div class="col-xl-12">
                    <div class="card widget-1">
                      <div class="card-body">
                        <div class="widget-content">
                          <div class="widget-round warning">
                            <div class="bg-round">
 <svg xmlns="http://www.w3.org/2000/svg"
                 fill="#007bff"
                 width="24" height="24"
                 viewBox="0 0 24 24"
                 aria-hidden="true">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
                              <svg class="half-circle svg-fill">
                                <use href="../assets/svg/icon-sprite.svg#halfcircle"></use>
                              </svg>
                            </div>
                          </div>
                          
                           <div>
          <h4 class="mb-0"><?= number_format($totalVisits) ?></h4>
          <span class="f-light">Total Visits</span>
        </div>

        <!-- Persentase Growth -->
        <div class="common-align gap-1 justify-content-start">
          <i class="<?= $trendClassVisits ?>" data-feather="<?= $trendIconVisits ?>"></i>
          <span class="<?= $trendClassVisits ?> f-w-500">
            <?= $signVisits . abs($percentChangeVisits) ?>%
          </span>
        </div>  </div>
                      </div>
                    </div>

<div class="col-xl-12">
  <div class="card widget-1">
    <div class="card-body">
      <div class="widget-content d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
          <div class="widget-round primary me-3">
            <div class="bg-round">
              <!-- Icon “eye” untuk visits/klik -->
              <svg class="fill-primary" width="32" height="32" viewBox="0 0 24 24">
                <use href="../assets/svg/icon-sprite.svg#eye"></use>
              </svg>
              <svg class="half-circle svg-fill">
                <use href="../assets/svg/icon-sprite.svg#halfcircle"></use>
              </svg>
            </div>
          </div>
          <div>
            <h4 class="mb-0"><?= number_format($todayClicks) ?></h4>
            <span class="f-light">Clicks Today</span>
          </div>
        </div>
        <div class="font-<?= $percentClickChange >= 0 ? 'success' : 'danger' ?> f-w-500 d-flex align-items-center">
          <i class="me-1" data-feather="<?= $trendIconClick ?>"></i>
          <span class="<?= $trendClassClick ?>">
            <?= $signClick . abs($percentClickChange) ?>%
          </span>
        </div>
      </div>
    </div>
  </div>
</div></div>
                </div>
              </div>
<!-- Chart Row -->
  <div class="row g-3 mt-2">
    <div class="col-xl-4">
      <div class="card h-100">
        <div class="card-header card-no-border">
          <h5>Statistik</h5>
        </div>
        <div class="card-body">
          <div id="overiewChart" style="min-height: 260px;"></div>
        </div>
      </div>
    </div>
    <div class="col-xl-4">
      <div class="card h-100">
        <div class="card-header card-no-border">
          <h5>Click By Device</h5>
        </div>
        <div class="card-body justify-content-center align-items-center px-0">
          <div class="d-flex align-items-start justify-content-center align-items-center gap-4 flex-wrap">
            <div id="morris_donut" style="height:230px; width:230px; min-width:180px;"></div>
            <div>
              <table class="table table-striped mb-0">
                <thead><tr><th>Device</th><th>Clicks</th></tr></thead>
                <tbody>
                  <tr><td>Desktop</td><td><?= $desktopClicks ?></td></tr>
                  <tr><td>Mobile</td><td><?= $mobileClicks ?></td></tr>
                  <tr><td>Tablet</td><td><?= $tabletClicks ?></td></tr>
                  <tr><td>Unknown</td><td><?= $unknownClicks ?></td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-xl-4">
      <div class="card h-100">
        <div class="card-header border-0 pb-0">
          <h5 class="mb-0">Click by Referrer</h5>
        </div>
        <div class="card-body px-0">
          <?php
            $colors = ['#1921fa','#10ca93','#ff5c00'];
            $totals = array_fill_keys($ykeys, 0);
            foreach ($chartData as $row) {
              foreach ($ykeys as $domain) {
                $totals[$domain] += isset($row[$domain]) ? (int)$row[$domain] : 0;
              }
            }
          ?>
          <div id="morris_referrer" style="height:230px;"></div>
          <div id="referrer-legend" class="mt-3" style="font-family:Arial,sans-serif;">
            <strong>Referrer:</strong>
            <?php foreach ($ykeys as $i => $domain): ?>
              <span class="d-inline-flex align-items-center me-3">
                <span class="me-1" style="display:inline-block; width:10px; height:10px; background:<?= $colors[$i] ?>; border-radius:50%;"></span>
                <?= ucfirst($domain) ?> (<?= number_format($totals[$domain]) ?> klik)
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>


<div class="row g-3 align-items-stretch">
  <!-- Recent Links -->
  <div class="col-xl-4 col-lg-5 col-md-6 col-sm-12">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center py-2">
        <h6 class="mb-0">Recent Links</h6>
        <span class="badge bg-warning"><?= count($recentLinks) ?> Links</span>
      </div>
      <ul class="list-group list-group-flush" id="recentAjaxDashboard" style="max-height:350px; min-height:160px; overflow-y:auto;">
        <?php if (!empty($recentLinks)): ?>
          <?php
            $linkIds = array_column($recentLinks, 'id');
            $in  = implode(',', array_fill(0, count($linkIds), '?'));
            $destStmt = $pdo->prepare("SELECT link_id, COUNT(*) AS total FROM redirect_urls WHERE link_id IN ($in) GROUP BY link_id");
            $destStmt->execute($linkIds);
            $destCounts = $destStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $fbStmt = $pdo->prepare("SELECT link_id, COUNT(*) AS total FROM fallback_urls WHERE link_id IN ($in) GROUP BY link_id");
            $fbStmt->execute($linkIds);
            $fallbackCounts = $fbStmt->fetchAll(PDO::FETCH_KEY_PAIR);
          ?>
          <?php if (count($recentLinks) === 1): // JIKA CUMA 1 DATA ?>
            <?php
              $link = $recentLinks[0];
              $fullUrl       = "{$link['domain']}/{$link['short_code']}";
              $destTotal     = $destCounts[$link['id']]     ?? 0;
              $fallbackTotal = $fallbackCounts[$link['id']] ?? 0;
            ?>
            <li class="list-group-item py-4">
              <div role="button"
                   class="d-flex flex-column justify-content-center align-items-center copy-link"
                   data-url="<?= htmlspecialchars($fullUrl) ?>"
                   title="Klik untuk copy URL"
                   style="gap:6px;">
                <div class="w-100 text-center" style="max-width: 100%;">
                  <strong><?= htmlspecialchars($fullUrl) ?></strong><br>
                  <small class="text-muted">Created: <?= htmlspecialchars(formatTanggalIndonesia($link['created_at'])) ?></small>
                </div>
                <div class="mt-2">
                  <span class="badge rounded-pill bg-primary px-3"><?= $destTotal ?> URL</span>
                  <span class="badge rounded-pill bg-info px-3"><?= $fallbackTotal ?> Fallback</span>
                </div>
              </div>
            </li>
          <?php else: // BANYAK DATA, LIST BIASA ?>
            <?php foreach ($recentLinks as $link):
              $fullUrl       = "{$link['domain']}/{$link['short_code']}";
              $destTotal     = $destCounts[$link['id']]     ?? 0;
              $fallbackTotal = $fallbackCounts[$link['id']] ?? 0;
            ?>
              <li class="list-group-item p-2">
                <div role="button"
                     class="d-flex justify-content-between align-items-center copy-link"
                     data-url="<?= htmlspecialchars($fullUrl) ?>"
                     title="Klik untuk copy URL">
                  <div class="text-truncate" style="max-width: 60%;">
                    <strong><?= htmlspecialchars($fullUrl) ?></strong><br>
                    <small class="text-muted">Created: <?= htmlspecialchars(formatTanggalIndonesia($link['created_at'])) ?></small>
                  </div>
                  <div class="text-end ms-2">
                    <span class="badge rounded-pill bg-primary px-3"><?= $destTotal ?> URL</span>
                    <span class="badge rounded-pill bg-info px-3"><?= $fallbackTotal ?> Fallback</span>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php else: ?>
          <li class="list-group-item text-center text-muted py-4">
            <strong>Belum ada link</strong><br>
            <small>Mulai buat shortlink baru 🚀</small>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>

  <!-- Clicks by Location -->
  <div class="col-xl-8 col-lg-7 col-md-6 col-sm-12">
    <div class="card h-100">
      <div class="card-header border-0 pb-0 flex-wrap d-flex align-items-center">
        <h5 class="h-title mb-0 me-3 d-flex align-items-center">
          <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 120 120" style="margin-right:7px;">
            <defs>
              <linearGradient id="grad-link" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#3A8DFF"/>
                <stop offset="100%" stop-color="#10CA93"/>
              </linearGradient>
              <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
                <feDropShadow dx="0" dy="4" stdDeviation="4" flood-color="#000" flood-opacity="0.2"/>
              </filter>
            </defs>
            <path d="M40 20 A20 20 0 0 1 60 20 L80 40 A20 20 0 0 1 80 60 L60 80 A20 20 0 0 1 40 80 L20 60 A20 20 0 0 1 20 40 Z" fill="none" stroke="url(#grad-link)" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" filter="url(#shadow)" />
            <path d="M80 20 A20 20 0 0 0 100 40 L100 60 A20 20 0 0 0 80 80 L60 60 A20 20 0 0 0 60 40 L80 20 Z" fill="none" stroke="url(#grad-link)" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" filter="url(#shadow)" />
          </svg>
          Clicks by Location
        </h5>
      </div>
      <div class="card-body px-0 pt-3">
<?php
// pastikan user_id sudah disimpan di session saat login
$userId = $_SESSION['user_id'];

// ambil statistik country hanya untuk user ini
$countryStmt = $pdo->prepare("
  SELECT a.country, COUNT(*) AS clicks
    FROM analytics a
    JOIN links l ON a.link_id = l.id
   WHERE l.user_id = ?
   GROUP BY a.country
   ORDER BY clicks DESC
");
$countryStmt->execute([$userId]);
$countryStats      = $countryStmt->fetchAll(PDO::FETCH_ASSOC);
$totalCountryClicks = array_sum(array_column($countryStats, 'clicks'));

// ambil statistik city hanya untuk user ini
$cityStmt = $pdo->prepare("
  SELECT a.city, COUNT(*) AS clicks
    FROM analytics a
    JOIN links l ON a.link_id = l.id
   WHERE l.user_id = ?
   GROUP BY a.city
   ORDER BY clicks DESC
");
$cityStmt->execute([$userId]);
$cityStats      = $cityStmt->fetchAll(PDO::FETCH_ASSOC);
$totalCityClicks = array_sum(array_column($cityStats, 'clicks'));
?>

<!-- Nav tabs -->
<ul class="nav nav-tabs mb-3" id="locTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="tab-country" data-bs-toggle="tab" data-bs-target="#pane-country" type="button" role="tab">
      Countries
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-city" data-bs-toggle="tab" data-bs-target="#pane-city" type="button" role="tab">
      Cities
    </button>
  </li>
</ul>

<div class="tab-content">
  <!-- COUNTRIES -->
  <div class="tab-pane fade show active" id="pane-country" role="tabpanel">
    <div class="table-responsive">
      <table id="tbl-country" class="table table-sm table-striped mb-4">
        <thead>
          <tr>
            <th>#</th>
            <th>Country</th>
            <th>Clicks</th>
            <th>%</th>
          </tr>
        </thead>
    <tbody >

          <?php foreach($countryStats as $i => $row): 
            $pct = $totalCountryClicks
              ? number_format($row['clicks'] / $totalCountryClicks * 100, 1) . '%'
              : '0%';
          ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><?= htmlspecialchars($row['country'] ?: 'Unknown') ?></td>
            <td><?= $row['clicks'] ?></td>
            <td><?= $pct ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
   


    </div>
  </div>

  <!-- CITIES -->
  <div class="tab-pane fade" id="pane-city" role="tabpanel">
    <div class="table-responsive">
      <table id="tbl-city" class="table table-sm table-striped mb-4">
        <thead>
          <tr>
            <th>#</th>
            <th>City</th>
            <th>Clicks</th>
            <th>%</th>
          </tr>
        </thead>
       <tbody>

          <?php foreach($cityStats as $i => $row): 
            $pct = $totalCityClicks
              ? number_format($row['clicks'] / $totalCityClicks * 100, 1) . '%'
              : '0%';
          ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><?= htmlspecialchars($row['city'] ?: 'Unknown') ?></td>
            <td><?= $row['clicks'] ?></td>
            <td><?= $pct ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
     

    </div>
  </div>
</div>
        </div>
          <div class="col-xl-12 custome-width">
                <div id="app‐sections">
  <?php if ($successMessage): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= $successMessage ?></div>
  <?php elseif ($errorMessage): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= $errorMessage ?></div>
  <?php endif; ?>
    <div class="card mb-4 section" id="shortenLinkSection" style="display:none;"><div class="card-body">
  <h4 class="mb-3"><i class="fa-solid fa-plus-circle"></i> Shorten a New Link</h4>

  <form method="POST">
    <div class="mb-3">
      <label>Destination URLs (pisahkan dengan baris)</label>
      <textarea name="urls" class="form-control" rows="5" placeholder="https://example1.com&#10;https://example2.com&#10;https://example3.com&#10; " required></textarea>
    </div>
<div id="advancedOptions" class="d-none">
  <div class="mb-3">
    <label>Custom Alias (opsional)</label>
    <input type="text" name="alias" class="form-control" placeholder="Contoh: linkgacor">
  </div>
  <div class="mb-3">
    <label>Pilih Domain</label>
    <select name="domain" class="form-select">
      <?php foreach ($domains as $domain): ?>
        <option value="<?= $domain ?>"><?= $domain ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="mb-3">
  <label>Fallback URLs (satu URL per baris)</label>
  <textarea name="fallback_urls" class="form-control" rows="5" placeholder="https://example1.com&#10;https://example2.com&#10;https://example3.com&#10; "></textarea>
      <small class="text-muted">URL ini akan dipakai jika semua destination URL tidak aktif atau diblokir.</small>
</div>

</div>
    <div class="d-flex justify-content-between flex-wrap gap-2">
      <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Shorten Link
      </button>
      <button type="button" class="btn btn-secondary"
        onclick="document.getElementById('advancedOptions').classList.toggle('d-none');
                 document.getElementById('editDeleteTools')?.classList.toggle('d-none')">
        <i class="fa-solid fa-gear"></i> Expert Mode
      </button>
    </div>
  </form>
</div></div>
    </div>
    </div>


  <!-- dst… -->
</div>

              </div>
            </div>  </div>  </div>
            
            
          </div>
          <!-- Container-fluid Ends-->
        </div>
        <!-- footer start-->
        <footer class="footer">
          <div class="container-fluid">
            <div class="row">
              <div class="col-md-12 footer-copyright text-center">
                <p class="mb-0">Copyright <span class="year-update"></span> © SFLINK.ID </p>
              </div>
            </div>
          </div>
        </footer>
      </div>
    </div>
    <!-- latest jquery-->
    <script src="../assets/js/jquery.min.js"></script>
    <!-- Bootstrap js-->
    <script src="../assets/js/bootstrap/bootstrap.bundle.min.js"></script>
    <!-- feather icon js-->
    <script src="../assets/js/icons/feather-icon/feather.min.js"></script>
    <script src="../assets/js/icons/feather-icon/feather-icon.js"></script>
    <!-- scrollbar js-->
    <script src="../assets/js/scrollbar/simplebar.min.js"></script>
    <script src="../assets/js/scrollbar/custom.js"></script>
    <!-- Sidebar jquery-->
    <script src="../assets/js/config.js"></script>
    <!-- Plugins JS start-->
    <script src="../assets/js/sidebar-menu.js"></script>
    <script src="../assets/js/sidebar-pin.js"></script>
    <script src="../assets/js/clock.js"></script>
    <script src="../assets/js/slick/slick.min.js"></script>
    <script src="../assets/js/slick/slick.js"></script>
    <script src="../assets/js/header-slick.js"></script>
    <script src="../assets/js/chart/apex-chart/apex-chart.js"></script>
    <script src="../assets/js/chart/apex-chart/stock-prices.js"></script>
    <script src="../assets/js/counter/counter-custom.js"></script>

    <script src="../assets/js/dashboard/default.js"></script>
    <script src="../assets/js/notify/index.js"></script>
    <script src="../assets/js/datatable/datatables/jquery.dataTables.min.js"></script>
    <script src="../assets/js/datatable/datatables/dataTables.js"></script>
    <script src="../assets/js/datatable/datatables/dataTables.select.js"></script>
    <script src="../assets/js/datatable/datatables/select.bootstrap5.js"></script>
    <script src="../assets/js/datatable/datatables/datatable.custom.js"></script>
    <script src="../assets/js/typeahead/handlebars.js"></script>
    <script src="../assets/js/typeahead/typeahead.bundle.js"></script>
    <script src="../assets/js/typeahead/typeahead.custom.js"></script>
    <script src="../assets/js/typeahead-search/handlebars.js"></script>
    <script src="../assets/js/typeahead-search/typeahead-custom.js"></script>
    <!-- Plugins JS Ends-->
    <!-- Theme js-->
    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/script1.js"></script>

    
    <script>
  // ambil username dari PHP ke JS
  const username = <?= json_encode($username, JSON_HEX_TAG) ?>;

  function nowJakarta() {
    return new Date(
      new Date().toLocaleString('en-US', { timeZone: 'Asia/Jakarta' })
    );
  }

  function updateAnalyticsHeader() {
    const now = nowJakarta();
    const hour = now.getHours();
    let greeting, message;

    if (hour >= 5 && hour < 12) {
      greeting = 'Good Morning';
      message = 'Start your day with insightful analytics to boost engagement.';
    } else if (hour >= 12 && hour < 15) {
      greeting = 'Good Afternoon';
      message = 'Monitor your traffic trends and make data-driven decisions this afternoon.';
    } else if (hour >= 15 && hour < 18) {
      greeting = 'Good Evening';
      message = 'Review your performance metrics to plan for tomorrow.';
    } else {
      greeting = 'Good Night';
      message = 'Analyze nightly data to understand user behavior patterns.';
    }

    // gabungkan greeting + username
    document.getElementById('analytics-title').textContent =
      `${greeting}, ${username}`;

    document.getElementById('analytics-text').textContent = message;

    // update jam digital
    document.getElementById('jakarta-clock').textContent =
      now.toLocaleTimeString('en-GB', { hour12: false });
  }

  updateAnalyticsHeader();
  setInterval(updateAnalyticsHeader, 1000);
</script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelector('.mode').addEventListener('click', () => {
      document.body.classList.toggle('dark');
    });
  });
</script>
	
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
  
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
  'riwayatPembayaranSection',
  // tambah section ID jika ada baru
];

// Map antara menu param & section ID
const menuMap = {
  'dashboard':         'dashboardSection',
  'shorten-link':      'shortenLinkSection',
  'recent-links':      'recentAjaxSection',
  'analytics':         'analyticsSection',
  'add-domain':        'adddomainSection',
  'set-timer':         'settimerSection',
  'dapa-checker':      'dapacheckerSection',
  'daftar-harga':      'daftarhargaSection',
  'user-profile':      'userprofileSection',
  'notification':      'notificationSection',
  'check-domain':      'checkdomainSection',
  'riwayat-pembayaran':'riwayatPembayaranSection',
  // dst, samain aja sesuai section yg kamu punya
  null:                'dashboardSection'
};

function showSectionByName(name) {
  sections.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = (id === name) ? 'block' : 'none';
  });
}

function getMenuParam() {
  return new URLSearchParams(window.location.search).get('menu');
}

document.addEventListener('DOMContentLoaded', () => {
  // Tampilkan section awal dari URL
  const menu = getMenuParam();
  showSectionByName(menuMap[menu] || menuMap.null);

  // SPA navigation: hijack semua link sidebar/submenu yg nav-link & href ?menu=xxx
  document.querySelectorAll('a.nav-link[href^="?menu="]').forEach(a => {
    a.addEventListener('click', function(e) {
      e.preventDefault();
      // Remove active dari semua nav-link, kasih ke yang aktif
      document.querySelectorAll('a.nav-link').forEach(x=>x.classList.remove('active'));
      this.classList.add('active');
      // get menu param dari href
      const href = new URL(this.href, window.location.origin);
      const m = href.searchParams.get('menu');
      showSectionByName(menuMap.hasOwnProperty(m) ? menuMap[m] : menuMap.null);
      history.pushState({menu: m}, '', '?menu='+m);
    });
  });

  // Handle back/forward browser
  window.addEventListener('popstate', ev => {
    const m = (ev.state && ev.state.menu) || getMenuParam();
    showSectionByName(menuMap.hasOwnProperty(m) ? menuMap[m] : menuMap.null);
    // Aktifkan nav-link-nya juga
    document.querySelectorAll('a.nav-link').forEach(x=>x.classList.remove('active'));
    const target = document.querySelector('a.nav-link[href="?menu='+m+'"]');
    if (target) target.classList.add('active');
  });

  // Optional: langsung aktifkan nav-link pertama (Home/dashboard)
  const menuFirst = getMenuParam() || 'dashboard';
  const linkActive = document.querySelector('a.nav-link[href="?menu='+menuFirst+'"]');
  if (linkActive) linkActive.classList.add('active');
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
                  data-urls="${link.destinations.join('|||')}"
                  data-fallback="${link.fallbacks.join('|||')}"
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
document.getElementById('searchRecentAjax').addEventListener('input', function () {
  recentKeyword = this.value.trim();
  loadRecentLinksAjax(1, recentKeyword);
});

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

  // ✅ Regex domain validator
  const validDomainRegex = /^[a-z0-9\-\.]+\.[a-z]{2,}$/i;

  // ❌ Cek apakah semua input valid domain
  const invalidEntries = inputLines.filter(domain => !validDomainRegex.test(domain.replace(/^https?:\/\//i, '')));

  if (invalidEntries.length > 0) {
    msgBox.className = 'alert alert-danger';
    msgBox.innerHTML = '❌ Hanya domain yang dapat didaftarkan!<br>Kesalahan pada:<br><code>' + invalidEntries.join('</code><br><code>') + '</code>';
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
    loadUserDomains();
    if (data.success) form.reset();
  })
  .catch(err => {
    console.error(err);
    msgBox.className = 'alert alert-danger';
    msgBox.textContent = '❌ Terjadi kesalahan jaringan/Domain sudah digunakan.';
  });
});


  // Load & render daftar domain
  function loadUserDomains() {
    fetch('/dashboard/ajax/get-domains.php')
      .then(res => res.json())
      .then(data => {
        const tbody = document.getElementById('userDomainList');
        tbody.innerHTML = '';
        if (data.success && data.domains.length) {
          data.domains.forEach(d => {
            const tr = document.createElement('tr');

            // Domain
            const tdDomain = document.createElement('td');
            tdDomain.textContent = d.domain;

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

            tr.append(tdDomain, tdStatus, tdAksi);
            tbody.appendChild(tr);

            // Jalankan cek status melalui checklist.php
            checkListStatus(d.domain, tdStatus);
          });
        } else {
          tbody.innerHTML = `
            <tr>
              <td colspan="3" class="text-center text-muted">
                Belum ada domain.
              </td>
            </tr>`;
        }
      })
      .catch(() => {
        document.getElementById('userDomainList').innerHTML = `
          <tr>
            <td colspan="3" class="text-center text-danger">
              Gagal memuat domain.
            </td>
          </tr>`;
      });
  }

  // Hapus domain
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
      } else {
        alert(data.message);
      }
    })
    .catch(() => alert('Gagal menghapus domain.'));
  }

  document.addEventListener('DOMContentLoaded', loadUserDomains);
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
  // 1. Inject ke JS
  const isVIP = <?= json_encode($isVIP); ?>;

  document.getElementById('formCheckDA').addEventListener('submit', async function(e) {
    // 2. Cek VIP dulu
    if (!isVIP) {
      e.preventDefault(); // batalkan submit

      // Ganti alert() dengan Swal.fire()
      Swal.fire({
        icon: 'warning',
        title: 'Akses Ditolak',
        text: 'Maaf, hanya user VIP yang dapat melakukan pengecekan DA/PA & Umur Domain.',
        confirmButtonText: 'Oke'
      });
      return;
    }

    // 3. Kalau VIP baru lanjut
    e.preventDefault();

    const domains = this.domain.value
      .split('\n')
      .map(d => d.trim())
      .filter(d => d);

    const resultBox = document.getElementById('resultDA');

    if (domains.length === 0) {
      resultBox.innerHTML = '<div class="alert alert-danger">❌ Tidak ada domain untuk dicek.</div>';
      return;
    }

    resultBox.innerHTML = `
      <div class="text-center text-muted my-3" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2">Mengecek ${domains.length} domain (interval 30 detik)...</div>
      </div>
      <div id="progressDA" class="mt-2 alert alert-info"></div>
      <div id="finalAlert" class="mt-3"></div>
      <div class="table-responsive">
        <table class="table table-bordered">
          <thead>
            <tr>
              <th>Domain</th><th>DA</th><th>PA</th><th>Spam</th><th>Backlink</th>
              <th>MT</th><th>MR</th><th>NOF</th><th>RED</th><th>DEL</th>
              <th>History</th><th>Registered</th><th>Expired</th>
            </tr>
          </thead>
          <tbody id="tableBodyDA"></tbody>
        </table>
      </div>
    `;

    const tableBody = document.getElementById('tableBodyDA');
    const progressDA = document.getElementById('progressDA');
    const loadingSpinner = document.getElementById('loadingSpinner');
    const finalAlert = document.getElementById('finalAlert');

    const wait = (seconds) => new Promise(res => setTimeout(res, seconds * 1000));

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

        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const row = doc.querySelector('tbody').innerHTML;
        tableBody.insertAdjacentHTML('beforeend', row);

      } catch (error) {
        tableBody.insertAdjacentHTML('beforeend', `<tr><td>${domain}</td><td colspan="12">❌ Gagal memeriksa domain.</td></tr>`);
      }

      if (i < domains.length - 1) {
        await wait(30);
      }
    }

    loadingSpinner.remove();
    progressDA.remove();
    finalAlert.innerHTML = `<div class="alert alert-success">✅ Selesai memeriksa semua domain.</div>`;
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
function showModal(btn) {
  const id = btn.getAttribute('data-id');
  const short = btn.getAttribute('data-short');
  const created = btn.getAttribute('data-created');
  const urls = btn.getAttribute('data-urls').split('|||').join('\n');
  const fallbacks = (btn.getAttribute('data-fallback') || '').split('|||').join('\n');

  document.getElementById('modalShortlinkText').innerText = short;
  document.getElementById('modalShortlinkText').setAttribute('data-url', short);
  document.getElementById('modalCreated').innerText = created;
  document.getElementById('modalUrls').value = urls;
  document.getElementById('modalFallbacks').value = fallbacks;
  document.getElementById('modalEditId').value = id;

  renderBadgeOverlay('modalUrls', 'modalUrlsBadges');
  renderBadgeOverlay('modalFallbacks', 'modalFallbacksBadges');

  const modal = new bootstrap.Modal(document.getElementById('shortlinkModal'));
  modal.show();
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

    // ✅ Jika berhasil
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
    const last7DaysLabels = <?= json_encode(array_keys($chartDates)) ?>;
const last7DaysClicks = <?= json_encode(array_values($chartDates)) ?>;
  // Baca mode (light/dark) dari body
  const isDark = document.body.getAttribute('data-theme-version') === 'dark';

  // Opsi dasar
  const options = {
    series: [{
      name: 'Total Klik',
      data: last7DaysClicks
    }],
    chart: {
      type: 'bar',
      height: '100%',
      toolbar: { show: false },
      background: 'transparent'
    },
    plotOptions: {
      bar: {
        borderRadius: 4,
        horizontal: false,
        columnWidth: '50%'
      }
    },
    dataLabels: { enabled: false },
    xaxis: {
      categories: last7DaysLabels.map(d => {
        // Ubah format tgl jadi e.g. "22 Apr"
        const dt = new Date(d);
        return dt.toLocaleDateString('id-ID', { day:'numeric', month:'short' });
      }),
      labels: {
        style: { colors: Array(7).fill(isDark ? '#fff' : '#333') }
      },
      axisBorder: { show: false },
      axisTicks: { show: false }
    },
    yaxis: {
      labels: {
        style: { colors: isDark ? '#fff' : '#333' }
      }
    },
    grid: {
      borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)'
    },
    tooltip: {
      theme: isDark ? 'light' : 'dark'
    },
    theme: {
      mode: isDark ? 'light' : 'dark',
      palette: 'palette1'
    },
    colors: [ 'var(--primary)' ]
  };

  // Render chart
  const chart = new ApexCharts(
    document.querySelector("#overiewChart"),
    options
  );
  chart.render();

  // Jika switcher mengubah theme, re-render tooltip & label
  document.getElementById('theme_version')?.addEventListener('change', () => {
    const newDark = document.body.getAttribute('data-theme-version') === 'dark';
    chart.updateOptions({
      xaxis: {
        labels: { style: { colors: Array(7).fill(newDark ? '#fff' : '#333') } }
      },
      yaxis: {
        labels: { style: { colors: newDark ? '#fff' : '#333' } }
      },
      grid: { borderColor: newDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)' },
      tooltip: { theme: newDark ? 'dark' : 'light' },
      theme: { mode: newDark ? 'dark' : 'light' }
    });
  });
});
</script>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    new Morris.Donut({
      element: 'morris_donut',
      data: [
        { label: 'Desktop', value: <?= $desktopClicks ?> },
        { label: 'Mobile',  value: <?= $mobileClicks  ?> },
        { label: 'Tablet',  value: <?= $tabletClicks  ?> },
        { label: 'Unknown', value: <?= $unknownClicks ?> }
      ],
      colors: ['#0088FE','#00C49F','#FFBB28','#FF8042'],
      formatter: function (x) { return x + ' clicks'; }
    });
  });
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  new Morris.Donut({
    element: 'morris_referrer',
    data: [
      <?php foreach($referrers as $r): ?>
      { label: '<?= addslashes($r['domain']) ?>', value: <?= $r['cnt'] ?> },
      <?php endforeach; ?>
    ],
    // sesuaikan warna kalau mau
    colors: ['#1921fa','#10ca93','#ff5c00','#ffaa2b','#575df3','#f57f3d','#23e2aa'],
    formatter: function(x){ return x + ' klik'; }
  });
});
</script>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    new Morris.Area({
      element: 'morris_referrer',
      behaveLikeLine: true,
      data: <?= json_encode($chartData) ?>,
      xkey: 'day',
      ykeys: <?= json_encode($ykeys) ?>,
      labels: <?= json_encode(array_map('ucwords', $ykeys)) ?>,
      lineColors: ['#1921fa','#10ca93','#ff5c00'],
      pointSize: 0,
      parseTime: false,
      grid: false,
      resize: true,
      fillOpacity: 0.5
    });
  });
</script>


<script>
document.addEventListener('DOMContentLoaded', function(){
  // Data dari PHP
  const countryStats = <?= json_encode($countryStats, JSON_HEX_TAG) ?>;
  const cityStats    = <?= json_encode($cityStats, JSON_HEX_TAG) ?>;
  const totalCountry = <?= $totalCountryClicks ?>;
  const totalCity    = <?= $totalCityClicks ?>;

  function renderTable(type) {
    const stats = (type === 'country') ? countryStats : cityStats;
    const total = (type === 'country') ? totalCountry : totalCity;
    const tbody = document.getElementById('loc-table-body');
    tbody.innerHTML = '';

    stats.forEach((row, idx) => {
      const name   = type === 'country' ? row.country : row.city;
      const clicks = parseInt(row.clicks, 10);
      const pct    = total ? ((clicks / total) * 100).toFixed(1) + '%' : '0%';
      tbody.insertAdjacentHTML('beforeend', `
        <tr>
          <td>${idx+1}</td>
          <td>${name || 'Unknown'}</td>
          <td>${clicks}</td>
          <td>${pct}</td>
        </tr>
      `);
    });

    document.getElementById('loc-header').textContent = type === 'country' ? 'Country' : 'City';
  }

  // inisiasi awal dengan country
  renderTable('country');

  // toggle buttons
  document.querySelectorAll('.segmented-control button').forEach(btn => {
    btn.addEventListener('click', function(){
      document.querySelectorAll('.segmented-control button')
              .forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      renderTable(this.getAttribute('data-type'));
    });
  });
});
</script>
<!-- setelah tabel country/city -->
<script>
jQuery(function($){
  const rowsPerPage = 8;

  function setup(type){
    const $table = $('#tbl-'+type);
    const $tbody = $table.find('tbody');
    // 1) simpan semua <tr> original hanya sekali
    if (!$table.data('allRows')) {
      $table.data('allRows', $tbody.find('tr').clone());
    }
    const allRows = $table.data('allRows').toArray();
    let filtered   = allRows.slice();
    let currentPage = 1;

    // 2) buat/ambil container pagination
    let $pager = $table.next('.pagination');
    if (!$pager.length) {
      $pager = $('<ul class="pagination pagination-sm mb-4"></ul>');
      $table.after($pager);
    }

    function render(){
      // hitung slice
      const start = (currentPage-1)*rowsPerPage;
      const end   = start + rowsPerPage;
      // kosongkan dan append
      $tbody.empty();
      filtered.slice(start, end).forEach(r => $tbody.append(r));
      // build pager
      const totalPages = Math.max(1, Math.ceil(filtered.length/rowsPerPage));
      let html = '';
      html += `<li class="page-item ${currentPage===1?'disabled':''}">
                 <a class="page-link" href="#" data-page="${currentPage-1}">&laquo;</a>
               </li>`;
      for(let p=1; p<=totalPages; p++){
        html += `<li class="page-item ${p===currentPage?'active':''}">
                   <a class="page-link" href="#" data-page="${p}">${p}</a>
                 </li>`;
      }
      html += `<li class="page-item ${currentPage===totalPages?'disabled':''}">
                 <a class="page-link" href="#" data-page="${currentPage+1}">&raquo;</a>
               </li>`;
      $pager.html(html);
    }

    // 3) event click pager
    $pager.off('click').on('click','a.page-link',function(e){
      e.preventDefault();
      const p = parseInt($(this).data('page'));
      if (!isNaN(p) && p>=1 && p<=Math.ceil(filtered.length/rowsPerPage)) {
        currentPage = p;
        render();
      }
    });

    // 4) pertama kali render
    render();
  }

  // init untuk keduanya
  setup('country');
  setup('city');

  // re-setup (cukup render) tiap kali tab di-show
  $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e){
    const type = $(e.target).data('bsTarget').replace('#pane-','');
    setup(type);
  });
});
</script>
<script>
  const packages = {
    medium: { title: 'Paket Medium', price: 350000 },
    vip:    { title: 'Paket VIP',    price: 650000 }
  };
  let selectedPackage = null;
  let selectedMethod  = null;

  function showOptions(pkgKey) {
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
        <button class="btn btn-outline-secondary method-btn" data-method="BCAVA">
          <img src="/assets/logos/bca.png" width="24" class="me-1">BCA Virtual Account
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
          package: selectedPackage.title.toLowerCase().split(' ')[1],
          method: selectedMethod
        };
        for (let k in fields) {
          const inp = document.createElement('input'); inp.type='hidden'; inp.name=k; inp.value=fields[k]; form.appendChild(inp);
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
  </body>
</html>
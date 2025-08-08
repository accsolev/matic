<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/ajax/ddos_protection.php';
session_start();

// === LOAD DATABASE ===
require_once 'includes/db.php';

// === HANDLER: SHORTLINK SUBDOMAIN ACAK ===
// Mendukung akses: domain.com/bebas, /terserah, /acak, dst (asal short_code sudah ada dan mode subdo random)
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if (!preg_match('/^[a-zA-Z0-9_\-]{3,32}$/', $uri)) {
    // Tetap amankan filter karakter untuk shortlink + subdo mode
    header("HTTP/1.0 404 Not Found");
    header("Location: /404.html");
    exit;
}

// Ambil data link
$stmt = $pdo->prepare("
  SELECT l.*, d.domain as main_domain 
  FROM links l 
  JOIN domains d ON l.domain_id = d.id 
  WHERE l.short_code=? 
  LIMIT 1
");
$stmt->execute([$uri]);
$link = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$link) {
    header("HTTP/1.0 404 Not Found");
    header("Location: /404.html");
    exit;
}

// === Handler SUBDO RANDOM ===
if (!empty($link['is_subdo_random']) && !empty($link['domain_subdo'])) {
    // Generate subdomain random (5-9 karakter)
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $len = random_int(5, 9);
    $subd = '';
    for ($i=0; $i<$len; $i++) $subd .= $chars[random_int(0, strlen($chars)-1)];

    // === REFERAL (tambahan path, opsional) ===
    $referal = !empty($link['referal_path']) ? '/' . ltrim($link['referal_path'],'/') : '';

    $target = "https://$subd." . $link['domain_subdo'] . $referal;

    // Log click jika mau (opsional)
    if (function_exists('logClick')) logClick($pdo, (int)$link['id']);

    // Hitung statistik
    $pdo->prepare("UPDATE links SET clicks = clicks + 1 WHERE id = ?")->execute([$link['id']]);

    header("Location: $target", true, 302);
    exit;
}

// === HELPER GEOLOCATION ===
require_once __DIR__ . '/vendor/autoload.php';
use GeoIp2\Database\Reader;
$reader = null;
try {
    $reader = new Reader(__DIR__ . '/GeoLite2-City.mmdb');
} catch (Exception $e) {
    $reader = null; // Tidak fatal
}
function getGeo($ip) {
    global $reader;
    if (!$reader) return [
        'country' => 'Unknown',
        'countryCode' => 'XX',
        'city' => 'Unknown'
    ];
    try {
        $record = $reader->city($ip);
        return [
            'country' => $record->country->name ?? 'Unknown',
            'countryCode' => $record->country->isoCode ?? 'XX',
            'city' => $record->city->name ?? 'Unknown'
        ];
    } catch (Exception $e) {
        return [
            'country' => 'Unknown',
            'countryCode' => 'XX',
            'city' => 'Unknown'
        ];
    }
}
// === HELPER DEVICE & BROWSER ===
function getDeviceInfo(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/mobile/i', $ua))  return 'Mobile';
    if (preg_match('/tablet/i', $ua))  return 'Tablet';
    return 'Desktop';
}

function getBrowserInfo(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (strpos($ua, 'Chrome')  !== false) return 'Chrome';
    if (strpos($ua, 'Firefox') !== false) return 'Firefox';
    if (strpos($ua, 'Safari')  !== false && strpos($ua, 'Chrome') === false) return 'Safari';
    if (strpos($ua, 'Edge')    !== false) return 'Edge';
    if (strpos($ua, 'Opera')   !== false || strpos($ua, 'OPR/') !== false) return 'Opera';
    return 'Unknown';
}

// === LOG CLICK DENGAN GEO ===
function logClick(PDO $pdo, int $linkId): void {
    $ip      = getClientIp();
    $geo     = getGeo($ip);
    $device  = getDeviceInfo();
    $browser = getBrowserInfo();
    $ref     = $_SERVER['HTTP_REFERER'] ?? 'Direct';

    $stmt = $pdo->prepare("
      INSERT INTO analytics 
        (link_id, ip_address, country, city, device, browser, referrer) 
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $linkId,
        $ip,
        $geo['country'],
        $geo['city'],
        $device,
        $browser,
        $ref
    ]);
}

function blockCloudflareIP($ip) {
    global $zoneIds;
    $cf_api = 'https://api.cloudflare.com/client/v4/';
    foreach ($zoneIds as $zoneId) {
        $data = [
            'mode' => 'block',
            'configuration' => ['target' => 'ip', 'value' => $ip],
            'notes' => 'Auto DDoS block SFLINK'
        ];
        $ch = curl_init($cf_api . "zones/$zoneId/firewall/access_rules/rules");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "X-Auth-Email: " . CF_EMAIL,
                "X-Auth-Key: "   . CF_API_KEY,
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS     => json_encode($data)
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

// === REDIRECT 404 HELPER ===
function redirectTo404(): void {
    header("HTTP/1.0 404 Not Found");
    header("Location: /404.html");
    exit;
}

// === URL BLOCK CHECKER (optional) ===
function isUrlBlocked($url) {
    // Ambil domain utama dari URL
    $domain = parse_url($url, PHP_URL_HOST) ?: $url;
    $domain = strtolower(preg_replace('/^www\./', '', $domain));
    if (!$domain || !preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $domain)) {
        return false; // domain tidak valid, dianggap tidak diblokir
    }

    $checkUrl = "https://trustpositif.komdigi.go.id/?domains=" . urlencode($domain);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $checkUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) return false; // jika gagal ambil data, dianggap tidak diblokir (safe fallback)

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if ($dom->loadHTML($html)) {
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//table//tr') as $row) {
            $cols = $xpath->query('td', $row);
            if ($cols->length >= 2) {
                $st = trim($cols->item(1)->nodeValue);
                return $st === 'Ada'; // TRUE = DIBLOKIR, FALSE = AMAN
            }
        }
    }
    libxml_clear_errors();
    return false; // default: dianggap aman kalau tidak ketemu
}

// --- FLEXIBLE MAIN DOMAIN BUILDER ---
function urlFromMainDomain($domain, $path) {
    $path = '/' . ltrim($path, '/');
    return "https://$domain$path";
}

function getClientIp() {
    $keys = [
        'HTTP_CF_CONNECTING_IP',  // Cloudflare
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ipList = explode(',', $_SERVER[$k]);
            foreach ($ipList as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP) && $ip !== '127.0.0.1') {
                    return $ip;
                }
            }
        }
    }
    return '0.0.0.0';
}

// === PROSES REDIRECT ===
// (kode berikut ini bisa diletakkan langsung setelah blok subdo random di atas jika ingin cek shortlink biasa)
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if (!preg_match('/^[a-zA-Z0-9]{3,30}$/', $uri)) {
    redirectTo404();
}

// Ambil detail link
$stmt = $pdo->prepare("SELECT * FROM links WHERE short_code = ?");
$stmt->execute([$uri]);
$link = $stmt->fetch(PDO::FETCH_ASSOC);
if (empty($link)) redirectTo404();

$linkId = (int)$link['id'];

// --- AMBIL MAIN DOMAIN LOGIC + STATUS AKTIF ---
$useMainDomain = isset($link['use_main_domain']) && $link['use_main_domain'];
$mainDomain = null;
$fallbackDomain = null;
$mainDomainPaths = [];
$fallbackPaths = [];

if ($useMainDomain && !empty($link['main_domain_id'])) {
    $stmt = $pdo->prepare("SELECT domain, fallback_domain FROM main_domains WHERE id = ?");
    $stmt->execute([$link['main_domain_id']]);
    $mainDomainData = $stmt->fetch(PDO::FETCH_ASSOC);

    $mainDomain = $mainDomainData['domain'] ?? null;
    $fallbackDomain = $mainDomainData['fallback_domain'] ?? null;

    // Jika main domain diblokir, pakai fallback_domain (jika ada)
    if ($mainDomain && isUrlBlocked('https://' . $mainDomain) && $fallbackDomain) {
        $mainDomain = $fallbackDomain;
    }

    // Ambil array path/fallback (format json dalam db)
    $mainDomainPaths = @json_decode($link['path_url'], true);
    $fallbackPaths = @json_decode($link['fallback_path_url'], true);
    if (!is_array($mainDomainPaths)) $mainDomainPaths = [];
    if (!is_array($fallbackPaths)) $fallbackPaths = [];
}

// Ambil URL utama
$stmt = $pdo->prepare("SELECT url FROM redirect_urls WHERE link_id = ?");
$stmt->execute([$linkId]);
$destUrls = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'url');

// Ambil fallback
$stmt = $pdo->prepare("SELECT url FROM fallback_urls WHERE link_id = ?");
$stmt->execute([$linkId]);
$fallbackUrls = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'url');

// Ambil device targeting
$deviceTargets = [];
$dq = $pdo->prepare("SELECT device_type, url FROM device_targets WHERE link_id = ?");
$dq->execute([$linkId]);
foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $deviceTargets[strtolower($row['device_type'])] = $row['url'];
}

// Ambil white page dan filter negara
$whitePage = $link['white_page_url'] ?: null;
$allowedCountries = array_filter(array_map('trim', explode(',', strtoupper($link['allowed_countries']))));
$blockedCountries = array_filter(array_map('trim', explode(',', strtoupper($link['blocked_countries']))));

// === CEK NEGARA USER ===
$ipUser = getClientIp();
$geo = getGeo($ipUser);
$userCountry = strtoupper($geo['countryCode']);

// === 1. FILTER NEGARA ===
if (!empty($blockedCountries) && in_array($userCountry, $blockedCountries) && $whitePage) {
    header("Location: $whitePage", true, 302);
    exit;
}
if (!empty($allowedCountries) && !in_array($userCountry, $allowedCountries) && $whitePage) {
    header("Location: $whitePage", true, 302);
    exit;
}
// --- 2. LOGIKA DEVICE TARGETING & MAIN DOMAIN ---
$targetUrl = null;
$device = strtolower(getDeviceInfo());

// ====== PRIORITAS DEVICE TARGETING FULL URL ======
if (!empty($deviceTargets[$device])) {
    $dt = trim($deviceTargets[$device]);
    // Full URL: http/https di depan = redirect langsung, bypass main domain
    if (preg_match('~^https?://~i', $dt)) {
        if (!isUrlBlocked($dt)) $targetUrl = $dt;
    }
    // Bukan URL (anggap path): baru treat as main domain + path
    elseif ($useMainDomain && $mainDomain) {
        $url = urlFromMainDomain($mainDomain, $dt);
        if (!isUrlBlocked($url)) $targetUrl = $url;
    }
}

// Kalau masih null, cek logic main domain (path/fallback) atau mode biasa
if ($useMainDomain && $mainDomain && !$targetUrl) {
    // 1. Path utama di main domain
    if (!empty($mainDomainPaths)) {
        foreach ($mainDomainPaths as $mainPath) {
            $url = urlFromMainDomain($mainDomain, $mainPath);
            if (!isUrlBlocked($url)) {
                $targetUrl = $url;
                break;
            }
        }
    }
    // 2. Fallback path di main domain
    if (!$targetUrl && !empty($fallbackPaths)) {
        foreach ($fallbackPaths as $fallbackPath) {
            $url = urlFromMainDomain($mainDomain, $fallbackPath);
            if (!isUrlBlocked($url)) {
                $targetUrl = $url;
                break;
            }
        }
    }
}

// ===== MODE BIASA =====
if (!$useMainDomain && !$targetUrl) {
    // Device targeting (full url)
    if (!empty($deviceTargets[$device])) {
        $dt = trim($deviceTargets[$device]);
        if (preg_match('~^https?://~i', $dt) && !isUrlBlocked($dt)) $targetUrl = $dt;
    }
    // Destination url (RANDOM jika lebih dari 1)
    if (!$targetUrl && !empty($destUrls)) {
        if (count($destUrls) > 1) {
            shuffle($destUrls);
            foreach ($destUrls as $url) {
                if (!isUrlBlocked($url)) {
                    $targetUrl = $url;
                    break;
                }
            }
        } else {
            if (!isUrlBlocked($destUrls[0])) {
                $targetUrl = $destUrls[0];
            }
        }
    }
    // Fallback urls
    if (!$targetUrl && !empty($fallbackUrls)) {
        foreach ($fallbackUrls as $url) {
            if (!isUrlBlocked($url)) {
                $targetUrl = $url;
                break;
            }
        }
    }
}

// Jika semua gagal, redirect ke white page jika ada
if (!$targetUrl && $whitePage && !isUrlBlocked($whitePage)) {
    $targetUrl = $whitePage;
}

// Jika tetap tidak ada, 404
if (!$targetUrl) redirectTo404();

// Log click (dicatat setelah redirect dipastikan sukses)
$pdo->prepare("UPDATE links SET clicks = clicks + 1 WHERE id = ?")->execute([$linkId]);
logClick($pdo, $linkId);

// Redirect!
header("Location: $targetUrl", true, 302);
exit;

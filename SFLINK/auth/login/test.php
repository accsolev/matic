<?php
// Nyalakan full error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- CONFIG --- //
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('DDOS_LIMIT', 60); // Maks request per WINDOW
define('DDOS_WINDOW', 10); // Detik, window time
define('BLOCK_TIME', 1800); // 30 menit block jika melewati limit
define('TELEGRAM_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN'); // isi token bot kamu
define('TELEGRAM_CHAT_ID', 'YOUR_CHAT_ID'); // isi chat id target

// --- REDIS CONNECT --- //
$redis = new Redis();
$redis->connect(REDIS_HOST, REDIS_PORT);

// --- AMBIL IP USER --- //
function getIP() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

$ip = getIP();
$key = "rl:$ip";
$blockKey = "block:$ip";

// --- BLOCKED? --- //
if ($redis->exists($blockKey)) {
    http_response_code(429);
    die("🚫 Akses anda diblokir sementara karena mencurigakan. Hubungi admin jika ini error.");
}

// --- RATE LIMIT --- //
$now = time();
$requests = $redis->incr($key);
if ($requests == 1) $redis->expire($key, DDOS_WINDOW);

if ($requests > DDOS_LIMIT) {
    // Set block
    $redis->setex($blockKey, BLOCK_TIME, $now);
    sendTelegramAlert($ip, $requests);
    http_response_code(429);
    die("🚫 Terlalu banyak request, IP anda diblokir sementara.");
}

// --- ANTI BOT/UA ANEH --- //
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
if (preg_match('/(python|curl|wget|scan|masscan|bot|httpclient|scrapy)/', $ua)) {
    $redis->setex($blockKey, BLOCK_TIME, $now);
    sendTelegramAlert($ip, 999, "Bot UA Detected: $ua");
    http_response_code(403);
    die('Forbidden');
}

// --- TELEGRAM ALERT FUNC --- //
function sendTelegramAlert($ip, $req=0, $extra='') {
    $msg = "🚨 [ANTI-DDOS] IP <code>$ip</code> terblokir ($req req/".DDOS_WINDOW."s)" . ($extra ? "\n$extra" : '') . "\n🕒 ".date('Y-m-d H:i:s');
    $token = TELEGRAM_TOKEN;
    $chatid = TELEGRAM_CHAT_ID;
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = [
        'chat_id' => $chatid,
        'text' => $msg,
        'parse_mode' => 'HTML'
    ];
    // Async send (non-blocking)
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 900);
    curl_exec($ch);
    curl_close($ch);
}
?>
<?php phpinfo(); ?>
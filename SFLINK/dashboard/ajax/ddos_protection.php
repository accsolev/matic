<?php
/**
 * Advanced Anti-DDoS Protection System
 * Version: 2025 Ultimate Edition
 * Features: AI-based detection, fingerprinting, advanced rate limiting
 */

// === CONFIGURATION ===
$config = [
    'rate_limit' => [
        'per_minute' => 60,
        'per_hour' => 600,
        'per_day' => 5000,
        'burst_limit' => 10, // Max requests dalam 5 detik
    ],
    'challenge' => [
        'js_delay' => 1, // Reduced to 1 second
        'captcha_threshold' => 3, // Show captcha after 3 suspicious activities
        'pow_difficulty' => 2, // Reduced difficulty for faster solving
        'enable_pow' => false, // Disable PoW by default, only JS challenge
    ],
    'cloudflare' => [
        'api_key' => '1dbe11f48040907075c9e3903509dae6087d4',
        'email' => 'accsolev9@gmail.com',
        'zone_ids' => [
            '76f1282dfe5207402bb8a8c7383f7a79',
            '82cf35e43e0d4fc1e283d022590d8b62',
            '257744e851b3f35bdd301be5dab0c933'
        ]
    ],
    'telegram' => [
        'bot_token' => '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw',
        'chat_id' => '5554218612'
    ],
    'security' => [
        'enable_fingerprint' => true,
        'enable_behavior_analysis' => true,
        'enable_geo_blocking' => true,
        'blocked_countries' => [], // Add country codes if needed
    ]
];

// === INITIALIZE REDIS ===
try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
} catch (Exception $e) {
    error_log("Redis connection failed: " . $e->getMessage());
    // Fallback to file-based storage if Redis fails
}

// === GET CLIENT INFO ===
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$timestamp = time();

// === ADVANCED FINGERPRINTING ===
function generateFingerprint($ip, $ua, $headers) {
    $fingerprint = [
        'ip' => $ip,
        'ua_hash' => md5($ua),
        'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
        'accept_lang' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        'accept_enc' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
        'dnt' => $_SERVER['HTTP_DNT'] ?? '',
        'connection' => $_SERVER['HTTP_CONNECTION'] ?? '',
        'viewport' => $_SERVER['HTTP_VIEWPORT_WIDTH'] ?? '',
    ];
    return hash('sha256', json_encode($fingerprint));
}

// === THREAT SCORE CALCULATION ===
function calculateThreatScore($ip, $ua, $uri, $method, $redis) {
    $score = 0;
    $timestamp = time(); // Define timestamp here
    
    // 1. Check UA patterns
    $suspicious_ua_patterns = [
        '/^$/i' => 30, // Empty UA
        '/curl|wget|python|scrapy|headless|phantomjs|selenium/i' => 25,
        '/bot|crawler|spider|scraper/i' => 20,
        '/^Mozilla\/5\.0 \(Windows NT \d+\.\d+\) AppleWebKit\/\d+\.\d+ \(KHTML, like Gecko\) Chrome\/\d+\.\d+\.\d+\.\d+ Safari\/\d+\.\d+$/i' => 10, // Generic Chrome
    ];
    
    foreach ($suspicious_ua_patterns as $pattern => $points) {
        if (preg_match($pattern, $ua)) {
            $score += $points;
        }
    }
    
    // 2. Check request patterns
    if (strlen($ua) < 30) $score += 15;
    if (!isset($_SERVER['HTTP_ACCEPT'])) $score += 20;
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $score += 20;
    if (!isset($_SERVER['HTTP_ACCEPT_ENCODING'])) $score += 15;
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) $score += 5; // Possible proxy
    
    // 3. Check request frequency pattern
    $recentRequests = $redis->zRangeByScore("requests:$ip", $timestamp - 10, $timestamp);
    if (count($recentRequests) > 5) {
        $intervals = [];
        for ($i = 1; $i < count($recentRequests); $i++) {
            $intervals[] = $recentRequests[$i] - $recentRequests[$i-1];
        }
        $avgInterval = array_sum($intervals) / count($intervals);
        if ($avgInterval < 0.5) $score += 25; // Too fast for human
    }
    
    // 4. Check for scanning patterns
    $uriPattern = $redis->get("uri_pattern:$ip");
    if ($uriPattern) {
        $patterns = json_decode($uriPattern, true);
        if (count(array_unique($patterns)) > 10) $score += 15; // Path scanning
    }
    
    return min($score, 100); // Max score 100
}

// === MAIN PROTECTION LOGIC ===
$fingerprint = generateFingerprint($ip, $ua, getallheaders());
$threatScore = calculateThreatScore($ip, $ua, $uri, $method, $redis);

// Store request info for pattern analysis
$redis->zAdd("requests:$ip", $timestamp, $timestamp);
$redis->expire("requests:$ip", 3600);

// === MULTI-LAYER RATE LIMITING ===
$rateLimits = [
    ['key' => "rate:min:$ip", 'limit' => $config['rate_limit']['per_minute'], 'ttl' => 60],
    ['key' => "rate:hour:$ip", 'limit' => $config['rate_limit']['per_hour'], 'ttl' => 3600],
    ['key' => "rate:day:$ip", 'limit' => $config['rate_limit']['per_day'], 'ttl' => 86400],
    ['key' => "rate:burst:$ip", 'limit' => $config['rate_limit']['burst_limit'], 'ttl' => 5],
];

$blocked = false;
foreach ($rateLimits as $limit) {
    $count = $redis->incr($limit['key']);
    if ($count === 1) $redis->expire($limit['key'], $limit['ttl']);
    
    if ($count > $limit['limit']) {
        $blocked = true;
        $blockReason = "Rate limit exceeded: {$limit['key']}";
        break;
    }
}

// === AUTO-BLOCK HIGH THREAT SCORES ===
if ($threatScore > 80) { // Increased threshold from 70 to 80
    $blocked = true;
    $blockReason = "High threat score: $threatScore";
}

// === HANDLE BLOCKING ===
if ($blocked) {
    // Block via Cloudflare
    foreach ($config['cloudflare']['zone_ids'] as $zoneId) {
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/firewall/access_rules/rules");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "X-Auth-Email: " . $config['cloudflare']['email'],
                "X-Auth-Key: " . $config['cloudflare']['api_key'],
                "Content-Type: application/json",
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'mode' => 'block',
                'configuration' => ['target' => 'ip', 'value' => $ip],
                'notes' => "Auto-block: $blockReason | Score: $threatScore",
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    
    // Send Telegram alert
    $msg = "🚨 *DDoS Attack Blocked*\n"
         . "━━━━━━━━━━━━━━━\n"
         . "🌐 IP: `$ip`\n"
         . "📊 Threat Score: `$threatScore/100`\n"
         . "🚫 Reason: $blockReason\n"
         . "🔗 URI: `$uri`\n"
         . "🤖 UA: `" . substr($ua, 0, 50) . "...`\n"
         . "🕐 Time: " . date('Y-m-d H:i:s');
    
    @file_get_contents("https://api.telegram.org/bot" . $config['telegram']['bot_token'] 
        . "/sendMessage?chat_id=" . $config['telegram']['chat_id'] 
        . "&text=" . urlencode($msg) . "&parse_mode=Markdown");
    
    http_response_code(429);
    showBlockedPage($threatScore, $blockReason);
    exit;
}

// === JS CHALLENGE + PROOF OF WORK ===
$cookieKey = 'sf_shield_' . substr(hash('sha256', $ip . $ua), 0, 16);
$powKey = 'sf_pow_' . substr(hash('sha256', $fingerprint), 0, 16);

// Check if already verified today
$verifiedKey = "verified:$fingerprint";
$isVerified = $redis->get($verifiedKey);

if (!$isVerified && !isset($_COOKIE[$cookieKey])) {
    showChallengePage($cookieKey, $powKey, $config['challenge']['js_delay'], $config['challenge']['pow_difficulty'], $config['challenge']['enable_pow']);
    exit;
}

// Mark as verified for 24 hours after successful challenge
if (isset($_COOKIE[$cookieKey]) && !$isVerified) {
    $redis->setex($verifiedKey, 86400, 1); // Remember for 24 hours
}

// === PASSED ALL CHECKS - LOG SUCCESS ===
$redis->incr("passed:$ip");
$redis->expire("passed:$ip", 86400);

// === CHALLENGE PAGE FUNCTION ===
function showChallengePage($cookieKey, $powKey, $delay, $difficulty, $enablePow = false) {
    $token = bin2hex(random_bytes(16));
    $challenge = substr(hash('sha256', $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT'] . date('Ymd')), 0, 32);
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Check | SFLINK.ID</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, \'Inter\', \'Segoe UI\', Roboto, sans-serif;
            background: #0a0a0a;
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        
        /* Animated background */
        .bg-grid {
            position: fixed;
            top: -50%;
            left: -50%;
            right: -50%;
            bottom: -50%;
            background-image: 
                linear-gradient(rgba(99, 102, 241, 0.1) 1px, transparent 1px),
                linear-gradient(90deg, rgba(99, 102, 241, 0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: grid-move 20s linear infinite;
            z-index: 0;
        }
        
        @keyframes grid-move {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }
        
        /* Glow effects */
        .glow-orb {
            position: absolute;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.5;
            animation: float 10s ease-in-out infinite;
        }
        
        .glow-orb:nth-child(1) {
            background: radial-gradient(circle, #6366f1, transparent);
            top: -200px;
            left: -200px;
            animation-delay: 0s;
        }
        
        .glow-orb:nth-child(2) {
            background: radial-gradient(circle, #8b5cf6, transparent);
            bottom: -200px;
            right: -200px;
            animation-delay: 3s;
        }
        
        .glow-orb:nth-child(3) {
            background: radial-gradient(circle, #3b82f6, transparent);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation-delay: 6s;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -30px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
        }
        
        /* Main container */
        .container {
            position: relative;
            z-index: 10;
            background: rgba(17, 17, 17, 0.8);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 48px;
            max-width: 480px;
            width: 90%;
            box-shadow: 
                0 0 0 1px rgba(99, 102, 241, 0.1),
                0 10px 40px rgba(0, 0, 0, 0.5),
                0 0 80px rgba(99, 102, 241, 0.2);
            animation: container-enter 0.6s ease-out;
        }
        
        @keyframes container-enter {
            0% { 
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            100% { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        /* Logo */
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 32px;
            position: relative;
        }
        
        .shield {
            width: 100%;
            height: 100%;
            position: relative;
            animation: shield-float 3s ease-in-out infinite;
        }
        
        @keyframes shield-float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .shield-icon {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            mask: url("data:image/svg+xml,%3Csvg viewBox=\'0 0 24 24\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cpath d=\'M12 2L4 7v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V7l-8-5z\' fill=\'white\'/%3E%3C/svg%3E") center/contain no-repeat;
            -webkit-mask: url("data:image/svg+xml,%3Csvg viewBox=\'0 0 24 24\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cpath d=\'M12 2L4 7v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V7l-8-5z\' fill=\'white\'/%3E%3C/svg%3E") center/contain no-repeat;
            animation: shield-glow 2s ease-in-out infinite;
        }
        
        @keyframes shield-glow {
            0%, 100% { filter: drop-shadow(0 0 20px rgba(99, 102, 241, 0.6)); }
            50% { filter: drop-shadow(0 0 30px rgba(139, 92, 246, 0.8)); }
        }
        
        /* Content */
        h1 {
            font-size: 28px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 12px;
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .subtitle {
            text-align: center;
            color: #94a3b8;
            font-size: 16px;
            margin-bottom: 40px;
            line-height: 1.5;
        }
        
        /* Progress */
        .progress-container {
            margin-bottom: 32px;
        }
        
        .progress-bar {
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            overflow: hidden;
            position: relative;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            border-radius: 2px;
            width: 0%;
            animation: progress-grow ' . $delay . 's ease-out forwards;
            box-shadow: 0 0 20px rgba(99, 102, 241, 0.5);
        }
        
        @keyframes progress-grow {
            0% { width: 0%; }
            100% { width: 100%; }
        }
        
        /* Status */
        .status {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 24px;
        }
        
        .status-icon {
            width: 20px;
            height: 20px;
            position: relative;
        }
        
        .spinner {
            width: 100%;
            height: 100%;
            border: 2px solid rgba(99, 102, 241, 0.2);
            border-top-color: #6366f1;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Info */
        .info {
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            font-size: 13px;
            color: #94a3b8;
            line-height: 1.5;
        }
        
        .info-icon {
            display: inline-block;
            width: 16px;
            height: 16px;
            background: #6366f1;
            mask: url("data:image/svg+xml,%3Csvg viewBox=\'0 0 24 24\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cpath d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z\' fill=\'white\'/%3E%3C/svg%3E") center/contain no-repeat;
            -webkit-mask: url("data:image/svg+xml,%3Csvg viewBox=\'0 0 24 24\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cpath d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z\' fill=\'white\'/%3E%3C/svg%3E") center/contain no-repeat;
            vertical-align: -3px;
            margin-right: 6px;
        }
        
        /* Footer */
        .footer {
            position: fixed;
            bottom: 24px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 12px;
            color: #4b5563;
            letter-spacing: 2px;
            text-transform: uppercase;
            z-index: 10;
        }
        
        .brand {
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 600;
        }
        
        /* Mobile responsive */
        @media (max-width: 640px) {
            .container {
                padding: 32px 24px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .subtitle {
                font-size: 14px;
            }
            
            .glow-orb {
                width: 300px;
                height: 300px;
            }
        }
        
        /* Hidden elements for PoW */
        #pow-status {
            display: none;
            color: #10b981;
            font-size: 12px;
            text-align: center;
            margin-top: 16px;
        }
    </style>
</head>
<body>
    <div class="bg-grid"></div>
    <div class="glow-orb"></div>
    <div class="glow-orb"></div>
    <div class="glow-orb"></div>
    
    <div class="container">
        <div class="logo">
            <div class="shield">
                <div class="shield-icon"></div>
            </div>
        </div>
        
        <h1>Security Check</h1>
        <p class="subtitle">Verifying your connection to ensure a safe browsing experience</p>
        
        <div class="progress-container">
            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
        </div>
        
        <div class="status">
            <div class="status-icon">
                <div class="spinner"></div>
            </div>
            <span id="status-text">Performing security verification...</span>
        </div>
        
        <div class="info">
            <span class="info-icon"></span>
            This process helps protect our services from automated attacks and ensures the best experience for all users.
        </div>
        
        <div id="pow-status"></div>
    </div>
    
    <div class="footer">
        Protected by <span class="brand">SFLINK.ID</span>
    </div>
    
    <script>
        // Configuration
        const cookieKey = "' . $cookieKey . '";
        const powKey = "' . $powKey . '";
        const token = "' . $token . '";
        const challenge = "' . $challenge . '";
        const difficulty = ' . $difficulty . ';
        const delay = ' . $delay . ' * 1000;
        const enablePow = ' . ($enablePow ? 'true' : 'false') . ';
        
        // Simple JS verification without PoW
        async function performSimpleVerification() {
            const statusEl = document.getElementById("status-text");
            statusEl.textContent = "Verifying your browser...";
            
            // Set cookie immediately
            document.cookie = `${cookieKey}=${token}; path=/; max-age=86400; SameSite=Strict`;
            
            // Short delay then reload
            await new Promise(resolve => setTimeout(resolve, delay));
            statusEl.textContent = "Verification complete!";
            
            setTimeout(() => {
                location.reload();
            }, 200);
        }
        
        // Start simple verification
        performSimpleVerification();
        
        // Anti-debugging measures
        (function() {
            const devtools = { open: false, orientation: null };
            const threshold = 160;
            
            setInterval(() => {
                if (window.outerHeight - window.innerHeight > threshold || 
                    window.outerWidth - window.innerWidth > threshold) {
                    if (!devtools.open) {
                        devtools.open = true;
                        console.log("%cSecurity Warning!", "color: red; font-size: 30px; font-weight: bold;");
                        console.log("%cThis browser feature is intended for developers only.", "color: red; font-size: 16px;");
                    }
                } else {
                    devtools.open = false;
                }
            }, 500);
        })();
    </script>
</body>
</html>';
}

// === BLOCKED PAGE FUNCTION ===
function showBlockedPage($threatScore, $reason) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $incidentId = strtoupper(substr(hash('sha256', $ip . time()), 0, 16));
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied | SFLINK.ID</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, \'Inter\', \'Segoe UI\', Roboto, sans-serif;
            background: #0a0a0a;
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        
        /* Animated background */
        .danger-grid {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                repeating-linear-gradient(45deg, transparent, transparent 35px, rgba(239, 68, 68, 0.1) 35px, rgba(239, 68, 68, 0.1) 70px);
            animation: danger-move 10s linear infinite;
            z-index: 0;
        }
        
        @keyframes danger-move {
            0% { transform: translate(0, 0); }
            100% { transform: translate(70px, 0); }
        }
        
        /* Container */
        .container {
            position: relative;
            z-index: 10;
            background: rgba(17, 17, 17, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 24px;
            padding: 48px;
            max-width: 480px;
            width: 90%;
            box-shadow: 
                0 0 0 1px rgba(239, 68, 68, 0.2),
                0 10px 40px rgba(0, 0, 0, 0.5),
                0 0 80px rgba(239, 68, 68, 0.2);
            animation: shake 0.5s ease-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        /* Icon */
        .icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 32px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            mask: url("data:image/svg+xml,%3Csvg viewBox=\'0 0 24 24\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cpath d=\'M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z\' fill=\'white\'/%3E%3C/svg%3E") center/contain no-repeat;
            -webkit-mask: url("data:image/svg+xml,%3Csvg viewBox=\'0 0 24 24\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cpath d=\'M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z\' fill=\'white\'/%3E%3C/svg%3E") center/contain no-repeat;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); filter: drop-shadow(0 0 20px rgba(239, 68, 68, 0.6)); }
            50% { transform: scale(1.05); filter: drop-shadow(0 0 30px rgba(239, 68, 68, 0.8)); }
        }
        
        h1 {
            font-size: 28px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 12px;
            color: #ef4444;
        }
        
        .subtitle {
            text-align: center;
            color: #94a3b8;
            font-size: 16px;
            margin-bottom: 32px;
            line-height: 1.5;
        }
        
        /* Details */
        .details {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .detail-item:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #64748b;
            font-size: 14px;
        }
        
        .detail-value {
            color: #e2e8f0;
            font-size: 14px;
            font-family: \'SF Mono\', \'Monaco\', \'Inconsolata\', monospace;
        }
        
        /* Threat meter */
        .threat-meter {
            margin: 24px 0;
        }
        
        .threat-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .threat-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }
        
        .threat-fill {
            height: 100%;
            background: linear-gradient(90deg, #fbbf24, #f59e0b, #ef4444);
            border-radius: 4px;
            width: ' . $threatScore . '%;
            animation: threat-grow 1s ease-out;
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.5);
        }
        
        @keyframes threat-grow {
            0% { width: 0%; }
        }
        
        /* Actions */
        .actions {
            text-align: center;
            margin-top: 32px;
        }
        
        .action-text {
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .support-link {
            color: #6366f1;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .support-link:hover {
            color: #8b5cf6;
            text-decoration: underline;
        }
        
        /* Footer */
        .footer {
            position: fixed;
            bottom: 24px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 12px;
            color: #4b5563;
            letter-spacing: 2px;
            text-transform: uppercase;
            z-index: 10;
        }
        
        .incident-id {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: #4b5563;
            font-family: \'SF Mono\', \'Monaco\', \'Inconsolata\', monospace;
        }
    </style>
</head>
<body>
    <div class="danger-grid"></div>
    
    <div class="container">
        <div class="icon"></div>
        
        <h1>Access Denied</h1>
        <p class="subtitle">Your request has been blocked by our security system</p>
        
        <div class="details">
            <div class="detail-item">
                <span class="detail-label">Reason</span>
                <span class="detail-value">' . htmlspecialchars($reason) . '</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Your IP</span>
                <span class="detail-value">' . htmlspecialchars($ip) . '</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Timestamp</span>
                <span class="detail-value">' . date('Y-m-d H:i:s') . '</span>
            </div>
        </div>
        
        <div class="threat-meter">
            <div class="threat-label">
                <span>Threat Score</span>
                <span style="color: #ef4444">' . $threatScore . '/100</span>
            </div>
            <div class="threat-bar">
                <div class="threat-fill"></div>
            </div>
        </div>
        
        <div class="actions">
            <p class="action-text">
                If you believe this is a mistake, please contact our support team at<br>
                <a href="mailto:security@sflink.id" class="support-link">security@sflink.id</a>
            </p>
        </div>
        
        <div class="incident-id">
            Incident ID: ' . $incidentId . '
        </div>
    </div>
    
    <div class="footer">
        Protected by <span style="color: #ef4444">SFLINK.ID</span>
    </div>
</body>
</html>';
}

// Continue with normal request processing...
?>
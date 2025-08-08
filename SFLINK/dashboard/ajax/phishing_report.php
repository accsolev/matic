<?php
// File: ajax/phishing_report.php
session_start();
require '../../includes/auth.php';
include '../../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keyword = trim($_POST['keyword']);
    $whitelist_raw = trim($_POST['whitelist']);
    $whitelist = array_map('trim', explode("\n", $whitelist_raw));

    $SERP_API_KEY = "c82e8f0808dca1320c3ec77a28666ae7708404e5e048c911bfb39fbba2977445";
    $TELEGRAM_BOT_TOKEN = "7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw";

    $stmt = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $TELEGRAM_CHAT_ID = $stmt->fetchColumn();

    // ✅ Simpan Telegram ID ke file agar Python bisa baca
    $telegram_file = "telegram_id_user_{$_SESSION['user_id']}.txt";
    file_put_contents($telegram_file, $TELEGRAM_CHAT_ID);

    $results = getGoogleResults($keyword, $SERP_API_KEY);
    $filtered = array_filter($results, fn($url) => isPhishing($url, $whitelist));

    $target_file = "targets_user_{$_SESSION['user_id']}.txt";
    file_put_contents($target_file, implode("\n", $filtered));

    // Tandai status awal = processing
    file_put_contents("report_status_user_{$_SESSION['user_id']}.txt", "processing");

    // Jalankan Python async dengan user_id
    $cmd = "python3 reporter.py {$_SESSION['user_id']} > /dev/null 2>&1 &";
    exec($cmd);

    sendTelegram("🚨 <b>Scan dimulai</b> dengan keyword: <code>$keyword</code>.\nTotal <b>" . count($filtered) . "</b> link mencurigakan dikirim ke <b>Google SafeBrowsing</b>.", $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID);

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'processing',
        'keyword' => $keyword,
        'detected_links' => array_values($filtered)
    ]);
    exit;
}

function sendTelegram($msg, $token, $chat_id) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = ["chat_id" => $chat_id, "text" => $msg, "parse_mode" => "HTML"];
    file_get_contents($url . "?" . http_build_query($data));
}

function isPhishing($url, $whitelist) {
    foreach ($whitelist as $white) {
        if (stripos($url, $white) !== false) return false;
    }
    return true;
}

function getGoogleResults($keyword, $api_key) {
    $params = http_build_query([
        "api_key" => $api_key,
        "engine" => "google",
        "q" => $keyword,
        "hl" => "id",
        "gl" => "id",
        "num" => 20,
        "device" => "mobile"
    ]);
    $url = "https://serpapi.com/search?$params";
    $response = @file_get_contents($url);

    if ($response === false || empty($response)) return [];
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) return [];

    $links = [];
    if (!empty($data['organic_results'])) {
        foreach ($data['organic_results'] as $result) {
            if (!empty($result['link'])) {
                $links[] = $result['link'];
            }
        }
    }
    return $links;
}

<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

require_once '../../includes/db.php';
header('Content-Type: application/json');

function logActivity($pdo, $userId, $username, $action) {
  $now = date('Y-m-d H:i:s'); // format waktu Asia/Jakarta (sudah diset timezone di atas)
  $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
  $stmt->execute([$userId, $username, $action, $now]);
}

// ✅ Fungsi notifikasi Telegram
function sendTelegramNotif($userId, $pdo, $message) {
    $stmt = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $telegramId = $stmt->fetchColumn();

    if (!$telegramId) return;

    $token = '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw';
    $text = urlencode($message);
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?chat_id=$telegramId&text=$text");
}

// ✅ Cek login session
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Unknown';
$id = intval($_POST['id'] ?? 0);

// ✅ Cek apakah link milik user
$stmt = $pdo->prepare("
    SELECT l.short_code, d.domain 
    FROM links l
    JOIN domains d ON l.domain_id = d.id
    WHERE l.id = ? AND l.user_id = ?
");
$stmt->execute([$id, $userId]);
$link = $stmt->fetch();

if (!$link) {
    echo json_encode(['success' => false, 'message' => 'Shortlink tidak ditemukan atau bukan milik Anda.']);
    exit;
}

$fullUrl = $link['domain'] . '/' . $link['short_code'];

// ✅ Hapus link
$stmt = $pdo->prepare("DELETE FROM links WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $userId]);

// ✅ Kirim notifikasi & log aktivitas
sendTelegramNotif($userId, $pdo, "⚠️️ Anda telah menghapus shortlink: https://$fullUrl");
logActivity($pdo, $userId, $username, "Menghapus shortlink: https://$fullUrl");

echo json_encode(['success' => true, 'message' => "Shortlink berhasil dihapus: <code>$fullUrl</code>"]);
exit;

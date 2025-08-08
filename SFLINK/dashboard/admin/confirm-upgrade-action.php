<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
date_default_timezone_set('Asia/Jakarta');

$requestId = $_POST['request_id'] ?? 0;
$userId = $_POST['user_id'] ?? 0;
$type = $_POST['upgrade_type'] ?? '';
$action = $_POST['action'] ?? '';

if (!$requestId || !$userId || !in_array($type, ['medium', 'vip']) || !in_array($action, ['confirm', 'reject'])) {
  die('Data tidak valid.');
}

if ($action === 'confirm') {
  $startDate = date('Y-m-d H:i:s');
  $expireDate = date('Y-m-d H:i:s', strtotime('+1 month'));

  // ✅ Update ke tabel upgrade_requests
  $pdo->prepare("UPDATE upgrade_requests SET status = 'confirmed', updated_at = NOW(), upgraded_at = ?, expires_at = ? WHERE id = ?")
      ->execute([$startDate, $expireDate, $requestId]);

  // ✅ Update tipe user di tabel users juga
  $pdo->prepare("UPDATE users SET type = ? WHERE id = ?")->execute([$type, $userId]);

  // Kirim notifikasi Telegram
  $stmt = $pdo->prepare("SELECT username, telegram_id FROM users WHERE id = ?");
  $stmt->execute([$userId]);
  $user = $stmt->fetch();

  $username = $user['username'] ?? 'User';
  $telegramId = $user['telegram_id'] ?? null;

  if ($telegramId) {
    $msg = "📢 Permintaan upgrade <b>$type</b> anda telah diproses.\n" .
           "👤 Username: <b>$username</b>\n" .
           "📆 Aktif: <b>" . date('d M Y H:i', strtotime($startDate)) . "</b>\n" .
           "⏳ Expired: <b>" . date('d M Y H:i', strtotime($expireDate)) . "</b>\n\n" .
           "Jika ada kendala, silakan hubungi kami. Terima kasih!";

    $token = '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw';
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?chat_id=$telegramId&text=" . urlencode($msg) . "&parse_mode=HTML");
  }

} else {
  $pdo->prepare("UPDATE upgrade_requests SET status = 'rejected', updated_at = NOW() WHERE id = ?")
      ->execute([$requestId]);
}

header('Location: index.php');
exit;
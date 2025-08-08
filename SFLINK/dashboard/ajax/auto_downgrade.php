<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ✅ Include DB dan set timezone
require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
date_default_timezone_set('Asia/Jakarta');

// Ambil semua user yang sudah expired
$stmt = $pdo->query("
  SELECT r.id AS request_id, r.user_id, r.expires_at, u.username, u.telegram_id
  FROM upgrade_requests r
  JOIN users u ON r.user_id = u.id
  WHERE r.status = 'confirmed' AND r.expires_at < NOW()
");

$expiredUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Token Telegram
$token = '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw';

foreach ($expiredUsers as $user) {
  $userId = $user['user_id'];
  $username = $user['username'];
  $telegramId = $user['telegram_id'];
  $requestId = $user['request_id'];

  // Update user ke trial
  $pdo->prepare("UPDATE users SET type = 'trial' WHERE id = ?")->execute([$userId]);

  // Tandai upgrade request sebagai expired
  $pdo->prepare("UPDATE upgrade_requests SET status = 'expired', updated_at = NOW() WHERE id = ?")->execute([$requestId]);

  // Kirim notifikasi Telegram
  if ($telegramId) {
    $msg = "⚠️ Masa upgrade akun <b>$username</b> telah berakhir.\n" .
           "Akun Anda kini kembali ke status <b>TRIAL</b>.\n\n" .
           "Silakan upgrade kembali untuk mengakses fitur premium. 🙏";

    file_get_contents("https://api.telegram.org/bot$token/sendMessage?chat_id=$telegramId&text=" . urlencode($msg) . "&parse_mode=HTML");
  }
}

echo "✅ Auto downgrade selesai. Jumlah user diturunkan: " . count($expiredUsers);
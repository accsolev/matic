<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require '../../includes/db.php';

header('Content-Type: application/json');

function logActivity($pdo, $userId, $username, $action) {
  $now = date('Y-m-d H:i:s'); // format waktu Asia/Jakarta (sudah diset timezone di atas)
  $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
  $stmt->execute([$userId, $username, $action, $now]);
}


// Pastikan user login
if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => '❌ Anda belum login.']);
  exit;
}

$userId = $_SESSION['user_id'];
$upgradeType = $_POST['upgrade_type'] ?? '';
$validTypes = ['medium', 'vip'];

if (!in_array($upgradeType, $validTypes)) {
  echo json_encode(['success' => false, 'message' => '❌ Tipe upgrade tidak valid.']);
  exit;
}

// Ambil kurs dari file lokal
$rateData = @file_get_contents(__DIR__ . '/get-rate.json');
$rateJson = json_decode($rateData, true);

if (!$rateJson || !isset($rateJson['rate'])) {
  echo json_encode(['success' => false, 'message' => '❌ Gagal mengambil kurs terkini.']);
  exit;
}

$rate = floatval($rateJson['rate']);
if ($rate <= 0) {
  echo json_encode(['success' => false, 'message' => '❌ Kurs tidak valid.']);
  exit;
}

// Harga USD
$pricesUSD = [
  'medium' => 18.88,
  'vip' => 37.77
];

$amount = round($pricesUSD[$upgradeType] * $rate);

// Cek request pending
$stmt = $pdo->prepare("SELECT COUNT(*) FROM upgrade_requests WHERE user_id = ? AND status = 'pending'");
$stmt->execute([$userId]);
if ($stmt->fetchColumn() > 0) {
  echo json_encode(['success' => false, 'message' => '❗ Permintaan upgrade Anda masih dalam proses. Mohon tunggu verifikasi admin.']);
  exit;
}

// Simpan permintaan upgrade
$stmt = $pdo->prepare("INSERT INTO upgrade_requests (user_id, upgrade_type, amount, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
$stmt->execute([$userId, $upgradeType, $amount]);

// Ambil info user
$stmt = $pdo->prepare("SELECT username, telegram_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$username = $user['username'] ?? 'Unknown';
$telegram_id = $user['telegram_id'] ?? null;

// ✅ Catat log aktivitas
logActivity($pdo, $userId, $username, "Mengajukan upgrade ke <b>" . strtoupper($upgradeType) . "</b> (Rp " . number_format($amount) . ")");

// Kirim notifikasi ke admin via Telegram
$adminTelegramId = '5554218612';
$botToken = '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw';

$message = "📥 <b>Permintaan Upgrade Baru</b>\n👤 User: <b>$username</b>\n💳 Tipe: <b>" . strtoupper($upgradeType) . "</b>\n💰 Jumlah: Rp " . number_format($amount) . "\n🕒 " . date('d M Y H:i') . "\n\n✅ Silakan verifikasi via panel admin.";

file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?chat_id=$adminTelegramId&text=" . urlencode($message) . "&parse_mode=HTML");

echo json_encode(['success' => true, 'message' => '✅ Permintaan upgrade berhasil dikirim. Admin akan memverifikasi pembayaran Anda.']);

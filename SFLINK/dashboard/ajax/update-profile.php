<?php
session_start();
require_once '../../includes/db.php';
header('Content-Type: application/json');
date_default_timezone_set('Asia/Jakarta');
// 🔧 Fungsi log aktivitas
function logActivity($pdo, $userId, $username, $action) {
  $now = date('Y-m-d H:i:s'); // format waktu Asia/Jakarta (sudah diset timezone di atas)
  $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
  $stmt->execute([$userId, $username, $action, $now]);
}

function sendTelegramNotif($userId, $pdo, $message, $toPersonal = true, $toGroup = true) {
    $stmt = $pdo->prepare("SELECT telegram_id, telegram_group_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) return false;

    $token = '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw';
    $text = urlencode($message);

    if ($toPersonal && !empty($user['telegram_id'])) {
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?chat_id={$user['telegram_id']}&text=$text");
    }

    if ($toGroup && !empty($user['telegram_group_id'])) {
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?chat_id={$user['telegram_group_id']}&text=$text");
    }
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '🚫 Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Unknown';
$newPassword = trim($_POST['new_password'] ?? '');
$confirmPassword = trim($_POST['confirm_password'] ?? '');
$telegramId = trim($_POST['telegram_id'] ?? '');
$telegramGroupId = trim($_POST['telegram_group_id'] ?? '');
$notifToPersonal = isset($_POST['notif_to_personal']) ? 1 : 0;
$notifToGroup = isset($_POST['notif_to_group']) ? 1 : 0;

$updateFields = [];
$params = [];
$notifMessages = [];
$logMessages = [];

$stmt = $pdo->prepare("SELECT password, telegram_id, telegram_group_id, notif_to_personal, notif_to_group FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch();

$oldTelegramId = $userData['telegram_id'];
$oldTelegramGroupId = $userData['telegram_group_id'];
$oldNotifPersonal = $userData['notif_to_personal'];
$oldNotifGroup = $userData['notif_to_group'];

if (!empty($telegramId) && $telegramId !== $oldTelegramId) {
    if (!ctype_digit($telegramId)) {
        echo json_encode(['success' => false, 'message' => '❌ Telegram ID harus berupa angka.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ? AND id != ?");
    $stmt->execute([$telegramId, $userId]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => "❌ Telegram ID <b>$telegramId</b> sudah digunakan."]);
        exit;
    }

    $updateFields[] = "telegram_id = ?";
    $params[] = $telegramId;
    $notifMessages[] = "📲 Anda berhasil menghubungkan akun Telegram pribadi ke SFLINK.ID.";
    $logMessages[] = "Mengubah Telegram ID menjadi $telegramId";
}

if (!empty($telegramGroupId) && $telegramGroupId !== $oldTelegramGroupId) {
    if (!ctype_digit(str_replace('-', '', $telegramGroupId))) {
        echo json_encode(['success' => false, 'message' => '❌ Telegram Group ID harus berupa angka (boleh minus).']);
        exit;
    }

    $updateFields[] = "telegram_group_id = ?";
    $params[] = $telegramGroupId;
    $notifMessages[] = "👥 Bot SFLINK.ID berhasil terhubung ke Grup Telegram Anda.";
    $logMessages[] = "Mengubah Telegram Group ID menjadi $telegramGroupId";
}

if ($notifToPersonal != $oldNotifPersonal) {
    $updateFields[] = "notif_to_personal = ?";
    $params[] = $notifToPersonal;
    if ($notifToPersonal) {
        $notifMessages[] = "✅ Notifikasi ke Telegram pribadi berhasil diaktifkan.";
        $logMessages[] = "Mengaktifkan notifikasi Telegram pribadi";
    } else {
        $logMessages[] = "Menonaktifkan notifikasi Telegram pribadi";
    }
}

if ($notifToGroup != $oldNotifGroup) {
    $updateFields[] = "notif_to_group = ?";
    $params[] = $notifToGroup;
    if ($notifToGroup) {
        $notifMessages[] = "✅ Notifikasi ke Grup Telegram berhasil diaktifkan.";
        $logMessages[] = "Mengaktifkan notifikasi Grup Telegram";
    } else {
        $logMessages[] = "Menonaktifkan notifikasi Grup Telegram";
    }
}

if ($newPassword || $confirmPassword) {
    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => '❌ Password tidak cocok.']);
        exit;
    }

    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => '❌ Password minimal 6 karakter.']);
        exit;
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateFields[] = "password = ?";
    $params[] = $hashedPassword;
    $notifMessages[] = "🔐 Password akun Anda berhasil diubah.";
    $logMessages[] = "Mengubah password akun";
}

if (!empty($updateFields)) {
    $params[] = $userId;
    $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?");
    $stmt->execute($params);

    foreach ($notifMessages as $msg) {
        sendTelegramNotif($userId, $pdo, $msg, $notifToPersonal, $notifToGroup);
    }

    foreach ($logMessages as $log) {
        logActivity($pdo, $userId, $username, $log);
    }

    echo json_encode([
        'success' => true,
        'message' => count($notifMessages) > 0 ? '✅ Profil berhasil diperbarui & notifikasi terkirim.' : '✅ Profil berhasil diperbarui.'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => '⚠️ Tidak ada perubahan yang dikirim.']);
}

<?php
// File: ajax/send-telegram.php
session_start();
require '../../../includes/auth.php';
require_login();

// Cek kalau bukan admin
if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'admin') {
  echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
  exit;
}

// Bot Token Telegram
$botToken = '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw';

// Ambil input
$chatId = trim($_POST['chat_id'] ?? '');
$message = trim($_POST['message'] ?? '');
$hasPhoto = isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK;

if (empty($chatId) || empty($message)) {
  echo json_encode(['success' => false, 'message' => 'Chat ID dan pesan wajib diisi.']);
  exit;
}

if ($hasPhoto) {
  // Kirim gambar
  $url = "https://api.telegram.org/bot$botToken/sendPhoto";
  $photoPath = $_FILES['photo']['tmp_name'];
  $postData = [
    'chat_id' => $chatId,
    'caption' => $message,
    'parse_mode' => 'HTML',
    'photo' => new CURLFile($photoPath)
  ];
} else {
  // Kirim teks biasa
  $url = "https://api.telegram.org/bot$botToken/sendMessage";
  $postData = [
    'chat_id' => $chatId,
    'text' => $message,
    'parse_mode' => 'HTML'
  ];
}

$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL => $url,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $postData,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
  echo json_encode(['success' => false, 'message' => 'Gagal menghubungi Telegram API.']);
} else {
  $result = json_decode($response, true);
  if (!empty($result['ok'])) {
    echo json_encode(['success' => true, 'message' => '✅ Pesan berhasil dikirim.']);
  } else {
    $errorDesc = $result['description'] ?? 'Unknown error';
    echo json_encode(['success' => false, 'message' => '❌ ' . $errorDesc]);
  }
}
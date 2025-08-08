<?php
$botToken = '7694118793';

// Ambil daftar grup
$groups = file('group_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// Pesan yang mau dikirim
$message = "Halo semua! Ini update otomatis dari bot saya.";

foreach ($groups as $chatId) {
  $url = "https://api.telegram.org/bot$botToken/sendMessage";
  $data = [
    'chat_id' => trim($chatId),
    'text' => $message,
    'parse_mode' => 'HTML'
  ];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  curl_exec($ch);
  curl_close($ch);
}
?>
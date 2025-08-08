<?php
// File: includes/telegram.php

function kirimTelegram($telegramId, $pesan) {
    $token = 'TOKEN_BOT_KAMU'; // Ganti dengan token bot aslimu
    $url = "https://api.telegram.org/bot$token/sendMessage";

    $data = [
        'chat_id' => $telegramId,
        'text' => $pesan,
        'parse_mode' => 'HTML'
    ];

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded",
            'content' => http_build_query($data),
        ]
    ];
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context); // suppress warning jika gagal
}
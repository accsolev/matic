<?php
function sendTelegramLog($userId, $pdo, $message) {
    $token = '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw';

    // Ambil telegram_id dari database
    $stmt = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row || empty($row['telegram_id'])) return; // jika tidak ada telegram_id, skip

    $chatId = $row['telegram_id'];
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type:application/x-www-form-urlencoded",
            'content' => http_build_query($data),
        ]
    ];

    file_get_contents($url, false, stream_context_create($options));
}
<?php
require_once 'includes/db.php'; // file koneksi PDO

header('Content-Type: application/json');

// Hapus shortlink yang lebih dari 1 menit
$pdo->exec("DELETE FROM shortlinks_nologin WHERE created_at < (NOW() - INTERVAL 1 MINUTE)");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url'] ?? '');

    // Tambahkan https:// jika tidak ada
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    // Validasi URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['status' => 'error', 'message' => 'URL tidak valid!']);
        exit;
    }

    // Generate kode unik
    do {
        $shortCode = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM shortlinks_nologin WHERE short_code = ?");
        $stmt->execute([$shortCode]);
    } while ($stmt->fetchColumn() > 0);

    // Simpan ke database
    $stmt = $pdo->prepare("INSERT INTO shortlinks_nologin (short_code, original_url) VALUES (?, ?)");
    $stmt->execute([$shortCode, $url]);

    $shortUrl = "https://sflink.id/" . $shortCode;

echo json_encode([
    'status' => 'success',
    'short_url' => $shortUrl,
    'message' => 'This just example,<br>if you want to real shortlink please <a href=/login>login</a>'
]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
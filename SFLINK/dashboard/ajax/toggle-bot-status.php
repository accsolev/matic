<?php
session_start();
require '../../includes/auth.php';
require_login();
require '../../includes/db.php';

$userId = $_SESSION['user_id'];
$status = isset($_POST['status']) ? (int)$_POST['status'] : 0;

// Cek apakah mau aktifkan bot?
if ($status == 1) {
    // Ambil Telegram ID & Group ID user
    $q = $pdo->prepare("SELECT telegram_id, telegram_group_id FROM users WHERE id=? LIMIT 1");
    $q->execute([$userId]);
    $u = $q->fetch(PDO::FETCH_ASSOC);

    if (empty($u['telegram_id']) && empty($u['telegram_group_id'])) {
        // Dua-duanya kosong → error
        echo json_encode([
            'success' => false,
            'message' => 'Masukan Telegram ID atau Telegram Group ID anda terlebih dahulu sebelum mengaktifkan bot! | Status: Gagal'
        ]);
        exit;
    }
}

// Lolos cek, update status semua domain user
$q = $pdo->prepare("UPDATE list_domains SET status=? WHERE user_id=?");
$q->execute([$status, $userId]);

echo json_encode(['success' => true]);

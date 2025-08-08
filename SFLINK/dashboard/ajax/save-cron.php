<?php
session_start();
header('Content-Type: application/json');
require '../../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$interval = isset($_POST['interval_minute']) ? (int) $_POST['interval_minute'] : 0;
$status = isset($_POST['status']) ? (int) $_POST['status'] : 0;

// Ambil tipe user (trial / medium / vip)
$stmt = $pdo->prepare("SELECT type FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userType = $stmt->fetchColumn();

// Validasi umum
if ($interval < 1 || $interval > 1440) {
    echo json_encode(['success' => false, 'message' => '❌ Interval tidak valid (1–1440 menit).']);
    exit;
}

// Validasi interval minimum sesuai tipe
if ($userType === 'trial' && $interval < 5) {
    echo json_encode(['success' => false, 'message' => '⛔ Akun trial hanya bisa mengatur interval minimal 5 menit.']);
    exit;
} elseif ($userType === 'medium' && $interval < 5) {
    echo json_encode(['success' => false, 'message' => '⚠️ Akun medium hanya bisa mengatur interval minimal 5 menit.']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE list_domains SET interval_minute = ?, status = ? WHERE user_id = ?");
    $stmt->execute([$interval, $status, $userId]);

    $message = $status == 1
        ? "✅ Pengaturan cron berhasil disimpan. Bot akan cek setiap {$interval} menit sekali."
        
        : "⛔ Cron job dinonaktifkan. Bot tidak akan melakukan pengecekan otomatis.";

    echo json_encode(['success' => true, 'message' => $message]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '❌ Gagal menyimpan: ' . $e->getMessage()]);
}
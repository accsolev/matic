<?php
session_start();
date_default_timezone_set('Asia/Jakarta'); // ⬅️ Tambahkan ini
require '../../includes/db.php';

header('Content-Type: application/json');

// Cek login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '❌ Anda belum login.']);
    exit;
}

$userId = $_SESSION['user_id'];

// Ambil kritik
$stmt = $pdo->prepare("
    SELECT kritik_saran, 
           DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', '+07:00'), '%d %M %Y %H:%i') AS created_at
    FROM kritik_saran
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'kritik' => $data
]);
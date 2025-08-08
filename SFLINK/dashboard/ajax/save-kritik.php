<?php
session_start();
require '../../includes/db.php'; // pastikan ini sudah konek PDO ke database

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => '❌ Anda belum login.']);
  exit;
}

$kritik = trim($_POST['kritik_saran'] ?? '');

if (!$kritik) {
  echo json_encode(['success' => false, 'message' => '❌ Kritik & Saran tidak boleh kosong.']);
  exit;
}

// Simpan ke tabel kritik_saran
$stmt = $pdo->prepare("INSERT INTO kritik_saran (user_id, kritik_saran, created_at) VALUES (?, ?, NOW())");
$success = $stmt->execute([$_SESSION['user_id'], $kritik]);

if ($success) {
  echo json_encode(['success' => true, 'message' => 'Terima kasih! atas kritikan dan masukan anda berhasil di kirim!']);
} else {
  echo json_encode(['success' => false, 'message' => '❌ Gagal mengirim kritik & saran.']);
}
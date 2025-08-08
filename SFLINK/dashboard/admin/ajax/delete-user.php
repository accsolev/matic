<?php
require '../../../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
  echo json_encode(['success' => false, 'message' => '❌ Permintaan tidak valid.']);
  exit;
}

$id = (int) $_POST['id'];

try {
  $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
  $stmt->execute([$id]);

  if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true, 'message' => '✅ User berhasil dihapus.']);
  } else {
    echo json_encode(['success' => false, 'message' => '❌ User tidak ditemukan.']);
  }
} catch (Exception $e) {
  echo json_encode(['success' => false, 'message' => '❌ Terjadi kesalahan saat menghapus.']);
}
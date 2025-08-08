<?php
require '../../../includes/db.php';
session_start();
header('Content-Type: application/json');

if ($_SESSION['username'] !== 'admin') {
  echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
  exit;
}

$action = $_POST['action'] ?? '';
$requestId = intval($_POST['request_id'] ?? 0);

if ($action === 'confirm') {
  $userId = intval($_POST['user_id'] ?? 0);
  $upgradeType = $_POST['upgrade_type'] ?? '';

  $pdo->prepare("UPDATE users SET type = ? WHERE id = ?")->execute([$upgradeType, $userId]);
  $pdo->prepare("UPDATE upgrade_requests SET status = 'approved' WHERE id = ?")->execute([$requestId]);

  $username = $pdo->query("SELECT username FROM users WHERE id = $userId")->fetchColumn();
  $pdo->prepare("INSERT INTO activity_logs (user_id, username, action) VALUES (?, ?, ?)")
      ->execute([$userId, $username, "Meningkatkan akun ke <b>$upgradeType</b>"]);

  echo json_encode(['success' => true, 'message' => '✅ Upgrade berhasil dikonfirmasi.']);
} elseif ($action === 'reject') {
  $pdo->prepare("UPDATE upgrade_requests SET status = 'rejected' WHERE id = ?")->execute([$requestId]);
  echo json_encode(['success' => true, 'message' => '❌ Permintaan ditolak.']);
} else {
  echo json_encode(['success' => false, 'message' => 'Aksi tidak valid.']);
}
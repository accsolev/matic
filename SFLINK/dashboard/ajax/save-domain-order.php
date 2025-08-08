<?php
session_start();
require '../../includes/db.php';
$userId = $_SESSION['user_id'] ?? 0;

$data = json_decode(file_get_contents('php://input'), true);
$order = $data['order'] ?? [];

if (!$order || !$userId) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid data']);
  exit;
}

foreach ($order as $i => $id) {
  $stmt = $pdo->prepare("UPDATE list_domains SET sort_order=? WHERE id=? AND user_id=?");
  $stmt->execute([$i+1, $id, $userId]);
}

echo json_encode(['success' => true]);

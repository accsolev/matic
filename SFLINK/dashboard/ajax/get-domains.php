<?php
session_start();
require '../../includes/auth.php';
require_login();
require '../../includes/db.php';

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT id, domain FROM list_domains WHERE user_id = ? ORDER BY sort_order ASC, id ASC");
$stmt->execute([$userId]);
$domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'domains' => $domains]);

<?php
session_start();
require '../../includes/db.php';
$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT id, domain FROM main_domains WHERE user_id=? ORDER BY id DESC");
$stmt->execute([$userId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

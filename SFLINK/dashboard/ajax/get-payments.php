<?php
session_start();
require '../../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT amount, status, created_at FROM payments WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);

$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(array_map(function($p) {
    return [
        'amount' => number_format($p['amount'], 0, ',', '.'),
        'status' => $p['status'],
        'date' => date('d-m-Y H:i', strtotime($p['created_at']))
    ];
}, $payments));

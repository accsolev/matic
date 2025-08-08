<?php
session_start();
require '../../../includes/auth.php';
require_login();
require '../../../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT k.id, k.kritik_saran, k.created_at, u.username
        FROM kritik_saran k
        LEFT JOIN users u ON k.user_id = u.id
        ORDER BY k.created_at DESC
    ");
    $kritikList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'kritik' => $kritikList
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>
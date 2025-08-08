<?php
require '../../includes/db.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_POST['domains'])) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

$userId = $_SESSION['user_id'];
$raw = trim($_POST['domains']);
$lines = array_filter(array_map('trim', explode("\n", $raw)));

$response = [];

foreach ($lines as $domain) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM list_domains WHERE domain = ? AND user_id = ?");
    $stmt->execute([$domain, $userId]);
    $exists = $stmt->fetchColumn() > 0;

    $response[] = [
        'domain' => $domain,
        'exists' => $exists
    ];
}

echo json_encode(['success' => true, 'results' => $response]);

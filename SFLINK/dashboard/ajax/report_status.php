<?php
session_start();
require '../../includes/auth.php';

$userId = $_SESSION['user_id'] ?? null;
$logFile = __DIR__ . "/../../logs/report_done_{$userId}.txt";

header('Content-Type: application/json');

if (!$userId || !file_exists($logFile)) {
    echo json_encode(['status' => 'processing']);
    exit;
}

$doneMessage = trim(file_get_contents($logFile));
unlink($logFile);

echo json_encode([
    'status' => 'done',
    'message' => $doneMessage
]);
exit;
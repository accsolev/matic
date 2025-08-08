<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../includes/db.php';
$newPlain = 'awea';     // password yang ingin kamu set
$newHash  = password_hash($newPlain, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
$stmt->execute([$newHash, 'seoweb']);

echo "Password telah di-reset ke: $newPlain";
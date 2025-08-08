<?php
session_start();
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
}

setcookie('rememberme', '', time() - 3600, "/"); // hapus cookie
session_destroy();
header("Location: ../login");
exit;
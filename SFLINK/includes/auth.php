<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

// Auto login via cookie (remember me)
if (!isset($_SESSION['user_id']) && isset($_COOKIE['rememberme'])) {
    $token = $_COOKIE['rememberme'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
    }
}

// Fungsi untuk cek login
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Fungsi untuk paksa redirect kalau belum login
function require_login() {
    if (!is_logged_in()) {
        header("Location: ../auth/login/");
        exit;
    }
}

// Fungsi ambil data user aktif
function get_current_user_data($pdo) {
    if (!is_logged_in()) return null;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../../includes/db.php';

// Ambil username dari URL
$username = isset($_GET['username']) ? trim($_GET['username']) : '';
if (!$username) die("Username tidak boleh kosong!");

// Set password baru (bisa diganti sesuai keinginan)
$newPlain = 'add123';
$newHash  = password_hash($newPlain, PASSWORD_DEFAULT);

// Eksekusi update ke database
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
$stmt->execute([$newHash, $username]);

if ($stmt->rowCount() > 0) {
    echo "Password untuk user <b>$username</b> telah di-reset ke: <b>$newPlain</b>";
} else {
    echo "Gagal reset password. Username tidak ditemukan atau sudah sama.";
}
?>
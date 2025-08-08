<?php
session_start();
require '../../includes/db.php';

header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success'=>false, 'message'=>'Not logged in!']); exit;
}

$ownerId = $_SESSION['user_id'];

// Pastikan owner username tersedia di session (untuk validasi prefix)
if (!isset($_SESSION['username'])) {
  echo json_encode(['success'=>false, 'message'=>'Session username not found!']); exit;
}
$ownerUser = $_SESSION['username'];

// Dapatkan data dari POST (username sudah gabung dari JS hidden input)
$username = trim($_POST['team_username'] ?? '');
$email    = trim($_POST['team_email'] ?? '');
$password = trim($_POST['team_password'] ?? '');

// Cek prefix username agar selalu format: {owner}_suffix
if (strpos($username, $ownerUser . '_') !== 0) {
    echo json_encode(['success'=>false, 'message'=>'Username harus diawali: ' . $ownerUser . '_']); exit;
}

// Validation
if (strlen($username)<3 || strlen($username)>30) die(json_encode(['success'=>false, 'message'=>'Username 3-30 karakter!']));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) die(json_encode(['success'=>false, 'message'=>'Email tidak valid!']));
if (strlen($password)<6) die(json_encode(['success'=>false, 'message'=>'Password minimal 6 karakter!']));

// Cek username/email sudah dipakai?
$stmt = $pdo->prepare("SELECT 1 FROM users WHERE username=? OR email=?");
$stmt->execute([$username, $email]);
if ($stmt->fetch()) die(json_encode(['success'=>false, 'message'=>'Username/Email sudah digunakan!']));

// Ambil paket/level user owner
$stmt = $pdo->prepare("SELECT type FROM users WHERE id = ?");
$stmt->execute([$ownerId]);
$ownerType = $stmt->fetchColumn();
if (!$ownerType) $ownerType = 'trial'; // fallback default

// Hash password
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    // Insert user team dengan parent_user_id DAN paket sama owner
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, parent_user_id, type) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$username, $email, $hash, $ownerId, $ownerType]);
    $team_id = $pdo->lastInsertId();

    echo json_encode(['success'=>true, 'message'=>'Team berhasil ditambahkan!', 'team_id'=>$team_id]);
} catch (PDOException $e) {
    echo json_encode(['success'=>false, 'message'=>'Gagal menambah team: ' . $e->getMessage()]);
}

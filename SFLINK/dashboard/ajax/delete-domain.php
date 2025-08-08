<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require '../../includes/auth.php';
require_login();
require '../../includes/db.php';
header('Content-Type: application/json');

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Unknown';
$id = $_POST['id'] ?? null;
$ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : null;

function logActivity($pdo, $userId, $username, $action) {
  $now = date('Y-m-d H:i:s');
  $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
  $stmt->execute([$userId, $username, $action, $now]);
}

// Jika ada data bulk (ids array)
if ($ids && is_array($ids) && count($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    // Ambil nama domain untuk log
    $query = "SELECT id, domain FROM list_domains WHERE id IN ($placeholders) AND user_id = ?";
    $params = $ids;
    $params[] = $userId;
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $domains = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id => domain]

    if (!$domains) {
        echo json_encode(['success' => false, 'message' => 'Domain tidak ditemukan atau bukan milik Anda.']);
        exit;
    }

    // Hapus
    $delQuery = "DELETE FROM list_domains WHERE id IN ($placeholders) AND user_id = ?";
    $delParams = $ids;
    $delParams[] = $userId;
    $delStmt = $pdo->prepare($delQuery);
    $success = $delStmt->execute($delParams);

    if ($success) {
        foreach ($domains as $d) {
            logActivity($pdo, $userId, $username, "Menghapus domain dari daftar: $d");
        }
    }

    echo json_encode(['success' => $success]);
    exit;
}

// Single delete (fallback)
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID domain tidak ditemukan.']);
    exit;
}

// Ambil nama domain untuk log
$stmt = $pdo->prepare("SELECT domain FROM list_domains WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $userId]);
$domain = $stmt->fetchColumn();

if (!$domain) {
    echo json_encode(['success' => false, 'message' => 'Domain tidak ditemukan atau bukan milik Anda.']);
    exit;
}

// Hapus domain
$stmt = $pdo->prepare("DELETE FROM list_domains WHERE id = ? AND user_id = ?");
$success = $stmt->execute([$id, $userId]);

if ($success) {
    logActivity($pdo, $userId, $username, "Menghapus domain dari daftar: $domain");
}

echo json_encode(['success' => $success]);

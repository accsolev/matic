<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require '../../includes/auth.php';
require_login();
require '../../includes/db.php';

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Unknown';

if (!$userId || empty($_POST['domains'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or empty input.']);
    exit;
}

function logActivity($pdo, $userId, $username, $action) {
  $now = date('Y-m-d H:i:s'); // format waktu Asia/Jakarta (sudah diset timezone di atas)
  $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
  $stmt->execute([$userId, $username, $action, $now]);
}
// Ambil tipe user
$stmt = $pdo->prepare("SELECT type FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userType = $stmt->fetchColumn(); // trial, medium, vip

// Tentukan limit berdasarkan tipe user
function getDomainLimit($type) {
    return match ($type) {
        'trial' => 1,
        'medium' => 3,
        'vip' => 30,
        'vipmax' => 100,
        default => 9999
    };
}

$maxDomainAllowed = getDomainLimit($userType);

// Hitung jumlah domain yang sudah ada
$stmt = $pdo->prepare("SELECT COUNT(*) FROM list_domains WHERE user_id = ?");
$stmt->execute([$userId]);
$currentDomainCount = $stmt->fetchColumn();

$domainsInput = trim($_POST['domains']);
$domains = array_filter(array_map('trim', explode("\n", $domainsInput)));

$remainingSlot = $maxDomainAllowed - $currentDomainCount;
if ($remainingSlot <= 0) {
    echo json_encode([
        'success' => false,
        'message' => "⚠️ Akun $userType hanya dapat menambahkan maksimal ($maxDomainAllowed) domain. Anda sudah mencapai batas."
    ]);
    exit;
}

if (count($domains) > $remainingSlot) {
    echo json_encode([
        'success' => false,
        'message' => "⚠️ Akun $userType hanya dapat menambahkan maksimal ($maxDomainAllowed) domain. Anda sudah memiliki $currentDomainCount domain, jadi hanya bisa menambahkan maksimal $remainingSlot lagi."
    ]);
    exit;
}

$addedDomains = [];
$existingDomains = [];

foreach ($domains as $domain) {
    $domain = strtolower(preg_replace('/^https?:\/\//', '', $domain));
    if (!preg_match('/^([a-z0-9\-\.]+\.[a-z0-9\-\.]+|(?:\d{1,3}\.){3}\d{1,3})$/', $domain)) continue;


    $stmt = $pdo->prepare("SELECT id FROM list_domains WHERE domain = ? AND user_id = ?");
    $stmt->execute([$domain, $userId]);

    if ($stmt->fetch()) {
        $existingDomains[] = $domain;
    } else {
        $created_at = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO list_domains (user_id, domain, status, created_at) VALUES (?, ?, 1, ?)");
        $stmt->execute([$userId, $domain, $created_at]);
        $addedDomains[] = $domain;
    }
}

// Logging aktivitas user
if (!empty($addedDomains)) {
    logActivity($pdo, $userId, $username, "Menambahkan domain baru: " . implode(', ', $addedDomains));
}
if (!empty($existingDomains)) {
    logActivity($pdo, $userId, $username, "Mencoba menambahkan domain yang sudah ada: " . implode(', ', $existingDomains));
}

$message = '';
if ($addedDomains) {
    $message .= "✅ Domain berhasil ditambahkan:\n" . implode("\n", $addedDomains) . "\n\n";
}
if ($existingDomains) {
    $message .= "⚠️ Sudah terdaftar sebelumnya:\n" . implode("\n", $existingDomains);
}

echo json_encode([
    'success' => true,
    'message' => nl2br(htmlspecialchars($message))
]);

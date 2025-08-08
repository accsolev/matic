<?php
require '../../includes/db.php';
session_start();
date_default_timezone_set('Asia/Jakarta');
$userId = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'unknown';

function logActivity($pdo, $userId, $username, $action) {
  $now = date('Y-m-d H:i:s'); // format waktu Asia/Jakarta (sudah diset timezone di atas)
  $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
  $stmt->execute([$userId, $username, $action, $now]);
}


// Per Hari per Shortlink
$perDay = $pdo->prepare("
  SELECT 
    l.short_code,
    d.domain,
    DATE(a.created_at) AS date,
    COUNT(*) AS clicks
  FROM analytics a
  JOIN links l ON a.link_id = l.id
  JOIN domains d ON l.domain_id = d.id
  WHERE l.user_id = ?
  GROUP BY l.id, date
  ORDER BY date DESC
");
$perDay->execute([$userId]);
$perDayData = $perDay->fetchAll(PDO::FETCH_ASSOC);

// Berdasarkan Negara
$byCountry = $pdo->prepare("
  SELECT 
    l.short_code,
    d.domain,
    a.country,
    COUNT(*) AS clicks
  FROM analytics a
  JOIN links l ON a.link_id = l.id
  JOIN domains d ON l.domain_id = d.id
  WHERE l.user_id = ?
  GROUP BY l.id, a.country
  ORDER BY clicks DESC
");
$byCountry->execute([$userId]);
$byCountryData = $byCountry->fetchAll(PDO::FETCH_ASSOC);

// Berdasarkan Perangkat
$byDevice = $pdo->prepare("
  SELECT 
    l.short_code,
    d.domain,
    a.device,
    COUNT(*) AS clicks
  FROM analytics a
  JOIN links l ON a.link_id = l.id
  JOIN domains d ON l.domain_id = d.id
  WHERE l.user_id = ?
  GROUP BY l.id, a.device
  ORDER BY clicks DESC
");
$byDevice->execute([$userId]);
$byDeviceData = $byDevice->fetchAll(PDO::FETCH_ASSOC);

// Kirim data JSON
echo json_encode([
  'perDay' => $perDayData,
  'byCountry' => $byCountryData,
  'byDevice' => $byDeviceData
]);

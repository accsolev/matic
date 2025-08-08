<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
date_default_timezone_set('Asia/Jakarta');
require '../../includes/db.php';
session_start();
$userId = $_SESSION['user_id'] ?? 0;

// --- Redis Cache 20 detik untuk Recent Activity ---
$redis = null;
try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379, 1); // Timeout 1 detik
} catch (Throwable $e) {}

$cacheKey = "recent_activity:$userId";
if ($redis && $redis->exists($cacheKey)) {
    header('Content-Type: application/json');
    echo $redis->get($cacheKey);
    exit;
}

// 10 aktivitas terakhir (bisa dinaikkan kalau mau, asal LIMIT kecil)
$stmt = $pdo->prepare("
  SELECT
    a.created_at,
    l.short_code,
    d.domain,
    a.country,
    a.city,
    a.device,
    a.browser,
    a.referrer
  FROM analytics a
  JOIN links l ON a.link_id = l.id
  JOIN domains d ON l.domain_id = d.id
  WHERE l.user_id = ?
  ORDER BY a.created_at DESC
  LIMIT 10
");
$stmt->execute([$userId]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Simpan ke Redis 20 detik
if ($redis) $redis->setex($cacheKey, 20, json_encode($data));

header('Content-Type: application/json');
echo json_encode($data);
?>

<?php
// File: ajax/get-recent-links.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Jakarta');
require '../../includes/db.php';
session_start();

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 7;
$offset = ($page - 1) * $limit;

$searchClause = '';
$params = [$userId];
if ($search !== '') {
  $searchClause = " AND (l.short_code LIKE ? OR d.domain LIKE ?)";
  $params[] = "%$search%";
  $params[] = "%$search%";
}

// Total Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM links l JOIN domains d ON l.domain_id = d.id WHERE l.user_id = ? $searchClause");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();

// --- UPGRADE: JOIN main_domains & SELECT kolom baru ---
$sql = "SELECT 
            l.id, l.short_code, d.domain, l.created_at,
            l.white_page_url, l.allowed_countries, l.blocked_countries,
            l.use_main_domain, l.main_domain_id, 
            md.domain as main_domain, 
            l.path_url, l.fallback_path_url
        FROM links l 
        JOIN domains d ON l.domain_id = d.id 
        LEFT JOIN main_domains md ON l.main_domain_id = md.id
        WHERE l.user_id = ? $searchClause 
        ORDER BY l.created_at DESC 
        LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recentLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lengkapi dengan destination, fallback, dan device_targets
foreach ($recentLinks as &$link) {
  $linkId = $link['id'];

  // Destination URLs
  $stmt2 = $pdo->prepare("SELECT url FROM redirect_urls WHERE link_id = ?");
  $stmt2->execute([$linkId]);
  $link['destinations'] = $stmt2->fetchAll(PDO::FETCH_COLUMN);

  // Fallback URLs
  $stmt2 = $pdo->prepare("SELECT url FROM fallback_urls WHERE link_id = ?");
  $stmt2->execute([$linkId]);
  $link['fallbacks'] = $stmt2->fetchAll(PDO::FETCH_COLUMN);

  // White page
  $link['white_page'] = $link['white_page_url'] ?? '';

  // Allowed countries (jadikan array biar enak di JS)
  $ac = $link['allowed_countries'] ?? '';
  $link['allowed_countries'] = array_filter(array_map('trim', explode(',', strtoupper($ac))));

  // Blocked countries
  $bc = $link['blocked_countries'] ?? '';
  $link['blocked_countries'] = array_filter(array_map('trim', explode(',', strtoupper($bc))));

  // Device targeting (array of object)
  $stmt2 = $pdo->prepare("SELECT device_type, url FROM device_targets WHERE link_id = ?");
  $stmt2->execute([$linkId]);
  $link['device_targets'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

  // Format tampilan
  $link['full_url'] = $link['domain'] . '/' . $link['short_code'];
  $link['created_at_formatted'] = date('d M Y H:i', strtotime($link['created_at']));

  // Tambahan Fitur Main Domain
  $link['use_main_domain'] = (int)($link['use_main_domain'] ?? 0);
  $link['main_domain_id']  = (int)($link['main_domain_id'] ?? 0);
  $link['main_domain']     = $link['main_domain'] ?? '';
  // Jika field path/fallback path json kosong/null di DB, kembalikan array kosong
  $link['path_url'] = ($link['path_url'] !== null && $link['path_url'] !== '') 
      ? json_decode($link['path_url'], true) : [];
  $link['fallback_path_url'] = ($link['fallback_path_url'] !== null && $link['fallback_path_url'] !== '') 
      ? json_decode($link['fallback_path_url'], true) : [];
}

echo json_encode([
  "success" => true,
  "links" => $recentLinks,
  "total" => (int)$total,
  "per_page" => $limit,
  "page" => $page
]);
exit;
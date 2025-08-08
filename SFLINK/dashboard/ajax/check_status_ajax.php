<?php
require '../../includes/db.php';

header('Content-Type: application/json');
$domain = trim($_GET['domain'] ?? '');

if (!$domain) {
  echo json_encode(['icon' => '⚠️', 'status' => 'Domain kosong']);
  exit;
}

function getStatus($domain) {
  $url = "https://check.skiddle.id/?domain=" . urlencode($domain);

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 TrustCheckBot/1.0'
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if (!$response || $httpCode !== 200) return ['⚠️', 'Tidak bisa ambil data'];

  $json = json_decode($response, true);
  if (!isset($json[$domain]['blocked'])) {
    return ['⚠️', 'Status tidak diketahui'];
  }

  return $json[$domain]['blocked'] ? ['❌', 'Diblokir'] : ['✅', 'Aman'];
}

list($icon, $statusText) = getStatus($domain);
echo json_encode(['icon' => $icon, 'status' => $statusText]);

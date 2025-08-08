<?php
header('Content-Type: application/json');

// Ambil input
$domain = $_GET['domain'] ?? '';
if (!$domain) {
  echo json_encode(['status' => 'error', 'message' => 'Domain kosong']);
  exit;
}

// 🔥 Filter: buang http/https/path/query, ambil domain saja
$parsed = parse_url(trim($domain));
$cleanDomain = $parsed['host'] ?? $parsed['path'];
$cleanDomain = strtolower(preg_replace('/^www\./', '', $cleanDomain));

if (!$cleanDomain || !preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $cleanDomain)) {
  echo json_encode(['status' => 'error', 'message' => 'Domain tidak valid']);
  exit;
}

// 🔁 Cek ke TrustPositif (scrape)
$url = "https://trustpositif.komdigi.go.id/?domains=" . urlencode($cleanDomain);
$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL => $url,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT => 12,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_USERAGENT => 'Mozilla/5.0'
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $httpCode != 200) {
  echo json_encode(['status' => 'error', 'message' => 'Gagal fetch']);
  exit;
}

// Parse hasil tabel TrustPositif
$status = 'unknown';
libxml_use_internal_errors(true);
$dom = new DOMDocument();
if ($dom->loadHTML($html)) {
  $xpath = new DOMXPath($dom);
  foreach ($xpath->query('//table//tr') as $row) {
    $cols = $xpath->query('td', $row);
    if ($cols->length >= 2) {
      $st = trim($cols->item(1)->nodeValue);
      if ($st === 'Ada') {
        $status = 'blocked';
      } elseif ($st === 'Tidak Ada') {
        $status = 'safe';
      }
      break;
    }
  }
}
libxml_clear_errors();

echo json_encode(['status' => $status]);

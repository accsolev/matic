<?php
header('Content-Type: application/json; charset=UTF-8');
error_reporting(0);

$domainInput = trim($_POST['domain'] ?? $_GET['domain'] ?? ''); // <- ini bro!
if (empty($domainInput)) {
    echo json_encode(['success' => false, 'message' => 'Domain kosong']);
    exit;
}
if (!preg_match('~^https?://~', $domainInput)) {
    $domainInput = "https://{$domainInput}";
}

// --- API 1: check-ahrefs (metric utama) ---
$api1 = "https://www.trustpositif.web.id/api/check-ahrefs?domains=" . urlencode($domainInput);
$ch1 = curl_init($api1);
curl_setopt_array($ch1, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_USERAGENT => 'Mozilla/5.0'
]);
$res1 = curl_exec($ch1);
$err1 = curl_error($ch1);
curl_close($ch1);
$data1 = $res1 ? json_decode($res1, true) : null;

// --- API 2: check-ahrefs-traffic (traffic, keywords, dst) ---
$api2 = "https://www.trustpositif.web.id/api/check-ahrefs-traffic?domain=" . urlencode($domainInput);
$ch2 = curl_init($api2);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_USERAGENT => 'Mozilla/5.0'
]);
$res2 = curl_exec($ch2);
$err2 = curl_error($ch2);
curl_close($ch2);
$data2 = $res2 ? json_decode($res2, true) : null;

// --- Compose output ---
$output = [
    'success' => true,
    'domain'  => $domainInput,
    'ahrefs'  => $data1[$domainInput] ?? null, // data utama (DA, backlink, dll)
    'traffic' => $data2 ?? null,               // traffic bulanan, dsb
    'error'   => [],
];

// --- Error reporting if needed ---
if ($err1 || !$data1) $output['error']['api1'] = $err1 ?: 'API1: empty or invalid response';
if ($err2 || !$data2) $output['error']['api2'] = $err2 ?: 'API2: empty or invalid response';

echo json_encode($output);

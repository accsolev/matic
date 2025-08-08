<?php
header('Content-Type: application/json');

$response = file_get_contents("https://open.er-api.com/v6/latest/USD");

if (!$response) {
  echo json_encode(['success' => false, 'error' => 'Gagal mengambil data dari open.er-api.com']);
  exit;
}

$data = json_decode($response, true);

// Ambil dan simpan kurs IDR
if (isset($data['rates']['IDR'])) {
  $rate = $data['rates']['IDR'];

  // Simpan ke file get-rate.json
  $saved = file_put_contents(__DIR__ . '/get-rate.json', json_encode([
    'success' => true,
    'rate' => $rate,
    'updated_at' => date('Y-m-d H:i:s')
  ], JSON_PRETTY_PRINT));

  if (!$saved) {
    echo json_encode(['success' => false, 'error' => 'Gagal menyimpan file rate']);
    exit;
  }

  echo json_encode(['success' => true, 'rate' => $rate]);
} else {
  echo json_encode(['success' => false, 'error' => 'Gagal parsing data kurs', 'parsed' => $data]);
}
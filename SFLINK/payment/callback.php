<?php
// Set header agar Tripay tahu responsenya OK
header("Content-Type: application/json");

// Ambil input dari Tripay (JSON)
$data = json_decode(file_get_contents("php://input"), true);

// Log untuk cek isi callback (debug)
file_put_contents("tripay_callback_log.txt", json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);

// Validasi status pembayaran
if (!isset($data['status']) || !isset($data['reference'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

// Jika pembayaran berhasil
if ($data['status'] === 'PAID') {
    $reference = $data['reference'];
    $amount    = $data['amount'];
    $orderId   = $data['merchant_ref'];

    // 👉 Di sini kamu bisa update status di database jadi "PAID"
    // Misalnya: update_payment_status($reference, 'PAID');

    // Kirim respons ke Tripay
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Pembayaran berhasil diproses']);
    exit;
} else {
    // Kalau belum paid atau gagal, abaikan
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Status bukan PAID, tidak diproses']);
    exit;
}
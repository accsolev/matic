<?php
$input = json_decode(file_get_contents("php://input"), true);

// Simpan ke log (opsional)
file_put_contents("tripay_callback_log.txt", json_encode($input) . PHP_EOL, FILE_APPEND);

// Validasi transaksi berhasil
if ($input['status'] === 'PAID') {
    $reference = $input['reference'];
    $amount    = $input['amount'];
    // TODO: update status transaksi di database
    http_response_code(200);
    echo "OK";
} else {
    http_response_code(400);
    echo "INVALID";
}

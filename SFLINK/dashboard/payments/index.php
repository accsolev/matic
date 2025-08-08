<?php
// dashboard/payments/index.php

session_start();
// Debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../../includes/db.php';
date_default_timezone_set('Asia/Jakarta');

// Tripay credentials
$apiKey       = trim('XZwtQ570AYY8MDj6zaO71Htdb8p0AN0bMwE60Sq2');
$privateKey   = trim('9yVkO-0go48-Nnq7f-AtH1z-mYubX');
$merchantCode = trim('T39858');

// helper untuk log activity
function logActivity(PDO $pdo, int $userId, string $username, string $action) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, username, action, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $username, $action, $now]);
}

// ambil user_id dari session
$userId  = $_SESSION['user_id'] ?? null;
$usernameSession = $_SESSION['username'] ?? '';

// 1) Ambil daftar payment channels dari Tripay API
$ch = curl_init('https://tripay.co.id/api/merchant/payment-channel');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$apiKey}",
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
curl_close($ch);
$channelsData   = json_decode($resp, true)['data'] ?? [];
$allowedMethods = array_column($channelsData, 'code');

// 2) Proses POST (buat transaksi)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil & sanitize input
    $method       = trim($_POST['method']        ?? '');
    $username     = trim($_POST['customer_name'] ?? '');
    $amount       = (int) ($_POST['amount']      ?? 0);
    $package      = trim($_POST['package']       ?? '');
    $merchant_ref = 'INV' . time();

    // Validasi metode
    if (!in_array($method, $allowedMethods, true)) {
        die("Gagal membuat transaksi: Metode “{$method}” tidak valid.");
    }

    // Hitung signature & payload
    $signature = hash_hmac('sha256', $merchantCode . $merchant_ref . $amount, $privateKey);
    $expiredTs = time() + 300; // 5 menit dari sekarang

    $payload = [
        'method'         => $method,
        'merchant_ref'   => $merchant_ref,
        'amount'         => $amount,
        'customer_name'  => $username,
        'customer_email' => $username . '@sflink.id',
        'order_items'    => [[
            'sku'      => 'SKU001',
            'name'     => "Upgrade $package",
            'price'    => $amount,
            'quantity' => 1
        ]],
        'callback_url'   => 'https://sflink.id/dashboard/payments/callback.php',
        'return_url'     => 'https://sflink.id/dashboard/?menu=riwayat-pembayaran',
        'expired_time'   => $expiredTs,
        'signature'      => $signature
    ];

    // Kirim request ke Tripay
    $ch2 = curl_init('https://tripay.co.id/api/transaction/create');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$apiKey}",
            "Content-Type: application/json"
        ],
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 10,
    ]);
    $response = curl_exec($ch2);
    $curlErr  = curl_error($ch2);
    curl_close($ch2);

    if ($curlErr) {
        die("CURL Error: {$curlErr}");
    }

    $result = json_decode($response, true);
    if (empty($result['success']) || $result['success'] !== true) {
        $msg = $result['message'] ?? 'Unknown error';
        die("Gagal membuat transaksi: " . htmlspecialchars($msg, ENT_QUOTES));
    }

    // Ambil data transaksi dari response Tripay
    $data       = $result['data'];
    $reference  = $data['reference'];
    $checkout   = $data['checkout_url'];
    $expiredApi = $data['expired_time'];

    // Format ke DATETIME MySQL (WIB)
    $createdAt = date('Y-m-d H:i:s');
    $expiredAt = date('Y-m-d H:i:s', $expiredApi);

    // Simpan ke DB
    $stmt = $pdo->prepare("
        INSERT INTO payments
          (reference, merchant_ref, method, amount, customer_name,
           status, package, checkout_url, created_at, expired_time)
        VALUES
          (?,         ?,            ?,      ?,      ?,
           ?,      ?,       ?,            ?,           ?)
    ");
    $stmt->execute([
        $reference,
        $merchant_ref,
        $method,
        $amount,
        $username,
        'UNPAID',
        $package,
        $checkout,
        $createdAt,
        $expiredAt
    ]);

    // Log activity jika user_id valid
    if ($userId) {
        $action = sprintf(
            "Transaksi dibuat: Ref %s | Paket %s | Metode %s | Jumlah Rp %s",
            $reference,
            $package,
            $method,
            number_format($amount, 0, ',', '.')
        );
        logActivity($pdo, $userId, $usernameSession, $action);
    }

    // Redirect ke halaman checkout Tripay
    header("Location: {$checkout}");
    exit;
}

// … lanjutkan render halaman HTML jika diperlukan …
?>

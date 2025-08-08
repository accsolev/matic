<?php
require __DIR__ . '/../../includes/db.php';
date_default_timezone_set('Asia/Jakarta');

// Log helper
function logActivity(PDO $pdo, int $userId, string $username, string $action) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, username, action, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $username, $action, $now]);
}

// 1) Paksa MySQL pakai WIB
$pdo->exec("SET time_zone = '+07:00'");

// 2) Debug: tampilkan waktu DB sekarang
echo "DB NOW() = " . $pdo->query("SELECT NOW()")->fetchColumn() . "\n";

// 3) Cari semua pembayaran UNPAID yang sudah lewat expired_time
$stmt = $pdo->query("
    SELECT p.reference, p.customer_name, p.expired_time, u.id AS user_id
      FROM payments p
      JOIN users u ON p.customer_name = u.username
     WHERE p.status = 'UNPAID'
       AND p.expired_time < NOW()
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($rows) === 0) {
    echo "No unpaid rows to expire.\n";
} else {
    echo "Expiring " . count($rows) . " rows:\n";
    foreach ($rows as $r) {
        echo "- {$r['reference']} expired at {$r['expired_time']}\n";

        // Log each expiry
        logActivity(
            $pdo,
            (int)$r['user_id'],
            $r['customer_name'],
            "Payment {$r['reference']} sudah expired"
        );
    }

    // 4) Tandai semua sebagai EXPIRED
    $updated = $pdo->exec("
        UPDATE payments
           SET status = 'EXPIRED'
         WHERE status = 'UNPAID'
           AND expired_time < NOW()
    ");
    echo "Marked as EXPIRED: {$updated} rows\n";
}

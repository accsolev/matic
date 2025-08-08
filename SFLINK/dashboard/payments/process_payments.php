<?php
// cron/upgrade-paid-users.php

// Debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../../includes/db.php';

// Set timezone ke Jakarta, Indonesia
date_default_timezone_set('Asia/Jakarta');

/**
 * Log activity ke table activity_logs
 *
 * @param PDO    $pdo
 * @param int    $userId
 * @param string $username
 * @param string $action
 */
function logActivity(PDO $pdo, int $userId, string $username, string $action) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, username, action, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $username, $action, $now]);
}

// 1) Cari transaksi LUNAS yang user masih 'free'
$sql = "
    SELECT p.reference,
           p.customer_name,
           p.package,
           u.id AS user_id
      FROM payments p
      JOIN users u
        ON p.customer_name = u.username
     WHERE p.status = 'PAID'
       AND u.type   = 'free'
";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Running upgrade at " . date('Y-m-d H:i:s') . " WIB ===\n";

foreach ($rows as $row) {
    $userId   = (int) $row['user_id'];
    $username = $row['customer_name'];
    $package  = $row['package'];

    // 2) Upgrade user type
    $upgrade = $pdo->prepare("
        UPDATE users
           SET type = ?
         WHERE id = ?
    ");
    $upgrade->execute([$package, $userId]);

    // 3) Log ke console/file
    $msg = date('Y-m-d H:i:s') . " — Upgraded {$username} to {$package}";
    echo $msg . "\n";

    // 4) Simpan ke activity_logs
    logActivity(
        $pdo,
        $userId,
        $username,
        "Account upgraded automatically to '{$package}' by admin"
    );
}

echo "=== Done ===\n";
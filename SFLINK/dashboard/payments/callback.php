<?php
require __DIR__ . '/../../includes/db.php';

// 1) Set Jakarta timezone everywhere
date_default_timezone_set('Asia/Jakarta');
$pdo->exec("SET time_zone = '+07:00'");

// 2) Helper to log into activity_logs
function logActivity(PDO $pdo, int $userId, string $username, string $action) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs
          (user_id, username, action, created_at)
        VALUES
          (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $username, $action, $now]);
}

// 3) Read callback JSON
$data = json_decode(file_get_contents("php://input"), true);

// 4) Log the raw callback
$now = date('Y-m-d H:i:s');
file_put_contents(
    __DIR__ . '/callback_log.txt',
    "[{$now}] " . json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL,
    FILE_APPEND
);

// 5) Process only if status=PAID
if (!empty($data['status']) && $data['status'] === 'PAID' && !empty($data['reference'])) {
    $reference = $data['reference'];

    // 5a) Mark payment as PAID
    $pdo->prepare("UPDATE payments SET status='PAID' WHERE reference=?")
        ->execute([$reference]);

    // 5b) Fetch full payment + user info
    $infoStmt = $pdo->prepare("
        SELECT
          p.package,
          p.customer_name,
          p.amount,
          p.created_at   AS requested_at,
          u.id           AS user_id
        FROM payments p
        JOIN users u ON p.customer_name=u.username
        WHERE p.reference=?
        LIMIT 1
    ");
    $infoStmt->execute([$reference]);
    $row = $infoStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $userId      = (int)$row['user_id'];
        $username    = $row['customer_name'];
        $upgradeType = $row['package'];
        $amount      = $row['amount'];
        $requestedAt = $row['requested_at']; // when payment was created

        // 5c) Log “paid” action
        logActivity($pdo, $userId, $username, "Payment marked PAID (ref: {$reference})");

        // 5d) Upgrade user.account type
        $pdo->prepare("UPDATE users SET type=? WHERE id=?")
            ->execute([$upgradeType, $userId]);

        // 5e) Log “upgrade” action
        logActivity($pdo, $userId, $username, "Account upgraded automatically to '{$upgradeType}'");

        // 5f) Calculate expires_at as requested_at + 1 month
        $dtReq = new DateTime($requestedAt, new DateTimeZone('Asia/Jakarta'));
        $dtReq->modify('+1 month');
        $expiresAt = $dtReq->format('Y-m-d H:i:s');
        $upgradedAt = date('Y-m-d H:i:s');

        // 5g) Insert into upgrade_requests
        $stmt = $pdo->prepare("
            INSERT INTO upgrade_requests
              (user_id, upgrade_type, amount, status,
               created_at, updated_at,
               requested_at, upgraded_at, expires_at)
            VALUES
              (?,          ?,            ?,      'confirmed',
               ?,          ?,
               ?,            ?,           ?)
        ");
        $stmt->execute([
            $userId,
            $upgradeType,
            $amount,
            $now,         // created_at
            $now,         // updated_at
            $requestedAt, // requested_at
            $upgradedAt,  // upgraded_at
            $expiresAt    // expires_at = +1 month
        ]);

        // 5h) Log “upgrade_request” insertion
        logActivity(
            $pdo,
            $userId,
            $username,
            "Logged upgrade_request for ref: {$reference}, type: {$upgradeType}, expires: {$expiresAt}"
        );
    }
}

http_response_code(200);
echo json_encode(['success' => true]);

<?php
session_start();
require '../../../includes/auth.php';
require_login();
require '../../../includes/db.php';

// Check admin access
if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

$userId   = $_POST['id'] ?? 0;
$username = trim($_POST['username'] ?? '');
$type     = $_POST['type'] ?? 'trial';
$password = $_POST['password'] ?? '';
$telegram_id = trim($_POST['telegram_id'] ?? '');
$telegram_group_id = trim($_POST['telegram_group_id'] ?? '');

// Convert empty string to NULL for unique fields
$telegram_id = $telegram_id === '' ? null : $telegram_id;
$telegram_group_id = $telegram_group_id === '' ? null : $telegram_group_id;

// Checkbox: jika tidak diceklis maka POST-nya kosong/NULL
$notif_to_personal = isset($_POST['notif_to_personal']) ? 1 : 0;
$notif_to_group    = isset($_POST['notif_to_group']) ? 1 : 0;

if (!$userId || !$username || !in_array($type, ['trial', 'medium', 'vip', 'vipmax'])) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid.']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Check if telegram_id already exists for another user
    if ($telegram_id !== null) {
        $checkTelegramStmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ? AND id != ?");
        $checkTelegramStmt->execute([$telegram_id, $userId]);
        if ($checkTelegramStmt->fetch()) {
            throw new Exception("Telegram ID sudah digunakan oleh user lain");
        }
    }
    
    // Update user data
    if ($password) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $sql = "UPDATE users SET username=?, type=?, password=?, telegram_id=?, telegram_group_id=?, notif_to_personal=?, notif_to_group=? WHERE id=?";
        $params = [$username, $type, $hashed, $telegram_id, $telegram_group_id, $notif_to_personal, $notif_to_group, $userId];
    } else {
        $sql = "UPDATE users SET username=?, type=?, telegram_id=?, telegram_group_id=?, notif_to_personal=?, notif_to_group=? WHERE id=?";
        $params = [$username, $type, $telegram_id, $telegram_group_id, $notif_to_personal, $notif_to_group, $userId];
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Check if user has active upgrade_requests
    // If changing type, we should handle upgrade_requests table too
    if (in_array($type, ['medium', 'vip', 'vipmax'])) {
        // Check if user has existing upgrade request
        $checkStmt = $pdo->prepare("SELECT id FROM upgrade_requests WHERE user_id = ? AND status = 'confirmed' ORDER BY expires_at DESC LIMIT 1");
        $checkStmt->execute([$userId]);
        $existingUpgrade = $checkStmt->fetch();
        
        if ($existingUpgrade) {
            // Update existing upgrade type if needed
            // Only update if type in upgrade_requests doesn't match new type
            $updateUpgradeStmt = $pdo->prepare("UPDATE upgrade_requests SET upgrade_type = ? WHERE id = ? AND upgrade_type != ?");
            $updateUpgradeStmt->execute([$type, $existingUpgrade['id'], $type]);
        }
    }
    
    // Log activity
    $adminId = $_SESSION['user_id'];
    $adminUsername = $_SESSION['username'];
    $action = "Edit user @$username (ID: $userId) - Type: $type";
    if ($telegram_id === null && $_POST['telegram_id'] !== null) {
        $action .= " - Telegram ID dikosongkan";
    }
    
    $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, NOW())");
    $logStmt->execute([$adminId, $adminUsername, $action]);
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => '✅ Data user berhasil diperbarui.']);
    
} catch (Exception $e) {
    $pdo->rollBack();
    
    // Handle specific MySQL errors
    if ($e->getCode() == '23000' && strpos($e->getMessage(), 'Duplicate entry') !== false) {
        if (strpos($e->getMessage(), 'telegram_id') !== false) {
            echo json_encode([
                'success' => false, 
                'message' => '❌ Telegram ID sudah digunakan oleh user lain. Gunakan ID yang berbeda atau kosongkan.'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => '❌ Data yang dimasukkan sudah ada di database.'
            ]);
        }
    } elseif ($e->getCode() == '01000' && strpos($e->getMessage(), 'Data truncated') !== false) {
        echo json_encode([
            'success' => false, 
            'message' => '❌ Error: Tipe user tidak valid untuk tabel upgrade_requests. Silakan update struktur database terlebih dahulu.'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '❌ Gagal menyimpan: ' . $e->getMessage()]);
    }
}
?>
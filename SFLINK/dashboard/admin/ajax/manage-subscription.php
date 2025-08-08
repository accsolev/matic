<?php
session_start();
require '../../../includes/auth.php';
require '../../../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$adminId = $_SESSION['user_id'];
$adminUsername = $_SESSION['username'];

function logActivity($pdo, $adminId, $adminUsername, $action) {
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$adminId, $adminUsername, $action]);
}

// Get POST data
$userId = $_POST['user_id'] ?? null;
$actionType = $_POST['action_type'] ?? null;
$upgradeType = $_POST['upgrade_type'] ?? null;
$duration = $_POST['duration'] ?? null;
$expireDate = $_POST['expire_date'] ?? null;
$amount = $_POST['amount'] ?? 0;
$notes = $_POST['notes'] ?? '';

if (!$userId || !$actionType) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

try {
    // Get user info
    $stmt = $pdo->prepare("SELECT username, type FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
        exit;
    }
    
    $pdo->beginTransaction();
    
    switch ($actionType) {
        case 'new':
            // Create new upgrade
            if (!$upgradeType || !$duration) {
                throw new Exception('Data upgrade tidak lengkap');
            }
            
            // Calculate dates
            $upgradedAt = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', strtotime("+$duration days"));
            
            // Insert upgrade request
            $stmt = $pdo->prepare("
                INSERT INTO upgrade_requests 
                (user_id, upgrade_type, amount, status, created_at, upgraded_at, expires_at) 
                VALUES (?, ?, ?, 'confirmed', NOW(), ?, ?)
            ");
            $stmt->execute([$userId, $upgradeType, $amount, $upgradedAt, $expiresAt]);
            
            // Update user type
            $stmt = $pdo->prepare("UPDATE users SET type = ? WHERE id = ?");
            $stmt->execute([$upgradeType, $userId]);
            
            logActivity($pdo, $adminId, $adminUsername, 
                "Upgrade user @{$user['username']} ke $upgradeType untuk $duration hari");
            
            $message = "Berhasil upgrade user ke $upgradeType untuk $duration hari";
            break;
            
        case 'extend':
            // Extend existing subscription
            if (!$duration) {
                throw new Exception('Durasi perpanjangan tidak valid');
            }
            
            // Get current subscription
            $stmt = $pdo->prepare("
                SELECT id, expires_at, upgrade_type 
                FROM upgrade_requests 
                WHERE user_id = ? AND status = 'confirmed' 
                ORDER BY expires_at DESC LIMIT 1
            ");
            $stmt->execute([$userId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($current) {
                // Extend from current expire date
                $baseDate = new DateTime($current['expires_at']);
                if ($baseDate < new DateTime()) {
                    $baseDate = new DateTime(); // If already expired, extend from today
                }
                $baseDate->modify("+$duration days");
                $newExpiresAt = $baseDate->format('Y-m-d H:i:s');
                
                // Update existing record
                $stmt = $pdo->prepare("UPDATE upgrade_requests SET expires_at = ? WHERE id = ?");
                $stmt->execute([$newExpiresAt, $current['id']]);
                
                $finalType = $upgradeType ?: $current['upgrade_type'];
            } else {
                // No existing subscription, create new one
                $upgradedAt = date('Y-m-d H:i:s');
                $expiresAt = date('Y-m-d H:i:s', strtotime("+$duration days"));
                $finalType = $upgradeType ?: $user['type'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO upgrade_requests 
                    (user_id, upgrade_type, amount, status, created_at, upgraded_at, expires_at) 
                    VALUES (?, ?, ?, 'confirmed', NOW(), ?, ?)
                ");
                $stmt->execute([$userId, $finalType, $amount, $upgradedAt, $expiresAt]);
            }
            
            // Update user type if changed
            if ($upgradeType && $upgradeType !== $user['type']) {
                $stmt = $pdo->prepare("UPDATE users SET type = ? WHERE id = ?");
                $stmt->execute([$upgradeType, $userId]);
            }
            
            logActivity($pdo, $adminId, $adminUsername, 
                "Perpanjang subscription @{$user['username']} untuk $duration hari");
            
            $message = "Berhasil perpanjang subscription untuk $duration hari";
            break;
            
case 'modify':
    $upgradeDate = $_POST['upgrade_date'] ?? null;
    $expireDate = $_POST['expire_date'] ?? null;
    
    if (!$expireDate && !$upgradeDate) {
        throw new Exception('Minimal satu tanggal harus diisi');
    }
    
    // Try to get existing record
    $stmt = $pdo->prepare("
        SELECT id FROM upgrade_requests 
        WHERE user_id = ? AND status = 'confirmed' 
        ORDER BY expires_at DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($current) {
        // Update existing record
        $updates = [];
        $params = [];
        
        if ($upgradeDate) {
            $updates[] = "upgraded_at = ?";
            $params[] = $upgradeDate . ' 00:00:00';
        }
        
        if ($expireDate) {
            $updates[] = "expires_at = ?";
            $params[] = $expireDate . ' 23:59:59';
        }
        
        $params[] = $current['id'];
        
        $sql = "UPDATE upgrade_requests SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        // No record exists - create new one
        $upgradedAt = $upgradeDate ? $upgradeDate . ' 00:00:00' : date('Y-m-d H:i:s');
        $expiresAt = $expireDate ? $expireDate . ' 23:59:59' : date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $stmt = $pdo->prepare("
            INSERT INTO upgrade_requests 
            (user_id, upgrade_type, amount, status, created_at, upgraded_at, expires_at) 
            VALUES (?, ?, 0, 'confirmed', NOW(), ?, ?)
        ");
        $stmt->execute([$userId, $user['type'], $upgradedAt, $expiresAt]);
    }
    
    // Log activity
    $logMessage = "Edit/Create subscription @{$user['username']}";
    if ($upgradeDate) $logMessage .= " - Tgl upgrade: " . date('d/m/Y', strtotime($upgradeDate));
    if ($expireDate) $logMessage .= " - Tgl expired: " . date('d/m/Y', strtotime($expireDate));
    
    logActivity($pdo, $adminId, $adminUsername, $logMessage);
    
    $message = "Berhasil update tanggal subscription";
    break;
            
        case 'cancel':
            // Cancel/stop subscription
            // Get current subscription first
            $stmt = $pdo->prepare("
                SELECT id FROM upgrade_requests 
                WHERE user_id = ? AND status = 'confirmed' 
                ORDER BY expires_at DESC LIMIT 1
            ");
            $stmt->execute([$userId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($current) {
                // Update status to cancelled instead of using 'cancelled' status
                $stmt = $pdo->prepare("
                    UPDATE upgrade_requests 
                    SET status = 'rejected', expires_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$current['id']]);
            }
            
            // Revert user to trial
            $stmt = $pdo->prepare("UPDATE users SET type = 'trial' WHERE id = ?");
            $stmt->execute([$userId]);
            
            logActivity($pdo, $adminId, $adminUsername, 
                "Cancel subscription @{$user['username']}");
            
            $message = "Subscription berhasil dibatalkan";
            break;
            
        default:
            throw new Exception('Action type tidak valid');
    }
    
    // Add notes if provided
    if ($notes) {
        logActivity($pdo, $adminId, $adminUsername, 
            "Note for @{$user['username']}: $notes");
    }
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $message]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
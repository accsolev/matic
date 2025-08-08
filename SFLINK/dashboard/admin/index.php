<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require '../../includes/auth.php';
require_login();
require '../../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    header('Location: /dashboard');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    switch($_GET['ajax']) {
        case 'users':
            $limit = 10;
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $offset = ($page - 1) * $limit;
            $search = $_GET['search'] ?? '';
            $typeFilter = $_GET['filter_type'] ?? '';
            
            $where = [];
            $params = [];
            if ($search) {
                $where[] = "(username LIKE :search OR telegram_id LIKE :search)";
                $params[':search'] = "%$search%";
            }
            if ($typeFilter) {
                $where[] = "type = :type";
                $params[':type'] = $typeFilter;
            }
            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : '';
            
            $sql = "SELECT id, username, telegram_id, telegram_group_id, type, created_at, 
                           (SELECT upgraded_at FROM upgrade_requests WHERE user_id = users.id AND status = 'confirmed' ORDER BY upgraded_at DESC LIMIT 1) AS upgraded_at,
                           (SELECT expires_at FROM upgrade_requests WHERE user_id = users.id AND status = 'confirmed' ORDER BY expires_at DESC LIMIT 1) AS expires_at
                    FROM users $whereClause ORDER BY id DESC LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $users = $stmt->fetchAll();
            
            $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereClause");
            foreach ($params as $key => $val) {
                $totalStmt->bindValue($key, $val);
            }
            $totalStmt->execute();
            $totalUsers = $totalStmt->fetchColumn();
            $totalPages = ceil($totalUsers / $limit);
            
            echo json_encode([
                'users' => $users,
                'totalPages' => $totalPages,
                'currentPage' => $page,
                'totalUsers' => $totalUsers
            ]);
            exit;
            
        case 'stats':
            $stats = $pdo->query("SELECT COUNT(*) AS total_links, SUM(clicks) AS total_clicks FROM links")->fetch(PDO::FETCH_ASSOC);
            $domainStats = $pdo->query("SELECT COUNT(*) AS total_domains FROM list_domains")->fetch(PDO::FETCH_ASSOC);
            $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            
            // Get expiring users (within 7 days)
            $expiringUsers = $pdo->query("
                SELECT u.id, u.username, u.telegram_id, u.type, 
                       ur.expires_at,
                       DATEDIFF(ur.expires_at, NOW()) as days_left
                FROM users u
                JOIN upgrade_requests ur ON u.id = ur.user_id
                WHERE ur.status = 'confirmed' 
                AND ur.expires_at IS NOT NULL
                AND ur.expires_at > NOW()
                AND ur.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                ORDER BY ur.expires_at ASC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            // Get recently expired users (last 3 days)
            $expiredUsers = $pdo->query("
                SELECT u.id, u.username, u.telegram_id, u.type, 
                       ur.expires_at,
                       DATEDIFF(NOW(), ur.expires_at) as days_expired
                FROM users u
                JOIN upgrade_requests ur ON u.id = ur.user_id
                WHERE ur.status = 'confirmed' 
                AND ur.expires_at IS NOT NULL
                AND ur.expires_at < NOW()
                AND ur.expires_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
                ORDER BY ur.expires_at DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'totalUsers' => $totalUsers,
                'totalDomains' => $domainStats['total_domains'],
                'totalLinks' => $stats['total_links'],
                'totalClicks' => $stats['total_clicks'],
                'expiringUsers' => $expiringUsers,
                'expiredUsers' => $expiredUsers
            ]);
            exit;
            
        case 'upgrades':
            $stmt = $pdo->query("SELECT ur.id, ur.user_id, u.username, u.telegram_id, ur.upgrade_type, ur.amount, ur.created_at 
                                FROM upgrade_requests ur 
                                JOIN users u ON ur.user_id = u.id 
                                WHERE ur.status = 'pending' 
                                ORDER BY ur.created_at DESC");
            $pendingUpgrades = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['upgrades' => $pendingUpgrades]);
            exit;
            
        case 'domains':
            $domains = $pdo->query("SELECT * FROM domains ORDER BY id DESC")->fetchAll();
            echo json_encode(['domains' => $domains]);
            exit;
            
        case 'activities':
            $search = $_GET['search'] ?? '';
            $where = '';
            $params = [];
            
            if ($search) {
                $where = "WHERE username LIKE :search OR action LIKE :search";
                $params[':search'] = "%$search%";
            }
            
           $sql = "SELECT * FROM activity_logs $where ORDER BY created_at DESC";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->execute();
            $activities = $stmt->fetchAll();
            
            echo json_encode(['activities' => $activities]);
            exit;
            case 'domain_monitoring':
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $search = $_GET['search'] ?? '';
    $statusFilter = $_GET['status'] ?? '';
    $userFilter = $_GET['user'] ?? '';
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "ld.domain LIKE :search";
        $params[':search'] = "%$search%";
    }
    if ($statusFilter !== '') {
        $where[] = "ld.status = :status";
        $params[':status'] = $statusFilter;
    }
    if ($userFilter) {
        $where[] = "ld.user_id = :user_id";
        $params[':user_id'] = $userFilter;
    }
    
    $whereClause = $where ? "WHERE " . implode(" AND ", $where) : '';
    
    // Get domains with user info
    $sql = "SELECT ld.*, u.username, u.type as user_type,
                   TIMESTAMPDIFF(MINUTE, ld.last_checked, NOW()) as minutes_since_check
            FROM list_domains ld
            JOIN users u ON ld.user_id = u.id
            $whereClause
            ORDER BY ld.last_checked DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $countSql = "SELECT COUNT(*) FROM list_domains ld JOIN users u ON ld.user_id = u.id $whereClause";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val);
    }
    $countStmt->execute();
    $totalDomains = $countStmt->fetchColumn();
    $totalPages = ceil($totalDomains / $limit);
    
    // Get summary stats
    $summaryStats = $pdo->query("
        SELECT 
            COUNT(*) as total_domains,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_domains,
            SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as inactive_domains,
            COUNT(DISTINCT user_id) as total_users,
            AVG(interval_minute) as avg_interval
        FROM list_domains
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Get domains that haven't been checked recently
    $staleDomainsQuery = "
        SELECT ld.*, u.username, 
               TIMESTAMPDIFF(MINUTE, ld.last_checked, NOW()) as minutes_since_check
        FROM list_domains ld
        JOIN users u ON ld.user_id = u.id
        WHERE ld.status = 1 
        AND (ld.last_checked IS NULL OR ld.last_checked < DATE_SUB(NOW(), INTERVAL (ld.interval_minute * 2) MINUTE))
        ORDER BY ld.last_checked ASC
        LIMIT 10
    ";
    $staleDomains = $pdo->query($staleDomainsQuery)->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'domains' => $domains,
        'totalPages' => $totalPages,
        'currentPage' => $page,
        'totalDomains' => $totalDomains,
        'summaryStats' => $summaryStats,
        'staleDomains' => $staleDomains
    ]);
    exit;

case 'domain_check_history':
    $domainId = $_GET['domain_id'] ?? '';
    if (!$domainId) {
        echo json_encode(['error' => 'Domain ID required']);
        exit;
    }
    
    // Get domain details
    $domainStmt = $pdo->prepare("
        SELECT ld.*, u.username 
        FROM list_domains ld 
        JOIN users u ON ld.user_id = u.id 
        WHERE ld.id = :id
    ");
    $domainStmt->execute([':id' => $domainId]);
    $domain = $domainStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$domain) {
        echo json_encode(['error' => 'Domain not found']);
        exit;
    }
    
    echo json_encode([
        'domain' => $domain,
        'history' => [] // Would contain check history if table existed
    ]);
    exit;

    }
    
}

function esc($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function logActivity($pdo, $userId, $username, $action) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $username, $action, $now]);
}

// Initial data load
$stats = $pdo->query("SELECT COUNT(*) AS total_links, SUM(clicks) AS total_clicks FROM links")->fetch(PDO::FETCH_ASSOC);
$domainStats = $pdo->query("SELECT COUNT(*) AS total_domains FROM list_domains")->fetch(PDO::FETCH_ASSOC);
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Export handlers
if (isset($_GET['export']) && $_GET['export'] === 'users') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=users.csv');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Username', 'Telegram ID', 'Type', 'Created At', 'Upgraded At', 'Expires At']);
    $stmt = $pdo->query("SELECT id, username, telegram_id, type, created_at, 
                                (SELECT upgraded_at FROM upgrade_requests WHERE user_id = users.id AND status = 'confirmed' ORDER BY upgraded_at DESC LIMIT 1) AS upgraded_at,
                                (SELECT expires_at FROM upgrade_requests WHERE user_id = users.id AND status = 'confirmed' ORDER BY expires_at DESC LIMIT 1) AS expires_at
                         FROM users");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'domains') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=domains.csv');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Domain']);
    $domains = $pdo->query("SELECT * FROM domains ORDER BY id DESC")->fetchAll();
    foreach ($domains as $d) {
        fputcsv($fp, [$d['id'], $d['domain']]);
    }
    fclose($fp);
    exit;
}
if (isset($_GET['export']) && $_GET['export'] === 'domain_monitoring') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=domain_monitoring.csv');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Username', 'User Type', 'Domain', 'Status', 'Interval (min)', 'Last Checked', 'Created At']);
    
    $stmt = $pdo->query("
        SELECT ld.*, u.username, u.type as user_type
        FROM list_domains ld
        JOIN users u ON ld.user_id = u.id
        ORDER BY ld.last_checked DESC
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($fp, [
            $row['id'],
            $row['username'],
            $row['user_type'],
            $row['domain'],
            $row['status'] ? 'Active' : 'Inactive',
            $row['interval_minute'],
            $row['last_checked'],
            $row['created_at']
        ]);
    }
    fclose($fp);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
  <title>SFLINK.ID - Admin Panel</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="icon" type="image/png" href="https://sflink.id/favicon.png"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />
  
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            primary: '#3b82f6',
            secondary: '#8b5cf6',
            success: '#10b981',
            danger: '#ef4444',
            warning: '#f59e0b',
            info: '#06b6d4',
            dark: '#1f2937'
          },
          animation: {
            'fade-in': 'fadeIn 0.5s ease-in-out',
            'slide-in': 'slideIn 0.3s ease-out',
            'bounce-in': 'bounceIn 0.5s ease-out',
            'float': 'float 3s ease-in-out infinite',
            'pulse-slow': 'pulse 3s infinite'
          },
          keyframes: {
            fadeIn: {
              '0%': { opacity: '0' },
              '100%': { opacity: '1' }
            },
            slideIn: {
              '0%': { transform: 'translateX(-100%)' },
              '100%': { transform: 'translateX(0)' }
            },
            bounceIn: {
              '0%': { transform: 'scale(0.3)', opacity: '0' },
              '50%': { transform: 'scale(1.05)' },
              '70%': { transform: 'scale(0.9)' },
              '100%': { transform: 'scale(1)' }
            },
            float: {
              '0%, 100%': { transform: 'translateY(0)' },
              '50%': { transform: 'translateY(-10px)' }
            }
          }
        }
      }
    }
  </script>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition-colors duration-300">

<!-- Modal Edit User -->
<div id="editUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
  <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-md w-full animate__animated animate__fadeInUp">
    <form id="editUserForm" class="p-6">
      <div class="flex justify-between items-center mb-6">
        <h3 class="text-2xl font-bold text-gray-800 dark:text-white">Edit User</h3>
        <button type="button" onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
          <i class="fas fa-times text-xl"></i>
        </button>
      </div>
      
      <input type="hidden" id="editUserId" name="id">
      
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Username</label>
          <input type="text" id="editUsername" name="username" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all" required>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Password</label>
          <input type="password" id="editPassword" name="password" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all" placeholder="••••••••">
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Tipe</label>
          <select name="type" id="editType" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
            <option value="trial">Trial</option>
            <option value="medium">Medium</option>
            <option value="vip">VIP</option>
            <option value="vipmax">VIPMAX</option>
          </select>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Telegram ID</label>
          <input type="text" id="editTelegramId" name="telegram_id" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Telegram Group ID</label>
          <input type="text" id="editTelegramGroupId" name="telegram_group_id" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
        </div>
        
        <div class="space-y-2">
          <label class="flex items-center space-x-3 cursor-pointer">
            <input type="checkbox" id="notif_to_personal" name="notif_to_personal" class="w-4 h-4 text-primary rounded focus:ring-primary">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Notifikasi ke Personal</span>
          </label>
          
          <label class="flex items-center space-x-3 cursor-pointer">
            <input type="checkbox" id="notif_to_group" name="notif_to_group" class="w-4 h-4 text-primary rounded focus:ring-primary">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Notifikasi ke Group</span>
          </label>
        </div>
      </div>
      
      <div class="flex gap-3 mt-6">
        <button type="submit" class="flex-1 bg-gradient-to-r from-primary to-secondary text-white py-2.5 rounded-lg font-medium hover:shadow-lg transform hover:scale-105 transition-all duration-200">
          <i class="fas fa-save mr-2"></i>Simpan
        </button>
        <button type="button" onclick="closeEditModal()" class="flex-1 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 py-2.5 rounded-lg font-medium hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
          Batal
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Manage Subscription -->
<div id="manageSubscriptionModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
  <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-lg w-full animate__animated animate__fadeInUp">
    <form id="manageSubscriptionForm" class="p-6">
      <div class="flex justify-between items-center mb-6">
        <h3 class="text-2xl font-bold text-gray-800 dark:text-white">
          <i class="fas fa-crown text-yellow-500 mr-2"></i>
          Manage Subscription
        </h3>
        <button type="button" onclick="closeSubscriptionModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
          <i class="fas fa-times text-xl"></i>
        </button>
      </div>
      
      <input type="hidden" id="subUserId" name="user_id">
      
      <!-- User Info Display -->
      <div class="bg-gray-100 dark:bg-gray-700 rounded-lg p-4 mb-6">
        <h4 class="font-semibold text-gray-800 dark:text-gray-200 mb-2">User Information</h4>
        <div class="grid grid-cols-2 gap-2 text-sm">
          <div>
            <span class="text-gray-600 dark:text-gray-400">Username:</span>
            <span id="subUsername" class="font-medium text-gray-800 dark:text-gray-200 ml-2"></span>
          </div>
          <div>
            <span class="text-gray-600 dark:text-gray-400">Current Type:</span>
            <span id="subCurrentType" class="font-medium ml-2"></span>
          </div>
        </div>
      </div>
      
      <div class="space-y-4">
        <!-- Action Type -->
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Action Type</label>
          <select id="subActionType" name="action_type" onchange="toggleSubscriptionFields()" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
            <option value="">-- Pilih Action --</option>
            <option value="new">Upgrade Baru</option>
            <option value="extend">Perpanjang Existing</option>
            <option value="modify">Edit Tanggal Expired</option>
            <option value="cancel">Cancel/Stop Subscription</option>
          </select>
        </div>
        
        <!-- Upgrade Type (for new/extend) -->
        <div id="upgradeTypeField" class="hidden">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Upgrade Type</label>
          <select id="subUpgradeType" name="upgrade_type" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
            <option value="medium">Medium</option>
            <option value="vip">VIP</option>
            <option value="vipmax">VIPMAX</option>
          </select>
        </div>
        
        <!-- Duration (for new/extend) -->
        <div id="durationField" class="hidden">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Duration</label>
          <div class="grid grid-cols-3 gap-2">
            <button type="button" onclick="setDuration(30)" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 hover:bg-primary hover:text-white rounded-lg transition-colors">30 Hari</button>
            <button type="button" onclick="setDuration(90)" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 hover:bg-primary hover:text-white rounded-lg transition-colors">90 Hari</button>
            <button type="button" onclick="setDuration(365)" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 hover:bg-primary hover:text-white rounded-lg transition-colors">1 Tahun</button>
          </div>
          <input type="number" id="subDuration" name="duration" placeholder="Custom (hari)" class="w-full mt-2 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
        </div>
        
        <!-- Upgrade Date (for modify) -->
<div id="upgradeDateField" class="hidden">
  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Tanggal Upgrade</label>
  <input type="date" id="subUpgradeDate" name="upgrade_date" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
</div>


        <!-- Expire Date (for modify) -->
        <div id="expireDateField" class="hidden">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Tanggal Expired</label>
          <input type="date" id="subExpireDate" name="expire_date" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
        </div>
        
        <!-- Amount -->
        <div id="amountField" class="hidden">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Amount (Rp)</label>
          <input type="number" id="subAmount" name="amount" placeholder="Contoh: 50000" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
        </div>
        
        <!-- Notes -->
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes (Optional)</label>
          <textarea id="subNotes" name="notes" rows="2" placeholder="Catatan tambahan..." class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent transition-all"></textarea>
        </div>
        
        <!-- Current Status Display -->
        <div id="currentStatusDisplay" class="hidden bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
          <h5 class="font-semibold text-blue-800 dark:text-blue-300 mb-2">Current Subscription Status</h5>
          <div class="text-sm space-y-1">
            <div>Upgraded: <span id="statusUpgraded" class="font-medium"></span></div>
            <div>Expires: <span id="statusExpires" class="font-medium"></span></div>
            <div>Days Left: <span id="statusDaysLeft" class="font-medium"></span></div>
          </div>
        </div>
      </div>
      
      <div class="flex gap-3 mt-6">
        <button type="submit" class="flex-1 bg-gradient-to-r from-primary to-secondary text-white py-2.5 rounded-lg font-medium hover:shadow-lg transform hover:scale-105 transition-all duration-200">
          <i class="fas fa-check mr-2"></i>Apply Changes
        </button>
        <button type="button" onclick="closeSubscriptionModal()" class="flex-1 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 py-2.5 rounded-lg font-medium hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
          Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Sidebar -->
<div class="fixed inset-y-0 left-0 z-30 w-64 bg-gradient-to-b from-gray-900 to-gray-800 shadow-2xl transform transition-transform duration-300 lg:translate-x-0 -translate-x-full" id="sidebar">
  <div class="flex flex-col h-full">
    <!-- Logo -->
    <div class="p-6 text-center border-b border-gray-700">
      <a href="#dashboard" onclick="navigate('dashboard')" class="inline-block">
        <img src="https://sflink.id/logo.png" alt="SFLINK.ID" class="h-12 mx-auto rounded-lg shadow-lg hover:shadow-xl transform hover:scale-110 transition-all duration-300">
      </a>
    </div>
    
    <!-- Navigation -->
    <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto">
      <a href="#dashboard" onclick="navigate('dashboard')" class="nav-link flex items-center px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-700 hover:text-white transition-all duration-200 group">
        <i class="fas fa-home w-5 mr-3 group-hover:animate-pulse"></i>
        <span class="font-medium">Dashboard</span>
      </a>
      
      <a href="#upgrades" onclick="navigate('upgrades')" class="nav-link flex items-center px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-700 hover:text-white transition-all duration-200 group">
        <i class="fas fa-crown w-5 mr-3 text-yellow-400 group-hover:animate-bounce"></i>
        <span class="font-medium">Upgrade Request</span>
      </a>
      
      <a href="#users" onclick="navigate('users')" class="nav-link flex items-center px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-700 hover:text-white transition-all duration-200 group">
        <i class="fas fa-users w-5 mr-3 group-hover:animate-pulse"></i>
        <span class="font-medium">Daftar User</span>
      </a>
      
      <a href="#domains" onclick="navigate('domains')" class="nav-link flex items-center px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-700 hover:text-white transition-all duration-200 group">
        <i class="fas fa-globe w-5 mr-3 group-hover:animate-spin"></i>
        <span class="font-medium">Daftar Domain</span>
      </a>
      <a href="#domain-monitoring" onclick="navigate('domain-monitoring')" class="nav-link flex items-center px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-700 hover:text-white transition-all duration-200 group">
  <i class="fas fa-chart-line w-5 mr-3 text-green-400 group-hover:animate-pulse"></i>
  <span class="font-medium">Domain Monitoring</span>
</a>
      <a href="#activities" onclick="navigate('activities')" class="nav-link flex items-center px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-700 hover:text-white transition-all duration-200 group">
        <i class="fas fa-list w-5 mr-3 group-hover:animate-pulse"></i>
        <span class="font-medium">Log Aktivitas</span>
      </a>
      
      <a href="#telegram" onclick="navigate('telegram')" class="nav-link flex items-center px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-700 hover:text-white transition-all duration-200 group">
        <i class="fab fa-telegram w-5 mr-3 text-blue-400 group-hover:animate-bounce"></i>
        <span class="font-medium">Telegram Message</span>
      </a>
      
      <a href="#kritik" onclick="navigate('kritik')" class="nav-link flex items-center px-4 py-3 text-gray-300 rounded-lg hover:bg-gray-700 hover:text-white transition-all duration-200 group">
        <i class="fas fa-comment-dots w-5 mr-3 group-hover:animate-pulse"></i>
        <span class="font-medium">Semua Kritik</span>
      </a>
    </nav>
    
    <!-- Dark Mode Toggle -->
    <div class="p-4 border-t border-gray-700">
      <button onclick="toggleDarkMode()" class="w-full flex items-center justify-center px-4 py-2 bg-gray-700 hover:bg-gray-600 text-gray-300 rounded-lg transition-all duration-200">
        <i class="fas fa-moon mr-2"></i>
        <span>Mode Gelap</span>
      </button>
    </div>
  </div>
</div>

<!-- Mobile Menu Button -->
<button onclick="toggleSidebar()" class="lg:hidden fixed top-4 left-4 z-40 p-2 bg-gray-800 text-white rounded-lg shadow-lg">
  <i class="fas fa-bars"></i>
</button>

<!-- Main Content -->
<div class="lg:ml-64 min-h-screen">
  <!-- Topbar -->
  <div class="sticky top-0 z-20 bg-white dark:bg-gray-800 shadow-md">
    <div class="flex justify-between items-center px-6 py-4">
      <h1 class="text-2xl font-bold text-gray-800 dark:text-white animate__animated animate__fadeInLeft">Admin Panel</h1>
      
      <div class="relative">
        <button onclick="toggleDropdown()" class="flex items-center space-x-3 px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-all duration-200">
          <i class="fas fa-user-circle text-xl"></i>
          <span class="font-medium"><?= htmlspecialchars($username) ?></span>
          <i class="fas fa-chevron-down text-sm"></i>
        </button>
        
        <div id="userDropdown" class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 hidden">
          <a href="#profile" onclick="navigate('profile')" class="block px-4 py-2 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <i class="fas fa-user mr-2"></i> Profil
          </a>
          <hr class="border-gray-200 dark:border-gray-700">
          <a href="../logout" class="block px-4 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
            <i class="fas fa-sign-out-alt mr-2"></i> Logout
          </a>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Content Container -->
  <div class="p-6" id="mainContent">
    <!-- Content will be loaded here dynamically -->
  </div>
</div>

<!-- Toast Notification -->
<div id="toastNotifUser" class="fixed bottom-4 right-4 transform translate-x-full transition-transform duration-300 z-50">
  <div class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl p-4 flex items-center space-x-3 min-w-[300px]">
    <div class="flex-shrink-0">
      <div class="w-10 h-10 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
        <i class="fas fa-check text-green-600 dark:text-green-400"></i>
      </div>
    </div>
    <div class="flex-1">
      <p class="toast-body text-gray-800 dark:text-gray-200 font-medium">Operasi berhasil!</p>
    </div>
    <button onclick="hideToast()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
      <i class="fas fa-times"></i>
    </button>
  </div>
</div>

<script>
// Global variables
let currentPage = 'dashboard';
let userSearchTimeout;
let activitySearchTimeout;
let domainSearchTimeout;
let currentUserPage = 1;
let currentUserSearch = '';
let currentDomainPage = 1;
let currentDomainSearch = '';
let domainRefreshInterval;

// Navigation system
function navigate(page, params = {}) {
  currentPage = page;
  
  // Clear intervals when leaving domain monitoring
  if (domainRefreshInterval) {
    clearInterval(domainRefreshInterval);
  }
  
  // Update URL without reload
  const url = new URL(window.location);
  url.hash = page;
  if (params.page) url.searchParams.set('page', params.page);
  if (params.search) url.searchParams.set('search', params.search);
  window.history.pushState({page, params}, '', url);
  
  // Update active nav
  document.querySelectorAll('.nav-link').forEach(link => {
    link.classList.remove('bg-gray-700', 'text-white');
    link.classList.add('text-gray-300');
  });
  
  const activeLink = document.querySelector(`a[href="#${page}"]`);
  if (activeLink) {
    activeLink.classList.remove('text-gray-300');
    activeLink.classList.add('bg-gray-700', 'text-white');
  }
  
  // Load content
  loadContent(page, params);
  
  // Close mobile sidebar
  if (window.innerWidth < 1024) {
    document.getElementById('sidebar').classList.add('-translate-x-full');
  }
}

// Content loader
async function loadContent(page, params = {}) {
  const container = document.getElementById('mainContent');
  container.innerHTML = '<div class="flex justify-center items-center h-64"><div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div></div>';
  
  switch(page) {
    case 'dashboard':
      await loadDashboard();
      break;
    case 'users':
      await loadUsers(params.page || 1, params.search || '');
      break;
    case 'upgrades':
      await loadUpgrades();
      break;
    case 'domains':
      await loadDomains();
      break;
    case 'domain-monitoring':
      await loadDomainMonitoring(params.page || 1, params.search || '', params.status || '', params.user || '');
      break;
    case 'activities':
      await loadActivities();
      break;
    case 'telegram':
      loadTelegram();
      break;
    case 'kritik':
      await loadKritik();
      break;
    default:
      loadDashboard();
  }
}

// Dashboard loader
async function loadDashboard() {
  try {
    const response = await fetch('?ajax=stats');
    const data = await response.json();
    
    document.getElementById('mainContent').innerHTML = `
      <div class="animate__animated animate__fadeIn">
        <h2 class="text-3xl font-bold mb-8 text-center text-gray-800 dark:text-white">Dashboard Overview</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
          <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl shadow-xl p-6 text-white transform hover:scale-105 transition-all duration-300">
            <div class="flex justify-between items-center">
              <div>
                <p class="text-blue-100 text-sm">Total User</p>
                <p class="text-3xl font-bold mt-2">${data.totalUsers}</p>
              </div>
              <div class="bg-white/20 p-3 rounded-xl">
                <i class="fas fa-users text-2xl"></i>
              </div>
            </div>
          </div>
          
          <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl shadow-xl p-6 text-white transform hover:scale-105 transition-all duration-300">
            <div class="flex justify-between items-center">
              <div>
                <p class="text-purple-100 text-sm">Total Domain</p>
                <p class="text-3xl font-bold mt-2">${data.totalDomains}</p>
              </div>
              <div class="bg-white/20 p-3 rounded-xl">
                <i class="fas fa-globe text-2xl"></i>
              </div>
            </div>
          </div>
          
          <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl shadow-xl p-6 text-white transform hover:scale-105 transition-all duration-300">
            <div class="flex justify-between items-center">
              <div>
                <p class="text-green-100 text-sm">Total Shortlink</p>
                <p class="text-3xl font-bold mt-2">${data.totalLinks}</p>
              </div>
              <div class="bg-white/20 p-3 rounded-xl">
                <i class="fas fa-link text-2xl"></i>
              </div>
            </div>
          </div>
          
          <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-2xl shadow-xl p-6 text-white transform hover:scale-105 transition-all duration-300">
            <div class="flex justify-between items-center">
              <div>
                <p class="text-red-100 text-sm">Total Klik</p>
                <p class="text-3xl font-bold mt-2">${data.totalClicks}</p>
              </div>
              <div class="bg-white/20 p-3 rounded-xl">
                <i class="fas fa-mouse-pointer text-2xl"></i>
              </div>
            </div>
          </div>
        </div>
        
        <!-- User Expiry Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Users Will Expire Soon -->
          <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
            <h3 class="text-xl font-bold mb-4 flex items-center text-orange-600 dark:text-orange-400">
              <i class="fas fa-clock mr-3 animate-pulse"></i>
              User Akan Expired (7 Hari)
            </h3>
            ${renderExpiringUsers(data.expiringUsers)}
          </div>
          
          <!-- Recently Expired Users -->
          <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
            <h3 class="text-xl font-bold mb-4 flex items-center text-red-600 dark:text-red-400">
              <i class="fas fa-calendar-times mr-3"></i>
              User Baru Expired (3 Hari)
            </h3>
            ${renderExpiredUsers(data.expiredUsers)}
          </div>
        </div>
      </div>
    `;
  } catch (error) {
    showToast('Gagal memuat dashboard', 'error');
  }
}

// Render expiring users
function renderExpiringUsers(users) {
  if (!users || users.length === 0) {
    return `
      <div class="text-center py-8 text-gray-500 dark:text-gray-400">
        <i class="fas fa-check-circle text-4xl mb-3 text-green-500"></i>
        <p>Tidak ada user yang akan expired dalam 7 hari ke depan</p>
      </div>
    `;
  }
  
  let html = '<div class="space-y-3">';
  users.forEach(user => {
    const daysLeft = parseInt(user.days_left);
    const urgencyClass = daysLeft <= 1 ? 'bg-red-100 dark:bg-red-900/30 border-red-300 dark:border-red-700' :
                        daysLeft <= 3 ? 'bg-orange-100 dark:bg-orange-900/30 border-orange-300 dark:border-orange-700' :
                        'bg-yellow-100 dark:bg-yellow-900/30 border-yellow-300 dark:border-yellow-700';
    
    const urgencyText = daysLeft === 0 ? 'Hari Ini!' :
                       daysLeft === 1 ? 'Besok' :
                       `${daysLeft} hari lagi`;
    
    const typeColor = user.type === 'vip' ? 'text-green-600 dark:text-green-400' :
                     user.type === 'vipmax' ? 'text-red-600 dark:text-red-400' :
                     user.type === 'medium' ? 'text-blue-600 dark:text-blue-400' :
                     'text-yellow-600 dark:text-yellow-400';
    
    html += `
      <div class="p-4 rounded-lg border ${urgencyClass} hover:shadow-md transition-all">
        <div class="flex justify-between items-start">
          <div>
            <h4 class="font-semibold text-gray-800 dark:text-gray-200">
              @${escapeHtml(user.username)}
              <span class="ml-2 text-xs font-medium ${typeColor}">[${user.type.toUpperCase()}]</span>
            </h4>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
              <i class="fas fa-calendar-alt mr-1"></i>
              Expire: ${formatDate(user.expires_at)}
            </p>
            ${user.telegram_id ? `
              <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                <i class="fab fa-telegram mr-1"></i>
                ${escapeHtml(user.telegram_id)}
              </p>
            ` : ''}
          </div>
          <div class="text-right">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold 
                         ${daysLeft <= 1 ? 'bg-red-200 text-red-800 dark:bg-red-800 dark:text-red-200' :
                           daysLeft <= 3 ? 'bg-orange-200 text-orange-800 dark:bg-orange-800 dark:text-orange-200' :
                           'bg-yellow-200 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-200'}">
              <i class="fas fa-hourglass-half mr-1"></i>
              ${urgencyText}
            </span>
            <div class="mt-2">
              <button onclick="sendReminderToUser('${user.telegram_id}', '${escapeHtml(user.username)}', ${daysLeft})" 
                      class="text-xs bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg transition-colors"
                      ${!user.telegram_id ? 'disabled opacity-50' : ''}>
                <i class="fab fa-telegram mr-1"></i>Ingatkan
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  });
  html += '</div>';
  
  return html;
}

// Render expired users
function renderExpiredUsers(users) {
  if (!users || users.length === 0) {
    return `
      <div class="text-center py-8 text-gray-500 dark:text-gray-400">
        <i class="fas fa-smile text-4xl mb-3 text-green-500"></i>
        <p>Tidak ada user yang baru expired</p>
      </div>
    `;
  }
  
  let html = '<div class="space-y-3">';
  users.forEach(user => {
    const daysExpired = parseInt(user.days_expired);
    const expiredText = daysExpired === 0 ? 'Hari ini' :
                       daysExpired === 1 ? 'Kemarin' :
                       `${daysExpired} hari lalu`;
    
    html += `
      <div class="p-4 rounded-lg border bg-gray-50 dark:bg-gray-700/50 border-gray-300 dark:border-gray-600 hover:shadow-md transition-all">
        <div class="flex justify-between items-start">
          <div>
            <h4 class="font-semibold text-gray-800 dark:text-gray-200">
              @${escapeHtml(user.username)}
              <span class="ml-2 text-xs font-medium text-gray-500">[${user.type.toUpperCase()}]</span>
            </h4>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
              <i class="fas fa-calendar-times mr-1 text-red-500"></i>
              Expired: ${formatDate(user.expires_at)}
            </p>
            ${user.telegram_id ? `
              <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                <i class="fab fa-telegram mr-1"></i>
                ${escapeHtml(user.telegram_id)}
              </p>
            ` : ''}
          </div>
          <div class="text-right">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
              <i class="fas fa-times-circle mr-1"></i>
              ${expiredText}
            </span>
            <div class="mt-2">
              <button onclick="navigate('users', {search: '${escapeHtml(user.username)}'})" 
                      class="text-xs bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors">
                <i class="fas fa-user-edit mr-1"></i>Kelola
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  });
  html += '</div>';
  
  return html;
}

// Send reminder to user
function sendReminderToUser(telegramId, username, daysLeft) {
  if (!telegramId) {
    showToast('User tidak memiliki Telegram ID', 'error');
    return;
  }
  
  const message = `Halo @${username}! 👋\n\n` +
                  `⚠️ Pengingat: Akun premium Anda akan berakhir dalam ${daysLeft} hari.\n\n` +
                  `Silakan lakukan perpanjangan untuk tetap menikmati fitur premium.\n\n` +
                  `Terima kasih! 🙏`;
  
  // Navigate to telegram page with pre-filled message
  navigate('telegram');
  setTimeout(() => {
    document.getElementById('user_selector').value = telegramId;
    document.getElementById('chat_id').value = telegramId;
    document.getElementById('message').value = message;
  }, 300);
}

// Users loader
async function loadUsers(page = 1, search = '') {
  try {
    currentUserPage = page;
    currentUserSearch = search;
    
    const response = await fetch(`?ajax=users&page=${page}&search=${encodeURIComponent(search)}`);
    const data = await response.json();
    
    let html = `
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 animate__animated animate__fadeIn">
        <h3 class="text-2xl font-bold mb-6 flex items-center">
          <i class="fas fa-users text-blue-500 mr-3"></i>
          Daftar User
        </h3>
        
        <div class="mb-6">
          <div class="flex flex-col md:flex-row gap-4">
            <input type="text" id="userSearchInput" placeholder="Cari username / telegram..." 
                   value="${search}" 
                   class="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent">
            <a href="?export=users" class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors text-center">
              <i class="fas fa-download mr-2"></i>Export
            </a>
          </div>
        </div>
        
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-gray-200 dark:border-gray-700">
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">ID</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Username</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Telegram ID</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Group ID</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Tipe</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Dibuat</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Upgrade</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Expired</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Aksi</th>
              </tr>
            </thead>
            <tbody>`;
    
    if (data.users.length > 0) {
      data.users.forEach(user => {
        const typeClass = user.type === 'vip' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 
                         (user.type === 'vipmax' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300' : 
                         (user.type === 'medium' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300' : 
                         'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300'));
        
        html += `
          <tr id="user-row-${user.id}" class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
            <td class="px-4 py-3">${user.id}</td>
            <td class="px-4 py-3 font-medium">${escapeHtml(user.username)}</td>
            <td class="px-4 py-3 text-sm">${user.telegram_id ? escapeHtml(user.telegram_id) : '<span class="text-gray-400">Belum diisi</span>'}</td>
            <td class="px-4 py-3 text-sm">${user.telegram_group_id ? escapeHtml(user.telegram_group_id) : '<span class="text-gray-400">Belum diisi</span>'}</td>
            <td class="px-4 py-3">
              <span class="px-3 py-1 rounded-full text-xs font-semibold ${typeClass}">
                ${user.type.toUpperCase()}
              </span>
            </td>
            <td class="px-4 py-3 text-sm">${formatDate(user.created_at)}</td>
            <td class="px-4 py-3 text-sm">${user.upgraded_at ? formatDate(user.upgraded_at) : '-'}</td>
            <td class="px-4 py-3 text-sm">${user.expires_at ? formatDate(user.expires_at) : '-'}</td>
            <td class="px-4 py-3">
              <div class="flex gap-1">
                <button onclick="window.openEditUserModal(${user.id}, '${escapeHtml(user.username)}', '${user.type}', '${escapeHtml(user.telegram_id || '')}', '${escapeHtml(user.telegram_group_id || '')}', ${user.notif_to_personal || 0}, ${user.notif_to_group || 0})" 
                        class="p-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg transition-colors" title="Edit User">
                  <i class="fas fa-edit"></i>
                </button>
                <button onclick="openManageSubscription(${user.id}, '${escapeHtml(user.username)}', '${user.type}', '${user.upgraded_at || ''}', '${user.expires_at || ''}')" 
                        class="p-2 bg-purple-500 hover:bg-purple-600 text-white rounded-lg transition-colors" title="Manage Subscription">
                  <i class="fas fa-crown"></i>
                </button>
                <button onclick="deleteUser(${user.id})" 
                        class="p-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors" title="Delete User">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </td>
          </tr>`;
      });
    } else {
      html += '<tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">Tidak ada user ditemukan.</td></tr>';
    }
    
    html += `
            </tbody>
          </table>
        </div>`;
    
    // Pagination
    if (data.totalPages > 1) {
      html += `
        <div class="flex justify-center mt-6">
          <nav class="flex space-x-2">`;
      
      for (let i = 1; i <= data.totalPages; i++) {
        const isActive = i === data.currentPage;
        html += `
          <button onclick="navigate('users', {page: ${i}, search: '${search}'})" 
                  class="px-4 py-2 rounded-lg ${isActive ? 'bg-primary text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-600'} transition-colors">
            ${i}
          </button>`;
      }
      
      html += `
          </nav>
        </div>`;
    }
    
    html += `
      </div>
    `;
    
    document.getElementById('mainContent').innerHTML = html;
    
    // Setup search listener
    const searchInput = document.getElementById('userSearchInput');
    searchInput.addEventListener('input', (e) => {
      clearTimeout(userSearchTimeout);
      userSearchTimeout = setTimeout(() => {
        navigate('users', {page: 1, search: e.target.value});
      }, 500);
    });
    
  } catch (error) {
    showToast('Gagal memuat data user', 'error');
  }
}

// Upgrades loader
async function loadUpgrades() {
  try {
    const response = await fetch('?ajax=upgrades');
    const data = await response.json();
    
    let html = `
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 animate__animated animate__fadeIn">
        <h3 class="text-2xl font-bold mb-6 flex items-center">
          <i class="fas fa-crown text-yellow-500 mr-3"></i>
          Permintaan Upgrade Akun
        </h3>`;
    
    if (data.upgrades.length === 0) {
      html += `
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
          <p class="text-blue-800 dark:text-blue-300">
            <i class="fas fa-info-circle mr-2"></i>
            Tidak ada permintaan upgrade saat ini.
          </p>
        </div>`;
    } else {
      html += `
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-gray-200 dark:border-gray-700">
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Username</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Tipe</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Jumlah</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Waktu</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Aksi</th>
              </tr>
            </thead>
            <tbody>`;
      
      data.upgrades.forEach(req => {
        const typeClass = req.upgrade_type === 'vip' ? 
          'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 
          'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
        
        html += `
          <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
            <td class="px-4 py-3">@${escapeHtml(req.username)}</td>
            <td class="px-4 py-3">
              <span class="px-3 py-1 rounded-full text-xs font-semibold ${typeClass}">
                ${req.upgrade_type.toUpperCase()}
              </span>
            </td>
            <td class="px-4 py-3">Rp ${formatNumber(req.amount)}</td>
            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">${formatDateTime(req.created_at)}</td>
            <td class="px-4 py-3">
              <form method="POST" action="confirm-upgrade-action.php" class="flex gap-2">
                <input type="hidden" name="request_id" value="${req.id}">
                <input type="hidden" name="user_id" value="${req.user_id}">
                <input type="hidden" name="upgrade_type" value="${req.upgrade_type}">
                <button name="action" value="confirm" class="px-3 py-1 bg-green-500 hover:bg-green-600 text-white rounded-lg text-sm transition-colors">
                  <i class="fas fa-check mr-1"></i>Konfirmasi
                </button>
                <button name="action" value="reject" class="px-3 py-1 bg-red-500 hover:bg-red-600 text-white rounded-lg text-sm transition-colors">
                  <i class="fas fa-times mr-1"></i>Tolak
                </button>
              </form>
            </td>
          </tr>`;
      });
      
      html += `
            </tbody>
          </table>
        </div>`;
    }
    
    html += '</div>';
    document.getElementById('mainContent').innerHTML = html;
    
  } catch (error) {
    showToast('Gagal memuat permintaan upgrade', 'error');
  }
}

// Domains loader
async function loadDomains() {
  try {
    const response = await fetch('?ajax=domains');
    const data = await response.json();
    
    let html = `
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 animate__animated animate__fadeIn">
        <h3 class="text-2xl font-bold mb-6 flex items-center">
          <i class="fas fa-globe text-purple-500 mr-3"></i>
          Daftar Domain
        </h3>
        
        <div class="mb-4">
          <a href="?export=domains" class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors inline-block">
            <i class="fas fa-download mr-2"></i>Export Domain
          </a>
        </div>
        
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-gray-200 dark:border-gray-700">
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">ID</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Domain</th>
                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Aksi</th>
              </tr>
            </thead>
            <tbody>`;
    
    data.domains.forEach(domain => {
      html += `
        <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
          <td class="px-4 py-3">${domain.id}</td>
          <td class="px-4 py-3 font-medium">${escapeHtml(domain.domain)}</td>
          <td class="px-4 py-3">
            <a href="edit-domain.php?id=${domain.id}" class="p-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors inline-block mr-2">
              <i class="fas fa-edit"></i>
            </a>
            <a href="delete-domain.php?id=${domain.id}" onclick="return confirm('Hapus domain ini?')" 
               class="p-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors inline-block">
              <i class="fas fa-trash"></i>
            </a>
          </td>
        </tr>`;
    });
    
    html += `
            </tbody>
          </table>
        </div>
      </div>`;
    
    document.getElementById('mainContent').innerHTML = html;
    
  } catch (error) {
    showToast('Gagal memuat data domain', 'error');
  }
}

// Domain Monitoring loader
async function loadDomainMonitoring(page = 1, search = '', statusFilter = '', userFilter = '') {
  try {
    currentDomainPage = page;
    currentDomainSearch = search;
    
    const response = await fetch(`?ajax=domain_monitoring&page=${page}&search=${encodeURIComponent(search)}&status=${statusFilter}&user=${userFilter}`);
    const data = await response.json();
    
    // Get unique users for filter
    const usersResponse = await fetch('?ajax=users&page=1&limit=1000');
    const usersData = await usersResponse.json();
    
    let html = `
      <div class="animate__animated animate__fadeIn">
        <!-- Summary Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
          <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-4 text-white">
            <div class="flex justify-between items-center">
              <div>
                <p class="text-blue-100 text-xs">Total Domains</p>
                <p class="text-2xl font-bold mt-1">${data.summaryStats.total_domains}</p>
              </div>
              <i class="fas fa-globe text-2xl opacity-50"></i>
            </div>
          </div>
          
          <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-4 text-white">
            <div class="flex justify-between items-center">
              <div>
                <p class="text-green-100 text-xs">Active</p>
                <p class="text-2xl font-bold mt-1">${data.summaryStats.active_domains}</p>
              </div>
              <i class="fas fa-check-circle text-2xl opacity-50"></i>
            </div>
          </div>
          
          <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl shadow-lg p-4 text-white">
            <div class="flex justify-between items-center">
              <div>
                <p class="text-red-100 text-xs">Inactive</p>
                <p class="text-2xl font-bold mt-1">${data.summaryStats.inactive_domains}</p>
              </div>
              <i class="fas fa-times-circle text-2xl opacity-50"></i>
            </div>
          </div>
          
          <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg p-4 text-white">
            <div class="flex justify-between items-center">
              <div>
                <p class="text-purple-100 text-xs">Total Users</p>
                <p class="text-2xl font-bold mt-1">${data.summaryStats.total_users}</p>
              </div>
              <i class="fas fa-users text-2xl opacity-50"></i>
            </div>
          </div>
          
          <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg p-4 text-white">
            <div class="flex justify-between items-center">
              <div>
                <p class="text-yellow-100 text-xs">Avg Interval</p>
                <p class="text-2xl font-bold mt-1">${Math.round(data.summaryStats.avg_interval)} min</p>
              </div>
              <i class="fas fa-clock text-2xl opacity-50"></i>
            </div>
          </div>
        </div>
        
        <!-- Stale Domains Alert -->
        ${data.staleDomains && data.staleDomains.length > 0 ? `
          <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4 mb-6">
            <h4 class="font-semibold text-yellow-800 dark:text-yellow-300 mb-2 flex items-center">
              <i class="fas fa-exclamation-triangle mr-2"></i>
              Domain yang Belum Dicek Sesuai Interval
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
              ${data.staleDomains.map(d => `
                <div class="bg-white dark:bg-gray-800 rounded p-2 text-sm">
                  <div class="font-medium text-gray-800 dark:text-gray-200">${escapeHtml(d.domain)}</div>
                  <div class="text-xs text-gray-600 dark:text-gray-400">
                    User: @${escapeHtml(d.username)} | 
                    ${d.last_checked ? `Last: ${Math.round(d.minutes_since_check / 60)}h ago` : 'Never checked'}
                  </div>
                </div>
              `).join('')}
            </div>
          </div>
        ` : ''}
        
        <!-- Main Content -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
          <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold flex items-center">
              <i class="fas fa-chart-line text-green-500 mr-3"></i>
              Domain Monitoring
            </h3>
            <button onclick="refreshDomainMonitoring()" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors">
              <i class="fas fa-sync-alt mr-2"></i>Refresh
            </button>
          </div>
          
          <!-- Filters -->
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <input type="text" id="domainSearchInput" placeholder="Cari domain..." 
                   value="${search}" 
                   class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent">
            
            <select id="statusFilter" onchange="applyDomainFilters()" 
                    class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700">
              <option value="">Semua Status</option>
              <option value="1" ${statusFilter === '1' ? 'selected' : ''}>Active</option>
              <option value="0" ${statusFilter === '0' ? 'selected' : ''}>Inactive</option>
            </select>
            
            <select id="userFilter" onchange="applyDomainFilters()" 
                    class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700">
              <option value="">Semua User</option>
              ${usersData.users.map(u => `
                <option value="${u.id}" ${userFilter == u.id ? 'selected' : ''}>
                  @${escapeHtml(u.username)} [${u.type.toUpperCase()}]
                </option>
              `).join('')}
            </select>
            
            <a href="?export=domain_monitoring" 
               class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors text-center">
              <i class="fas fa-download mr-2"></i>Export
            </a>
          </div>
          
          <!-- Table -->
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                  <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">User</th>
                  <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Domain</th>
                  <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Status</th>
                  <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Interval</th>
                  <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Last Check</th>
                  <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Created</th>
                  <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Action</th>
                </tr>
              </thead>
              <tbody>`;
    
    if (data.domains.length > 0) {
      data.domains.forEach(domain => {
        const isStale = domain.status == 1 && domain.minutes_since_check > (domain.interval_minute * 2);
        const rowClass = isStale ? 'bg-yellow-50 dark:bg-yellow-900/20' : '';
        
        const typeClass = domain.user_type === 'vip' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 
                         (domain.user_type === 'vipmax' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300' : 
                         (domain.user_type === 'medium' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300' : 
                         'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300'));
        
        html += `
          <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors ${rowClass}">
            <td class="px-4 py-3">
              <div class="flex items-center">
                <span class="font-medium">${escapeHtml(domain.username)}</span>
                <span class="ml-2 px-2 py-1 rounded-full text-xs font-semibold ${typeClass}">
                  ${domain.user_type.toUpperCase()}
                </span>
              </div>
            </td>
            <td class="px-4 py-3">
              <div class="font-medium text-gray-800 dark:text-gray-200">
                ${escapeHtml(domain.domain)}
                ${isStale ? '<i class="fas fa-exclamation-triangle text-yellow-500 ml-2" title="Overdue check"></i>' : ''}
              </div>
            </td>
            <td class="px-4 py-3">
              ${domain.status == 1 ? 
                '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300"><i class="fas fa-circle text-green-500 mr-1"></i>Active</span>' : 
                '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300"><i class="fas fa-circle text-red-500 mr-1"></i>Inactive</span>'}
            </td>
            <td class="px-4 py-3">
              <span class="text-sm">${domain.interval_minute} menit</span>
            </td>
            <td class="px-4 py-3">
              <div class="text-sm">
                ${domain.last_checked ? 
                  `<div>${formatDateTime(domain.last_checked)}</div>
                   <div class="text-xs text-gray-500">${formatTimeAgo(domain.minutes_since_check)}</div>` : 
                  '<span class="text-gray-400">Belum pernah</span>'}
              </div>
            </td>
            <td class="px-4 py-3 text-sm">${formatDate(domain.created_at)}</td>
            <td class="px-4 py-3">
              <button onclick="viewDomainDetails(${domain.id})" 
                      class="p-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition-colors" 
                      title="View Details">
                <i class="fas fa-eye"></i>
              </button>
            </td>
          </tr>`;
      });
    } else {
      html += '<tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">Tidak ada domain ditemukan.</td></tr>';
    }
    
    html += `
            </tbody>
          </table>
        </div>`;
    
    // Pagination
    if (data.totalPages > 1) {
      html += `
        <div class="flex justify-center mt-6">
          <nav class="flex space-x-2">`;
      
      for (let i = 1; i <= data.totalPages; i++) {
        const isActive = i === data.currentPage;
        html += `
          <button onclick="navigate('domain-monitoring', {page: ${i}, search: '${search}', status: '${statusFilter}', user: '${userFilter}'})" 
                  class="px-4 py-2 rounded-lg ${isActive ? 'bg-primary text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-600'} transition-colors">
            ${i}
          </button>`;
      }
      
      html += `
          </nav>
        </div>`;
    }
    
    html += `
        </div>
      </div>
    `;
    
    document.getElementById('mainContent').innerHTML = html;
    
    // Setup search listener
    const searchInput = document.getElementById('domainSearchInput');
    searchInput.addEventListener('input', (e) => {
      clearTimeout(domainSearchTimeout);
      domainSearchTimeout = setTimeout(() => {
        applyDomainFilters();
      }, 500);
    });
    
    // Set up auto refresh every 30 seconds
    domainRefreshInterval = setInterval(() => {
      if (currentPage === 'domain-monitoring') {
        refreshDomainMonitoring();
      }
    }, 30000);
    
  } catch (error) {
    showToast('Gagal memuat data domain monitoring', 'error');
  }
}

// Apply domain filters
function applyDomainFilters() {
  const search = document.getElementById('domainSearchInput').value;
  const status = document.getElementById('statusFilter').value;
  const user = document.getElementById('userFilter').value;
  
  navigate('domain-monitoring', {
    page: 1, 
    search: search,
    status: status,
    user: user
  });
}

// Refresh domain monitoring
function refreshDomainMonitoring() {
  const search = document.getElementById('domainSearchInput')?.value || '';
  const status = document.getElementById('statusFilter')?.value || '';
  const user = document.getElementById('userFilter')?.value || '';
  
  loadDomainMonitoring(currentDomainPage, search, status, user);
}

// View domain details
async function viewDomainDetails(domainId) {
  try {
    const response = await fetch(`?ajax=domain_check_history&domain_id=${domainId}`);
    const data = await response.json();
    
    if (data.error) {
      showToast(data.error, 'error');
      return;
    }
    
    // Create modal content
    const modalHtml = `
      <div class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full max-h-[80vh] overflow-y-auto animate__animated animate__fadeInUp">
          <div class="p-6">
            <div class="flex justify-between items-center mb-6">
              <h3 class="text-2xl font-bold text-gray-800 dark:text-white">Domain Details</h3>
              <button onclick="closeDomainModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
              </button>
            </div>
            
            <div class="space-y-4">
              <div class="bg-gray-100 dark:bg-gray-700 rounded-lg p-4">
                <h4 class="font-semibold text-gray-800 dark:text-gray-200 mb-3">Domain Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">Domain:</span>
                    <span class="font-medium text-gray-800 dark:text-gray-200 ml-2">${escapeHtml(data.domain.domain)}</span>
                  </div>
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">User:</span>
                    <span class="font-medium text-gray-800 dark:text-gray-200 ml-2">@${escapeHtml(data.domain.username)}</span>
                  </div>
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">Status:</span>
                    <span class="ml-2">
                      ${data.domain.status == 1 ? 
                        '<span class="text-green-600 dark:text-green-400 font-medium">Active</span>' : 
                        '<span class="text-red-600 dark:text-red-400 font-medium">Inactive</span>'}
                    </span>
                  </div>
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">Check Interval:</span>
                    <span class="font-medium text-gray-800 dark:text-gray-200 ml-2">${data.domain.interval_minute} minutes</span>
                  </div>
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">Last Checked:</span>
                    <span class="font-medium text-gray-800 dark:text-gray-200 ml-2">
                      ${data.domain.last_checked ? formatDateTime(data.domain.last_checked) : 'Never'}
                    </span>
                  </div>
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">Created:</span>
                    <span class="font-medium text-gray-800 dark:text-gray-200 ml-2">${formatDateTime(data.domain.created_at)}</span>
                  </div>
                </div>
              </div>
              
              <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                <p class="text-blue-800 dark:text-blue-300 text-sm">
                  <i class="fas fa-info-circle mr-2"></i>
                  Domain check history feature coming soon. This will show detailed logs of each domain check including response times, status codes, and error messages.
                </p>
              </div>
            </div>
            
            <div class="mt-6 flex justify-end">
              <button onclick="closeDomainModal()" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
    
    // Add modal to body
    const modalDiv = document.createElement('div');
    modalDiv.id = 'domainDetailModal';
    modalDiv.innerHTML = modalHtml;
    document.body.appendChild(modalDiv);
    
  } catch (error) {
    showToast('Gagal memuat detail domain', 'error');
  }
}

// Close domain modal
function closeDomainModal() {
  const modal = document.getElementById('domainDetailModal');
  if (modal) {
    modal.remove();
  }
}

// Format time ago
function formatTimeAgo(minutes) {
  if (!minutes && minutes !== 0) return 'Unknown';
  
  if (minutes < 60) {
    return `${Math.round(minutes)} menit yang lalu`;
  } else if (minutes < 1440) {
    const hours = Math.round(minutes / 60);
    return `${hours} jam yang lalu`;
  } else {
    const days = Math.round(minutes / 1440);
    return `${days} hari yang lalu`;
  }
}

// Activities loader
async function loadActivities(search = '') {
  try {
    const response = await fetch(`?ajax=activities&search=${encodeURIComponent(search)}`);
    const data = await response.json();
    
    let html = `
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 animate__animated animate__fadeIn">
        <h3 class="text-2xl font-bold mb-6 flex items-center">
          <i class="fas fa-list text-info mr-3"></i>
          Log Aktivitas
        </h3>
        
        <div class="mb-4">
          <input type="text" id="activitySearchInput" placeholder="Cari aktivitas atau user..." 
                 value="${search}"
                 class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent">
        </div>
        
        <div class="max-h-96 overflow-y-auto">
          <ul class="space-y-2">`;
    
    data.activities.forEach(log => {
      html += `
        <li class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
          <div class="flex justify-between items-start">
            <div>
              <span class="font-semibold text-gray-800 dark:text-gray-200">${escapeHtml(log.username)}:</span>
              <span class="text-gray-600 dark:text-gray-400 ml-2">${escapeHtml(log.action)}</span>
            </div>
            <span class="text-sm text-gray-500 dark:text-gray-500">${formatDateTime(log.created_at)}</span>
          </div>
        </li>`;
    });
    
    html += `
          </ul>
        </div>
      </div>`;
    
    document.getElementById('mainContent').innerHTML = html;
    
    // Setup search listener
    const searchInput = document.getElementById('activitySearchInput');
    searchInput.addEventListener('input', (e) => {
      clearTimeout(activitySearchTimeout);
      activitySearchTimeout = setTimeout(() => {
        loadActivities(e.target.value);
      }, 500);
    });
    
  } catch (error) {
    showToast('Gagal memuat log aktivitas', 'error');
  }
}

// Telegram loader
function loadTelegram() {
  const html = `
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 animate__animated animate__fadeIn">
      <h3 class="text-2xl font-bold mb-6 flex items-center">
        <i class="fab fa-telegram text-blue-500 mr-3"></i>
        Kirim Pesan Telegram
      </h3>
      
      <form id="sendTelegramForm" enctype="multipart/form-data" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Pilih User</label>
          <select id="user_selector" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent">
            <option value="">-- Pilih User --</option>
            <option value="all_users">- Kirim ke Semua User -</option>
            <?php
            $usersWithTelegram = $pdo->query("SELECT username, telegram_id, type FROM users WHERE telegram_id IS NOT NULL AND telegram_id != '' ORDER BY username ASC")->fetchAll();
            foreach ($usersWithTelegram as $u) {
              echo '<option value="' . htmlspecialchars($u['telegram_id']) . '">@' . htmlspecialchars($u['username']) . ' [' . strtoupper(htmlspecialchars($u['type'])) . '] (' . htmlspecialchars($u['telegram_id']) . ')</option>';
            }
            ?>
          </select>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Chat ID / Group ID</label>
          <div class="flex gap-2">
            <input type="text" id="chat_id" name="chat_id" placeholder="Contoh: 123456789" 
                   class="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent hidden">
            <button type="button" id="toggleChatId" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors">
              Input Manual ID
            </button>
          </div>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Isi Pesan / Caption</label>
          <textarea id="message" name="message" rows="4" placeholder="Tulis pesan atau caption gambar..." required
                    class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-primary focus:border-transparent"></textarea>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Upload Gambar (Opsional)</label>
          <input type="file" id="photo" name="photo" accept="image/*" 
                 class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-blue-600">
        </div>
        
        <button type="submit" class="w-full py-3 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white rounded-lg font-medium transition-all duration-200 transform hover:scale-105">
          <i class="fas fa-paper-plane mr-2"></i>Kirim Pesan
        </button>
      </form>
    </div>
  `;
  
  document.getElementById('mainContent').innerHTML = html;
  
  // Setup telegram form handlers
  setupTelegramHandlers();
}

// Kritik loader
async function loadKritik() {
  const container = document.getElementById('mainContent');
  container.innerHTML = `
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 animate__animated animate__fadeIn">
      <h3 class="text-2xl font-bold mb-6 flex items-center">
        <i class="fas fa-comment-dots text-green-500 mr-3"></i>
        Daftar Kritik & Saran User
      </h3>
      
      <div id="kritikContent">
        <div class="text-center py-8">
          <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
          <p class="mt-4 text-gray-600 dark:text-gray-400">Memuat daftar kritik...</p>
        </div>
      </div>
    </div>
  `;
  
  try {
    const response = await fetch('ajax/get-all-kritik.php');
    const data = await response.json();
    
    if (!data.success) {
      document.getElementById('kritikContent').innerHTML = 
        '<div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4"><p class="text-red-800 dark:text-red-300">❌ Gagal memuat kritik user.</p></div>';
      return;
    }
    
    if (data.kritik.length === 0) {
      document.getElementById('kritikContent').innerHTML = 
        '<div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4"><p class="text-yellow-800 dark:text-yellow-300">Belum ada kritik & saran dari user.</p></div>';
      return;
    }
    
    const groupByDate = {};
    data.kritik.forEach(k => {
      const dateOnly = new Date(k.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' });
      if (!groupByDate[dateOnly]) groupByDate[dateOnly] = [];
      groupByDate[dateOnly].push(k);
    });
    
    let html = '';
    Object.entries(groupByDate).forEach(([date, kritiks]) => {
      html += `
        <div class="mb-6">
          <h4 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center">
            <i class="fas fa-calendar-day mr-2 text-primary"></i>${date}
          </h4>
          <div class="space-y-3">`;
      
      kritiks.forEach(k => {
        html += `
          <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <div class="flex justify-between items-start mb-2">
              <h5 class="font-semibold text-gray-800 dark:text-gray-200">
                <i class="fas fa-user mr-2 text-gray-500"></i>${k.username ? escapeHtml(k.username) : '<i>Unknown</i>'}
              </h5>
              <span class="text-sm text-gray-500">${new Date(k.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</span>
            </div>
            <p class="text-gray-600 dark:text-gray-400">${escapeHtml(k.kritik_saran)}</p>
          </div>`;
      });
      
      html += `
          </div>
        </div>`;
    });
    
    document.getElementById('kritikContent').innerHTML = html;
    
  } catch (error) {
    document.getElementById('kritikContent').innerHTML = 
      '<div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4"><p class="text-red-800 dark:text-red-300">❌ Gagal terhubung ke server.</p></div>';
  }
}

// Helper functions
function escapeHtml(text) {
  const map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
  return text ? text.replace(/[&<>"']/g, m => map[m]) : '';
}

function formatDate(dateString) {
  const date = new Date(dateString);
  return date.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatDateTime(dateString) {
  const date = new Date(dateString);
  return date.toLocaleDateString('id-ID', { 
    day: '2-digit', 
    month: 'short', 
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function formatNumber(num) {
  return new Intl.NumberFormat('id-ID').format(num);
}

// Dark Mode
function toggleDarkMode() {
  document.documentElement.classList.toggle('dark');
  localStorage.setItem('darkMode', document.documentElement.classList.contains('dark') ? 'on' : 'off');
}

// Sidebar Toggle
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  sidebar.classList.toggle('-translate-x-full');
}

// Dropdown Toggle
function toggleDropdown() {
  document.getElementById('userDropdown').classList.toggle('hidden');
}

// Toast Functions
function showToast(message, type = 'success') {
  const toast = document.getElementById('toastNotifUser');
  const body = toast.querySelector('.toast-body');
  const icon = toast.querySelector('i');
  const iconContainer = toast.querySelector('.w-10');
  
  body.textContent = message;
  
  if (type === 'success') {
    icon.className = 'fas fa-check text-green-600 dark:text-green-400';
    iconContainer.className = 'w-10 h-10 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center';
  } else {
    icon.className = 'fas fa-times text-red-600 dark:text-red-400';
    iconContainer.className = 'w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center';
  }
  
  toast.classList.remove('translate-x-full');
  
  setTimeout(() => {
    hideToast();
  }, 5000);
}

function hideToast() {
  const toast = document.getElementById('toastNotifUser');
  toast.classList.add('translate-x-full');
}

// Edit User Modal
function closeEditModal() {
  document.getElementById('editUserModal').classList.add('hidden');
}

window.openEditUserModal = function(id, username, type, telegramId = '', telegramGroupId = '', notifPersonal = false, notifGroup = false) {
  document.getElementById('editUserId').value = id;
  document.getElementById('editUsername').value = username;
  document.getElementById('editType').value = type;
  document.getElementById('editPassword').value = '';
  document.getElementById('editTelegramId').value = telegramId;
  document.getElementById('editTelegramGroupId').value = telegramGroupId;
  document.getElementById('notif_to_personal').checked = !!notifPersonal;
  document.getElementById('notif_to_group').checked = !!notifGroup;
  
  document.getElementById('editUserModal').classList.remove('hidden');
};

// Edit User Form Submit
document.getElementById('editUserForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  
  fetch('ajax/edit-user.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    showToast(data.success ? '✅ Data user berhasil diperbarui.' : '❌ ' + data.message, data.success ? 'success' : 'error');
    
    if (data.success) {
      closeEditModal();
      // Reload current page data
      if (currentPage === 'users') {
        loadUsers(currentUserPage, currentUserSearch);
      }
    }
  })
  .catch(() => {
    showToast('❌ Gagal terhubung ke server.', 'error');
  });
});

// Delete User
function deleteUser(id) {
  if (!confirm('Yakin ingin menghapus user ini?')) return;
  
  fetch('ajax/delete-user.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'id=' + encodeURIComponent(id)
  })
  .then(res => res.json())
  .then(data => {
    showToast(data.message, data.success ? 'success' : 'error');
    
    if (data.success) {
      // Remove row immediately
      const row = document.getElementById('user-row-' + id);
      if (row) {
        row.classList.add('animate__animated', 'animate__fadeOut');
        setTimeout(() => {
          row.remove();
        }, 500);
      }
    }
  })
  .catch(() => {
    showToast('❌ Terjadi kesalahan saat menghapus.', 'error');
  });
}

// Setup Telegram Handlers
function setupTelegramHandlers() {
  // Send Telegram Form
  const form = document.getElementById('sendTelegramForm');
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      const submitBtn = this.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Mengirim...';
      
      fetch('ajax/send-telegram.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        showToast(data.message, data.success ? 'success' : 'error');
        
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>Kirim Pesan';
        
        if (data.success) {
          document.getElementById('sendTelegramForm').reset();
        }
      })
      .catch(() => {
        showToast('❌ Gagal terhubung ke server.', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>Kirim Pesan';
      });
    });
  }
  
  // Toggle Chat ID Input
  const toggleBtn = document.getElementById('toggleChatId');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function() {
      const chatIdInput = document.getElementById('chat_id');
      if (chatIdInput.classList.contains('hidden')) {
        chatIdInput.classList.remove('hidden');
        chatIdInput.focus();
        this.textContent = 'Sembunyikan Input ID';
      } else {
        chatIdInput.classList.add('hidden');
        chatIdInput.value = '';
        this.textContent = 'Input Manual ID';
      }
    });
  }
  
  // Auto-fill chat ID
  const userSelector = document.getElementById('user_selector');
  if (userSelector) {
    userSelector.addEventListener('change', function() {
      const selectedValue = this.value;
      const chatIdInput = document.getElementById('chat_id');
      
      if (selectedValue === 'all_users') {
        chatIdInput.value = 'all_users';
      } else if (selectedValue) {
        chatIdInput.value = selectedValue;
      }
    });
  }
}

// Handle browser back/forward
window.addEventListener('popstate', function(event) {
  const hash = window.location.hash.substring(1);
  if (hash) {
    const params = Object.fromEntries(new URLSearchParams(window.location.search));
    navigate(hash, params);
  } else {
    navigate('dashboard');
  }
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
  // Check dark mode preference
  if (localStorage.getItem('darkMode') === 'on') {
    document.documentElement.classList.add('dark');
  }
  
  // Handle initial route
  const hash = window.location.hash.substring(1);
  if (hash) {
    const params = Object.fromEntries(new URLSearchParams(window.location.search));
    navigate(hash, params);
  } else {
    navigate('dashboard');
  }
  
  // Close dropdown when clicking outside
  document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('userDropdown');
    const button = e.target.closest('button[onclick="toggleDropdown()"]');
    
    if (!button && !dropdown.contains(e.target)) {
      dropdown.classList.add('hidden');
    }
  });
  
  // Auto refresh stats every 30 seconds if on dashboard
  setInterval(() => {
    if (currentPage === 'dashboard') {
      loadDashboard();
    }
  }, 30000);
});

// Manage Subscription Functions
function openManageSubscription(userId, username, userType, upgradedAt, expiresAt) {
  // Reset form
  document.getElementById('manageSubscriptionForm').reset();
  
  // Set user info
  document.getElementById('subUserId').value = userId;
  document.getElementById('subUsername').textContent = '@' + username;
  document.getElementById('subCurrentType').textContent = userType.toUpperCase();
  document.getElementById('subCurrentType').className = `font-medium ml-2 ${
    userType === 'vip' ? 'text-green-600 dark:text-green-400' :
    userType === 'vipmax' ? 'text-red-600 dark:text-red-400' :
    userType === 'medium' ? 'text-blue-600 dark:text-blue-400' :
    'text-yellow-600 dark:text-yellow-400'
  }`;
  
  // Show current status if exists
  // Always show current status if available
  if (upgradedAt || expiresAt) {
      document.getElementById('currentStatusDisplay').classList.remove('hidden');
      document.getElementById('statusUpgraded').textContent = upgradedAt ? formatDate(upgradedAt) : 'Not set';
      document.getElementById('statusExpires').textContent = expiresAt ? formatDate(expiresAt) : 'Not set';
      
      const daysLeft = expiresAt ? Math.floor((new Date(expiresAt) - new Date()) / (1000 * 60 * 60 * 24)) : 0;
      document.getElementById('statusDaysLeft').textContent = daysLeft > 0 ? `${daysLeft} hari` : 'Expired/No date';
      document.getElementById('statusDaysLeft').className = `font-medium ${daysLeft > 7 ? 'text-green-600' : daysLeft > 0 ? 'text-yellow-600' : 'text-red-600'}`;
  } else {
      document.getElementById('currentStatusDisplay').classList.add('hidden');
  }
  
  // Show modal
  document.getElementById('manageSubscriptionModal').classList.remove('hidden');
}

function closeSubscriptionModal() {
  document.getElementById('manageSubscriptionModal').classList.add('hidden');
}

function toggleSubscriptionFields() {
  const actionType = document.getElementById('subActionType').value;
  
  // Hide all fields first
  document.getElementById('upgradeTypeField').classList.add('hidden');
  document.getElementById('durationField').classList.add('hidden');
  document.getElementById('expireDateField').classList.add('hidden');
  document.getElementById('amountField').classList.add('hidden');
  
  // Show relevant fields
  switch(actionType) {
    case 'new':
    case 'extend':
      document.getElementById('upgradeTypeField').classList.remove('hidden');
      document.getElementById('durationField').classList.remove('hidden');
      document.getElementById('amountField').classList.remove('hidden');
      break;
    case 'modify':
      document.getElementById('upgradeDateField').classList.remove('hidden');
      document.getElementById('expireDateField').classList.remove('hidden');
      break;
    case 'cancel':
      // No additional fields needed
      break;
  }
}

function setDuration(days) {
  document.getElementById('subDuration').value = days;
}

// Manage Subscription Form Submit
document.getElementById('manageSubscriptionForm').addEventListener('submit', function(e) {
  e.preventDefault();
  
  const formData = new FormData(this);
  const actionType = formData.get('action_type');
  
  if (!actionType) {
    showToast('Pilih action type terlebih dahulu', 'error');
    return;
  }
  
  // Validate based on action type
  if ((actionType === 'new' || actionType === 'extend') && !formData.get('duration')) {
    showToast('Masukkan durasi subscription', 'error');
    return;
  }
  
  if (actionType === 'modify') {
    const upgradeDate = formData.get('upgrade_date');
    const expireDate = formData.get('expire_date');
    
    if (!upgradeDate && !expireDate) {
        showToast('Pilih minimal satu tanggal untuk diubah', 'error');
        return;
    }
    
    // Remove date comparison check - let admin set any dates
  }
  
  // Submit to server
  fetch('ajax/manage-subscription.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    showToast(data.message, data.success ? 'success' : 'error');
    
    if (data.success) {
      closeSubscriptionModal();
      // Reload current page data
      if (currentPage === 'users') {
        loadUsers(currentUserPage, currentUserSearch);
      } else if (currentPage === 'dashboard') {
        loadDashboard();
      }
    }
  })
  .catch(() => {
    showToast('❌ Gagal terhubung ke server.', 'error');
  });
});
</script>
</body>
</html>
<?php
session_start();
require '../../includes/db.php';

$userId = $_SESSION['user_id'];

// === RENAME/UPDATE MAIN DOMAIN & FALLBACK ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id']) && isset($_POST['rename'])) {
    $id = (int)$_POST['id'];
    $domainBaru = trim($_POST['domain'] ?? '');
    $fallbackDomainBaru = trim($_POST['fallback_domain'] ?? '');

    // Validasi domain utama
    if (!$domainBaru || !preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $domainBaru)) {
        echo json_encode(['success' => false, 'message' => 'Format domain tidak valid!']); exit;
    }
    // Validasi fallback domain (boleh kosong)
    if ($fallbackDomainBaru && !preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $fallbackDomainBaru)) {
        echo json_encode(['success' => false, 'message' => 'Format fallback domain tidak valid!']); exit;
    }

    // Update kedua field sekaligus
    $stmt = $pdo->prepare("UPDATE main_domains SET domain=?, fallback_domain=? WHERE id=? AND user_id=?");
    $stmt->execute([$domainBaru, $fallbackDomainBaru, $id, $userId]);
    echo json_encode(['success' => true, 'message' => 'Domain dan fallback berhasil diubah!']);
    exit;
}

// === GET: list domain (with fallback) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT id, domain, fallback_domain, created_at FROM main_domains WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$userId]);
    echo json_encode(['success'=>true, 'domains'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// === POST: tambah/hapus ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete'])) {
        // Hapus
        $id = intval($_POST['id']);
        $pdo->prepare("DELETE FROM main_domains WHERE id=? AND user_id=?")->execute([$id, $userId]);
        echo json_encode(['success'=>true, 'message'=>'Main domain dihapus!']); exit;
    } elseif (isset($_POST['domain'])) {
        // Tambah
        $domain = trim($_POST['domain'] ?? '');
        $fallback = trim($_POST['fallback_domain'] ?? '');

        if (!$domain) {
            echo json_encode(['success'=>false, 'message'=>'Domain tidak boleh kosong!']); exit;
        }
        // Validasi domain utama
        if (!preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $domain)) {
            echo json_encode(['success'=>false, 'message'=>'Format domain salah!']); exit;
        }
        // Validasi fallback domain jika diisi
        if ($fallback && !preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $fallback)) {
            echo json_encode(['success'=>false, 'message'=>'Format fallback domain salah!']); exit;
        }
        $pdo->prepare("INSERT INTO main_domains (user_id, domain, fallback_domain, created_at) VALUES (?, ?, ?, NOW())")
            ->execute([$userId, $domain, $fallback]);
        echo json_encode(['success'=>true, 'message'=>'Main domain berhasil ditambahkan!']); exit;
    }
}

// Fallback (jika request tidak valid)
echo json_encode(['success'=>false, 'message'=>'Permintaan tidak valid!']);
exit;
?>

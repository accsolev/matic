<?php
session_start();
require '../../includes/db.php';

// Optional: Cek apakah user ini adalah admin
// if ($_SESSION['user_id'] != 1) exit('Akses ditolak!');

$stmt = $pdo->query("
  SELECT ur.id, ur.user_id, u.username, u.telegram_id, ur.upgrade_type, ur.amount, ur.created_at 
  FROM upgrade_requests ur 
  JOIN users u ON ur.user_id = u.id 
  WHERE ur.status = 'pending' 
  ORDER BY ur.created_at DESC
");
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
  <title>Admin Panel - Upgrade Requests</title>
  <meta charset="utf-8">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-5">
  <h2 class="mb-4">📥 Permintaan Upgrade Akun</h2>

  <?php if (empty($requests)): ?>
    <div class="alert alert-info">Tidak ada permintaan upgrade saat ini.</div>
  <?php else: ?>
    <table class="table table-bordered table-hover">
      <thead class="table-dark">
        <tr>
          <th>Username</th>
          <th>Tipe</th>
          <th>Jumlah</th>
          <th>Waktu</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $req): ?>
          <tr>
            <td>@<?= htmlspecialchars($req['username']) ?></td>
            <td><span class="badge bg-<?= $req['upgrade_type'] === 'vip' ? 'success' : 'primary' ?>"><?= strtoupper($req['upgrade_type']) ?></span></td>
            <td>Rp <?= number_format($req['amount']) ?></td>
            <td><?= date('d M Y H:i', strtotime($req['created_at'])) ?></td>
            <td>
              <form method="POST" action="confirm-upgrade-action.php" class="d-flex gap-2">
                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                <input type="hidden" name="user_id" value="<?= $req['user_id'] ?>">
                <input type="hidden" name="upgrade_type" value="<?= $req['upgrade_type'] ?>">
                <button name="action" value="confirm" class="btn btn-success btn-sm">✅ Konfirmasi</button>
                <button name="action" value="reject" class="btn btn-danger btn-sm">❌ Tolak</button>
              </form>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
</body>
</html>
<?php
require '../../../includes/db.php';

// Tangkap parameter
$page   = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$search = trim($_GET['search'] ?? '');
$limit  = 10;
$offset = ($page - 1) * $limit;

// Query user + upgrade info
$whereClause = '';
$params = [];

if ($search !== '') {
  $whereClause = "WHERE u.username LIKE ? OR u.telegram_id LIKE ?";
  $params[] = "%$search%";
  $params[] = "%$search%";
}

$totalQuery = $pdo->prepare("SELECT COUNT(*) FROM users u $whereClause");
$totalQuery->execute($params);
$totalUsers = $totalQuery->fetchColumn();

$query = $pdo->prepare("
  SELECT u.*, 
         (SELECT upgraded_at FROM upgrade_requests WHERE user_id = u.id AND status = 'confirmed' ORDER BY id DESC LIMIT 1) AS upgraded_at,
         (SELECT expires_at FROM upgrade_requests WHERE user_id = u.id AND status = 'confirmed' ORDER BY id DESC LIMIT 1) AS expires_at
  FROM users u
  $whereClause
  ORDER BY u.id DESC
  LIMIT $limit OFFSET $offset
");
$query->execute($params);
$users = $query->fetchAll(PDO::FETCH_ASSOC);

// Tampilkan baris <tr>
if (!empty($users)) {
  foreach ($users as $user) {
    $badge = $user['type'] === 'vip' ? 'success' : ($user['type'] === 'medium' ? 'info' : 'warning');
    $upgraded = $user['upgraded_at'] ? date('d M Y', strtotime($user['upgraded_at'])) : '-';
    $expired  = $user['expires_at'] ? date('d M Y', strtotime($user['expires_at'])) : '-';

    echo '<tr id="user-row-' . $user['id'] . '">';
    echo '<td>' . $user['id'] . '</td>';
    echo '<td>' . htmlspecialchars($user['username']) . '</td>';
    echo '<td>' . ($user['telegram_id'] ? htmlspecialchars($user['telegram_id']) : '<i>Belum diisi</i>') . '</td>';
     echo '<td>' . ($user['telegram_group_id'] ? htmlspecialchars($user['telegram_group_id']) : '<i>Belum diisi</i>') . '</td>';
    echo '<td><span class="badge bg-' . $badge . '">' . strtoupper($user['type']) . '</span></td>';
    echo '<td>' . htmlspecialchars($user['created_at']) . '</td>';
    echo '<td>' . $upgraded . '</td>';
    echo '<td>' . $expired . '</td>';
    echo '<td>
<button
  type="button"
  class="btn btn-sm btn-warning"
  onclick="window.openEditUserModal(
    <?= $user['id'] ?>,
    '<?= esc($user['username']) ?>',
    '<?= $user['type'] ?>',
    '<?= esc($user['telegram_id'] ?? '') ?>',
    '<?= esc($user['telegram_group_id'] ?? '') ?>',
    <?= ($user['notif_to_personal'] ?? 0) ? 'true' : 'false' ?>,
    <?= ($user['notif_to_group'] ?? 0) ? 'true' : 'false' ?>
  )"
>
  <i class="fa fa-edit"></i>
</button>
      <a href="#" class="btn btn-sm btn-danger" onclick="deleteUser(' . $user['id'] . ')">
        <i class="fa fa-trash"></i>
      </a>
    </td>';
    echo '</tr>';
  }
} else {
  echo '<tr><td colspan="8" class="text-center text-muted">Tidak ada user ditemukan.</td></tr>';
}

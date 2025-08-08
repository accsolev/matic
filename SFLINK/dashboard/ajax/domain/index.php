<?php
// dashboard.php
require_once '../../../includes/db.php';
date_default_timezone_set('Asia/Jakarta');

echo "<h2>Status Cek Domain per User</h2>";
echo "<style>
    table { border-collapse: collapse; width: 100%; font-family: Arial; }
    th, td { border: 1px solid #ddd; padding: 8px; }
    th { background-color: #f2f2f2; }
    .blocked { color: red; font-weight: bold; }
    .safe { color: green; font-weight: bold; }
</style>";

$sqlUsers = "SELECT id, username FROM users ORDER BY id";
$resUsers = $pdo->query($sqlUsers);

while ($user = $resUsers->fetch()) {
    $userId = $user['id'];
    echo "<h3>User: {$user['username']} (ID: $userId)</h3>";

    $stmt = $pdo->prepare("SELECT domain, status, last_checked, interval_minute FROM list_domains WHERE user_id = ?");
    $stmt->execute([$userId]);
    $domains = $stmt->fetchAll();

    if (!$domains) {
        echo "<p><i>Tidak ada domain terdaftar.</i></p>";
        continue;
    }

    echo "<table>
        <tr>
            <th>Domain</th>
            <th>Status</th>
            <th>Last Checked</th>
            <th>Interval (menit)</th>
            <th>Siap Dicek?</th>
        </tr>";

    foreach ($domains as $d) {
        $lastChecked = strtotime($d['last_checked'] ?? '2000-01-01 00:00:00');
        $interval = (int)$d['interval_minute'];
        $selisih = time() - $lastChecked;
        $siap = ($selisih >= ($interval * 60)) ? '<span class="safe">YA</span>' : '<span class="blocked">TIDAK</span>';

        echo "<tr>
            <td>{$d['domain']}</td>
            <td>" . ($d['status'] ? 'Aktif' : 'Nonaktif') . "</td>
            <td>{$d['last_checked']}</td>
            <td>{$interval}</td>
            <td>$siap</td>
        </tr>";
    }
    echo "</table><br>";
}
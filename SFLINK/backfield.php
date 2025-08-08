<?php
require_once 'includes/db.php';  // pastikan $pdo sudah konek
set_time_limit(0);

// 1) Ambil semua record yang belum punya geo
$stmt = $pdo->query("
  SELECT id, ip_address 
  FROM analytics 
  WHERE country IS NULL OR country = '' 
     OR city    IS NULL OR city    = ''
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Fungsi getGeo() sama dengan yang kita pake saat logClick
function getGeo(string $ip): array {
    $url  = "http://ip-api.com/json/{$ip}?fields=status,country,city";
    $json = @file_get_contents($url);
    if ($json) {
        $data = json_decode($json, true);
        if (($data['status'] ?? '') === 'success') {
            return [
                'country' => $data['country'] ?? 'Unknown',
                'city'    => $data['city']    ?? 'Unknown',
            ];
        }
    }
    return ['country' => 'Unknown', 'city' => 'Unknown'];
}

// 3) Loop dan update
$upd = $pdo->prepare("
  UPDATE analytics
     SET country = :country,
         city    = :city
   WHERE id      = :id
");
foreach ($rows as $r) {
    $geo = getGeo($r['ip_address']);
    $upd->execute([
      ':country' => $geo['country'],
      ':city'    => $geo['city'],
      ':id'      => $r['id']
    ]);
    // opsional sleep untuk hindari rate-limit API
    usleep(200_000); // 0.2 detik
}

echo "✔️ Backfill selesai untuk " . count($rows) . " record.\n";
<?php
// Konfigurasi API Cloudflare
define('CF_API_KEY', '1dbe11f48040907075c9e3903509dae6087d4');
define('CF_EMAIL', 'accsolev9@gmail.com');

// List Zone ID
$zoneIds = [
    '76f1282dfe5207402bb8a8c7383f7a79',
    '82cf35e43e0d4fc1e283d022590d8b62',
    '257744e851b3f35bdd301be5dab0c933'
];

// Fungsi ambil semua domain (pakai cache)
function getAllDomains() {
    $cacheFile = __DIR__ . '/cache_zone.json';
    $cacheTime = 300; // 5 menit

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (!empty($data)) return $data;
    }

    $zones = [];
    foreach ($GLOBALS['zoneIds'] as $zoneId) {
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "X-Auth-Email: " . CF_EMAIL,
                "X-Auth-Key: " . CF_API_KEY,
                "Content-Type: application/json"
            ]
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!empty($data['success']) && !empty($data['result']['name'])) {
            $zones[$zoneId] = $data['result']['name'];
        }
    }

    file_put_contents($cacheFile, json_encode($zones));
    return $zones;
}

// Fungsi ambil lokasi IP (pakai cache)
function getIpLocation($ip) {
    $cacheFile = __DIR__ . '/cache_ip.json';
    $cacheTime = 86400; // 1 hari

    $cache = [];
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
    }

    if (isset($cache[$ip]) && (time() - $cache[$ip]['time']) < $cacheTime) {
        return $cache[$ip]['data'];
    }

    $url = "http://ip-api.com/json/{$ip}?fields=status,country,city";
    $response = @file_get_contents($url);
    $data = json_decode($response, true);

    if ($data && $data['status'] == 'success') {
        $result = [
            'country' => $data['country'] ?? 'Unknown',
            'city' => $data['city'] ?? 'Unknown'
        ];
    } else {
        $result = ['country' => 'Unknown', 'city' => 'Unknown'];
    }

    $cache[$ip] = [
        'time' => time(),
        'data' => $result
    ];

    file_put_contents($cacheFile, json_encode($cache));
    return $result;
}

// Fungsi ambil daftar IP blokir
function getBlockedIps() {
    $allBlocks = [];
    $domains = getAllDomains();

    foreach ($GLOBALS['zoneIds'] as $zoneId) {
        $domain = $domains[$zoneId] ?? 'Unknown Domain';

        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/firewall/access_rules/rules?mode=block&per_page=1000");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "X-Auth-Email: " . CF_EMAIL,
                "X-Auth-Key: " . CF_API_KEY,
                "Content-Type: application/json"
            ]
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!empty($data['result'])) {
            foreach ($data['result'] as $rule) {
                $location = getIpLocation($rule['configuration']['value']);
                $date = new DateTime($rule['created_on'], new DateTimeZone('UTC'));
$date->setTimezone(new DateTimeZone('Asia/Jakarta'));
$createdOn = $date->format('Y-m-d H:i:s');
                $allBlocks[] = [
                    'id' => $rule['id'],
                    'ip' => $rule['configuration']['value'],
                    'zone_id' => $zoneId,
                    'domain' => $domain,
                    'country' => $location['country'],
                    'city' => $location['city'],
                    'created_on' => $createdOn
                ];
            }
        }
    }

    return $allBlocks;
}

// Fungsi hapus blokir berdasarkan ID rule
function deleteBlock($zoneId, $ruleId) {
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/firewall/access_rules/rules/$ruleId");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "DELETE",
        CURLOPT_HTTPHEADER => [
            "X-Auth-Email: " . CF_EMAIL,
            "X-Auth-Key: " . CF_API_KEY,
            "Content-Type: application/json"
        ]
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return !empty($data['success']);
}

// Proses mass delete
if (isset($_POST['mass_delete']) && !empty($_POST['rules'])) {
    $deleted = 0;
    foreach ($_POST['rules'] as $rule) {
        list($zoneId, $ruleId) = explode('|', $rule);
        if (deleteBlock($zoneId, $ruleId)) $deleted++;
    }
    echo "<script>alert('✅ $deleted IP berhasil dihapus dari blokir!'); window.location='';</script>";
    exit;
}

// Ambil data blokiran
$blockedIps = getBlockedIps();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Panel IP Blokir - Cloudflare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #searchInput { margin-bottom: 1rem; }
    </style>
</head>
<body class="bg-light p-4">
<div class="container">
    <h1 class="mb-4">Panel IP Blokir</h1>
    <div class="card shadow-sm">
        <div class="card-body">
            <input type="text" id="searchInput" class="form-control" placeholder="🔍 Cari IP / Domain / Negara / Kota / Tanggal...">

            <form method="POST" id="massDeleteForm" onsubmit="return confirm('Yakin mau hapus semua IP terpilih?');">
                <?php if (empty($blockedIps)): ?>
                    <div class="alert alert-success">✅ Tidak ada IP yang diblokir saat ini.</div>
                <?php else: ?>
                    <table class="table table-bordered" id="ipTable">
                        <thead class="table-light">
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>#</th>
                                <th>IP Address</th>
                                <th>Domain</th>
                                <th>Negara</th>
                                <th>Kota</th>
                                <th>Waktu Blokir</th>
                                <th>Zone ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($blockedIps as $i => $block): ?>
                                <tr>
                                    <td><input type="checkbox" name="rules[]" value="<?= htmlspecialchars($block['zone_id']) . '|' . htmlspecialchars($block['id']) ?>"></td>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= htmlspecialchars($block['ip']) ?></td>
                                    <td><strong><?= htmlspecialchars($block['domain']) ?></strong></td>
                                    <td><?= htmlspecialchars($block['country']) ?></td>
                                    <td><?= htmlspecialchars($block['city']) ?></td>
                                    <td><?= htmlspecialchars($block['created_on']) ?></td>
                                    <td><small><?= htmlspecialchars($block['zone_id']) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" name="mass_delete" class="btn btn-danger btn-sm mt-2">🧹 Hapus IP Terpilih</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<script>
// Search Filter
document.getElementById('searchInput').addEventListener('input', function() {
    let filter = this.value.toLowerCase();
    let rows = document.querySelectorAll('#ipTable tbody tr');
    rows.forEach(row => {
        let text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
});

// Select All Checkbox
document.getElementById('selectAll').addEventListener('click', function() {
    let checkboxes = document.querySelectorAll('#ipTable tbody input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = this.checked);
});
</script>
</body>
</html>

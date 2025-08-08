<?php
// --- Cloudflare Unblock Script ---
// Konfig
define('CF_API_KEY',   '1dbe11f48040907075c9e3903509dae6087d4');
define('CF_EMAIL',     'accsolev9@gmail.com');
$zoneIds = [
    '76f1282dfe5207402bb8a8c7383f7a79',
    '82cf35e43e0d4fc1e283d022590d8b62',
    '257744e851b3f35bdd301be5dab0c933'
];

// 1. Fetch All Blocked IP Rules (limit: 1000 per zone, edit jika perlu)
function cf_get_blocked_ips($zoneId) {
    $url = "https://api.cloudflare.com/client/v4/zones/$zoneId/firewall/access_rules/rules?mode=block&per_page=1000";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "X-Auth-Email: ".CF_EMAIL,
            "X-Auth-Key: ".CF_API_KEY,
            "Content-Type: application/json",
        ]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['result'] ?? [];
}

// 2. Unblock/Remove Rule by ID
function cf_delete_rule($zoneId, $ruleId) {
    $url = "https://api.cloudflare.com/client/v4/zones/$zoneId/firewall/access_rules/rules/$ruleId";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "X-Auth-Email: ".CF_EMAIL,
            "X-Auth-Key: ".CF_API_KEY,
            "Content-Type: application/json",
        ]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['success'] ?? false;
}

// 3. Proses Unblock Semua IP (bisa tambahkan filter sesuai keperluan)
$totalRemoved = 0;
foreach ($zoneIds as $zoneId) {
    $rules = cf_get_blocked_ips($zoneId);
    foreach ($rules as $r) {
        // Optional: Only unblock rules dengan note "Auto-block via PHP Anti-DDoS"
        if (isset($r['notes']) && strpos($r['notes'], 'Auto-block via PHP Anti-DDoS') !== false) {
            $ok = cf_delete_rule($zoneId, $r['id']);
            if ($ok) {
                echo "Unblocked {$r['configuration']['value']} on $zoneId\n";
                $totalRemoved++;
            }
        }
    }
}
echo "Total Unblocked: $totalRemoved\n";
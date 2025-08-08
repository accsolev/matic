<?php
// ================= CONFIG =================
$panel_url = 'https://45.61.135.61:34526';
$api_key   = '0IOejnLjVmPErxDQ9KqMctnY35xOvgmK';

// ============ INPUT DARI AJAX ============
$domain = strtolower(trim($_POST['domain'] ?? ''));
if (!$domain || !preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/', $domain)) {
    die(json_encode(['success'=>false, 'msg'=>'Domain kosong/format salah!']));
}

// Email SSL auto
$email = 'ssl@' . $domain;

// ============= HELPER FUNCTION ============
function aapanel_sign($api_key) {
    $time = time();
    $token = md5($time . md5($api_key));
    return [$time, $token];
}
function aapanel_request($url, $data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// 1. Cek Site ID domain
list($time, $token) = aapanel_sign($api_key);
$sites = aapanel_request("$panel_url/data?action=getData", [
    'request_time' => $time,
    'request_token' => $token,
    'table' => 'sites'
]);
$site_id = null;
foreach ($sites['data'] ?? [] as $site) {
    if ($site['name'] === $domain) {
        $site_id = $site['id'];
        break;
    }
}
if (!$site_id) die(json_encode(['success'=>false, 'msg'=>'Site tidak ditemukan di aaPanel: '.$domain]));

// **JANGAN CEK HASH DULU!**
// **Langsung apply SSL**
list($time, $token) = aapanel_sign($api_key);
$apply = aapanel_request("$panel_url/v2/ssl_domain?action=apply_new_ssl", [
    'request_time' => $time,
    'request_token' => $token,
    //'id' => $site_id,
    'auth_type' => 'http',
    'auto_wildcard' => 0,
    'domains' => json_encode([$domain]),
    'deploy' => 1,
    //'email' => $email,
    //'dnsapi' => '',
    //'hash' => $hash
]);

// Ambil task_id untuk polling status jika mau
$task_id = $apply['message']['task_id'] ?? null;

if (!($apply['status'] === 0 || $apply['status'] === true)) {
    $msg = "Gagal request SSL: " . (is_array($apply['message'] ?? null) ? json_encode($apply['message']) : ($apply['message'] ?? ''));
    die(json_encode(['success'=>false, 'msg'=>$msg]));
}

// 4. (Optional) Polling status selama 3x6 detik
if ($task_id) {
    $success = false;
    $msg = "SSL sedang diproses untuk $domain. Silakan cek ulang status SSL dalam beberapa menit. Jika sudah selesai, SSL otomatis terpasang.";
    for ($i = 1; $i <= 3; $i++) {
        sleep(6);
        $status = aapanel_request("$panel_url/v2/ssl_domain?action=ssl_tasks_status", [
            'request_time' => $time,
            'request_token' => $token,
            'task_id' => $task_id
        ]);
        if (($status['status'] ?? null) === true) {
            $success = true;
            $msg = "SSL sudah terpasang untuk $domain! Silakan cek/refresh panel.";
            break;
        }
    }
    die(json_encode([
        'success' => true,
        'msg' => $msg
    ]));
}

// 5. Default return (fire and forget)
die(json_encode([
    'success' => true,
    'msg' => "SSL sedang diproses untuk $domain. Silakan cek ulang status SSL dalam beberapa menit. Jika sudah selesai, SSL otomatis terpasang."
]));


<?php
// ================= CONFIG =================
$panel_url = 'https://45.61.135.61:34526';
$api_key   = '0IOejnLjVmPErxDQ9KqMctnY35xOvgmK';
$domain    = 'mangea.bah.lol';

// ================= FUNCTIONS =================
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

    // Tambahkan debug ini
    echo "<pre style='background:#222;color:#aaff00;padding:10px'>RESPONSE DARI CURL:<br>";
    var_dump($response);
    echo "</pre>";

    return json_decode($response, true);
}

// 1. Ambil data semua site di aaPanel
list($time, $token) = aapanel_sign($api_key);
$sites = aapanel_request("$panel_url/data?action=getData", [
    'request_time' => $time,
    'request_token' => $token,
    'table' => 'sites'
]);
if (empty($sites['data'])) {
    echo "Gagal mengambil data site dari aaPanel!<br>";
    exit;
}

// 2. Temukan site yang sesuai
$found = false;
foreach ($sites['data'] as $site) {
    if ($site['name'] === $domain) {
        $found = true;
        $site_id = $site['id'];
        break;
    }
}
if (!$found) {
    echo "Domain <b>$domain</b> tidak ditemukan di aaPanel.<br>";
    exit;
}

echo "<h3>Detail Site: <span style='color:darkblue'>$domain</span></h3>";
echo "[1] Site ID: <b>{$site_id}</b><br><hr>";

// 3. Ambil daftar proxy (GetProxyList)
list($time, $token) = aapanel_sign($api_key);
$proxylist = aapanel_request("$panel_url/site?action=GetProxyList", [
    'request_time' => $time,
    'request_token' => $token,
    'sitename'     => $domain
]);

// Debug print response GetProxyList
echo "<pre style='background:#222;color:#fff;padding:10px;'>[DEBUG GetProxyList]\n";
print_r($proxylist);
echo "</pre>";

$proxy_arr = [];
if (isset($proxylist[0]) && is_array($proxylist[0])) {
    $proxy_arr = $proxylist;
} elseif (!empty($proxylist['proxies'])) {
    $proxy_arr = $proxylist['proxies'];
} elseif (!empty($proxylist['list'])) {
    $proxy_arr = $proxylist['list'];
} elseif (!empty($proxylist['data'])) {
    $proxy_arr = $proxylist['data'];
}

echo "<h4>Proxy List:</h4>";
if ($proxy_arr) {
    foreach ($proxy_arr as $proxy) {
        echo "<b>Proxy Name:</b> {$proxy['proxyname']} | <b>Dir:</b> {$proxy['proxydir']}<br>";
    }
    $proxyname = $proxy_arr[0]['proxyname']; // Ambil proxyname pertama
    // Print juga md5-nya untuk debug path
    echo "<small style='color:orange'>[proxyname]: {$proxyname} | [md5]: ".md5($proxyname)."</small><br>";
} else {
    echo "Tidak ada proxy yang terdaftar di domain ini.<br>";
    exit;
}

// 4. Get Proxy Configuration File
list($time, $token) = aapanel_sign($api_key);
$proxy_conf = aapanel_request("$panel_url/site?action=GetProxyList", [
    'request_time' => $time,
    'request_token' => $token,
    'sitename' => $domain,
    'proxyname' => $proxyname
]);

$proxy_md5 = md5($proxyname); // e.g., "03e8e9ca8fd90f09d5470b0b11d106c9"
$config_path = "/www/server/panel/vhost/nginx/proxy/{$domain}/{$proxy_md5}.conf";

if (file_exists($config_path)) {
    $config_content = file_get_contents($config_path);
    echo "<pre style='background:#23272e;color:#00ff9c;padding:15px;'>$config_content</pre>";
} else {
    echo "Config file not found at: $config_path";
}

echo "[DEBUG] Request URL: $panel_url/site?action=GetProxyFile" . http_build_query($proxy_data);
?>

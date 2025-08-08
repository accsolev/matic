<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require_once '../../includes/db.php'; // variabel koneksi: $pdo

header('Content-Type: application/json');

// -------- CONFIG -------
$cf_email   = 'accsolev9@gmail.com';
$cf_apikey  = '1dbe11f48040907075c9e3903509dae6087d4';
$aapanel_api_url = 'https://45.61.135.61:34526';
$aapanel_api_key = '0IOejnLjVmPErxDQ9KqMctnY35xOvgmK';
// ----------------------

// --- SESSION USER LOGIN (HARUS SUDAH LOGIN) ---
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success'=>false, 'msg'=>'Session user hilang, silakan login ulang!']);
    exit;
}
$user_id = intval($_SESSION['user_id']);




// Utility
function esc($str) { return htmlspecialchars($str??'', ENT_QUOTES); }

// Cloudflare API
function cf_api($method, $path, $data=[]) {
    global $cf_email, $cf_apikey;
    $url = "https://api.cloudflare.com/client/v4$path";
    $headers = [
        "X-Auth-Email: $cf_email",
        "X-Auth-Key: $cf_apikey",
        "Content-Type: application/json"
    ];
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ];
    if (in_array($method, ['POST','PUT','PATCH']))
        $opts[CURLOPT_POSTFIELDS] = json_encode($data);
    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $out = curl_exec($ch);
    curl_close($ch);
    return json_decode($out,true);
}

// aaPanel Proxy Helper
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

function aapanel_add_proxy($panel_url, $api_key, $sitename, $proxy_name, $proxy_dir, $target_url, $subfilter = []) {
    list($request_time, $request_token) = aapanel_sign($api_key);
    $parse = parse_url($target_url);
    if (empty($parse['host'])) {
        return ['success' => false, 'msg' => "❌ Error: Format URL target tidak valid."];
    }
    $domain_send = $parse['host'];
    if (empty($proxy_dir) || $proxy_dir[0] !== '/') {
        $proxy_dir = '/' . ltrim($proxy_dir, '/');
    }
    if (substr($target_url, -1) !== '/') {
        $target_url .= '/';
    }
    if (empty($proxy_name)) {
        $proxy_name = 'proxy' . rand(10000, 99999);
    }
    $post_data = [
        'request_time'  => $request_time,
        'request_token' => $request_token,
        'type'         => 1,
        'proxyname'    => $proxy_name,
        'cachetime'    => 1,
        'proxydir'     => $proxy_dir,
        'proxysite'    => $target_url,
        'todomain'     => $domain_send,
        'cache'        => 0,
        'advanced'     => 0,
        'sitename'     => $sitename,
        'subfilter'    => json_encode($subfilter)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $panel_url . '/site?action=CreateProxy');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);

    if ($response === false) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'msg' => "❌ Error dalam permintaan cURL: " . $error_msg];
    }
    curl_close($ch);

    $res = json_decode($response, true);
    if (isset($res['status']) && $res['status']) {
        return ['success' => true, 'msg' => "✅ Berhasil setting tujuan url, silahkan akses beberapa menit lagi!"];
    } else {
        return ['success' => false, 'msg' => "❌ Gagal: " . json_encode($res)];
    }
}

// -------- AJAX ACTION HANDLER --------
$action = $_POST['action'] ?? $_GET['action'] ?? '';
// 1. ADD DOMAIN
if ($action == 'add_domain') {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    if (!preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~', $domain))
        die(json_encode(['success'=>false, 'msg'=>'Format domain salah!']));
    // Pastikan domain belum ada milik user ini
    $cek = $pdo->prepare("SELECT id FROM domains_cf WHERE user_id=? AND domain=?");
    $cek->execute([$user_id, $domain]);
    if ($cek->fetch()) die(json_encode(['success'=>false, 'msg'=>'Domain sudah terdaftar untuk akun ini!']));

    // Tambah ke Cloudflare
    $resp = cf_api('POST', '/zones', ['name'=>$domain, 'jump_start'=>false]);
    if (!$resp['success'] || !isset($resp['result']['name_servers'])) {
        // Log debug
        file_put_contents(__DIR__.'/cf_debug.json', json_encode($resp, JSON_PRETTY_PRINT));
        $err = (!empty($resp['errors'][0]['message'])) ? ' ('.$resp['errors'][0]['message'].')' : '';
        die(json_encode(['success'=>false, 'msg'=>'Gagal tambah ke Cloudflare!' . $err]));
    }
    $zone = $resp['result'];
    $ns1 = $zone['name_servers'][0] ?? '';
    $ns2 = $zone['name_servers'][1] ?? '';
    $zone_id = $zone['id'];

    // Tambahkan A record @ dan wildcard *
    cf_api('POST', "/zones/$zone_id/dns_records", [
        'type'    => 'A',
        'name'    => $domain, // root (@)
        'content' => '45.61.135.61',
        'ttl'     => 1,
        'proxied' => true
    ]);
    cf_api('POST', "/zones/$zone_id/dns_records", [
        'type'    => 'A',
        'name'    => '*',
        'content' => '45.61.135.61',
        'ttl'     => 1,
        'proxied' => true
    ]);

    // Simpan DB pakai PDO, include user_id
    $status = 'pending';
    $stmt = $pdo->prepare("INSERT INTO domains_cf(user_id,domain,zone_id,ns1,ns2,status,cf_email,cf_api_key) VALUES(?,?,?,?,?,?,?,?)");
    $stmt->execute([$user_id, $domain, $zone_id, $ns1, $ns2, $status, $cf_email, $cf_apikey]);

    // === AUTO ADD SITE AAPANEL ===
    $phpver = '83';
    $site_path = '/www/wwwroot/' . $domain;
    $time = time();
    $req_token = md5($time . md5($aapanel_api_key));
    $post = [
        'request_token' => $req_token,
        'request_time'  => $time,
        'webname'       => json_encode(['domain'=>$domain, 'domainlist'=>[], 'count'=>0]),
        'path'          => $site_path,
        'type_id'       => 0,
        'type'          => 'PHP',
        'version'       => $phpver,
        'port'          => 80,
        'ps'            => 'PanelCFauto',
        'ftp'           => 'false',
        'sql'           => 'false',
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $aapanel_api_url . '/site?action=AddSite');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $aapanel_result = curl_exec($ch);
    curl_close($ch);

    $aa_data = json_decode($aapanel_result, true);

    $msg_aa = '';
    $site_id = 0;
    if ($aa_data && isset($aa_data['siteStatus']) && $aa_data['siteStatus'] === true) {
        $msg_aa = '<br><b>Add Domain berhasil dibuat!</b>';
        if (isset($aa_data['id'])) $site_id = $aa_data['id'];
    } else {
        $msg_aa = '<br><b class="text-danger">Add Domain gagal dibuat: '.esc(json_encode($aa_data)).'</b>';
    }

    // PATCH: Fallback cari site_id jika belum dapat
    if (!$site_id) {
        $time3 = time();
        $token3 = md5($time3 . md5($aapanel_api_key));
        $ch3 = curl_init();
        curl_setopt($ch3, CURLOPT_URL, $aapanel_api_url . '/data?action=getData');
        curl_setopt($ch3, CURLOPT_POST, true);
        curl_setopt($ch3, CURLOPT_POSTFIELDS, http_build_query([
            'request_token' => $token3,
            'request_time'  => $time3,
            'table'         => 'sites'
        ]));
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch3, CURLOPT_SSL_VERIFYHOST, false);
        $site_data = curl_exec($ch3);
        curl_close($ch3);
        $sites = json_decode($site_data, true);
        if (isset($sites['data'])) {
            foreach ($sites['data'] as $row) {
                if (isset($row['name']) && $row['name'] === $domain && isset($row['id'])) {
                    $site_id = $row['id'];
                    break;
                }
            }
        }
    }

    // Add wildcard domain jika site_id ada
    $wildcard_resp = null;
    if ($site_id) {
        $time2 = time();
        $token2 = md5($time2 . md5($aapanel_api_key));
        $post_wild = [
            'action'       => 'AddDomain',
            'id'           => $site_id,
            'webname'      => $domain,
            'domain'       => '*.'.$domain
        ];
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $aapanel_api_url . '/site?action=AddDomain');
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, http_build_query(array_merge([
            'request_token' => $token2,
            'request_time'  => $time2
        ], $post_wild)));
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
        $wild_resp = curl_exec($ch2);
        curl_close($ch2);
        $wildcard_resp = json_decode($wild_resp, true);
    }

    // Notif wildcard
    $msg_wildcard = '';
    if ($wildcard_resp && isset($wildcard_resp['status']) && $wildcard_resp['status']) {
        $msg_wildcard = '<br><b>Wildcard <span class="text-success">*.'.$domain.'</span> berhasil ditambahkan!</b>';
    } else if ($wildcard_resp) {
        $msg_wildcard = '<br><b class="text-danger">Wildcard gagal: '.esc(json_encode($wildcard_resp)).'</b>';
    }

    die(json_encode([
        'success'=>true,
        'msg'=>"Berhasil tambah domain <b>$domain</b> ke Cloudflare.<br>NS1: <b>$ns1</b><br>NS2: <b>$ns2</b>$msg_aa$msg_wildcard",
        'ns1'=>$ns1, 'ns2'=>$ns2, 'zone_id'=>$zone_id,
        'aapanel'=>$aa_data,
        'wildcard_add'=>$wildcard_resp
    ]));
}


// 2. REFRESH STATUS
if ($action == 'refresh_status') {
    $id = intval($_POST['id'] ?? 0);
    $q = $pdo->prepare("SELECT * FROM domains_cf WHERE id=? AND user_id=? LIMIT 1");
    $q->execute([$id, $user_id]);
    $r = $q->fetch();
    if (!$r) die(json_encode(['success'=>false, 'msg'=>'Domain tidak ditemukan!']));

    $status = cf_api('GET', "/zones/{$r['zone_id']}")['result']['status'] ?? 'unknown';
    $stmt = $pdo->prepare("UPDATE domains_cf SET status=? WHERE id=?");
    $stmt->execute([$status, $id]);

    if (strtolower($status) == 'pending') {
        die(json_encode([
            'success' => false,
            'msg' => "❌ <b>Status:</b> <span class='badge bg-warning'>PENDING</span><br>
            Domain <b>{$r['domain']}</b> <b>belum aktif</b> di Cloudflare.<br>
            Silakan <b>update nameserver domain anda</b> ke:
            <br><b>NS1:</b> {$r['ns1']}<br><b>NS2:</b> {$r['ns2']}
            <br>Setelah selesai, klik Refresh lagi sampai status <span class='badge bg-success'>ACTIVE</span>."
        ]));
    } else if (strtolower($status) == 'active') {
        die(json_encode([
            'success' => true,
            'msg' => "✅ <b>Status:</b> <span class='badge bg-success'>ACTIVE</span><br>
            Domain <b>{$r['domain']}</b> sudah aktif di Cloudflare!"
        ]));
    } else {
        die(json_encode([
            'success' => false,
            'msg' => "ℹ️ <b>Status:</b> <span class='badge bg-secondary'>".esc($status)."</span><br>
            Domain <b>{$r['domain']}</b> statusnya <b>".esc($status)."</b>."
        ]));
    }
}

// ===== aaPanel Helper: Get Site ID by domain =====
// ============ SITE ID GETTER BARU (FIX) ============
function aapanel_get_site_id($panel_url, $api_key, $domain) {
    // Ambil list site dari getData endpoint
    list($request_time, $request_token) = aapanel_sign($api_key);
    $params = [
        'request_time'  => $request_time,
        'request_token' => $request_token,
        'table'         => 'sites',
        'limit'         => 100
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $panel_url . '/data?action=getData');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $out = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($out, true);
    if (!empty($res['data'])) {
        foreach ($res['data'] as $site) {
            if (isset($site['name']) && $site['name'] == $domain) {
                return $site['id'];
            }
        }
    }
    // Logging error
    file_put_contents(__DIR__.'/aapanel_delete_site_error.log', "Site id not found for $domain\n", FILE_APPEND);
    return false;
}

function aapanel_delete_proxy($panel_url, $api_key, $sitename, $proxy_name) {
    list($request_time, $request_token) = aapanel_sign($api_key);
    $post_data = [
        'request_time'  => $request_time,
        'request_token' => $request_token,
        'sitename'      => $sitename,
        'proxyname'     => $proxy_name
    ];

    // Logging request
    file_put_contents(__DIR__.'/proxy_debug.log',
        "=== REMOVE PROXY ===\n".
        "RemoveProxy req: ".json_encode($post_data)."\n",
        FILE_APPEND);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $panel_url . '/site?action=RemoveProxy');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    file_put_contents(__DIR__.'/proxy_debug.log',
        "RemoveProxy resp: $response\nRemoveProxy cURL: $err\n",
        FILE_APPEND);

    $res = json_decode($response, true);

    // Logging parsed response
    file_put_contents(__DIR__.'/proxy_debug.log',
        "RemoveProxy decoded: ".print_r($res,true)."\n", FILE_APPEND);

    return (isset($res['status']) && $res['status']);
}

function aapanel_delete_all_proxy($panel_url, $api_key, $domain) {
    $time = time();
    $token = md5($time . md5($api_key));
    $post = [
        'request_time'  => $time,
        'request_token' => $token,
        'sitename'      => $domain
    ];

    file_put_contents(__DIR__.'/proxy_debug.log',
        "\n\n=== GET PROXY LIST ===\n".json_encode($post)."\n",
        FILE_APPEND);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $panel_url . '/site?action=GetProxyList');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    file_put_contents(__DIR__.'/proxy_debug.log',
        "GetProxyList resp: $out\nGetProxyList cURL: $err\n", FILE_APPEND);

    $res = json_decode($out, true);

    file_put_contents(__DIR__.'/proxy_debug.log',
        "GetProxyList decoded: ".print_r($res,true)."\n", FILE_APPEND);

    // ** FIXED: $res langsung array numerik **
    if (!empty($res) && is_array($res)) {
        foreach ($res as $proxy) {
            if (empty($proxy['proxyname'])) continue;
            file_put_contents(__DIR__.'/proxy_debug.log',
                "Proxy found for deletion: ".print_r($proxy,true)."\n", FILE_APPEND);
            aapanel_delete_proxy($panel_url, $api_key, $domain, $proxy['proxyname']);
        }
    } else {
        file_put_contents(__DIR__.'/proxy_debug.log',
            "No proxy found to delete for domain: $domain\n", FILE_APPEND);
    }
}

// ===== Delete Site aaPanel by site_id (NEW, FIXED) =====
function aapanel_delete_site($panel_url, $api_key, $domain) {
    $site_id = aapanel_get_site_id($panel_url, $api_key, $domain);
    if (!$site_id) {
        return ['status' => false, 'msg' => "Site id tidak ditemukan untuk $domain"];
    }
    list($request_time, $request_token) = aapanel_sign($api_key);
    $post = [
        'request_time'  => $request_time,
        'request_token' => $request_token,
        'id'            => $site_id,
        'webname'       => $domain,
        'database'      => 1,
        'path'          => 1,
        'ftp'           => 1,
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $panel_url . '/site?action=DeleteSite');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $out = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($out, true);
    return $res;
}


// ========== DELETE DOMAIN ACTION ==========
// --- DELETE DOMAIN: Cloudflare + aaPanel + DB + SUBDOMAIN FORCE DELETE ---
if ($action == 'delete_domain') {
    $id = intval($_POST['id'] ?? 0);
    $q = $pdo->prepare("SELECT * FROM domains_cf WHERE id=? AND user_id=? LIMIT 1");
    $q->execute([$id, $user_id]);
    $r = $q->fetch();
    if (!$r) die(json_encode(['success'=>false, 'msg'=>'Domain tidak ditemukan!']));

    $zone_id = $r['zone_id'];
    $main_domain = $r['domain'];
    $delete_log = [];

    // 1. Hapus semua subdomain dari tabel & cloudflare & aapanel
    $sres = $pdo->prepare("SELECT * FROM subdomains_cf WHERE domain_id=?");
    $sres->execute([$id]);
    while ($s = $sres->fetch()) {
        $sub = $s['subdomain'];

        // Hapus record A subdo dari Cloudflare
        $records = cf_api('GET', "/zones/$zone_id/dns_records?type=A&name=$sub")['result'] ?? [];
        foreach ($records as $rec) {
            cf_api('DELETE', "/zones/$zone_id/dns_records/{$rec['id']}");
        }

        // Hapus site di aaPanel
        aapanel_delete_site($aapanel_api_url, $aapanel_api_key, $sub);

        // Hapus dari DB subdomain
        $pdo->prepare("DELETE FROM subdomains_cf WHERE id=?")->execute([$s['id']]);
        $delete_log[] = "Subdo <b>$sub</b> dihapus!";
    }

    // 2. Hapus zone Cloudflare (domain utama)
    cf_api('DELETE', "/zones/{$zone_id}");
    $delete_log[] = "Cloudflare Zone utama dihapus!";

    // 3. Hapus semua proxy di aaPanel
    aapanel_delete_all_proxy($aapanel_api_url, $aapanel_api_key, $main_domain);

    // 4. Hapus site di aaPanel
    $aa_del = aapanel_delete_site($aapanel_api_url, $aapanel_api_key, $main_domain);
    if (empty($aa_del['status']) || $aa_del['status'] !== true) {
        $aa_msg = "Site domain tidak ditemukan atau sudah dihapus (skip).";
    } else {
        $aa_msg = "Site domain berhasil dihapus.";
    }

    // 5. Hapus dari database utama
    $pdo->prepare("DELETE FROM domains_cf WHERE id=? AND user_id=?")->execute([$id, $user_id]);

    die(json_encode([
        'success'=>true,
        'msg'=>"Sukses hapus domain <b>{$main_domain}</b> dan semua subdo terkait dari semua sistem!<br>$aa_msg<br>".implode('<br>',$delete_log)
    ]));
}


// 4. GET LIST (hanya milik user)
// 4. GET LIST (hanya milik user)
if ($action == 'get_list') {
    $out = [];
    $res = $pdo->prepare("SELECT * FROM domains_cf WHERE user_id=? ORDER BY id DESC");
    $res->execute([$user_id]);

    // --- PATCH: Sekali ambil semua site di aaPanel ---
    list($atime, $atok) = aapanel_sign($aapanel_api_key);
    $all_sites = aapanel_request("$aapanel_api_url/data?action=getData", [
        'request_time' => $atime,
        'request_token' => $atok,
        'table' => 'sites'
    ]);
    $map_domains = [];
    foreach ($all_sites['data'] ?? [] as $s) {
        $map_domains[$s['name']] = $s['id'];
    }

    while ($d = $res->fetch()) {
        // ====== CEK STATUS DOMAIN UTAMA DI CLOUDFLARE (A @ dan www) ======
        $zone_id = $d['zone_id'];
        $domain = $d['domain'];
        $is_main_domain_active = 0;
        $has_root = false; // @
        $has_www  = false; // www

        // Query DNS record dari Cloudflare (limit 50, aman)
        $records = cf_api('GET', "/zones/$zone_id/dns_records?type=A&per_page=50")['result'] ?? [];
        foreach ($records as $rec) {
            if ($rec['type'] == 'A' && $rec['name'] == $domain && $rec['content'] == '45.61.135.61') $has_root = true;
            if ($rec['type'] == 'A' && $rec['name'] == "www.$domain" && $rec['content'] == '45.61.135.61') $has_www = true;
        }
        $is_main_domain_active = ($has_root && $has_www) ? 1 : 0;
        $d['is_main_domain_active'] = $is_main_domain_active;

        // ====== LOAD LIST SUBDOMAIN ANAK UNTUK DOMAIN INI ======
$subs = [];
$sres = $pdo->prepare("SELECT subdomain FROM subdomains_cf WHERE domain_id=? ORDER BY id ASC");
$sres->execute([$d['id']]);
while ($s = $sres->fetch()) {
    $subname = $s['subdomain'];
    // Cari di all_sites: subdomain SSL status
    $sub_ssl = 0;
    if (!empty($all_sites['data'])) {
        foreach ($all_sites['data'] as $site_row) {
            if ($site_row['name'] === $subname && is_array($site_row['ssl'])) {
                $sub_ssl = 1;
                break;
            }
        }
    }
    $subs[] = [
        'name' => $subname,
        'is_ssl_enabled' => $sub_ssl
    ];
}
$d['subdomains'] = $subs;
        // ===== PATCH: DETEKSI SSL ENABLED (FAST via site list aaPanel) =====
        $site_id = $map_domains[$domain] ?? null;
        $is_ssl_enabled = 0;
        $ssl_expire = '';
        if (!empty($all_sites['data'])) {
            foreach ($all_sites['data'] as $site_row) {
                if ($site_row['name'] === $domain) {
                    if (is_array($site_row['ssl'])) {
                        $is_ssl_enabled = 1;
                        $ssl_expire = $site_row['ssl']['notAfter'] ?? '';
                    }
                    break;
                }
            }
        }
        $d['is_ssl_enabled'] = $is_ssl_enabled;
        $d['ssl_expire'] = $ssl_expire;

        $out[] = $d;
    }
    die(json_encode(['success'=>true, 'list'=>$out]));
}

// 5. ADD PROXY (REVERSE PROXY) ke aaPanel
if ($action == 'add_proxy') {
    $domain_id   = intval($_POST['domain_id'] ?? 0);
    $proxy_name  = trim($_POST['proxy_name'] ?? '');
    $proxy_dir   = trim($_POST['proxy_dir'] ?? '/');
    $target_url  = trim($_POST['target_url'] ?? '');
    $replace_from = trim($_POST['replace_from'] ?? '');
    $replace_to   = trim($_POST['replace_to'] ?? '');

    $q = $pdo->prepare("SELECT * FROM domains_cf WHERE id=? AND user_id=? LIMIT 1");
    $q->execute([$domain_id, $user_id]);
    $r = $q->fetch();
    if (!$r) die(json_encode(['success'=>false, 'msg'=>'Domain tidak ditemukan!']));

    $subfilter = [];
    if ($replace_from !== "" && $replace_to !== "") {
        $subfilter[] = ['sub1' => $replace_from, 'sub2' => $replace_to];
    }

    // 1. Hapus SEMUA proxy sebelum add proxy baru
    aapanel_delete_all_proxy($aapanel_api_url, $aapanel_api_key, $r['domain']);
    sleep(1);

    // 2. Tambahkan proxy baru
    $result = aapanel_add_proxy($aapanel_api_url, $aapanel_api_key, $r['domain'], $proxy_name, $proxy_dir, $target_url, $subfilter);

    // 3. Jika tetap gagal karena exists, ulang hapus & add lagi
    if (!$result['success'] && strpos($result['msg'], 'already exists') !== false) {
        aapanel_delete_all_proxy($aapanel_api_url, $aapanel_api_key, $r['domain']);
        sleep(1);
        $result = aapanel_add_proxy($aapanel_api_url, $aapanel_api_key, $r['domain'], $proxy_name, $proxy_dir, $target_url, $subfilter);
    }

    if ($result['success']) {
        $pdo->prepare("UPDATE domains_cf SET proxy_set=1 WHERE id=? AND user_id=?")->execute([$domain_id, $user_id]);
    }
    die(json_encode($result));
}

// 6. DELETE PROXY (DELETE URL)
if ($action == 'delete_proxy') {
    $id = intval($_POST['id'] ?? 0);
    $domain = trim($_POST['domain'] ?? '');
    $q = $pdo->prepare("SELECT * FROM domains_cf WHERE id=? AND user_id=? LIMIT 1");
    $q->execute([$id, $user_id]);
    $r = $q->fetch();
    if (!$r) die(json_encode(['success'=>false, 'msg'=>'Domain tidak ditemukan!']));

    // Ambil list proxy dari aaPanel, delete satu-satu pakai RemoveProxy
    $time = time();
    $token = md5($time . md5($aapanel_api_key));
    $post = [
        'request_time'  => $time,
        'request_token' => $token,
        'sitename'      => $r['domain']
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $aapanel_api_url . '/site?action=GetProxyList');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $out = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($out, true);

    $result = [];
    if (!empty($res['list'])) {
        foreach ($res['list'] as $proxy) {
            if (empty($proxy['proxyname'])) continue;
            // CALL RemoveProxy endpoint (bukan DeleteProxy!)
            $delete = aapanel_delete_proxy($aapanel_api_url, $aapanel_api_key, $r['domain'], $proxy['proxyname']);
            $result[] = $delete['msg'];
        }
    }
  aapanel_delete_all_proxy($aapanel_api_url, $aapanel_api_key, $r['domain']);
    sleep(1);
    // Update flag di DB
    $pdo->prepare("UPDATE domains_cf SET proxy_set=0 WHERE id=? AND user_id=?")->execute([$id, $user_id]);

    die(json_encode([
        'success'=>true,
        'msg'=>"Proxy/URL untuk <b>{$r['domain']}</b> berhasil dihapus!<br>" . implode('<br>',$result)
    ]));
}

// 7. ADD SUBDO
if ($action == 'add_subdo') {
    $domain_id = intval($_POST['domain_id'] ?? 0);
    $subdo = strtolower(trim($_POST['subdo'] ?? ''));
    if (!$domain_id || !$subdo || !preg_match('~^[a-z0-9][a-z0-9\-]{1,60}$~', $subdo)) {
        die(json_encode(['success'=>false, 'msg'=>'Format subdomain salah! (hanya huruf/angka/tanda minus)']));
    }
    // Ambil data domain utama
    $q = $pdo->prepare("SELECT * FROM domains_cf WHERE id=? AND user_id=? LIMIT 1");
    $q->execute([$domain_id, $user_id]);
    $d = $q->fetch();
    if (!$d) die(json_encode(['success'=>false, 'msg'=>'Domain tidak ditemukan!']));

    $main_domain = $d['domain'];
    $zone_id     = $d['zone_id'];
    $sub_domain  = "$subdo.$main_domain";

    // 1. Tambah A record subdo ke Cloudflare
    $cf = cf_api('POST', "/zones/$zone_id/dns_records", [
        'type'    => 'A',
        'name'    => $sub_domain,
        'content' => '45.61.135.61',
        'ttl'     => 1,
        'proxied' => true
    ]);
    if (empty($cf['success'])) {
        die(json_encode(['success'=>false, 'msg'=>'Gagal tambah A record ke Cloudflare: '.json_encode($cf)]));
    }

    // 2. Add Site ke aaPanel (mirip AddSite, path: /www/wwwroot/subdo.domain.tld)
    $phpver = '83';
    $site_path = '/www/wwwroot/' . $sub_domain;
    $time = time();
    $req_token = md5($time . md5($aapanel_api_key));
    $post = [
        'request_token' => $req_token,
        'request_time'  => $time,
        'webname'       => json_encode(['domain'=>$sub_domain, 'domainlist'=>[], 'count'=>0]),
        'path'          => $site_path,
        'type_id'       => 0,
        'type'          => 'PHP',
        'version'       => $phpver,
        'port'          => 80,
        'ps'            => 'PanelCFauto',
        'ftp'           => 'false',
        'sql'           => 'false',
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $aapanel_api_url . '/site?action=AddSite');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $aapanel_result = curl_exec($ch);
    curl_close($ch);

    $aa_data = json_decode($aapanel_result, true);

    // 3. Jika domain utama sudah di-set URL/proxy, auto set juga subdo reverse proxy
    $msg_proxy = '';
    if (!empty($d['proxy_set']) && $d['proxy_set']==1) {
        // Ambil target_url yg sudah ada di domain utama (auto clone)
        // Ambil proxy dari aaPanel: GetProxyList
        list($request_time, $request_token) = aapanel_sign($aapanel_api_key);
        $post_proxy = [
            'request_time'  => $request_time,
            'request_token' => $request_token,
            'sitename'      => $main_domain
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $aapanel_api_url . '/site?action=GetProxyList');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_proxy));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $resp = curl_exec($ch);
        curl_close($ch);
        $plist = json_decode($resp, true);

        $target_url = '';
        if (!empty($plist) && is_array($plist)) {
            // Ambil yang paling atas/pertama
            foreach ($plist as $px) {
                if (!empty($px['proxysite'])) {
                    $target_url = $px['proxysite'];
                    break;
                }
            }
        }

        if ($target_url) {
            // Set reverse proxy ke subdo (aaPanel)
            $proxy_res = aapanel_add_proxy($aapanel_api_url, $aapanel_api_key, $sub_domain, '', '/', $target_url, []);
            if ($proxy_res['success']) {
                $msg_proxy = '<br><b>Reverse proxy subdo berhasil diset!</b>';
            } else {
                $msg_proxy = '<br><b class="text-danger">Proxy gagal: '.esc(json_encode($proxy_res)).'</b>';
            }
        } else {
            $msg_proxy = '<br><b class="text-danger">Tidak ketemu URL target utama untuk auto set proxy subdo!</b>';
        }
    }

    // 4. Simpan subdo ke tabel subdomains_cf
    $stmt = $pdo->prepare("INSERT INTO subdomains_cf (domain_id, subdomain) VALUES (?, ?)");
    $stmt->execute([$domain_id, $sub_domain]);

    die(json_encode([
        'success'=>true,
        'msg'=>"Subdomain <b>$sub_domain</b> berhasil dibuat!<br>A record di Cloudflare OK.$msg_proxy"
    ]));
}

// 8. DELETE SUBDO
if ($action == 'delete_subdo') {
    $subdomain = strtolower(trim($_POST['subdomain'] ?? ''));
    $domain_id = intval($_POST['domain_id'] ?? 0);

    // Ambil data domain utama
    $q = $pdo->prepare("SELECT * FROM domains_cf WHERE id=? AND user_id=? LIMIT 1");
    $q->execute([$domain_id, $user_id]);
    $d = $q->fetch();
    if (!$d) die(json_encode(['success'=>false, 'msg'=>'Domain utama tidak ditemukan!']));

    $zone_id = $d['zone_id'];

    // 1. Hapus dari Cloudflare (A record)
    $cf = cf_api('GET', "/zones/$zone_id/dns_records?type=A&name=$subdomain");
    $hapusOk = true;
    if (!empty($cf['result'])) {
        foreach ($cf['result'] as $r) {
            $del = cf_api('DELETE', "/zones/$zone_id/dns_records/{$r['id']}");
            if (empty($del['success'])) $hapusOk = false;
        }
    }

    // 2. Hapus site subdo di aaPanel
    // (nama = subdomain, persis waktu add)
    $site_id = aapanel_get_site_id($aapanel_api_url, $aapanel_api_key, $subdomain);
    if ($site_id) {
        list($request_time, $request_token) = aapanel_sign($aapanel_api_key);
        $post = [
            'request_time'  => $request_time,
            'request_token' => $request_token,
            'id'            => $site_id,
            'webname'       => $subdomain,
            'database'      => 1,
            'path'          => 1,
            'ftp'           => 1,
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $aapanel_api_url . '/site?action=DeleteSite');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $out = curl_exec($ch);
        curl_close($ch);
    }

    // 3. Hapus dari table subdomains_cf
    $pdo->prepare("DELETE FROM subdomains_cf WHERE subdomain=? AND domain_id=?")->execute([$subdomain, $domain_id]);

    if ($hapusOk) {
        die(json_encode(['success'=>true, 'msg'=>"Subdomain <b>$subdomain</b> berhasil dihapus!"]));
    } else {
        die(json_encode(['success'=>false, 'msg'=>"Subdomain <b>$subdomain</b> gagal hapus A record di Cloudflare!"]));
    }
}

// TOGGLE MAIN DOMAIN
if ($action == 'toggle_main_domain') {
    $domain_id = intval($_POST['domain_id'] ?? 0);
    $enable = intval($_POST['enable'] ?? 0);
    $q = $pdo->prepare("SELECT * FROM domains_cf WHERE id=? AND user_id=? LIMIT 1");
    $q->execute([$domain_id, $user_id]);
    $d = $q->fetch();
    if (!$d) die(json_encode(['success'=>false, 'msg'=>'Domain tidak ditemukan!']));

    $zone_id = $d['zone_id'];
    $main_domain = $d['domain'];

    // Cari record_id A untuk www dan @
    $list = cf_api('GET', "/zones/$zone_id/dns_records?type=A&per_page=50");
    $a_www_id = $a_at_id = null;
    if (!empty($list['result'])) {
        foreach ($list['result'] as $rr) {
            if ($rr['name'] === $main_domain && $rr['content'] === '45.61.135.61') $a_at_id = $rr['id'];
            if ($rr['name'] === 'www.'.$main_domain && $rr['content'] === '45.61.135.61') $a_www_id = $rr['id'];
        }
    }

    if ($enable) {
        // Aktifkan: Tambah record A www dan A @
        $res1 = cf_api('POST', "/zones/$zone_id/dns_records", [
            'type'=>'A','name'=>$main_domain,'content'=>'45.61.135.61','ttl'=>1,'proxied'=>true
        ]);
        $res2 = cf_api('POST', "/zones/$zone_id/dns_records", [
            'type'=>'A','name'=>'www.'.$main_domain,'content'=>'45.61.135.61','ttl'=>1,'proxied'=>true
        ]);
        $msg = "Berhasil mengaktifkan kembali domain utama $main_domain dan dapat diakses kembali.";
        die(json_encode(['success'=>true, 'msg'=>$msg]));
    } else {
        // Matikan: Hapus A record www dan @ jika ada
        $failmsg = '';
        $ok = true;
        if ($a_at_id) {
            $del1 = cf_api('DELETE', "/zones/$zone_id/dns_records/$a_at_id");
            if (empty($del1['success'])) { $failmsg .= 'Gagal hapus A @. '; $ok = false; }
        }
        if ($a_www_id) {
            $del2 = cf_api('DELETE', "/zones/$zone_id/dns_records/$a_www_id");
            if (empty($del2['success'])) { $failmsg .= 'Gagal hapus A www. '; $ok = false; }
        }
        $msg = $ok ? "Matikan domain utama $main_domain berhasil dihapus dan tidak dapat diakses lagi." : $failmsg;
        die(json_encode(['success'=>$ok, 'msg'=>$msg]));
    }
}


// === GET PROXY CONFIG (untuk modal edit config domain/subdomain) ===
if ($action == 'get_config') {
    $domain = trim($_POST['domain'] ?? '');
    $is_sub = intval($_POST['is_sub'] ?? 0);

    // Ambil proxylist dulu
    list($time, $token) = aapanel_sign($aapanel_api_key);
    $proxy_list = aapanel_request($aapanel_api_url . '/site?action=GetProxyList', [
        'request_time' => $time,
        'request_token' => $token,
        'sitename'     => $domain
    ]);

    // Cari proxyname, fallback ke yang pertama
    $proxyname = $domain;
    if (!empty($proxy_list[0]['proxyname'])) {
        $proxyname = $proxy_list[0]['proxyname'];
    } elseif (!empty($proxy_list['list'][0]['proxyname'])) {
        $proxyname = $proxy_list['list'][0]['proxyname'];
    }

    // Ambil file config
    list($time, $token) = aapanel_sign($aapanel_api_key);
    $proxy_conf = aapanel_request($aapanel_api_url . '/site?action=GetProxyFile', [
        'request_time' => $time,
        'request_token' => $token,
        'sitename'     => $domain,
        'proxyname'    => $proxyname,
        'webserver'    => 'nginx'
    ]);
    
    // DEBUG (optional)
    //file_put_contents(__DIR__.'/proxy_get_config_debug.json', json_encode($proxy_conf));

    // === PATCH: Ambil config dari array[0]['data'] ===
    $config_data = '';
    if (is_array($proxy_conf) && isset($proxy_conf[0]['data']) && $proxy_conf[0]['data']) {
        $config_data = $proxy_conf[0]['data'];
    } elseif (isset($proxy_conf['config'])) {
        $config_data = $proxy_conf['config'];
    }

    if ($config_data) {
        echo json_encode(['success'=>true, 'config'=>$config_data]);
    } else {
        echo json_encode(['success'=>false, 'message'=>'Config not found!']);
    }
    exit;
}

// === SAVE PROXY CONFIG (simpan file config domain/subdomain) ===
if ($action == 'save_config') {
    $domain = trim($_POST['domain'] ?? '');
    $is_sub = intval($_POST['is_sub'] ?? 0);
    $config = $_POST['config'] ?? '';

    // --- STEP 1: Ambil proxyname & path dari GetProxyFile
    list($time, $token) = aapanel_sign($aapanel_api_key);
    $proxy_list = aapanel_request($aapanel_api_url . '/site?action=GetProxyList', [
        'request_time' => $time,
        'request_token' => $token,
        'sitename'     => $domain
    ]);
    $proxyname = $domain;
    if (!empty($proxy_list[0]['proxyname'])) {
        $proxyname = $proxy_list[0]['proxyname'];
    } elseif (!empty($proxy_list['list'][0]['proxyname'])) {
        $proxyname = $proxy_list['list'][0]['proxyname'];
    }

    // --- STEP 2: Ambil path file dari GetProxyFile
    list($time, $token) = aapanel_sign($aapanel_api_key);
    $proxy_conf = aapanel_request($aapanel_api_url . '/site?action=GetProxyFile', [
        'request_time' => $time,
        'request_token' => $token,
        'sitename'     => $domain,
        'proxyname'    => $proxyname,
        'webserver'    => 'nginx'
    ]);
    $config_path = '';
    if (is_array($proxy_conf) && isset($proxy_conf[1]) && is_string($proxy_conf[1])) {
        $config_path = $proxy_conf[1];
    } elseif (isset($proxy_conf['path'])) {
        $config_path = $proxy_conf['path'];
    }

    if (!$config_path) {
        echo json_encode(['success'=>false, 'message'=>'Config path tidak ditemukan dari aaPanel!']);
        exit;
    }

    // --- STEP 3: Save config dengan parameter path
    list($time, $token) = aapanel_sign($aapanel_api_key);
    $resp = aapanel_request($aapanel_api_url . '/site?action=SaveProxyFile', [
        'request_time' => $time,
        'request_token' => $token,
        'sitename'     => $domain,
        'proxyname'    => $proxyname,
        'webserver'    => 'nginx',
        'path'      => $config_path,
        'data'      => $config,
        'encoding'  => 'utf-8'
    ]);

    // Response OK check
    $ok = false;
    if (isset($resp['success']) && $resp['success']) $ok = true;
    if (isset($resp['status']) && $resp['status']) $ok = true;
    if (isset($resp[0]['status']) && $resp[0]['status']) $ok = true;

    if ($ok) {
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false, 'message'=>json_encode($resp)]);
    }
    exit;
}

if ($_POST['action'] === 'get_shortlink_domains') {
    $out = [];
    $q = $pdo->query("SELECT domain FROM domains ORDER BY id ASC");
    while($row = $q->fetch()) $out[] = ['domain' => $row['domain']];
    echo json_encode(['success'=>true,'list'=>$out]);
    exit;
}

if ($_POST['action'] === 'add_shortlink_subdo') {
    $alias        = trim($_POST['shortcode']   ?? '');
    $base_domain  = trim($_POST['basedomain']  ?? '');
    $subdo_domain = trim($_POST['subdodom']    ?? '');
    $referal      = trim($_POST['referal']     ?? '');
    $user_id      = $_SESSION['user_id']       ?? 0;

    // Validasi input
    if (!$alias || !$base_domain || !$subdo_domain || !$user_id) {
        echo json_encode(['success'=>false,'msg'=>'Input tidak valid!']); exit;
    }

    // Ambil domain_id dari domains (base shortlink)
    $stmt = $pdo->prepare("SELECT id FROM domains WHERE domain=?");
    $stmt->execute([$base_domain]);
    $domain_row = $stmt->fetch();
    if (!$domain_row) {
        echo json_encode(['success'=>false,'msg'=>'Domain base tidak valid!']); exit;
    }
    $domain_id = $domain_row['id'];

    // Cek alias sudah ada?
    $cek = $pdo->prepare("SELECT 1 FROM links WHERE short_code=? AND domain_id=? AND user_id=?");
    $cek->execute([$alias, $domain_id, $user_id]);
    if ($cek->fetch()) {
        echo json_encode(['success'=>false,'msg'=>'Alias sudah dipakai pada domain ini!']); exit;
    }

    // Simpan ke tabel links (wajib ada kolom referal_path dan user_id)
    $q = $pdo->prepare("INSERT INTO links (user_id, short_code, domain_id, is_subdo_random, domain_subdo, referal_path, created_at) VALUES (?, ?, ?, 1, ?, ?, NOW())");
    $q->execute([$user_id, $alias, $domain_id, $subdo_domain, $referal]);

    // Output
    $msg = "Shortlink subdo random <b>/$alias</b> berhasil dibuat di <b>$base_domain</b>, target subdo random: <b>$subdo_domain</b>";
    if ($referal) $msg .= " dengan path <b>$referal</b>";

    echo json_encode(['success'=>true, 'msg'=>$msg]);
    exit;
}

// === GET SHORTLINK SUBDO LIST ===
if ($_POST['action'] === 'get_shortlink_subdo_list') {
    $out = [];
    $user_id = $_SESSION['user_id'] ?? 0;
    // Tampilkan hanya shortlink subdo milik user yang login
    $q = $pdo->prepare("SELECT l.short_code, d.domain AS base_domain, l.domain_subdo, l.created_at
                        FROM links l
                        JOIN domains d ON l.domain_id = d.id
                        WHERE l.is_subdo_random=1 AND l.user_id = ?
                        ORDER BY l.id DESC");
    $q->execute([$user_id]);
    while($row = $q->fetch()) {
        $out[] = [
            'short_code'   => $row['short_code'],
            'base_domain'  => $row['base_domain'],
            'domain_subdo' => $row['domain_subdo'],
            'created_at'   => $row['created_at']
        ];
    }
    echo json_encode(['success'=>true, 'list'=>$out]);
    exit;
}

die(json_encode(['success'=>false, 'msg'=>'Invalid request']));

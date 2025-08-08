<?php
// Set zona waktu PHP ke Asia/Jakarta
date_default_timezone_set("Asia/Jakarta");

// TOKEN bot Telegram
$botToken = "7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw";
$apiUrl = "https://api.telegram.org/bot$botToken/";

// Koneksi ke database
$host = '127.0.0.1';
$db = 'sflink';
$user = 'sflink';
$pass = 'Memek123';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Set zona waktu MySQL ke Asia/Jakarta
    $pdo->exec("SET time_zone = '+07:00'");
} catch (\PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Fungsi kirim pesan ke Telegram
function sendMessage($chat_id, $message) {
    global $apiUrl;
    file_get_contents($apiUrl . "sendMessage?chat_id=$chat_id&text=" . urlencode($message) . "&parse_mode=HTML");
}

// Fungsi untuk mengambil konten HTML menggunakan cURL
function getHtmlContent($domain) {
    $url = "https://trustpositif.komdigi.go.id/?domains=" . urlencode($domain);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);

    if ($response === FALSE) {
        error_log("cURL error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return $response;
}

function logActivity($pdo, $userId, $username, $action) {
  $now = date('Y-m-d H:i:s'); // format waktu Asia/Jakarta (sudah diset timezone di atas)
  $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
  $stmt->execute([$userId, $username, $action, $now]);
}

// Fungsi untuk memparsing dan mengekstrak data tabel dari HTML
function extractTableData($html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//table//tr');
    $tableData = [];
    foreach ($rows as $row) {
        $cols = $xpath->query('td', $row);
        if ($cols->length > 0) {
            $rowData = [];
            foreach ($cols as $col) {
                $rowData[] = trim($col->nodeValue);
            }
            $tableData[] = $rowData;
        }
    }
    return $tableData;
}

// Fungsi untuk mengubah teks status yang ada
function filterStatus($status) {
    switch (trim($status)) {
        case 'Ada':
            return '❌ DIBLOKIR!!';
        case 'Tidak Ada':
            return '✅ AMAN';
        default:
            return $status;
    }
}
// Fungsi untuk mengecek apakah URL diblokir
function isUrlBlocked($url) {
    // Ambil domain dari URL
    $domain = parse_url($url, PHP_URL_HOST);

    // Jika domain berhasil diambil
    if ($domain) {
        // URL API TrustPositif
        $apiUrl = "https://trustpositif.komdigi.go.id/?domains=" . urlencode($domain);

        // Inisialisasi cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);

        // Mengecek apakah response mengandung teks 'Tidak Ada' yang menunjukkan domain aman
        return strpos($response, 'Tidak Ada') === false; // Jika false, domain diblokir
    } else {
        return false; // Jika tidak ada domain, dianggap tidak valid
    }
}



// Fungsi untuk menghapus semua sesi yang terkait dengan pengguna
function clearUserSession($chat_id) {
    $sessionFiles = glob("user_sessions/{$chat_id}_*.json");
    foreach ($sessionFiles as $file) {
        unlink($file);
    }
}

// Fungsi untuk mengecek status domain dan mengirimkan pemberitahuan ke pengguna
function checkAndNotifyBlockedDomains() {
    global $pdo;

    // Ambil semua link dari database
    $stmt = $pdo->prepare("SELECT l.short_code, u.telegram_id, r.url, r.id AS redirect_id
                           FROM links l 
                           JOIN users u ON l.user_id = u.id
                           JOIN redirect_urls r ON l.id = r.link_id");
    $stmt->execute();
    $links = $stmt->fetchAll();

    foreach ($links as $link) {
        // Cek apakah URL diblokir
        if (isUrlBlocked($link['url'])) {
            // Kirim pesan ke pengguna jika domain diblokir
            $message = "❌ Link tujuan <b>{$link['url']}</b> pada shortlink <b>{$link['short_code']}</b> diblokir!";
            sendMessage($link['telegram_id'], $message);

            // Hapus link yang diblokir dari tabel redirect_urls
            $deleteStmt = $pdo->prepare("DELETE FROM redirect_urls WHERE id = :redirect_id");
            $deleteStmt->bindParam(':redirect_id', $link['redirect_id']);
            $deleteStmt->execute();
        }
    }
}
function deleteMessage($chat_id, $message_id) {
    global $apiUrl;
    file_get_contents($apiUrl . "deleteMessage?chat_id=$chat_id&message_id=$message_id");
}

// ==================== VALIDASI INPUT ====================
function isValidAlias($alias) {
    return preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $alias);
}

function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) && strlen($url) <= 2000;
}


function getUserType($telegram_id, $pdo) {
    $stmt = $pdo->prepare("SELECT type FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    return $stmt->fetchColumn() ?: 'trial';
}

function checkDomainLimit($telegram_id, $pdo) {
    $type = getUserType($telegram_id, $pdo);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM list_domains WHERE user_id = (SELECT id FROM users WHERE telegram_id = ?)");
    $stmt->execute([$telegram_id]);
    $count = $stmt->fetchColumn();
    return !($type === 'trial' && $count >= 1 || $type === 'medium' && $count >= 3);
}

function checkShortlinkLimit($telegram_id, $pdo) {
    $type = getUserType($telegram_id, $pdo);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE user_id = (SELECT id FROM users WHERE telegram_id = ?)");
    $stmt->execute([$telegram_id]);
    $count = $stmt->fetchColumn();
    return !($type === 'trial' && $count >= 1 || $type === 'medium' && $count >= 3);
}


// Baca data dari Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);
if (!$update) exit();

// Deteksi isi pesan: teks bisa berada di text atau caption (misalnya saat kirim gambar)
$text = $update['message']['text'] ?? $update['message']['caption'] ?? '';
$chat_id = $update['message']['chat']['id'];
$username = $update['message']['chat']['username'] ?? 'Unknown';

// Bersihkan mention jika perintah ditulis /adddomain@BotKamu
$text = preg_replace('/@\w+/', '', $text);

// Cek apakah pengguna sudah terdaftar
$stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
$stmt->execute([$chat_id]);
$user = $stmt->fetch();
// Fungsi untuk mencatat log ke file
function logToFile($chat_id, $username, $command) {
    $logMessage = date("Y-m-d H:i:s") . " - [$chat_id] @$username : $command\n";
    file_put_contents("logs.txt", $logMessage, FILE_APPEND);
}

// Perintah /login
if ($text == "/id") {
    logToFile($chat_id, $username, $text);
    logActivity($pdo, $user['id'], $username, 'Mengakses perintah /id');
    sendMessage($chat_id, "👤 Info Pengguna:\n🆔 Telegram ID: <b>$chat_id</b>\n📛 Username: <b>$username</b>");
}
// Jika belum terdaftar, hanya boleh akses /register
if (!$user && strpos($text, "/register") !== 0) {
        logToFile($chat_id, $username, $text);
    sendMessage($chat_id, "⚠️ Anda belum terdaftar! Gunakan /register <key> untuk memulai.");
    exit();
}

// Perintah /register
if (strpos($text, "/register") === 0) {
    logToFile($chat_id, $username, $text);
    // Cek apakah pengguna sudah terdaftar
    $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    $userExists = $stmt->fetch();

    if ($userExists) {
        sendMessage($chat_id, "✅ Anda sudah terdaftar! Tidak perlu registrasi lagi.");
        exit();
    }

    $regKey = trim(substr($text, 10));
    if (empty($regKey)) {
        sendMessage($chat_id, "⚠️ Format salah! Gunakan: /register <key>");
        exit();
    }

    $stmt = $pdo->prepare("SELECT id FROM registration_keys WHERE reg_key = ? AND used_by IS NULL");
    $stmt->execute([$regKey]);
    $key = $stmt->fetch();

    if (!$key) {
        sendMessage($chat_id, "❌ Key registrasi tidak valid atau sudah dipakai!");
        exit();
    }

    $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username) VALUES (?, ?) ON DUPLICATE KEY UPDATE username = ?");
    $stmt->execute([$chat_id, $username, $username]);

    $stmt = $pdo->prepare("UPDATE registration_keys SET used_by = ? WHERE id = ?");
    $stmt->execute([$chat_id, $key['id']]);

    sendMessage($chat_id, "✅ Registrasi berhasil! Anda bisa menggunakan bot sekarang.");
}

elseif ($text == "/deldomain") {
    logToFile($chat_id, $username, $text);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    $userRow = $stmt->fetch();

    if (!$userRow) {
        sendMessage($chat_id, "⚠️ Anda belum terdaftar. Gunakan /register <key> untuk memulai.");
        exit;
    }

    $userId = $userRow['id'];

    // Ambil daftar domain milik user dari list_domains
    $stmt = $pdo->prepare("SELECT domain FROM list_domains WHERE user_id = ?");
    $stmt->execute([$userId]);
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$domains) {
        sendMessage($chat_id, "❌ Anda belum memiliki domain di list_domains.");
        exit;
    }

    file_put_contents("user_sessions/deldomain_$chat_id.json", json_encode(["domains" => $domains]));

    $message = "Pilih domain yang ingin Anda hapus dari daftar domain anda dengan mengetik angka:\n";
    foreach ($domains as $index => $domain) {
        $message .= ($index + 1) . ". $domain\n";
    }

    sendMessage($chat_id, $message);
    exit;
}

// Menangani pilihan angka dari /deldomain
elseif (is_numeric($text) && file_exists("user_sessions/deldomain_$chat_id.json")) {
    $sessionData = json_decode(file_get_contents("user_sessions/deldomain_$chat_id.json"), true);
    $domains = $sessionData['domains'];
    $selectedIndex = (int)$text - 1;

    if (!isset($domains[$selectedIndex])) {
        sendMessage($chat_id, "⚠️ Pilihan tidak valid. Silakan ketik angka sesuai daftar.");
        exit();
    }

    $selectedDomain = $domains[$selectedIndex];

    $stmt = $pdo->prepare("DELETE FROM list_domains WHERE domain = ? AND user_id = (SELECT id FROM users WHERE telegram_id = ?)");
    $stmt->execute([$selectedDomain, $chat_id]);

    unlink("user_sessions/deldomain_$chat_id.json");
    sendMessage($chat_id, "✅ Domain <b>$selectedDomain</b> berhasil dihapus dari daftar domain anda.");
    exit;
}
elseif ($text == "/listdomain") {
    logToFile($chat_id, $username, $text);
    logActivity($pdo, $user['id'], $username, 'Mengakses perintah /listdomain');

    // Cek apakah user terdaftar
    $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    $userRow = $stmt->fetch();

    if (!$userRow) {
        sendMessage($chat_id, "⚠️ Anda belum terdaftar. Gunakan /register <key> untuk memulai.");
        exit;
    }

    $userId = $userRow['id'];

    // Ambil daftar domain dari list_domains
    $stmt = $pdo->prepare("SELECT domain, created_at FROM list_domains WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $domains = $stmt->fetchAll();

    if (!$domains) {
        sendMessage($chat_id, "❌ Anda belum menambahkan domain apapun.\nGunakan /adddomain untuk mulai menambah.");
        exit;
    }

    $msg = "📄 <b>Daftar Domain Anda:</b>\n\n";
    foreach ($domains as $i => $row) {
        $no = $i + 1;
        $msg .= "$no. <b>{$row['domain']}</b>\nTanggal: {$row['created_at']}\n\n";
    }

    sendMessage($chat_id, $msg);
    exit;
}
elseif ($text == "/adddomain") {
    logActivity($pdo, $user['id'], $username, 'Mengakses perintah /adddomain');
    logToFile($chat_id, $username, $text);

    $stmt = $pdo->prepare("SELECT id, type FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    $userRow = $stmt->fetch();

    if (!$userRow) {
        sendMessage($chat_id, "⚠️ Anda belum terdaftar. Gunakan /register <key> untuk memulai.");
        exit;
    }

    file_put_contents("user_sessions/adddomain_$chat_id.json", json_encode(["step" => 1]));

    sendMessage($chat_id, "📝 Silakan ketik daftar domain yang ingin ditambahkan, satu per baris.\nContoh:\n<code>domain1.com\ndomain2.com</code>");
    exit;
}
elseif (file_exists("user_sessions/adddomain_$chat_id.json")) {
    $sessionData = json_decode(file_get_contents("user_sessions/adddomain_$chat_id.json"), true);

    if ($sessionData['step'] == 1) {
        $stmt = $pdo->prepare("SELECT id, type FROM users WHERE telegram_id = ?");
        $stmt->execute([$chat_id]);
        $userRow = $stmt->fetch();

        if (!$userRow) {
            sendMessage($chat_id, "⚠️ Anda belum terdaftar. Gunakan /register <key> untuk memulai.");
            exit;
        }

        $userId = $userRow['id'];
        $userType = $userRow['type'];

        $domains = array_filter(array_map('trim', explode("\n", $text)));

        if (empty($domains)) {
            sendMessage($chat_id, "⚠️ Tidak ada domain yang valid. Ketik ulang satu per baris.");
            exit;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM list_domains WHERE user_id = ?");
        $stmt->execute([$userId]);
        $totalDomains = $stmt->fetchColumn();

        $limitMap = ['trial' => 1, 'medium' => 3, 'vip' => 30, 'vipmax' => PHP_INT_MAX];
        $maxAllowed = $limitMap[$userType] ?? 1;

        $added = [];
        $exist = [];

        foreach ($domains as $domain) {
            if ($totalDomains >= $maxAllowed) {
                sendMessage($chat_id, "⚠️ Kamu adalah pengguna <b>" . strtoupper($userType) . "</b>. Maksimal hanya dapat menambahkan <b>$maxAllowed domain</b>.\n\nUpgrade akun untuk menambah lebih banyak.");
                break;
            }

            $domain = preg_replace('/^https?:\/\//', '', strtolower($domain));
            if (!preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/', $domain)) continue;

            $stmt = $pdo->prepare("SELECT id FROM list_domains WHERE domain = ? AND user_id = ?");
            $stmt->execute([$domain, $userId]);

            if ($stmt->fetch()) {
                $exist[] = $domain;
            } else {
                $stmt = $pdo->prepare("INSERT INTO list_domains (user_id, domain, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$userId, $domain]);
                $added[] = $domain;
                $totalDomains++;
            }
        }

        unlink("user_sessions/adddomain_$chat_id.json");

        $msg = "";
        if ($added) {
            $msg .= "✅ Berhasil ditambahkan:\n" . implode("\n", $added) . "\n\n";
        }
        if ($exist) {
            $msg .= "⚠️ Sudah ada:\n" . implode("\n", $exist);
        }

        sendMessage($chat_id, $msg ?: "❌ Tidak ada domain yang berhasil ditambahkan.");
        exit;
    }
}

elseif (strpos($text, "/check2") === 0) {
    // Ambil tipe user (trial atau vip)
    $stmt = $pdo->prepare("SELECT type FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    $userType = $stmt->fetchColumn() ?? 'trial';

    $params = explode("\n", $text);
    array_shift($params); // Hapus "/check2" dari array

    if (empty($params)) {
        sendMessage($chat_id, "⚠️ Gunakan format: /check2\ndomain1.com\ndomain2.com");
        exit();
    }

    // Filter trial user hanya bisa 1 domain
    if ($userType === 'trial' && count($params) > 1) {
        sendMessage($chat_id, "⚠️ Kamu masih pengguna TRIAL. Kamu hanya bisa mengecek 1 domain saja di mode ini.\n\nGunakan:\n/check2\ndomainkamu.com");
        exit();
    }

    $results = "";
    foreach ($params as $domain) {
        $domain = trim($domain);
        if (empty($domain)) continue;

        // Buat URL API untuk mengecek domain
        $url = "https://check.skiddle.id/?domain=" . urlencode($domain);

        // Ambil data dengan cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        // Decode JSON
        $data = json_decode($response, true);

        // Cek apakah domain ditemukan dalam respons
        if (!is_array($data) || !isset($data[$domain])) {
            $results .= "❓ <b>$domain</b>: Tidak ditemukan dalam respons\n";
        } elseif (isset($data[$domain])) {
            $status = $data[$domain]['blocked'] ? "❌ <b>Blokir</b>" : "✅ <b>Tidak Blokir</b>";
            $results .= "$status - <b>$domain</b>\n";
        }
    }

    // Kirim hasil ke pengguna
    sendMessage($chat_id, $results);
}

// Pastikan folder user_sessions ada untuk menyimpan sesi pengguna
if (!is_dir("user_sessions")) {
    mkdir("user_sessions", 0777, true);
}

$sessionFile = "user_sessions/$chat_id.json";

// Jika pengguna mengetik perintah baru (reset sesi)
if (in_array($text, ["/start", "/add", "/list", "/delete", "/id", "/domain", "/check"])) {
    if (file_exists($sessionFile)) {
        unlink($sessionFile);
    }
}

// Perintah /add untuk memilih domain terlebih dahulu
if ($text == "/add") {
    clearUserSession($chat_id); // Hapus sesi lama sebelum memulai sesi baru
    logActivity($pdo, $user['id'], $username, 'Mengakses perintah /add');
    logToFile($chat_id, $username, $text);
    

    
    $stmt = $pdo->query("SELECT domain FROM domains");
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$domains) {
        sendMessage($chat_id, "❌ Tidak ada domain yang tersedia saat ini.");
        exit();
    }

    // Simpan sesi baru
    file_put_contents($sessionFile, json_encode(["step" => 1, "domains" => $domains]));

    $message = "📌 Pilih domain dengan mengetik angka:\n";
    foreach ($domains as $index => $domain) {
        $message .= ($index + 1) . ". $domain\n";
    }

    sendMessage($chat_id, $message);
    exit();
}

// Cek apakah ada sesi yang sedang berjalan
if (file_exists($sessionFile)) {
    $sessionData = json_decode(file_get_contents($sessionFile), true);

    // Langkah 2: Pilih domain berdasarkan angka
    if ($sessionData['step'] == 1 && is_numeric($text)) {
        $domains = $sessionData['domains'];
        $selectedIndex = (int)$text - 1;

        if (!isset($domains[$selectedIndex])) {
            sendMessage($chat_id, "⚠️ Pilihan tidak valid. Silakan ketik angka yang sesuai dengan daftar domain.");
            exit();
        }

        $sessionData['step'] = 2;
        $sessionData['domain'] = $domains[$selectedIndex];
        file_put_contents($sessionFile, json_encode($sessionData));

        sendMessage($chat_id, "📛 Masukkan alias shortlink yang diinginkan:");
        exit();
    }

    // Langkah 3: Masukkan alias shortlink
    if ($sessionData['step'] == 2) {
        $shortCode = trim($text);
        if (empty($shortCode)) {
            sendMessage($chat_id, "⚠️ Alias tidak boleh kosong. Masukkan alias shortlink:");
            exit();
        }
        if (!isValidAlias($shortCode)) {
            sendMessage($chat_id, "❌ Alias tidak valid! Hanya huruf, angka, - dan _ (3-30 karakter).");
            exit();
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE short_code = ? AND domain_id = (SELECT id FROM domains WHERE domain = ?)");
        $stmt->execute([$shortCode, $sessionData['domain']]);
        if ($stmt->fetchColumn()) {
            sendMessage($chat_id, "❌ Alias <b>$shortCode</b> sudah digunakan pada domain <b>{$sessionData['domain']}</b>. Silakan masukkan alias lain.");
            exit();
        }

        $sessionData['step'] = 3;
        $sessionData['short_code'] = $shortCode;
        $sessionData['urls'] = [];
        $sessionData['fallbacks'] = [];
        file_put_contents($sessionFile, json_encode($sessionData));

        sendMessage($chat_id, "🔗 Masukkan URL tujuan pertama (atau beberapa URL dipisahkan baris, ketik 'selesai' jika sudah):");
        exit();
    }

    // Langkah 4: Masukkan URL tujuan
    if ($sessionData['step'] == 3) {
        $urls = explode("\n", trim($text));

        if (strtolower($text) == "selesai") {
            if (empty($sessionData['urls'])) {
                sendMessage($chat_id, "⚠️ Anda harus memasukkan minimal 1 URL tujuan sebelum menyelesaikan.");
                exit();
            }

            $sessionData['step'] = 4;
            file_put_contents($sessionFile, json_encode($sessionData));
            sendMessage($chat_id, "🆘 Masukkan fallback URL (opsional) jika semua link diblokir, atau ketik 'skip' jika tidak ingin mengatur cadangan:");
            exit();
        }

        $addedUrls = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
                $url = "http://" . $url;
            }
            if (!isValidUrl($url)) {
                sendMessage($chat_id, "❌ URL tidak valid atau terlalu panjang. Masukkan URL yang benar.");
                continue;
            }
            $sessionData['urls'][] = $url;
            $addedUrls[] = $url;
        }

        file_put_contents($sessionFile, json_encode($sessionData));
        $message = "Link tujuan berhasil ditambahkan:\n" . implode("\n", array_map(fn($u) => "🔗 $u", $addedUrls));
        $message .= "\n\nMasukkan URL berikutnya atau ketik '<b>selesai</b>' jika sudah:";
        sendMessage($chat_id, $message);
        exit();
    }

// Langkah 5: Masukkan Fallback URL (multi-line support)
if ($sessionData['step'] == 4) {
    if (strtolower($text) === "skip") {
        $sessionData['fallbacks'] = [];
        $sessionData['step'] = 5;
        file_put_contents($sessionFile, json_encode($sessionData));
    } elseif (strtolower($text) === "selesai") {
        if (empty($sessionData['fallbacks'])) {
            sendMessage($chat_id, "⚠️ Anda belum memasukkan fallback URL apapun. Ketik 'skip' jika ingin lewati.");
            exit();
        }
        $sessionData['step'] = 5;
        file_put_contents($sessionFile, json_encode($sessionData));
    } else {
        $fallbacks = explode("\n", trim($text));
        $addedFallbacks = [];
        foreach ($fallbacks as $url) {
            $url = trim($url);
            if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
                $url = "http://" . $url;
            }
            if (!isValidUrl($url)) {
                sendMessage($chat_id, "❌ Fallback URL tidak valid: $url");
                continue;
            }
            $sessionData['fallbacks'][] = $url;
            $addedFallbacks[] = $url;
        }

        file_put_contents($sessionFile, json_encode($sessionData));

        $msg = "✅ Fallback URL ditambahkan:\n" . implode("\n", array_map(fn($u) => "🆘 $u", $addedFallbacks));
        $msg .= "\n\nKetik fallback lain, atau ketik '<b>selesai</b>' jika sudah, atau '<b>skip</b>' jika ingin melewati.";
        sendMessage($chat_id, $msg);
        exit();
    }
}

// Langkah 6: Simpan semua data ke database
if ($sessionData['step'] == 5) {
    // Cek tipe user dan batas shortlink
    $stmt = $pdo->prepare("SELECT id, type FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    $userRow = $stmt->fetch();
    $userId = $userRow['id'];
    $userType = $userRow['type'];

    // Hitung total shortlink
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE user_id = ?");
    $stmt->execute([$userId]);
    $totalLinks = $stmt->fetchColumn();

    // Batasi jumlah berdasarkan tipe
    if ($userType === 'trial' && $totalLinks >= 1) {
        sendMessage($chat_id, "⚠️ Kamu adalah pengguna <b>TRIAL</b>. Maksimal hanya bisa membuat <b>1 shortlink</b>.\n\nUpgrade akun untuk menambah lebih banyak.");
        unlink($sessionFile); // reset sesi
        exit();
    } elseif ($userType === 'medium' && $totalLinks >= 3) {
        sendMessage($chat_id, "⚠️ Kamu adalah pengguna <b>MEDIUM</b>. Maksimal hanya bisa membuat <b>3 shortlink</b>.\n\nUpgrade ke VIP untuk tanpa batas.");
        unlink($sessionFile); // reset sesi
        exit();
    }

    // Ambil domain_id
    $stmt = $pdo->prepare("SELECT id FROM domains WHERE domain = ?");
    $stmt->execute([$sessionData['domain']]);
    $domainData = $stmt->fetch();
    $domainId = $domainData['id'];

    // Simpan link utama
    $stmt = $pdo->prepare("INSERT INTO links (user_id, short_code, domain_id) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $sessionData['short_code'], $domainId]);
    $linkId = $pdo->lastInsertId();

    // Simpan redirect URL
    foreach ($sessionData['urls'] as $link) {
        $stmt = $pdo->prepare("INSERT INTO redirect_urls (link_id, url) VALUES (?, ?)");
        $stmt->execute([$linkId, $link]);
    }

    // Simpan fallback URL
    foreach ($sessionData['fallbacks'] as $fb) {
        $stmt = $pdo->prepare("INSERT INTO fallback_urls (link_id, url) VALUES (?, ?)");
        $stmt->execute([$linkId, $fb]);
    }

    unlink($sessionFile);
    sendMessage($chat_id, "✅ Shortlink berhasil dibuat:\n<b>https://{$sessionData['domain']}/{$sessionData['short_code']}</b>");
    exit();
}
}

// Perintah /delete untuk memilih domain terlebih dahulu
elseif ($text == "/delete") {
    clearUserSession($chat_id); // Hapus sesi lama sebelum memulai sesi baru
    logActivity($pdo, $user['id'], $username, 'Mengakses perintah /delete');
    logToFile($chat_id, $username, $text);

    // Ambil daftar domain yang memiliki shortlink milik user
    $stmt = $pdo->prepare("
        SELECT DISTINCT d.domain 
        FROM links l 
        JOIN domains d ON l.domain_id = d.id 
        WHERE l.user_id = (SELECT id FROM users WHERE telegram_id = ?)
    ");
    $stmt->execute([$chat_id]);
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$domains) {
        sendMessage($chat_id, "❌ Anda belum memiliki shortlink untuk dihapus.");
        exit();
    }

    // Simpan sesi khusus untuk /delete
    file_put_contents("user_sessions/delete_$chat_id.json", json_encode(["domains" => $domains]));

    // Kirim daftar domain dengan angka
    $message = "Pilih domain shortlink yang ingin Anda hapus dengan mengetik angka:\n\n";
    foreach ($domains as $index => $domain) {
        $message .= ($index + 1) . ". $domain\n";
    }

    sendMessage($chat_id, $message);
    exit();
}

// Menangani pilihan angka domain setelah mengetik /delete
elseif (is_numeric($text) && file_exists("user_sessions/delete_$chat_id.json")) {
    $sessionData = json_decode(file_get_contents("user_sessions/delete_$chat_id.json"), true);

    if (!isset($sessionData['domains'])) {
        sendMessage($chat_id, "⚠️ Gunakan perintah /delete terlebih dahulu untuk memilih domain.");
        exit();
    }

    $domains = $sessionData['domains'];
    $selectedIndex = (int)$text - 1;

    if (!isset($domains[$selectedIndex])) {
        sendMessage($chat_id, "⚠️ Pilihan tidak valid. Silakan ketik angka yang sesuai dengan daftar domain.");
        exit();
    }

    $selectedDomain = $domains[$selectedIndex];

    // Hapus sesi setelah angka diproses
    unlink("user_sessions/delete_$chat_id.json");

    // Ambil daftar shortlink di domain yang dipilih
    $stmt = $pdo->prepare("
        SELECT l.short_code 
        FROM links l 
        JOIN domains d ON l.domain_id = d.id 
        WHERE l.user_id = (SELECT id FROM users WHERE telegram_id = ?) 
        AND d.domain = ?
    ");
    $stmt->execute([$chat_id, $selectedDomain]);
    $shortlinks = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$shortlinks) {
        sendMessage($chat_id, "❌ Tidak ada shortlink di domain <b>$selectedDomain</b>.");
        exit();
    }

    // Simpan sesi baru untuk memilih shortlink yang akan dihapus
    file_put_contents("user_sessions/delete_shortlink_$chat_id.json", json_encode(["domain" => $selectedDomain, "shortlinks" => $shortlinks]));

    $message = "Pilih shortlink yang ingin Anda hapus dengan mengetik angka:\n\n";
    foreach ($shortlinks as $index => $shortCode) {
        $message .= ($index + 1) . ". $shortCode\n";
    }

    sendMessage($chat_id, $message);
    exit();
}

// Menangani pilihan angka shortlink setelah domain dipilih
elseif (is_numeric($text) && file_exists("user_sessions/delete_shortlink_$chat_id.json")) {
    $sessionData = json_decode(file_get_contents("user_sessions/delete_shortlink_$chat_id.json"), true);
    $selectedDomain = $sessionData['domain'];
    $shortlinks = $sessionData['shortlinks'];
    $selectedIndex = (int)$text - 1;

    if (!isset($shortlinks[$selectedIndex])) {
        sendMessage($chat_id, "⚠️ Pilihan tidak valid. Silakan ketik angka yang sesuai dengan daftar shortlink.");
        exit();
    }

    $selectedShortCode = $shortlinks[$selectedIndex];

    // Hapus shortlink dari database
    $stmt = $pdo->prepare("
        DELETE FROM links 
        WHERE short_code = ? 
        AND user_id = (SELECT id FROM users WHERE telegram_id = ?) 
        AND domain_id = (SELECT id FROM domains WHERE domain = ?)
    ");
    $stmt->execute([$selectedShortCode, $chat_id, $selectedDomain]);

    // Hapus sesi setelah selesai
    unlink("user_sessions/delete_shortlink_$chat_id.json");

    sendMessage($chat_id, "✅ Shortlink <b>https://$selectedDomain/$selectedShortCode</b> ❌ berhasil dihapus!");
    exit();
}
// Pastikan folder user_sessions ada
if (!is_dir("user_sessions")) {
    mkdir("user_sessions", 0777, true);
}

// Perintah /list untuk memilih domain terlebih dahulu
if ($text == "/list") {
    clearUserSession($chat_id);
    logToFile($chat_id, $username, $text);
logActivity($pdo, $user['id'], $username, 'Mengakses perintah /list');
    $stmt = $pdo->query("SELECT domain FROM domains");
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$domains) {
        sendMessage($chat_id, "❌ Tidak ada domain yang tersedia saat ini.");
        exit();
    }

    file_put_contents("user_sessions/list_$chat_id.json", json_encode(["domains" => $domains]));

    $message = "📌 Pilih domain dengan mengetik angka:\n";
    foreach ($domains as $index => $domain) {
        $message .= ($index + 1) . ". $domain\n";
    }

    sendMessage($chat_id, $message);
    exit();
}

// Menangani pilihan angka domain dari /list
$listSessionFile = "user_sessions/list_$chat_id.json";
if (is_numeric($text) && file_exists($listSessionFile)) {
    $sessionData = json_decode(file_get_contents($listSessionFile), true);

    if (!isset($sessionData['domains'])) {
        sendMessage($chat_id, "⚠️ Gunakan perintah /list terlebih dahulu.");
        exit();
    }

    $domains = $sessionData['domains'];
    $selectedIndex = (int)$text - 1;

    if (!isset($domains[$selectedIndex])) {
        sendMessage($chat_id, "⚠️ Pilihan tidak valid.");
        exit();
    }

    $domain = $domains[$selectedIndex];
    unlink($listSessionFile);

    $stmt = $pdo->prepare("SELECT id FROM domains WHERE domain = ?");
    $stmt->execute([$domain]);
    $domainData = $stmt->fetch();

    if (!$domainData) {
        sendMessage($chat_id, "❌ Domain tidak ditemukan.");
        exit();
    }

    $domainId = $domainData['id'];

    // Ambil semua link user untuk domain ini
    $stmt = $pdo->prepare("SELECT id, short_code FROM links 
        WHERE user_id = (SELECT id FROM users WHERE telegram_id = ?) 
        AND domain_id = ?
        ORDER BY short_code");
    $stmt->execute([$chat_id, $domainId]);
    $links = $stmt->fetchAll();

    if (!$links) {
        sendMessage($chat_id, "❌ Anda belum memiliki shortlink di domain <b>$domain</b>.");
        exit();
    }

    // Ambil semua redirect_urls
    $stmt = $pdo->prepare("SELECT link_id, url FROM redirect_urls WHERE link_id IN (" . implode(',', array_column($links, 'id')) . ")");
    $stmt->execute();
    $redirects = $stmt->fetchAll();

    // Ambil semua fallback_urls
    $stmt = $pdo->prepare("SELECT link_id, url FROM fallback_urls WHERE link_id IN (" . implode(',', array_column($links, 'id')) . ")");
    $stmt->execute();
    $fallbacks = $stmt->fetchAll();

    // Index by link_id
    $redirectMap = [];
    foreach ($redirects as $r) {
        $redirectMap[$r['link_id']][] = $r['url'];
    }

    $fallbackMap = [];
    foreach ($fallbacks as $f) {
        $fallbackMap[$f['link_id']][] = $f['url'];
    }

    // Susun pesan
    $message = "📋 Daftar Shortlink Anda di <b>$domain</b>:\n";
    foreach ($links as $link) {
        $message .= "\n🔗 <b>https://$domain/{$link['short_code']}</b>\n";

        if (!empty($redirectMap[$link['id']])) {
            foreach ($redirectMap[$link['id']] as $url) {
                $message .= " ➜ $url\n";
            }
        }

        if (!empty($fallbackMap[$link['id']])) {
            foreach ($fallbackMap[$link['id']] as $fb) {
                $message .= " 🆘 <i>Fallback:</i> $fb \n";
            }
        }
    }

    sendMessage($chat_id, $message);
    exit();
}
// Perintah /clear untuk menghapus pesan-pesan terakhir
elseif ($text == "/clear") {
    // Kirim pesan notifikasi sedang memproses
    $alert = json_decode(file_get_contents($apiUrl . "sendMessage?chat_id=$chat_id&text=" . urlencode("⏳ Sedang proses membersihkan pesan...")), true);

    // Ambil message_id dari pesan alert
    $alertMsgId = $alert['result']['message_id'] ?? null;

    // Hapus 10 pesan terakhir
    for ($i = 0; $i < 10; $i++) {
        $msgId = $update['message']['message_id'] - $i;
        deleteMessage($chat_id, $msgId);
    }

    // Hapus pesan alert jika berhasil dikirim
    if ($alertMsgId) {
        deleteMessage($chat_id, $alertMsgId);
    }

    // Kirim notifikasi selesai
    sendMessage($chat_id, "✅ Pesan berhasil dibersihkan.");
    exit();
}
elseif ($text == "/edit") {
    clearUserSession($chat_id);
    logToFile($chat_id, $username, $text);

    $stmt = $pdo->prepare("
        SELECT l.id, l.short_code, d.domain
        FROM links l
        JOIN domains d ON l.domain_id = d.id
        WHERE l.user_id = (SELECT id FROM users WHERE telegram_id = ?)
        ORDER BY d.domain, l.short_code
    ");
    $stmt->execute([$chat_id]);
    $shortlinks = $stmt->fetchAll();

    if (!$shortlinks) {
        sendMessage($chat_id, "❌ Anda belum memiliki shortlink untuk diedit.");
        exit();
    }

    file_put_contents("user_sessions/edit_choose_$chat_id.json", json_encode(["links" => $shortlinks]));

    $msg = "Pilih shortlink yang ingin diedit dengan mengetik angka:\n\n";
    foreach ($shortlinks as $i => $row) {
        $msg .= ($i + 1) . ". <b>{$row['domain']}/{$row['short_code']}</b>\n";
    }
    sendMessage($chat_id, $msg);
    exit();
}

// Tangani angka pilihan shortlink
elseif (is_numeric($text) && file_exists("user_sessions/edit_choose_$chat_id.json")) {
    $sessionData = json_decode(file_get_contents("user_sessions/edit_choose_$chat_id.json"), true);
    $index = (int)$text - 1;
    if (!isset($sessionData['links'][$index])) {
        sendMessage($chat_id, "⚠️ Pilihan tidak valid.");
        exit();
    }

    $selected = $sessionData['links'][$index];
    unlink("user_sessions/edit_choose_$chat_id.json");

    file_put_contents("user_sessions/edit_urls_$chat_id.json", json_encode([
        "link_id" => $selected['id'],
        "domain" => $selected['domain'],
        "short_code" => $selected['short_code'],
        "urls" => []
    ]));

    sendMessage($chat_id, "🔗 Masukkan URL tujuan baru (satu per baris), atau ketik '<b>selesai</b>' jika sudah:");
    exit();
}

// Tambah URL tujuan baru (step edit)
elseif (file_exists("user_sessions/edit_urls_$chat_id.json")) {
    $sessionData = json_decode(file_get_contents("user_sessions/edit_urls_$chat_id.json"), true);
    $linkId = $sessionData['link_id'];
    $urls = explode("\n", trim($text));
    $added = [];

    foreach ($urls as $url) {
        $url = trim($url);
        if (strtolower($url) === "selesai") {
            if (empty($sessionData['urls'])) {
                sendMessage($chat_id, "⚠️ Masukkan minimal 1 URL tujuan.");
                exit();
            }

            file_put_contents("user_sessions/edit_fallbacks_$chat_id.json", json_encode([
                "link_id" => $linkId,
                "domain" => $sessionData['domain'],
                "short_code" => $sessionData['short_code'],
                "redirect_urls" => $sessionData['urls'],
                "fallback_urls" => []
            ]));
            unlink("user_sessions/edit_urls_$chat_id.json");

            sendMessage($chat_id, "🆘 Masukkan fallback URL (opsional) jika semua link diblokir, atau \nketik 'skip' jika tidak ingin mengatur cadangan:");
            exit();
        }

        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";
        if (!isValidUrl($url)) {
            sendMessage($chat_id, "❌ URL tidak valid: $url");
            continue;
        }

        $sessionData['urls'][] = $url;
        $added[] = $url;
    }

    file_put_contents("user_sessions/edit_urls_$chat_id.json", json_encode($sessionData));
    sendMessage($chat_id, "Link tujuan berhasil ditambahkan:\n" . implode("\n", array_map(fn($u) => "🔗 $u", $added)) . "\n\nMasukkan URL berikutnya atau ketik 'selesai' jika sudah:");
    exit();
}

// Input fallback URLs (edit)
elseif (file_exists("user_sessions/edit_fallbacks_$chat_id.json")) {
    $sessionData = json_decode(file_get_contents("user_sessions/edit_fallbacks_$chat_id.json"), true);
    $linkId = $sessionData['link_id'];
    $fallbacks = explode("\n", trim($text));
    $added = [];

    foreach ($fallbacks as $fb) {
        $fb = trim($fb);
        if (strtolower($fb) === "selesai" || strtolower($fb) === "skip") {
            // Simpan perubahan ke DB
            $pdo->prepare("DELETE FROM redirect_urls WHERE link_id = ?")->execute([$linkId]);
            foreach ($sessionData['redirect_urls'] as $r) {
                $pdo->prepare("INSERT INTO redirect_urls (link_id, url) VALUES (?, ?)")->execute([$linkId, $r]);
            }

            $pdo->prepare("DELETE FROM fallback_urls WHERE link_id = ?")->execute([$linkId]);
            foreach ($sessionData['fallback_urls'] as $f) {
                $pdo->prepare("INSERT INTO fallback_urls (link_id, url) VALUES (?, ?)")->execute([$linkId, $f]);
            }

            unlink("user_sessions/edit_fallbacks_$chat_id.json");
            sendMessage($chat_id, "✅ Shortlink <b>https://{$sessionData['domain']}/{$sessionData['short_code']}</b> berhasil diperbarui!");
            exit();
        }

        if (!preg_match("~^(?:f|ht)tps?://~i", $fb)) $fb = "http://$fb";
        if (!isValidUrl($fb)) {
            sendMessage($chat_id, "❌ Fallback URL tidak valid: $fb");
            continue;
        }

        $sessionData['fallback_urls'][] = $fb;
        $added[] = $fb;
    }

    file_put_contents("user_sessions/edit_fallbacks_$chat_id.json", json_encode($sessionData));
    sendMessage($chat_id, "🆘 Fallback ditambahkan:\n" . implode("\n", array_map(fn($u) => "📉 $u", $added)) . "\n\nKetik link fallback lain, atau ketik 'selesai' jika sudah, atau 'skip' jika ingin mengosongkan.");
    exit();
}


// Perintah /domains untuk menampilkan daftar domain yang tersedia
elseif ($text == "/domain") {
    $stmt = $pdo->query("SELECT domain FROM domains");
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if ($domains) {
        $message = "📌 Daftar Domain yang Tersedia:\n" . implode("\n", array_map(fn($d) => "➜ $d", $domains));
    } else {
        $message = "❌ Belum ada domain yang tersedia.";
    }
    sendMessage($chat_id, $message);
}


// Perintah /analytic untuk menampilkan statistik shortlink pengguna
elseif ($text == "/analytic") {
    clearUserSession($chat_id); // Hapus sesi lama sebelum memulai sesi baru
    logToFile($chat_id, $username, $text);

    // Ambil daftar domain yang memiliki shortlink milik user
    $stmt = $pdo->prepare("
        SELECT DISTINCT d.domain 
        FROM links l 
        JOIN domains d ON l.domain_id = d.id 
        WHERE l.user_id = (SELECT id FROM users WHERE telegram_id = ?)
    ");
    $stmt->execute([$chat_id]);
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$domains) {
        sendMessage($chat_id, "❌ Anda belum memiliki shortlink.");
        exit();
    }

    // Simpan sesi dengan label khusus untuk analytic
    file_put_contents("user_sessions/analytic_$chat_id.json", json_encode(["domains" => $domains]));

    // Kirim daftar domain dengan nomor pilihan
    $message = "📊 Pilih domain untuk melihat statistik:\n";
    foreach ($domains as $index => $domain) {
        $message .= ($index + 1) . ". $domain\n";
    }

    sendMessage($chat_id, $message);
    exit();
}

// Menangani pilihan angka setelah /analytic
elseif (is_numeric($text) && file_exists("user_sessions/analytic_$chat_id.json")) {
    $sessionData = json_decode(file_get_contents("user_sessions/analytic_$chat_id.json"), true);
    
    if (!isset($sessionData['domains'])) {
        sendMessage($chat_id, "⚠️ Gunakan perintah /analytic terlebih dahulu.");
        exit();
    }

    $domains = $sessionData['domains'];
    $selectedIndex = (int)$text - 1;

    if (!isset($domains[$selectedIndex])) {
        sendMessage($chat_id, "⚠️ Pilihan tidak valid. Silakan ketik angka yang sesuai dengan daftar.");
        exit();
    }

    $selectedDomain = $domains[$selectedIndex];
    unlink("user_sessions/analytic_$chat_id.json");

    // Ambil ID domain
    $stmt = $pdo->prepare("SELECT id FROM domains WHERE domain = ?");
    $stmt->execute([$selectedDomain]);
    $domainData = $stmt->fetch();

    if (!$domainData) {
        sendMessage($chat_id, "❌ Domain tidak ditemukan.");
        exit();
    }

    $domainId = $domainData['id'];

    // Ambil statistik detail
    $stmt = $pdo->prepare("
        SELECT l.id, l.short_code, 
               COUNT(a.id) AS total_clicks,
               (SELECT COUNT(*) FROM redirect_urls WHERE link_id = l.id) AS total_destinations
        FROM links l
        LEFT JOIN analytics a ON l.id = a.link_id
        WHERE l.user_id = (SELECT id FROM users WHERE telegram_id = ?)
        AND l.domain_id = ?
        GROUP BY l.id
    ");
    $stmt->execute([$chat_id, $domainId]);
    $links = $stmt->fetchAll();

    if (!$links) {
        sendMessage($chat_id, "❌ Tidak ada statistik untuk domain <b>$selectedDomain</b>.");
        exit();
    }

    // Statistik total
    $stmtTotal = $pdo->prepare("
        SELECT COUNT(DISTINCT l.id) AS total_shortlinks, COUNT(a.id) AS total_clicks 
        FROM links l 
        LEFT JOIN analytics a ON l.id = a.link_id 
        WHERE l.user_id = (SELECT id FROM users WHERE telegram_id = ?) 
        AND l.domain_id = ?
    ");
    $stmtTotal->execute([$chat_id, $domainId]);
    $totalStats = $stmtTotal->fetch();

    $message = "📊 Statistik Shortlink di <b>$selectedDomain</b>:\n";
    $message .= "🔢 Total Shortlink: <b>{$totalStats['total_shortlinks']}</b>\n";
    $message .= "👁️ Total Klik: <b>{$totalStats['total_clicks']}</b>\n\n";
    $message .= "────────────────────\n";

    foreach ($links as $link) {
        $short = $link['short_code'];
        $linkId = $link['id'];

        $message .= "🔗 <b>https://$selectedDomain/$short</b>\n";
        $message .= "👁️ Klik: <b>{$link['total_clicks']}</b>\n";
        $message .= "🎯 Tujuan: <b>{$link['total_destinations']}</b>\n";

        // Statistik harian
        $stmt2 = $pdo->prepare("
            SELECT DATE(created_at) as tanggal, COUNT(*) as jumlah 
            FROM analytics 
            WHERE link_id = ? 
            GROUP BY tanggal 
            ORDER BY tanggal DESC 
            LIMIT 7
        ");
        $stmt2->execute([$linkId]);
        $dailyStats = $stmt2->fetchAll();

        if ($dailyStats) {
            $message .= "\n📅 Klik per Hari:\n";
            foreach ($dailyStats as $day) {
                $message .= "• {$day['tanggal']} → {$day['jumlah']} klik\n";
            }
        }

        // Statistik perangkat
        $stmt3 = $pdo->prepare("
            SELECT device, COUNT(*) as jumlah 
            FROM analytics 
            WHERE link_id = ? 
            GROUP BY device 
            ORDER BY jumlah DESC
        ");
        $stmt3->execute([$linkId]);
        $deviceStats = $stmt3->fetchAll();

        if ($deviceStats) {
            $message .= "\n📱 Perangkat:\n";
            foreach ($deviceStats as $dev) {
                $devName = $dev['device'] ?: 'Tidak diketahui';
                $message .= "• $devName → {$dev['jumlah']} klik\n";
            }
        }

        $message .= "\n────────────────────\n";
    }

    sendMessage($chat_id, $message);
    exit();
}


// Perintah /check untuk mengecek status domain
elseif (strpos($text, "/check") === 0) {
    clearUserSession($chat_id); // Hapus sesi lama sebelum memulai sesi baru
    logToFile($chat_id, $username, $text);

    // Ambil tipe user
    $stmt = $pdo->prepare("SELECT type FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    $userType = $stmt->fetchColumn() ?? 'trial';

    $params = explode("\n", $text);
    array_shift($params); // Menghapus "/check" dari array
    $domains = array_map('trim', $params);
    $domains = array_filter($domains); // Hapus yang kosong

    // Batas untuk trial: hanya 1 domain
    if ($userType === 'trial' && count($domains) > 1) {
        sendMessage($chat_id, "⚠️ Kamu dalam masa TRIAL. Kamu hanya bisa mengecek 1 domain saja.\n\nGunakan:\n/check\ndomainkamu.com");
        exit();
    }

    if (empty($domains)) {
        sendMessage($chat_id, "⚠️ Format salah! Gunakan:\n/check\nexample.com\nexample2.com");
        exit();
    }

    $message = "🔎 <b>Status Domain dari TrustPositif:</b>\n\n";
    
    foreach ($domains as $domain) {
        $htmlContent = getHtmlContent($domain);
        if ($htmlContent) {
            $tableData = extractTableData($htmlContent);
            foreach ($tableData as $row) {
                if (isset($row[1])) {
                    $row[1] = filterStatus($row[1]);
                }
                $message .= "🌐 <b>{$row[0]}</b> → {$row[1]}\n";
            }
        } else {
            $message .= "❌ Tidak dapat mengambil data untuk <b>$domain</b>\n";
        }
    }

    sendMessage($chat_id, $message);
}
// Perintah /start
elseif ($text == "/start") {
    clearUserSession($chat_id); // Hapus sesi lama sebelum memulai sesi baru
     logToFile($chat_id, $username, $text);
     logActivity($pdo, $user['id'], $username, 'Mengakses perintah /start');
    sendMessage($chat_id, "<b>SFLINK.ID</b>\nList Perintah Bot Disimak Dengan Seksama:\n\n/domain - melihat list domain shortlink\n====================\n/add - Tambah shortlink\nContoh: Menambahkan Shortlink\n/add\nnama shortlink\nlinkdomain1\nlinkdomain2\n====================\n/adddomain - Menambahkan domain untuk di check nawala \n /listdomain - untuk melihat list domain check \n /deldomain - untuk mendelete list domain check \n====================\n/edit - Edit shortlink\nContoh Edit Shortlink\n/edit\nnama shortlink\nlinkdomainbaru1\nlinkdomainbaru2\n====================\n/delete - Hapus shortlink\nContoh: Delete Shortlink\n/delete\nnama shortlink\n====================\n/list - Daftar shortlink\nContoh: Melihat List Shortlink\n/list\n====================\n/analytic - Statistik shortlink\nContoh: Melihat Stat Click\n/analytic\n====================\n<b>New Fitur</b>: Check Domain Nawala\nContoh:/check\nnamadomain1.com\nnamadomain2.com\netc\n====================\n/check2 - Check Nawala Server Lain\nContoh:/check2\nnamadomain1.com\nnamadomain2.com\netc");
}
// Jika tidak ada perintah dikenali
else {
    sendMessage($chat_id, "Gunakan /start untuk melihat daftar perintah.");
}
?>
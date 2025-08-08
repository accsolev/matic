<?php
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
} catch (\PDOException $e) {
    die("Database error: " . $e->getMessage());
}

function isUrlBlocked($url) {
    // Ambil hanya domain tanpa protokol/path
    $domain = parse_url($url, PHP_URL_HOST) ?: $url;
    $domain = strtolower(preg_replace('/^www\./', '', $domain));
    if (!$domain || !preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $domain)) {
        return false;
    }
    $checkUrl = "https://trustpositif.komdigi.go.id/?domains=" . urlencode($domain);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $checkUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) return false;

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if ($dom->loadHTML($html)) {
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//table//tr') as $row) {
            $cols = $xpath->query('td', $row);
            if ($cols->length >= 2) {
                $st = trim($cols->item(1)->nodeValue);
                return $st === 'Ada'; // TRUE = diblokir, FALSE = aman
            }
        }
    }
    libxml_clear_errors();
    return false; // default: anggap aman kalau tidak ketemu
}

// Kirim ke personal & grup sekaligus
function sendMessage($telegram_id, $telegram_group_id, $to_personal, $to_group, $message) {
    $botToken = '7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw';
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    if ($to_personal && $telegram_id) {
        $data = [
            'chat_id' => $telegram_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_exec($ch); curl_close($ch);
    }
    if ($to_group && $telegram_group_id) {
        $data = [
            'chat_id' => $telegram_group_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_exec($ch); curl_close($ch);
    }
}

function checkAndNotifyBlockedDomains() {
    global $pdo;

    // --- 1. Redirect URLs ---
    $stmt = $pdo->query("
        SELECT r.id AS redirect_id, r.url, l.short_code,
               u.id AS uid, u.telegram_id, u.telegram_group_id,
               u.notif_to_personal, u.notif_to_group
          FROM links l
          JOIN users u ON l.user_id = u.id
          JOIN redirect_urls r ON l.id = r.link_id
          WHERE (u.telegram_id IS NOT NULL OR u.telegram_group_id IS NOT NULL)
    ");
    foreach ($stmt as $row) {
        if (isUrlBlocked($row['url'])) {
            $msg = "🚨 <b>Domain Redirect Diblokir</b>\n❌ <code>{$row['url']}</code>\npada shortlink <b>{$row['short_code']}</b> telah <b><u>DIBLOKIR</u></b>\n\nSudah dihapus dari daftar destination.";
            sendMessage($row['telegram_id'], $row['telegram_group_id'], $row['notif_to_personal'], $row['notif_to_group'], $msg);
            $pdo->prepare("DELETE FROM redirect_urls WHERE id=?")->execute([$row['redirect_id']]);
        }
    }

    // --- 2. Fallback URLs ---
    $stmt = $pdo->query("
        SELECT f.id AS fallback_id, f.url, l.short_code,
               u.id AS uid, u.telegram_id, u.telegram_group_id,
               u.notif_to_personal, u.notif_to_group
          FROM links l
          JOIN users u ON l.user_id = u.id
          JOIN fallback_urls f ON l.id = f.link_id
          WHERE (u.telegram_id IS NOT NULL OR u.telegram_group_id IS NOT NULL)
    ");
    foreach ($stmt as $row) {
        if (isUrlBlocked($row['url'])) {
            $msg = "🚨 <b>Fallback URL Diblokir</b>\n❌ <code>{$row['url']}</code>\npada shortlink <b>{$row['short_code']}</b> telah <b><u>DIBLOKIR</u></b>\n\n Sudah dihapus dari fallback.";
            sendMessage($row['telegram_id'], $row['telegram_group_id'], $row['notif_to_personal'], $row['notif_to_group'], $msg);
            $pdo->prepare("DELETE FROM fallback_urls WHERE id=?")->execute([$row['fallback_id']]);
        }
    }

    // --- 3. White Page URLs (hanya notif, tidak hapus) ---
    $stmt = $pdo->query("
        SELECT l.id, l.short_code, l.white_page_url, u.telegram_id, u.telegram_group_id, u.notif_to_personal, u.notif_to_group
        FROM links l
        JOIN users u ON l.user_id = u.id
        WHERE l.white_page_url IS NOT NULL AND l.white_page_url != ''
          AND (u.telegram_id IS NOT NULL OR u.telegram_group_id IS NOT NULL)
    ");
    foreach ($stmt as $row) {
        if (isUrlBlocked($row['white_page_url'])) {
            $msg = "🚨 <b>WhitePage Diblokir</b>\n<code>{$row['white_page_url']}</code>\npada shortlink <b>{$row['short_code']}</b> dideteksi <u>DIBLOKIR</u>!";
            sendMessage($row['telegram_id'], $row['telegram_group_id'], $row['notif_to_personal'], $row['notif_to_group'], $msg);
        }
    }

    // --- 4. Main Domains (hanya notif, tidak hapus) ---
    $stmt = $pdo->query("SELECT id, domain FROM main_domains");
    foreach ($stmt as $row) {
        if (isUrlBlocked('https://' . $row['domain'])) {
            // Cari semua user yang pakai domain ini
            $getLinks = $pdo->prepare("SELECT l.short_code, u.telegram_id, u.telegram_group_id, u.notif_to_personal, u.notif_to_group
                                       FROM links l
                                       JOIN users u ON l.user_id = u.id
                                       WHERE l.main_domain_id = ?");
            $getLinks->execute([$row['id']]);
            foreach ($getLinks as $linkRow) {
                $msg = "🚨 <b>Main Domain Diblokir</b>\n<code>{$row['domain']}</code>\ndipakai di shortlink <b>{$linkRow['short_code']}</b>, terdeteksi <u>DIBLOKIR</u>!";
                sendMessage($linkRow['telegram_id'], $linkRow['telegram_group_id'], $linkRow['notif_to_personal'], $linkRow['notif_to_group'], $msg);
            }
        }
    }
// --- 5. TABLE list_domains (cek domain, notif ke user_id-nya, tidak perlu cek links, langsung domain di table) ---
    $stmt = $pdo->query("SELECT id, user_id, domain FROM list_domains");
    foreach ($stmt as $row) {
        if (isUrlBlocked('https://' . $row['domain'])) {
            // Cek data user (cari telegram_id, telegram_group_id)
            $user = $pdo->prepare("SELECT telegram_id, telegram_group_id, notif_to_personal, notif_to_group FROM users WHERE id = ?");
            $user->execute([$row['user_id']]);
            $u = $user->fetch();
            if ($u) {
                $msg = "🚨 <b>List Domain Diblokir</b>\n<code>{$row['domain']}</code>\nDomain milik Anda telah <b><u>DIBLOKIR</u>!</b>\n\nHapus domain yang di blokir dari list agar bot berhenti mengirim pesan ini!";
                sendMessage($u['telegram_id'], $u['telegram_group_id'], $u['notif_to_personal'], $u['notif_to_group'], $msg);
            }
        }
    }
}    
checkAndNotifyBlockedDomains();

echo json_encode(["status" => "success", "message" => "Pengecekan selesai"]);
?>

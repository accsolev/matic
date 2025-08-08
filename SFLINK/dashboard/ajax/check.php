<?php
// Prevent overlapping runs
$fp = fopen(__FILE__, 'r');
if (!flock($fp, LOCK_EX | LOCK_NB)) exit;

date_default_timezone_set('Asia/Jakarta');
$telegramToken = "7860868779:AAFCPPwIAIUmUdYUVxA-1oRxvekGtIka9qw";
$host = "127.0.0.1"; $dbname = "sflink";
$user = "sflink";     $pass   = "Memek123";
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) die("Koneksi gagal: ".$conn->connect_error);

/**
 * Cek satu domain
 */
function getStatus(string $domain): array {
    $url = "https://trustpositif.komdigi.go.id/?domains=".urlencode($domain);
    $ch  = curl_init($url);
    curl_setopt_array($ch,[
      CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_FOLLOWLOCATION=>true,
      CURLOPT_TIMEOUT=>15,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return ['⚠️','Gagal ambil data'];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//table//tr') as $row) {
        $cols = $xpath->query('td',$row);
        if ($cols->length>=2) {
            $st = trim($cols->item(1)->nodeValue);
            return $st==='Ada'
                ? ['❌','Diblokir']
                : ['✅','Aman'];
        }
    }
    return ['⚠️','Tidak ditemukan'];
}

function sendMessageToTelegram($token, $chat1, $chat2, $msg, $p, $g) {
    $u = "https://api.telegram.org/bot{$token}/sendMessage?parse_mode=HTML&text=".urlencode($msg);
    if ($p && $chat1) file_get_contents("{$u}&chat_id={$chat1}");
    if ($g && $chat2) file_get_contents("{$u}&chat_id={$chat2}");
}

// Ambil tiap user + MAX(last_checked)
$sql = "
  SELECT u.id AS uid, u.telegram_id, u.telegram_group_id,
       u.notif_to_personal, u.notif_to_group,
       MAX(d.last_checked) AS lastc, MIN(d.interval_minute) AS intervalm
FROM list_domains d
JOIN users u ON d.user_id=u.id
WHERE d.status=1 AND (u.telegram_id IS NOT NULL OR u.telegram_group_id IS NOT NULL)
GROUP BY u.id
";
$res = $conn->query($sql);
$now = time();

while ($r = $res->fetch_assoc()) {
    $uid    = (int)$r['uid'];
    $tg1    = $r['telegram_id'];
    $tg2    = $r['telegram_group_id'];
    $sendP  = (bool)$r['notif_to_personal'];
    $sendG  = (bool)$r['notif_to_group'];
    $lastc  = strtotime($r['lastc']??'2000-01-01');
    $intv   = (int)$r['intervalm'];

    if ($now - $lastc < $intv*60) continue;

    // ambil domain user
    $domains = [];
    $st = $conn->prepare("SELECT domain FROM list_domains WHERE user_id=? AND status=1 ORDER BY sort_order ASC, id ASC");
    $st->bind_param("i",$uid);
    $st->execute();
    $gr = $st->get_result();
    while ($d = $gr->fetch_assoc()) $domains[] = $d['domain'];
    $st->close();
    if (!$domains) continue;

    // build message
    $msg = "<b>Status Domain Anda:</b>\n";
    foreach ($domains as $dom) {
        list($em,$desc) = getStatus($dom);
        $msg .= sprintf("%s <b>%s</b>: %s\n", $em, $dom, $desc);
        // update per-domain
        $nowWIB = date("Y-m-d H:i:s");
        $u2 = $conn->prepare("UPDATE list_domains SET last_checked=? WHERE user_id=? AND domain=?");
        $u2->bind_param("sis",$nowWIB,$uid,$dom);
        $u2->execute(); $u2->close();
    }
    $msg .= "\n‣ Last checked: <b>".date("Y-m-d - H:i:s")." WIB</b>";

    // kirim sekali
    sendMessageToTelegram($telegramToken, $tg1, $tg2, $msg, $sendP, $sendG);
}

$conn->close();
flock($fp, LOCK_UN);
fclose($fp);

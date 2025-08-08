<?php
function formatTanggalIndonesia($datetime) {
    $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $timestamp = strtotime($datetime);
    $tgl = date('d', $timestamp); $bln = $bulan[(int)date('m', $timestamp)]; $thn = date('Y', $timestamp); $jam = date('H:i', $timestamp);
    return "$tgl $bln $thn $jam";
}
function logActivity($pdo, $userId, $username, $action) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $username, $action, $now]);
}

function containsMaliciousPayload($text) {
    $patterns = [
        '/<\?(php)?/i', '/eval\s*\(/i', '/base64_decode\s*\(/i', '/file_get_contents\s*\(/i', '/urldecode\s*\(/i',
        '/document\.write\s*\(/i', '/window\.location/i', '/onerror\s*=/i', '/<script/i', '/&lt;script/i',
        '/data:text\/html/i', '/javascript:/i'
    ];
    foreach ($patterns as $pattern) if (preg_match($pattern, $text)) return true;
    return false;
}
function isValidAlias($alias) {
    return preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $alias);
}
function isValidUrl($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $url = urldecode(strtolower($url));
    $forbidden = [
        'eval(', 'base64_', 'base64,', 'data:text', 'data:application', '<?php', '</script>', '<script', '<iframe',
        '<img', '<svg', '<body', 'onerror=', 'onload=', 'document.', 'window.', 'file_get_contents',
        'curl_exec', 'exec(', 'passthru(', 'shell_exec', 'system('
    ];
    foreach ($forbidden as $bad) if (strpos($url, $bad) !== false) return false;
    return true;
}
function isValidDomainFormat($url) {
    $host = parse_url($url, PHP_URL_HOST);
    return (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host);
}


?>
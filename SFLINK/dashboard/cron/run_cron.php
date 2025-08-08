<?php
require '../../includes/db.php';
date_default_timezone_set('Asia/Jakarta');

// Ambil semua user yang aktif cron-nya dan waktunya jalan
$stmt = $pdo->query("SELECT * FROM user_cronjobs WHERE is_active = 1");
while ($cron = $stmt->fetch()) {
    $nextRun = strtotime($cron['last_run'] ?? '2000-01-01') + ($cron['interval_minutes'] * 60);
    if (time() >= $nextRun) {
        $userId = $cron['user_id'];

        // Ambil semua domain user
        $domains = $pdo->prepare("SELECT DISTINCT d.domain
                                  FROM links l
                                  JOIN domains d ON l.domain_id = d.id
                                  WHERE l.user_id = ?");
        $domains->execute([$userId]);
        $domainList = $domains->fetchAll(PDO::FETCH_COLUMN);

        $blocked = [];
        foreach ($domainList as $domain) {
            $html = file_get_contents("https://trustpositif.komdigi.go.id/?domains=" . urlencode($domain));
            if (strpos($html, 'Ada') !== false) {
                $blocked[] = $domain;
            }
        }

        // Kirim notifikasi ke user via Telegram
        if (!empty($blocked)) {
            $stmtUser = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $tgid = $stmtUser->fetchColumn();

            if ($tgid) {
                $msg = "🚨 Cron Job Deteksi Blokir:\nDomain kamu yang terdeteksi DIBLOKIR:\n\n";
                $msg .= implode("\n", array_map(fn($d) => "❌ $d", $blocked));
                file_get_contents("https://api.telegram.org/bot<YOUR_BOT_TOKEN>/sendMessage?chat_id=$tgid&text=" . urlencode($msg));
            }
        }

        // Update last_run
        $pdo->prepare("UPDATE user_cronjobs SET last_run = NOW() WHERE user_id = ?")->execute([$userId]);
    }
}
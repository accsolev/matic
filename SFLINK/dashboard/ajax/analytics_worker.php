<?php
require_once '../../includes/db.php'; // Pastikan path ini benar

$queueFile = __DIR__ . '/analytics_queue.txt';

try {
    $pdo->query("SELECT 1");
    echo "DB CONNECTED\n";
} catch (Exception $e) {
    exit("DB ERROR: " . $e->getMessage() . "\n");
}

if (!file_exists($queueFile)) exit("No log to process\n");

$fp = fopen($queueFile, "c+");
if (flock($fp, LOCK_EX)) {
    $lines = [];
    while (($line = fgets($fp)) !== false) {
        $lines[] = trim($line);
    }
    ftruncate($fp, 0); // kosongkan file setelah dibaca
    rewind($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

foreach ($lines as $line) {
    if (!$line) continue;
    $data = json_decode($line, true);
    if (!$data) {
        echo "JSON ERROR: $line\n";
        continue;
    }
    try {
        $stmt = $pdo->prepare("
          INSERT INTO analytics (link_id, ip_address, country, city, device, browser, referrer, created_at)
          VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['link_id'], $data['ip'], $data['country'], $data['city'],
            $data['device'], $data['browser'], $data['referrer']
        ]);
        echo "OK: link_id {$data['link_id']}\n";
    } catch (Exception $e) {
        echo "DB INSERT ERROR: " . $e->getMessage() . "\n";
        // Atau log ke file error
    }
}
} else {
    exit("Failed to lock file!\n");
}
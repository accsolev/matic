<?php
ini_set('memory_limit', '2048M');
set_time_limit(0); // Unlimited time, cuma bisa di CLI
require 'db.php';

$exportFile = "../data-analytics/analytics-all.txt";

// Step 1: Streaming select + write per-baris
$stmt = $pdo->query("SELECT * FROM analytics");

$fp = fopen($exportFile, 'w');
$headerPrinted = false;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!$headerPrinted) {
        fputcsv($fp, array_keys($row), "\t"); // Header sekali di awal
        $headerPrinted = true;
    }
    fputcsv($fp, $row, "\t");
}
fclose($fp);

// Step 2: Hapus semua data per-batch
$batch = 50000; // ganti sesuai kemampuan server
do {
    $delStmt = $pdo->prepare("DELETE FROM analytics LIMIT $batch");
    $delStmt->execute();
    $deleted = $delStmt->rowCount();
} while ($deleted == $batch);

echo "Export & delete selesai.";

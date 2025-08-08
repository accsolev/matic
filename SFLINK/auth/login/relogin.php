<?php
// Jalankan script ini sebagai admin/server, jangan diakses publik user biasa!
$sessionPath = ini_get('session.save_path');
if (!$sessionPath) $sessionPath = sys_get_temp_dir(); // fallback default

$deleted = 0;
foreach (glob("$sessionPath/sess_*") as $sessFile) {
    if (is_file($sessFile) && @unlink($sessFile)) $deleted++;
}

echo "Selesai! Semua user dipaksa logout ($deleted session dihapus).";
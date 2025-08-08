<?php
session_start();
require '../../includes/db.php'; // koneksi PDO dengan variable $pdo
date_default_timezone_set('Asia/Jakarta');

// Ambil data session & GET
if (!isset($_SESSION['username'])) {
    header('Location: /login.php');
    exit;
}
$username = htmlspecialchars($_SESSION['username'], ENT_QUOTES);
$ref = $_GET['ref'] ?? '';

// Query status pembayaran berdasarkan reference
$status = 'UNPAID';
if ($ref) {
    $stmt = $pdo->prepare("SELECT status FROM payments WHERE reference = ?");
    $stmt->execute([$ref]);
    $status = strtoupper($stmt->fetchColumn() ?: 'UNPAID');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SFLINK.ID | Status Pembayaran</title>
    <link rel="shortcut icon" href="https://sflink.id/favicon.png" />
    <link href="./vendor/bootstrap-select/dist/css/bootstrap-select.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="./css/style.css" rel="stylesheet">
</head>
<body>
<div id="main-wrapper">
    <?php include '../partials/nav-header.php'; ?>
    <?php include '../partials/header.php'; ?>
    <?php include '../partials/sidebar.php'; ?>

    <div class="content-body">
        <div class="container-fluid">
            <div class="row justify-content-center mt-5">
                <div class="col-lg-6">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <?php if ($status === 'PAID'): ?>
                                <i class="fa-solid fa-circle-check fa-4x text-success mb-3"></i>
                                <h3 class="card-title">Pembayaran Berhasil!</h3>
                                <p class="card-text">Terima kasih, <strong><?php echo $username; ?></strong>.<br>
                                Pembayaran Anda telah dikonfirmasi.</p>
                            <?php else: ?>
                                <i class="fa-solid fa-hourglass-half fa-4x text-warning mb-3"></i>
                                <h3 class="card-title">Menunggu Konfirmasi</h3>
                                <p class="card-text">Hai, <strong><?php echo $username; ?></strong>.<br>
                                Pembayaran Anda sedang diproses. Status: <span class="fw-bold"><?php echo $status; ?></span>.</p>
                            <?php endif; ?>

                            <?php if ($ref): ?>
                                <p class="text-muted">Kode Referensi:<br><strong><?php echo htmlspecialchars($ref, ENT_QUOTES); ?></strong></p>
                            <?php endif; ?>

                            <a href="/dashboard" class="btn btn-primary mt-3">Kembali ke Dashboard</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../partials/footer.php'; ?>
</div>

<script src="./vendor/global/global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="./js/dashboard/dashboard-1.js"></script>
</body>
</html>

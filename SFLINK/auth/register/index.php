<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/ajax/ddos_protection.php';
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once '../../includes/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: /dashboard");
    exit;
}

function logActivity($pdo, $userId, $username, $action) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId ?: null, $username, $action, $now]);
}

$error   = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username        = trim($_POST['username'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $agree           = isset($_POST['terms']) || isset($_POST['agree']); // from Frest/old

    if (!$agree) {
        $error = "Anda harus menyetujui syarat dan ketentuan.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    } elseif (strlen($username) < 6) {
        $error = "Username minimal 6 karakter.";
    } elseif (strlen($password) < 6) {
        $error = "Password minimal 6 karakter.";
    } elseif ($password !== $confirmPassword) {
        $error = "Password dan konfirmasi tidak cocok.";
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn() > 0) {
            $error = "Username atau email sudah digunakan.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username, email, password, type, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([null, $username, $email, $hashedPassword, 'trial']);

            $newUserId = $pdo->lastInsertId();
            logActivity($pdo, $newUserId, $username, 'Registrasi akun baru');

            $success = "Registrasi berhasil! Mengalihkan ke halaman login…";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="light-style layout-wide customizer-hide"
      dir="ltr"
      data-theme="theme-default"
      data-assets-path="../../assets/"
      data-template="vertical-menu-template">
<head>
    <meta charset="utf-8" />
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"/>
    <title>SFLINK.ID | Register Page</title>
    <meta name="description" content="Daftar SFLINK.ID" />

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../../img/favico.png" />

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="../../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../../assets/vendor/fonts/fontawesome.css" />
    <link rel="stylesheet" href="../../assets/vendor/fonts/flag-icons.css" />

    <!-- Core CSS -->
    <link rel="stylesheet" href="../../assets/vendor/css/rtl/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../../assets/vendor/css/rtl/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../../assets/css/demo.css" />

    <!-- Vendors CSS -->
    <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/typeahead-js/typeahead.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/@form-validation/umd/styles/index.min.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/pages/page-auth.css" />
    <style>
        .login-logo {
            display: block;
            margin: 0 auto 20px auto;
            max-width: 140px;
            height: auto;
            object-fit: contain;
        }
    </style>
</head>
<body>
<?php if ($error || $success): ?>
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;">
    <div class="toast align-items-center <?= $error ? 'text-bg-danger' : 'text-bg-success' ?> border-0 show" role="alert">
        <div class="d-flex">
            <div class="toast-body"><?= htmlspecialchars($error ?: $success) ?></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="container-xxl">
    <div class="authentication-wrapper authentication-basic container-p-y">
        <div class="authentication-inner py-4">
            <!-- Register Card -->
            <div class="card">
                <div class="card-body">

                    <!-- Logo -->
                    <div class="app-brand justify-content-center">
                        <img src="https://sflink.id/logo.png" alt="SFLINK.ID" class="login-logo">
                    </div>
                    <!-- /Logo -->

                    <h4 class="mb-2 text-center">SFLINK.ID | Register Page</h4>
                    <p class="mb-4 text-center">Please complete the form provided .</p>

                    <form id="formRegister" class="mb-3" method="POST" autocomplete="off" onsubmit="return validateRegisterForm();">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" placeholder="Masukkan username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="Masukkan email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="mb-3 form-password-toggle">
                            <label class="form-label" for="password">Password</label>
                            <div class="input-group input-group-merge">
                                <input type="password" id="password" class="form-control" name="password" placeholder="Password" required />
                                <span class="input-group-text cursor-pointer"><i class="bx bx-hide"></i></span>
                            </div>
                        </div>
                        <div class="mb-3 form-password-toggle">
                            <label class="form-label" for="confirm_password">Confirm Password</label>
                            <div class="input-group input-group-merge">
                                <input type="password" id="confirm_password" class="form-control" name="confirm_password" placeholder="Ulangi Password" required />
                                <span class="input-group-text cursor-pointer"><i class="bx bx-hide"></i></span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="terms" name="terms" <?= isset($_POST['terms']) ? 'checked' : '' ?>/>
                                <label class="form-check-label" for="terms">
                                    Saya setuju dengan <a href="#">Syarat & Ketentuan</a>
                                </label>
                            </div>
                        </div>
                        <button class="btn btn-primary d-grid w-100" type="submit">Register</button>
                    </form>

                    <p class="text-center">
                        <span>Sudah punya akun?</span>
                        <a href="../login">
                            <span>Login di sini</span>
                        </a>
                    </p>

        
                </div>
            </div>
            <!-- /Register Card -->
        </div>
    </div>
</div>

<!-- Core JS -->
<script src="../../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../../assets/vendor/libs/popper/popper.js"></script>
<script src="../../assets/vendor/js/bootstrap.js"></script>
<script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../../assets/vendor/libs/hammer/hammer.js"></script>
<script src="../../assets/vendor/libs/i18n/i18n.js"></script>
<script src="../../assets/vendor/libs/typeahead-js/typeahead.js"></script>
<script src="../../assets/vendor/js/menu.js"></script>
<script src="../../assets/vendor/libs/@form-validation/umd/bundle/popular.min.js"></script>
<script src="../../assets/vendor/libs/@form-validation/umd/plugin-bootstrap5/index.min.js"></script>
<script src="../../assets/vendor/libs/@form-validation/umd/plugin-auto-focus/index.min.js"></script>
<script src="../../assets/js/main.js"></script>
<script src="../../assets/js/pages-auth.js"></script>
<script>
    // Toggle show/hide password
    document.querySelectorAll('.input-group-text').forEach(btn => {
        btn.addEventListener('click', function() {
            const input = this.parentElement.querySelector('input');
            if (input.type === 'password') {
                input.type = 'text';
                this.querySelector('i').classList.replace('bx-hide', 'bx-show');
            } else {
                input.type = 'password';
                this.querySelector('i').classList.replace('bx-show', 'bx-hide');
            }
        });
    });

    // Toast auto close
    setTimeout(() => {
        document.querySelectorAll('.toast-container').forEach(el => el.remove());
    }, 4000);

    // Client-side validation
    function validateRegisterForm() {
        const username = document.getElementById('username').value.trim();
        const pw = document.getElementById('password').value;
        const confirm = document.getElementById('confirm_password').value;
        const agree = document.getElementById('terms').checked;

        if (username.length < 6) {
            showToast('❗ Username minimal 6 karakter.');
            return false;
        }
        if (pw.length < 6) {
            showToast('❗ Password minimal 6 karakter.');
            return false;
        }
        if (pw !== confirm) {
            showToast('❗ Password dan konfirmasi tidak cocok.');
            return false;
        }
        if (!agree) {
            showToast('❗ Anda harus menyetujui syarat dan ketentuan.');
            return false;
        }
        return true;
    }
    function showToast(msg) {
        const toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
        toastContainer.style.zIndex = '9999';
        toastContainer.innerHTML = `
            <div class="toast align-items-center text-bg-danger border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
              <div class="d-flex">
                <div class="toast-body">${msg}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
              </div>
            </div>
        `;
        document.body.appendChild(toastContainer);
        setTimeout(() => toastContainer.remove(), 3500);
    }
</script>
<?php if ($success): ?>
<script>
    setTimeout(() => {
        window.location.href = '../login';
    }, 2500);
</script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
</body>
</html>

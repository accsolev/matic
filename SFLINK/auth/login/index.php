<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/ajax/ddos_protection.php';
ini_set('session.cookie_secure', true);
ini_set('session.cookie_httponly', true);
session_start();
date_default_timezone_set('Asia/Jakarta');
session_regenerate_id(true);

require_once '../../includes/db.php';

// Fungsi ambil IP address user (support proxy/Cloudflare)
function getClientIP() {
    $headers = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP', // Kalau pakai Cloudflare
        'REMOTE_ADDR'
    ];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = explode(',', $_SERVER[$h])[0];
            return trim($ip);
        }
    }
    return 'UNKNOWN';
}

// Auto redirect if already login
if (isset($_SESSION['user_id'])) {
    header("Location: ../../dashboard");
    exit;
}

// Buat token CSRF jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Logging aktivitas user (pakai ip_address)
function logActivity($pdo, $userId, $username, $action, $ip = null) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, ip_address, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId ?: null, $username, $action, $ip, $now]);
}

// Handle proses login
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        $_POST['csrf_token'] !== $_SESSION['csrf_token']
    ) {
        $error = "Invalid CSRF token.";
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $max_attempts = 10;
        $lockout_minutes = 15;
        $ip = getClientIP();

        // Cek percobaan login gagal
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempt_time > (NOW() - INTERVAL ? MINUTE)");
        $stmt->execute([$username, $lockout_minutes]);
        $attempt_count = $stmt->fetchColumn();

        if ($attempt_count >= $max_attempts) {
            $error = "Akun terkunci sementara karena terlalu banyak percobaan gagal.";
            logActivity($pdo, null, $username, 'Akun terkunci sementara karena terlalu banyak percobaan gagal', $ip);
        } else {
            // Ambil user
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Login berhasil, reset attempt
                $pdo->prepare("DELETE FROM login_attempts WHERE username = ?")->execute([$username]);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                logActivity($pdo, $user['id'], $user['username'], "Login berhasil dari IP: $ip", $ip);

                // Remember me
                if (!empty($_POST['rememberme'])) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('rememberme', $token, [
                        'expires' => time() + (30 * 24 * 60 * 60),
                        'path' => '/',
                        'secure' => true,
                        'httponly' => true,
                        'samesite' => 'Strict'
                    ]);
                    $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")->execute([$token, $user['id']]);
                }

                header("Location: ../../dashboard");
                exit;
            } else {
                // Login gagal
                $pdo->prepare("INSERT INTO login_attempts (username) VALUES (?)")->execute([$username]);
                $error = "Username atau password salah.";
                logActivity($pdo, null, $username, 'Gagal login: username/password salah', $ip);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="light-style layout-wide customizer-hide" dir="ltr"
      data-theme="theme-default"
      data-assets-path="../../assets/"
      data-template="vertical-menu-template">
<head>
    <meta charset="utf-8" />
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"/>
    <title>SFLINK.ID | Login Page</title>
    <meta name="description" content="Login SFLINK.ID" />

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
<?php if ($error): ?>
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;">
        <div class="toast align-items-center text-bg-danger border-0 show"
             role="alert"
             aria-live="assertive"
             aria-atomic="true"
             data-bs-delay="3500"
             data-bs-autohide="true">
            <div class="d-flex">
                <div class="toast-body">
                    <?= htmlspecialchars($error) ?>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="container-xxl">
    <div class="authentication-wrapper authentication-basic container-p-y">
        <div class="authentication-inner py-4">
            <div class="card">
                <div class="card-body">

                    <!-- Logo Full -->
                    <div class="app-brand justify-content-center">
                        <img src="https://sflink.id/logo.png" alt="SFLINK.ID" class="login-logo">
                    </div>
                    <!-- /Logo -->

                    <h4 class="mb-2 text-center">SFLINK.ID | Login Page</h4>
                    <p class="mb-4 text-center">Please login to your account</p>

                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input
                                type="text"
                                class="form-control"
                                name="username"
                                id="username"
                                placeholder="Enter your username"
                                required
                                autofocus />
                        </div>
                        <div class="mb-3 form-password-toggle">
                            <div class="d-flex justify-content-between">
                                <label class="form-label" for="password">Password</label>
                                <a href="https://t.me/sflinkid">
                                    <small>Forgot Password?</small>
                                </a>
                            </div>
                            <div class="input-group input-group-merge">
                                <input
                                    type="password"
                                    id="password"
                                    class="form-control"
                                    name="password"
                                    placeholder="********"
                                    required
                                    aria-describedby="password" />
                                <span class="input-group-text cursor-pointer"><i class="bx bx-hide"></i></span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" name="rememberme" id="rememberme" />
                                <label class="form-check-label" for="rememberme"> Remember Me </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <button class="btn btn-primary d-grid w-100" type="submit">Login</button>
                        </div>
                    </form>

                    <p class="text-center">
                        <span>Tidak punya akun?</span>
                        <a href="../register">
                            <span>Create an account</span>
                        </a>
                    </p>

                    

                </div>
            </div>
        </div>
    </div>
</div>

<!-- JS -->
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
    const toastEl = document.querySelector('.toast');
    if (toastEl) {
        const bsToast = new bootstrap.Toast(toastEl);
        bsToast.show();
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
</body>
</html>

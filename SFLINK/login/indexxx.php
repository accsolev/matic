<?php
// ─── ANTI-DDoS PROTECTION ───────────────────────────────────────────────────────
// konfigurasi
$rateLimitPerMin  = 40;
$challengeSeconds = 1;
$cookieKey        = 'ddos_verified_' . md5($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

// Cloudflare API untuk multi-zone blocking
define('CF_API_KEY',   '1dbe11f48040907075c9e3903509dae6087d4');
define('CF_EMAIL',     'accsolev9@gmail.com');
$zoneIds = [
    '76f1282dfe5207402bb8a8c7383f7a79',
    '82cf35e43e0d4fc1e283d022590d8b62',
    '257744e851b3f35bdd301be5dab0c933'
];

// koneksi Redis untuk rate-limit
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

// 1) Blok User-Agent bot/headless
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (!$ua || preg_match('/(curl|wget|bot|spider|python|headless|scrapy|axios)/i', $ua)) {
    http_response_code(403);
    exit('Blocked bot UA');
}

// 2) Rate-limit per IP
$ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$key = "ddos:rate:$ip";
$cnt = $redis->incr($key);
if ($cnt === 1) {
    $redis->expire($key, 60);
}
if ($cnt > $rateLimitPerMin) {
    // block via Cloudflare API
    foreach ($zoneIds as $zoneId) {
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/firewall/access_rules/rules");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "X-Auth-Email: ".CF_EMAIL,
                "X-Auth-Key: ".CF_API_KEY,
                "Content-Type: application/json",
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'mode'          => 'block',
                'configuration' => ['target'=>'ip','value'=>$ip],
                'notes'         => 'Auto-block via PHP Anti-DDoS',
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    http_response_code(429);
    exit('Rate limit exceeded');
}

// 3) Simple JS-challenge untuk memastikan browser normal
if (!isset($_COOKIE[$cookieKey])) {
    $token = bin2hex(random_bytes(6));
    setcookie($cookieKey, $token, time()+1800, '/');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Just a moment...</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    html, body {
      background: #fff;
      color: #000;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 0;
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      text-align: center;
    }

    .dot-loader {
      display: flex;
      gap: 8px;
      margin-bottom: 24px;
    }

    .dot-loader div {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background-color: #0088ff;
      animation: dotPulse 1.2s infinite ease-in-out;
    }

    .dot-loader div:nth-child(2) {
      animation-delay: 0.2s;
    }

    .dot-loader div:nth-child(3) {
      animation-delay: 0.4s;
    }

    @keyframes dotPulse {
      0%, 80%, 100% {
        transform: scale(0.8);
        opacity: 0.3;
      }
      40% {
        transform: scale(1.4);
        opacity: 1;
      }
    }

    .title {
      font-size: 1.4rem;
      font-weight: 600;
      margin-bottom: 8px;
    }

    .subtitle {
      font-size: 0.95rem;
      color: #9ca3af;
    }

    .brand {
      position: fixed;
      bottom: 16px;
      font-size: 0.8rem;
      color: #666;
      opacity: 0.3;
    }
  </style>
  <script>
    setTimeout(() => {
      document.cookie = "{$cookieKey}={$token}; path=/";
      location.reload();
    }, {$challengeSeconds} * 1000);
  </script>
</head>
<body>
  <div class="dot-loader">
    <div></div>
    <div></div>
    <div></div>
  </div>
  <div class="title">Verifying your browser...</div>
  <div class="subtitle">Please wait while we confirm you're not a bot.</div>
  <div class="brand">Security by SFLINK.ID ™</div>
</body>
</html>
HTML;
    exit;
}
ini_set('session.cookie_secure', true);
ini_set('session.cookie_httponly', true);
session_start();
date_default_timezone_set('Asia/Jakarta');
session_regenerate_id(true);

require_once '../includes/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: /dashboard");
    exit;
}

function logActivity($pdo, $userId, $username, $action) {
  $now = date('Y-m-d H:i:s');
  $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, created_at) VALUES (?, ?, ?, ?)");
  $stmt->execute([$userId ?: null, $username, $action, $now]);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token.");
    }

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $max_attempts = 5;
    $lockout_minutes = 15;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempt_time > (NOW() - INTERVAL ? MINUTE)");
    $stmt->execute([$username, $lockout_minutes]);
    $attempt_count = $stmt->fetchColumn();

    if ($attempt_count >= $max_attempts) {
        $error = "Akun terkunci sementara karena terlalu banyak percobaan gagal.";
        logActivity($pdo, null, $username, 'Akun terkunci sementara karena terlalu banyak percobaan gagal');
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $pdo->prepare("DELETE FROM login_attempts WHERE username = ?")->execute([$username]);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            logActivity($pdo, $user['id'], $user['username'], 'Login berhasil');

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

            header("Location: ../dashboard");
            exit;
        } else {
            $pdo->prepare("INSERT INTO login_attempts (username) VALUES (?)")->execute([$username]);
            $error = "Username atau password salah.";
            logActivity($pdo, null, $username, 'Gagal login: username/password salah');
        }
    }
}

if(empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="id">
<meta http-equiv="content-type" content="text/html;charset=UTF-8" />
<head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title>SFLINK.ID - Login Page</title>
        <meta name="google" content="notranslate">
	<meta name="description" content="The best URL shortener in the world, boost your campaign by creating Dynamic Links, Auto Rotator Link, Auto Check Domain and get instant analytics.">
	<meta name="keywords" content="sflink, sflink.id, shortlink id, shortlink, shortlink bagus">
	<meta property="og:locale" content="id" />
	<meta property="og:type" content="website" />
	<meta property="og:url" content="https://sflink.id/"/>
	<meta property="og:title" content="SFLINK" />
	<meta property="og:description" content="The best URL shortener in the world, boost your campaign by creating Dynamic Links, Auto Rotator Link, Auto Check Domain and get instant analytics." />
	<meta property="og:site_name" content="SFLINK" />
	<meta name="twitter:card" content="summary_large_image">
	<meta name="twitter:site" content="@http://www.twitter.com/sflink">
	<meta name="twitter:title" content="SFLINK">
	<meta name="twitter:description" content="The best URL shortener in the world, boost your campaign by creating Dynamic Links, Auto Rotator Link, Auto Check Domain and get instant analytics.">
	<meta name="twitter:creator" content="@http://www.twitter.com/sflink">
	<meta name="twitter:domain" content="https://sflink.id/">
	<link rel="icon" type="image/png" href="../favicon.png" sizes="32x32" />
	<link rel="canonical" href="https://sflink.id/">

        <link rel="stylesheet" type="text/css" href="../static/bootstrap.min.css">
        <link rel="stylesheet" type="text/css" href="../static/frontend/libs/fontawesome/all.min.css">
                    <link rel="stylesheet" type="text/css" href="../static/frontend/libs/cookieconsent/cookieconsent.css">
                <link rel="stylesheet" href="../static/style.minc619.css?v=1.0" id="stylesheet">
                <script>
            var appurl = 'index.php';
        </script>
                    </head>
    <body>
       <?php if ($error): ?>
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;">
  <div class="toast align-items-center text-bg-danger border-0 show"
       role="alert"
       aria-live="assertive"
       aria-atomic="true"
       data-bs-delay="3000"
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
        <a href="/" class="position-absolute top-0 start-0 text-dark text-decoration-none d-block ps-4 pt-4">
                        <img alt="SFLINK - Login Page" src="../logo.png" id="navbar-logo">
                    
</a>
<section>    
    <div class="container-fluid d-flex flex-column">
        <div class="row align-items-center justify-content-center justify-content-lg-start min-vh-100">
            <div class="col-sm-7 col-lg-6 col-xl-6 py-6 py-md-0">
                <div class="row justify-content-center">
                    <div class="col-11 col-lg-10 col-xl-6">
                        <div class="text-center mb-3">
                            <h4 class="fw-bold">Welcome back</h4>
                        </div>
                                                                            <div class="mt-1">
                            <form method="post">
                                 <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <div class="my-4">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" name="username" placeholder="Username" required>
                                        <label>Username</label>
                                    </div>
                                    <div class="d-flex mt-2 d-sm-none">
                                        <div class="ms-auto">
                                            <a href="/register" class="small text-muted">Don't have an account?</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="my-4">
                                    <div class="form-floating">
                                        <input type="password" class="form-control" name="password" placeholder="Enter your password" required>
                                        <label>Password</label>
                                    </div>
                                    <div class="d-flex mt-2">
                                        <div class="ms-auto">
                                            <a href="#" class="small text-muted">Forgot Password?</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-check my-4 text-start">
                                    <input class="form-check-input" type="checkbox" value="1" name="rememberme" id="rememberme">
                                    <label class="form-check-label" for="rememberme">Remember me</label>
                                </div>
                                <div class="mt-4">
                                                                        <input type="hidden" name="_token" value="$2y$10$LH6Wdexs/413Q17K2jLl8.SRbJTLQcP8BZKRvFVAAxExKbD5BzX7W" />
                            <div class="d-grid gap-2">
  <button type="submit" class="btn btn-primary py-2">Login</button>
  <a href="/register" class="btn btn-secondary py-2">Register</a>
</div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-5">&copy; 2025 <a href="#" class="font-weight-bold">SFLINK</a>. All Rights Reserved</p>
            </div>
        </div>
    </div>
    <div class="gradient-primary position-absolute h-100 top-0 end-0 zindex-10 col-lg-6 col-xl-6 d-none d-lg-flex flex-column justify-content-center">
        <div class="position-relative zindex-10 p-5">
            <div class="text-center text-white mx-auto">
                <h5 class="h5 mt-3 fw-bold">Don't have an account?</h5>
                <p class="opacity-8">
                    Start your marketing campaign now and reach your customers efficiently.                </p>
                                    <a href="/register" class="btn btn-light text-primary px-5 rounded-pill shadow-sm">Register</a>
                            </div>
        </div>
    </div>
</section>        <script src="../../static/webpack.pack.js"></script>   
                    <script id="cookieconsent-script" src="../../static/frontend/libs/cookieconsent/cookieconsent.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const toastEl = document.querySelector('.toast');
  if (toastEl) {
    const bsToast = new bootstrap.Toast(toastEl);
    bsToast.show();
  }
</script>
        <script type="text/javascript">
    var lang = {"error":"Please enter a valid URL.","couponinvalid":"The coupon enter is not valid","minurl":"You must select at least 1 url.","minsearch":"Keyword must be more than 3 characters!","nodata":"No data is available for this request.","datepicker":{"7d":"Last 7 Days","3d":"Last 30 Days","tm":"This Month","lm":"Last Month"},"cookie":{"title":"Cookie Preferences","description":"This website uses essential cookies to ensure its proper operation and tracking cookies to understand how you interact with it. You have the option to choose which one to allow.","button":" <button type=\"button\" data-cc=\"c-settings\" class=\"cc-link\" aria-haspopup=\"dialog\">Let me choose<\/button>","accept_all":"Accept All","accept_necessary":"Accept Necessary","close":"Close","save":"Save Settings","necessary":{"title":"Strictly Necessary Cookies","description":"These cookies are required for the correct functioning of our service and without these cookies you will not be able to use our product."},"analytics":{"title":"Targeting and Analytics","description":"Providers such as Google use these cookies to measure and provide us with analytics on how you interact with our website. All of the data is anonymized and cannot be used to identify you."},"ads":{"title":"Advertisement","description":"These cookies are set by our advertisers so they can provide you with relevant ads."},"extra":{"title":"Additional Functionality","description":"We use various providers to enhance our products and they may or may not set cookies. Enhancement can include Content Delivery Networks, Google Fonts, etc"},"privacy":{"title":"Privacy Policy","description":"You can view our privacy policy <a target=\"_blank\" class=\"cc-link\" href=\"https:\/\/demo.gempixel.com\/short\/page\/privacy\">here<\/a>. If you have any questions, please do not hesitate to <a href=\"https:\/\/demo.gempixel.com\/short\/contact\" target=\"_blank\" class=\"cc-link\">Contact us<\/a>"}}}</script>        
        <script src="../../static/app.min.js"></script>
        <script src="../../static/custom.min.js"></script>
        <script src="../../static/server.min7839.js?v=1.2"></script>
                			<script type="text/plain" data-cookiecategory="analytics" async src='https://www.googletagmanager.com/gtag/js?id=UA-37726302-3'></script>
            <script type="text/plain" data-cookiecategory="analytics" >window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag('js', new Date());gtag('config', 'UA-37726302-3');</script>
		            </body>
</html>


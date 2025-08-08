
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
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once '../includes/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    } else {
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = "Email tidak ditemukan dalam sistem.";
        } else {
            // Token reset
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 jam dari sekarang

            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $stmt->execute([$token, $expires, $user['id']]);

            $resetLink = "https://sflink.id/reset_password.php?token=$token";

            // Kirim email (contoh sederhana, sesuaikan dengan mailer kamu)
            $subject = "Reset Password SFLINK";
            $message = "Halo {$user['username']},\n\nKlik link berikut untuk reset password:\n$resetLink\n\nLink berlaku selama 1 jam.";
            $headers = "From: cs@sflink.id";

            mail($email, $subject, $message, $headers);

            $success = "Link reset telah dikirim ke email Anda.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
   <head>
      <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SFLINK merupakan sebuah tools yang akan menjaga link anda setiap saat, dengan layanan robot auto check nonstop ketika ada masalah apapun dengan link anda!">
    <meta name="keywords" content="sflink, sflinkid, sflink tools, anti nawala, robot nawala, rotator link">
    <meta name="author" content="SFLINKID">
    <link rel="icon" href="https://sflink.id/assets/favico.png" type="image/x-icon">
    <link rel="shortcut icon" href="https://sflink.id/assets/favico.png" type="image/x-icon">
    <title>SFLINK - Forgot Password Page</title>
      <!-- Google font-->
      <link href="https://fonts.googleapis.com/css?family=Rubik:400,400i,500,500i,700,700i&amp;display=swap" rel="stylesheet">
      <link href="https://fonts.googleapis.com/css?family=Roboto:300,300i,400,400i,500,500i,700,700i,900&amp;display=swap" rel="stylesheet">
      <!-- Font Awesome-->
      <link rel="stylesheet" type="text/css" href="../assets/css/vendors/fontawesome.css">
      <!-- ico-font-->
      <link rel="stylesheet" type="text/css" href="../assets/css/vendors/icofont.css">
      <!-- Themify icon-->
      <link rel="stylesheet" type="text/css" href="../assets/css/vendors/themify.css">
      <!-- Flag icon-->
      <link rel="stylesheet" type="text/css" href="../assets/css/vendors/flag-icon.css">
      <!-- Feather icon-->
      <link rel="stylesheet" type="text/css" href="../assets/css/vendors/feather-icon.css">
      <!-- Plugins css start--><!-- Plugins css Ends--><!-- Bootstrap css-->
      <link rel="stylesheet" type="text/css" href="../assets/css/vendors/bootstrap.css">
      <!-- App css-->
      <link rel="stylesheet" type="text/css" href="../assets/css/style.css">
      <link id="color" rel="stylesheet" href="../assets/css/color-1.css" media="screen">
      <!-- Responsive css-->
      <link rel="stylesheet" type="text/css" href="../assets/css/responsive.css">
      <script defer src="../assets/css/color-1.js"></script><script defer src="../assets/css/color-2.js"></script><script defer src="../assets/css/color-3.js"></script><script defer src="../assets/css/color-4.js"></script><script defer src="../assets/css/color-5.js"></script><script defer src="../assets/css/color-6.js"></script><script defer src="../assets/css/responsive.js"></script><script defer src="../assets/css/style.js"></script>
      <link href="../assets/css/color-1.css" rel="stylesheet">
      <link href="../assets/css/color-2.css" rel="stylesheet">
      <link href="../assets/css/color-3.css" rel="stylesheet">
      <link href="../assets/css/color-4.css" rel="stylesheet">
      <link href="../assets/css/color-5.css" rel="stylesheet">
      <link href="../assets/css/color-6.css" rel="stylesheet">
      <link href="../assets/css/responsive.css" rel="stylesheet">
      <link href="../assets/css/style.css" rel="stylesheet">
   </head>
   <body>
   <!-- tap on top starts-->
      <div class="tap-top"><i data-feather="chevrons-up"></i></div>
      <!-- tap on tap ends--><!-- page-wrapper Start-->
      <div class="page-wrapper">
         <div class="container-fluid p-0">
            <!-- Unlock page start-->
            <div class="authentication-main mt-0">
               <div class="row">
                  <div class="col-12">
                     <div class="login-card login-dark">
                        <div>
                          <div><a class="logo" href="/"><img class="img-fluid for-light" src="../assets/logo.png" width="220"alt="looginpage"><img class="img-fluid for-dark" src="../assets/logo.png" alt="looginpage"></a></div>
                           <div class="login-main">
                              <form method="post" class="theme-form">
                                   <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php elseif ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>
                                 <h4>Reset Password                          </h4>
                                 <div class="form-group">
                                    <label class="col-form-label">Enter your Email</label>
                                    <div class="form-input position-relative">
                                       <input class="form-control" type="email" name="email" required="" placeholder="Masukkan Email Terdaftar">
                                     
                                    </div>
                                 </div>
                                 <div class="form-group mb-0">
        
                                    <button class="btn btn-primary btn-block w-100 mt-3" type="submit">Reset Password</button>
                                 </div>
                                 <p class="mt-4 mb-0">Already Have an account?<a class="ms-2" href="/login">Sign in</a></p>
                              </form>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
            <!-- Unlock page end--><!-- page-wrapper Ends--><!-- latest jquery--><script src="../assets/js/jquery.min.js"></script><!-- Bootstrap js--><script src="../assets/js/bootstrap/bootstrap.bundle.min.js"></script><!-- feather icon js--><script src="../assets/js/icons/feather-icon/feather.min.js"></script><script src="../assets/js/icons/feather-icon/feather-icon.js"></script><!-- scrollbar js--><!-- Sidebar jquery--><script src="../assets/js/config.js"></script><!-- Plugins JS start--><!-- Plugins JS Ends--><!-- Theme js--><script src="../assets/js/script.js"></script><script src="../assets/js/script1.js"></script>
         </div>
      </div>
   </body>
</html>
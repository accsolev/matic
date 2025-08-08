<?php
session_start();

// Fungsi ambil IP pengguna
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    return $_SERVER['REMOTE_ADDR'];
}

// Fungsi ambil konten dari trustpositif
function getHtmlContent($domain) {
    $url = "https://trustpositif.komdigi.go.id/?domains=" . urlencode($domain);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return $response;
}

// Fungsi parse tabel dari HTML TrustPositif
function extractTableData($html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//table//tr');
    $tableData = [];
    foreach ($rows as $row) {
        $cols = $xpath->query('td', $row);
        if ($cols->length > 0) {
            $rowData = [];
            foreach ($cols as $col) {
                $rowData[] = trim($col->nodeValue);
            }
            $tableData[] = $rowData;
        }
    }
    return $tableData;
}

// Fungsi ubah status "Ada" → "❌ DIBLOKIR!!"
function filterStatus($status) {
    return (trim($status) == 'Ada') ? '❌ DIBLOKIR!!' : '✅ AMAN';
}

// LIMIT BERDASARKAN IP + COOKIE
$ip = getUserIP();
$limit_file = __DIR__ . '/ip_limit.json';
$limit_data = file_exists($limit_file) ? json_decode(file_get_contents($limit_file), true) : [];

$today = date('Y-m-d');
if (!isset($limit_data[$ip]) || $limit_data[$ip]['date'] != $today) {
    $limit_data[$ip] = ['count' => 0, 'date' => $today];
}

// COOKIE: filter session browser
$cookieKey = 'check_limit';
$cookieCount = isset($_COOKIE[$cookieKey]) ? (int)$_COOKIE[$cookieKey] : 0;
$limit_reached = $limit_data[$ip]['count'] >= 2 || $cookieCount >= 2;

$results = [];
$alert = '';

// HANDLE FORM SUBMIT
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST["domains"])) {
    if ($limit_reached) {
        $alert = '<div class="alert alert-danger mt-3">❌ You have reached your daily checking limit (2 times). Please try again tomorrow or upgrade your plan!</div>';
    } else {
        $limit_data[$ip]['count']++;
        setcookie($cookieKey, $cookieCount + 1, strtotime('tomorrow'), "/");

        $domains = explode("\n", $_POST["domains"]);
        $domains = array_map('trim', $domains);
        $domains = array_filter($domains);

        $message = "Status Domain dari TrustPositif:\n\n";
        foreach ($domains as $domain) {
            $htmlContent = getHtmlContent($domain);
            if ($htmlContent) {
                $tableData = extractTableData($htmlContent);
                foreach ($tableData as $row) {
                    if (isset($row[1])) {
                        $row[1] = filterStatus($row[1]);
                    }
                    $results[] = $row;
                    $message .= implode(" | ", $row) . "\n";
                }
            } else {
                $results[] = [$domain, "Gagal mengambil data"];
                $message .= "$domain | ❌ Gagal mengambil data\n";
            }
        }
    }

    // Simpan ke file
    file_put_contents($limit_file, json_encode($limit_data, JSON_PRETTY_PRINT));
}
?>

<!DOCTYPE html>
<html lang="id">
<meta http-equiv="content-type" content="text/html;charset=UTF-8" />
<head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title>SFLINK.ID - Boost Your Bussines with us</title>
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
            var appurl = 'index.html';
        </script>
                    </head>
    <body>
        <header class="py-3" id="main-header">
    <div class="container">
        <div class="navbar navbar-expand-lg py-3">
            <a href="https://sflink.id/" class="d-flex align-items-center col-md-3 text-dark text-decoration-none navbar-logo">
                                                <img alt="SFLINK" src="../../logo.png" id="navbar-logo">
                                        <img alt="SFLINK" src="content/logo-white.png" id="navbar-logo">
                                                            </a>
            <button class="navbar-toggler border-0 collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#navbar" aria-controls="navbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggle-icon text-secondary">
                    <span></span>
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>
            <div class="collapse navbar-collapse" id="navbar">
                <ul class="nav col-12 col-md-auto mb-2 justify-content-center mb-md-0 flex-fill text-start" id="main-menu">
     
                                            <li class="nav-item">
                        <a class="nav-link" href="/pricing">Pricing</a>
                    </li>
                                                            <li class="nav-item">
                        <a class="nav-link" href="https://t.me/sflinkid">Contact</a>
                    </li>
                                                                                                                                                                <li class="nav-item">
                                        <a class="nav-link" href="/domains">Domain Checker</a>
                                    </li>
                                                                                                                            
                    </li>
                </ul>

                <div class="col-md-3 text-end flex-fill" id="login-menu">
                                                                <a href="/login" class="btn btn-outline-primary me-3 fw-bold align-items-center">Login</a>
                                                    <a href="/register" class="btn btn-primary fw-bold">Register</a>
                                                            </div>
            </div>
        </div>
    </div>
</header>

           <section class="bg-primary">
    <div class="container py-8">
        <div class="row mb-5 justify-content-center text-center">
            <div class="col-lg-7 col-md-9">
                <h4 class="py-2 px-3">
					<strong class="gradient-primary clip-text fw-bolder">Domain Checker</strong>
				</h4>
                <h1 class="fw-bolder mt-4">Check Your Links</h1>
                <p class="lead mb-0">
                 we give free 2 times every day to check your domain</p>
            </div>
        </div>
                        <?= $alert ?>
        <div class="card shadow">
            <div class="card-header bg-primary text-black text-center">
                <h4>Cek Status Domain (TrustPositif)</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="domains" class="form-label">Masukkan Domain (Pisahkan dengan Enter)</label>
                        <textarea class="form-control" id="domains" name="domains" rows="5" placeholder="Max Url (50 Colums)"required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Cek Sekarang</button>
                </form>
            </div>
        </div>

        <?php if (!empty($results)): ?>
            <div class="card mt-4">
                <div class="card-header bg-dark text-white text-center">
                    <h5>Hasil Pengecekan</h5>
                </div>
                <div class="card-body">
                    <table class="table table-bordered text-center">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row[0]) ?></td>
                                    <td class="<?= (strpos($row[1], 'DIBLOKIR') !== false) ? 'text-danger fw-bold' : 'text-success fw-bold' ?>">
                                        <?= htmlspecialchars($row[1]) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
<br>
                            <div class="h-100 p-5 gradient-primary text-white rounded-3 border-0 shadow-sm">
                    <div class="row align-items-center gy-lg-5">
                        <div class="col-sm-8">
                            <h2 class="fw-bold">Need a custom plan?</h2>
                            <p class="lead">If our current plans do not fit your needs, we will create a tailored plan just for your needs.</p>
                        </div>
                        <div class="col-sm-4 text-end">
                            <a class="btn btn-light text-primary d-block d-sm-inline-block" href="https://t.me/sflinkid">Contact</a>
                        </div>
                    </div>
                </div>
     
    </div>
</section>

<style>
#blurred, #blurred > div {
    transition: all 1s;
}

#blurred:hover > div {
    -webkit-filter: blur(10px);
    -moz-filter: blur(10px);
    -o-filter: blur(10px);
    -ms-filter: blur(10px);
    filter: blur(10px);
    opacity: 0.8;
    -webkit-transform: scale(0.95);
}

#blurred:hover > div:hover {
    -webkit-filter: blur(0);
    -moz-filter: blur(0);
    -o-filter: blur(0);
    -ms-filter: blur(0);
    filter: blur(0);
    opacity: 1;
    -webkit-transform: scale(1.05);
}

</style>
        <footer class="pt-5 text-start" id="footer-main">
    <div class="container">
        <div class="row">
            <div class="col-lg-4 mb-5 mb-lg-0" class="text-dark">
                <a href="https://sflink.id/">
                                                            <img alt="SFLINK" src="../../logo.png" id="navbar-logo">
                                                    </a>
                <p class="mt-4">The best URL shortener in the world, boost your campaign by creating Dynamic Links, Auto Rotator Link, Auto Check Domain and get instant analytics.</p>
                <ul class="nav mt-4">
                                            <li>
                            <a class="nav-link text-muted ps-0 me-2" href="#" target="_blank">
                                <i class="fab fa-facebook"></i>
                            </a>
                        </li>
                                                                <li>
                            <a class="nav-link text-muted ps-0 me-2" href="#" target="_blank">
                                <i class="fab fa-x-twitter"></i>
                            </a>
                        </li>
                                                                            </ul>
            </div>
            <div class="col-lg-4 col-6 col-sm-6 ml-lg-auto mb-5 mb-lg-0">
                <h6 class="fw-bold mb-3">Solutions</h6>
                <ul class="list-unstyled">
                    <li class="mb-2"><a class="nav-link" href="#">Smart Shortlink</a></li>
                    <li class="mb-2"><a class="nav-link" href="#">Auto Rotator Links</a></li>
					<li class="mb-2"><a class="nav-link" href="#">Auto Check Domains</a></li>
                                                        </ul>
            </div>
            <div class="col-lg-4 col-6 col-sm-6 mb-5 mb-lg-0">
                <h6 class="fw-bold mb-3">Resources</h6>
                <ul class="list-unstyled">
                                        
                                            <li class="mb-2"><a class="nav-link" href="#">Help Center</a></li>
                                                                <li class="mb-2"><a class="nav-link" href="#">Developer API</a></li>
                                                                <li class="mb-2"><a class="nav-link" href="#">Affiliate Program</a></li>
                                                                <li class="mb-2"><a class="nav-link" href="#">Contact Us</a></li>
                                    </ul>
            </div>
        </div>
        <div class="row align-items-center justify-content-md-between pb-2 mt-5">
            <div class="col-md-4">
                <div class="copyright text-sm text-center text-md-start">
                    &copy; 2025 <a href="https://sflink.id/" class="fw-bold">SFLINK.ID</a>. All Rights Reserved                </div>
            </div>
            <div class="col-md-8">
                <ul class="nav justify-content-center justify-content-md-end mt-3 mt-md-0">
                                                                                    <li class="nav-item"><a class="nav-link text-dark" href="#">Report</a></li>
                                                                                    <li class="nav-item"><a class="nav-link text-dark" href="#" data-cc="c-settings">Cookie Settings</a></li>
                                                                <li class="nav-item dropup">
                            <a class="nav-link text-dark" data-bs-toggle="dropdown" href="#"><i class="fa fa-globe" class="mr-1"></i>EN</a>

                        </li>
                                    </ul>
            </div>
        </div>
    </div>
</footer>
        <a class="position-fixed bottom-0 end-0 m-3 btn btn-dark rounded-circle shadow opacity-0" role="button" data-trigger="scrollto" data-top="0" id="scroll-to-top">
            <i class="fa fa-chevron-up small" aria-hidden="true"></i>
        </a>

        <script data-cfasync="false" src="../cdn-cgi/scripts/5c5dd728/cloudflare-../static/email-decode.min.js"></script><script type="text/javascript">
    var lang = {"error":"Please enter a valid URL.","couponinvalid":"The coupon enter is not valid","minurl":"You must select at least 1 url.","minsearch":"Keyword must be more than 3 characters!","nodata":"No data is available for this request.","datepicker":{"7d":"Last 7 Days","3d":"Last 30 Days","tm":"This Month","lm":"Last Month"},"cookie":{"title":"Cookie Preferences","description":"This website uses essential cookies to ensure its proper operation and tracking cookies to understand how you interact with it. You have the option to choose which one to allow.","button":" <button type=\"button\" data-cc=\"c-settings\" class=\"cc-link\" aria-haspopup=\"dialog\">Let me choose<\/button>","accept_all":"Accept All","accept_necessary":"Accept Necessary","close":"Close","save":"Save Settings","necessary":{"title":"Strictly Necessary Cookies","description":"These cookies are required for the correct functioning of our service and without these cookies you will not be able to use our product."},"analytics":{"title":"Targeting and Analytics","description":"Providers such as Google use these cookies to measure and provide us with analytics on how you interact with our website. All of the data is anonymized and cannot be used to identify you."},"ads":{"title":"Advertisement","description":"These cookies are set by our advertisers so they can provide you with relevant ads."},"extra":{"title":"Additional Functionality","description":"We use various providers to enhance our products and they may or may not set cookies. Enhancement can include Content Delivery Networks, Google Fonts, etc"},"privacy":{"title":"Privacy Policy","description":"You can view our privacy policy <a target=\"_blank\" class=\"cc-link\" href=\"https:\/\/demo.gempixel.com\/short\/page\/privacy\">here<\/a>. If you have any questions, please do not hesitate to <a href=\"https:\/\/demo.gempixel.com\/short\/contact\" target=\"_blank\" class=\"cc-link\">Contact us<\/a>"}}}</script>
        <script src="../static/webpack.pack.js"></script>
                    <script id="cookieconsent-script" src="../static/frontend/libs/cookieconsent/cookieconsent.js"></script>
<script src="../static/frontend/libs/clipboard/dist/clipboard.min.js"></script>
	<script src="../static/frontend/libs/typedjs/typed.min.js"></script>
        
        
        <script src="../static/app.minf9e3.js?v=1.1"></script>
        <script src="../static/server.mine67d.js?v=1.3"></script>
                			<script type="text/plain" data-cookiecategory="analytics" async src='https://www.googletagmanager.com/gtag/js?id=UA-37726302-3'></script>
            <script type="text/plain" data-cookiecategory="analytics" >window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag('js', new Date());gtag('config', 'UA-37726302-3');</script>
		                    
            <script type="text/plain" data-cookiecategory="extra" >
                $('head').append('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;900">').append('<style>body{font-family:\'Open Sans\' !important}</style>');
            </script>
            </body>

</html>
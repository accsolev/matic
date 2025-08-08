<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once __DIR__.'/dashboard/ajax/ddos_protection.php';
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: /dashboard");
    exit;
}
?>
<!DOCTYPE html>
<html
  lang="id"
  class="light-style layout-navbar-fixed layout-wide"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="assets/"
  data-template="front-pages-no-customizer">
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />

    <title>SFLINK.ID | Protect Your Link With Us</title>
	<meta name="description" content="SFLINK merupakan sebuah tools yang akan menjaga link anda setiap saat, dengan layanan robot auto check nonstop ketika ada masalah apapun dengan link anda!">
<meta name="keywords" content="sflink, sflinkid, sflink tools, anti nawala, robot nawala, rotator link">
	<!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="img/favico.png" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Rubik:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet" />

    <link rel="stylesheet" href="assets/vendor/fonts/boxicons.css" />

    <!-- Core CSS -->
    <link rel="stylesheet" href="assets/vendor/css/rtl/core.css" />
    <link rel="stylesheet" href="assets/vendor/css/rtl/theme-default.css" />
    <link rel="stylesheet" href="assets/css/demo.css" />
    <link rel="stylesheet" href="assets/vendor/css/pages/front-page.css" />
    <!-- Vendors CSS -->

    <link rel="stylesheet" href="assets/vendor/libs/nouislider/nouislider.css" />
    <link rel="stylesheet" href="assets/vendor/libs/swiper/swiper.css" />

    <!-- Page CSS -->

    <link rel="stylesheet" href="assets/vendor/css/pages/front-page-landing.css" />

    <!-- Helpers -->
    <script src="assets/vendor/js/helpers.js"></script>
    <!--! Template customizer & Theme config files MUST be included after core stylesheets and helpers.js in the <head> section -->
    <!--? Config:  Mandatory theme config file contain global vars & default theme options, Set your preferred theme option in this file.  -->
    <script src="assets/js/front-config.js"></script>
<style>
/* Logo di Navbar agar center dan proporsional */
.navbar .app-brand-link {
  display: flex;
  align-items: center;
  height: 56px;         /* Tinggi navbar ideal desktop */
  padding: 0 10px 0 0;  /* Padding kanan dikit biar lega */
}
.navbar .app-brand-logo.demo,
.navbar .login-logo {
  display: block;
  margin: 0;
  padding: 0;
  max-height: 38px;     /* Tinggi logo agar center & tidak menonjol */
  max-width: 100px;     /* Batasi lebar juga */
  width: auto;
  object-fit: contain;
  vertical-align: middle;
  transition: all 0.2s;
}
@media (max-width: 991px) {
  .navbar .app-brand-link {
    height: 44px;
  }
  .navbar .app-brand-logo.demo,
  .navbar .login-logo {
    max-height: 30px;
    max-width: 72px;
  }
}
.logo-carousel {
  gap: 36px !important; /* Jarak antar logo, bisa diubah */
  padding: 12px 0;
}

.client-logo {
  height: 56px;        /* Atur tinggi logo biar rata semua */
  width: auto;
  object-fit: contain;
  transition: transform .2s;
  filter: grayscale(0.15);   /* Efek sedikit muted, opsional */
  background: transparent;
  margin: 0 6px;
}

.client-logo:hover {
  filter: none;
  transform: scale(1.07);
}

/* Responsive: di HP lebih kecil */
@media (max-width: 600px) {
  .client-logo {
    height: 40px;
    margin: 0 3px;
  }
  .logo-carousel {
    gap: 16px !important;
  }
}

</style>
  </head>

  <body>
    <script src="assets/vendor/js/dropdown-hover.js"></script>
    <script src="assets/vendor/js/mega-dropdown.js"></script>

    <!-- Navbar: Start -->
    <nav class="layout-navbar shadow-none py-0">
      <div class="container">
        <div class="navbar navbar-expand-lg landing-navbar px-3 px-md-4">
          <!-- Menu logo wrapper: Start -->
          <div class="navbar-brand app-brand demo d-flex py-0 me-4">
            <!-- Mobile menu toggle: Start-->
            <button
              class="navbar-toggler border-0 px-0 me-2"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target="#navbarSupportedContent"
              aria-controls="navbarSupportedContent"
              aria-expanded="false"
              aria-label="Toggle navigation">
              <i class="tf-icons bx bx-menu bx-sm align-middle"></i>
            </button>
            <!-- Mobile menu toggle: End-->
            <a href="/" class="app-brand-link">
              <span class="app-brand-logo demo">
                <img src="https://sflink.id/logo.png" alt="SFLINK.ID" class="login-logo">
            </a>
          </div>
          <!-- Menu logo wrapper: End -->
          <!-- Menu wrapper: Start -->
          <div class="collapse navbar-collapse landing-nav-menu" id="navbarSupportedContent">
            <button
              class="navbar-toggler border-0 text-heading position-absolute end-0 top-0 scaleX-n1-rtl"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target="#navbarSupportedContent"
              aria-controls="navbarSupportedContent"
              aria-expanded="false"
              aria-label="Toggle navigation">
              <i class="tf-icons bx bx-x bx-sm"></i>
            </button>
            <ul class="navbar-nav me-auto">
              <li class="nav-item">
                <a class="nav-link fw-medium" aria-current="page" href="#landingHero">Home</a>
              </li>
              <li class="nav-item">
                <a class="nav-link fw-medium" href="#landingFeatures">Features</a>
              </li>
              <li class="nav-item">
                <a class="nav-link fw-medium" href="#landingReviews">Review</a>
              </li>
              <li class="nav-item">
                <a class="nav-link fw-medium" href="#landingPricing">Price List</a>
              </li>
        <li class="nav-item">
                <a class="nav-link fw-medium" href="#landingFAQ">FAQ</a>
              </li>
            </ul>
          </div>
          <div class="landing-menu-overlay d-lg-none"></div>
          <!-- Menu wrapper: End -->
          <!-- Toolbar: Start -->
          <ul class="navbar-nav flex-row align-items-center ms-auto">
            <!-- navbar button: Start -->
            <li>
              <a href="auth/login" class="btn btn-primary" target="_blank"
                ><span class="tf-icons bx bx-user me-md-1"></span
                ><span class="d-none d-md-block">Login/Register</span></a
              >
            </li>
            <!-- navbar button: End -->
          </ul>
          <!-- Toolbar: End -->
        </div>
      </div>
    </nav>
    <!-- Navbar: End -->

    <!-- Sections:Start -->

    <div data-bs-spy="scroll" class="scrollspy-example">
      <!-- Hero: Start -->
      <section id="hero-animation">
        <div id="landingHero" class="section-py landing-hero position-relative">
          <div class="container">
            <div class="hero-text-box text-center">
              <h1 class="text-primary hero-title display-5 fw-bold">Start Now Develop Your Business With Us
</h1>
              <h2 class="hero-sub-title h6 mb-4 pb-1">
                SFLINK merupakan sebuah tools yang akan menjaga link anda setiap saat, dengan layanan robot auto check nonstop ketika ada masalah apapun dengan link anda!

              </h2>
              <div class="landing-hero-btn d-inline-block position-relative">
                <span class="hero-btn-item position-absolute d-none d-md-flex text-heading"
                  >Gratis Uji Coba
                  <img
                    src="assets/img/front-pages/icons/Join-community-arrow.png"
                    alt="Join community arrow"
                    class="scaleX-n1-rtl"
                /></span>
                <a href="auth/login" class="btn btn-primary">Get early access</a>
              </div>
            </div>
           
          </div>
        </div>
      </section>
      <!-- Hero: End -->

      <!-- Useful features: Start -->
      <section id="landingFeatures" class="section-py landing-features">
        <div class="container">
          <div class="text-center mb-3 pb-1">
            <span class="badge bg-label-primary">Trusted by 1,000+ Users worldwide</span>
          </div>
          <h3 class="text-center mb-1">
            <span class="section-title">Business That Grows With Us Productivity Tool

          </h3>
          <p class="text-center mb-3 mb-md-5 pb-3">
            Customer support for costumer sflink is one of the best ways to understand your customers' feelings about your business.
          </p>
          <div class="features-icon-wrapper row gx-0 gy-4 g-sm-5">
            <div class="col-lg-4 col-sm-6 text-center features-icon-box">
              <div class="text-center mb-3">
                <img src="assets/img/front-pages/icons/laptop.png" alt="laptop charging" />
              </div>
              <h5 class="mb-3">Advance Analytics</h5>
              <p class="features-icon-description">
                Analisis tingkat lanjut mengacu pada penggunaan teknik dan alat analisis data yang kompleks dan canggih
              </p>
            </div>
            <div class="col-lg-4 col-sm-6 text-center features-icon-box">
              <div class="text-center mb-3">
                <img src="assets/img/front-pages/icons/rocket.png" alt="transition up" />
              </div>
              <h5 class="mb-3">Fast Loading & Anti DDoS</h5>
              <p class="features-icon-description">
                Permudahkan akses link anda dengan menggunakan SFLINK.ID mempermudahkan konsumen mengunjungi bisnis anda, dan sudah di protect anti DDoS tanpa khawatir jika link di jahilin orang.
              </p>
            </div>
            <div class="col-lg-4 col-sm-6 text-center features-icon-box">
              <div class="text-center mb-3">
                <img src="assets/img/front-pages/icons/paper.png" alt="edit" />
              </div>
              <h5 class="mb-3">Free Access</h5>
              <p class="features-icon-description">
              SFLINK.ID memberikan full akses uji coba untuk pengguna baru dengan semua fitur terbuka.
              </p>
            </div>
            <div class="col-lg-4 col-sm-6 text-center features-icon-box">
              <div class="text-center mb-3">
                <img src="assets/img/front-pages/icons/check.png" alt="3d select solid" />
              </div>
              <h5 class="mb-3">Automated Reports</h5>
              <p class="features-icon-description">
              Laporan otomatis yang dibuat secara otomatis oleh sistem sflink.
              </p>
            </div>
            <div class="col-lg-4 col-sm-6 text-center features-icon-box">
              <div class="text-center mb-3">
                <img src="assets/img/front-pages/icons/user.png" alt="lifebelt" />
              </div>
              <h5 class="mb-3">Excellent Support</h5>
              <p class="features-icon-description">Team kita siap kapanpun akan membalas pesanmu jika ada pertanyaan atau kendala 24 jam aktif support.</p>
            </div>
            <div class="col-lg-4 col-sm-6 text-center features-icon-box">
              <div class="text-center mb-3">
                <img src="assets/img/front-pages/icons/keyboard.png" alt="google docs" />
              </div>
              <h5 class="mb-3">Fiture Premium SFLINK</h5>
              <p class="features-icon-description">Kita menyediakan banyak fitur diantaranya yaitu:<br> AUTO CHECK DOMAIN <br> AUTO ROTATOR LINK <br> SHORTLINK PREMIUM <br> ANTIDDOS <br> FULL SUPPORT 24 JAM. <br> MASIH BANYAK LAINNYA SILAHKAN LOGIN</p>
            </div>
          </div>
        </div>
      </section>
      <!-- Useful features: End -->

      <!-- Real customers reviews: Start -->
      <section id="landingReviews" class="section-py bg-body landing-reviews pb-0">
        <!-- What people say slider: Start -->
        <div class="container">
          <div class="row align-items-center gx-0 gy-4 g-lg-5">
            <div class="col-md-6 col-lg-5 col-xl-3">
              <div class="mb-3 pb-1">
                <span class="badge bg-label-primary">Real Customers Reviews</span>
              </div>
              <h3 class="mb-1"><span class="section-title">What people say</span></h3>
              <p class="mb-3 mb-md-5">
                See what our customers have to<br class="d-none d-xl-block" />
                say about their experience.
              </p>
              <div class="landing-reviews-btns d-flex align-items-center gap-3">
                <button id="reviews-previous-btn" class="btn btn-label-primary reviews-btn" type="button">
                  <i class="bx bx-chevron-left bx-sm"></i>
                </button>
                <button id="reviews-next-btn" class="btn btn-label-primary reviews-btn" type="button">
                  <i class="bx bx-chevron-right bx-sm"></i>
                </button>
              </div>
            </div>
            <div class="col-md-6 col-lg-7 col-xl-9">
              <div class="swiper-reviews-carousel overflow-hidden mb-5 pb-md-2 pb-md-3">
                <div class="swiper" id="swiper-reviews">
                  <div class="swiper-wrapper">
                    <div class="swiper-slide">
                      <div class="card h-100">
                        <div class="card-body text-body d-flex flex-column justify-content-between h-100">
                         
                          <p>
                            “pembayaran cepat di proses, semua serba auto tools nya mantap.”
                          </p>
                          <div class="text-warning mb-3">
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                          </div>
                          <div class="d-flex align-items-center">
                            <div class="avatar me-2 avatar-sm">
                              <img src="assets/img/avatars/1.webp" alt="Avatar" class="rounded-circle" />
                            </div>
                            <div>
                              <h6 class="mb-0">Someone</h6>
                              <p class="small text-muted mb-0">Pengguna Baru</p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
 <div class="swiper-slide">
                      <div class="card h-100">
                        <div class="card-body text-body d-flex flex-column justify-content-between h-100">
                         
                          <p>
                            “awalnya bingung, ternyata semua ada tutorialnya didalam, penggunaan gampang thx.”
                          </p>
                          <div class="text-warning mb-3">
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                          </div>
                          <div class="d-flex align-items-center">
                            <div class="avatar me-2 avatar-sm">
                              <img src="assets/img/avatars/1.webp" alt="Avatar" class="rounded-circle" />
                            </div>
                            <div>
                              <h6 class="mb-0">Someone</h6>
                              <p class="small text-muted mb-0">Pengguna Baru</p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                       <div class="swiper-slide">
                      <div class="card h-100">
                        <div class="card-body text-body d-flex flex-column justify-content-between h-100">
                         
                          <p>
                            “review apa ini? masuk rupanya di tempat review? wkwkwkk, untuk saat ini aman sih bro.”
                          </p>
                          <div class="text-warning mb-3">
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                          </div>
                          <div class="d-flex align-items-center">
                            <div class="avatar me-2 avatar-sm">
                              <img src="assets/img/avatars/1.webp" alt="Avatar" class="rounded-circle" />
                            </div>
                            <div>
                              <h6 class="mb-0">Someone</h6>
                              <p class="small text-muted mb-0">Pengguna Baru</p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
					 <div class="swiper-slide">
                      <div class="card h-100">
                        <div class="card-body text-body d-flex flex-column justify-content-between h-100">
                         
                          <p>
                            “pembayaran cepat di proses, semua serba auto tools nya mantap.”
                          </p>
                          <div class="text-warning mb-3">
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                            <i class="bx bxs-star bx-sm"></i>
                          </div>
                          <div class="d-flex align-items-center">
                            <div class="avatar me-2 avatar-sm">
                              <img src="assets/img/avatars/1.webp" alt="Avatar" class="rounded-circle" />
                            </div>
                            <div>
                              <h6 class="mb-0">Someone</h6>
                              <p class="small text-muted mb-0">Pengguna Baru</p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    </div>
                  </div>
                  <div class="swiper-button-next"></div>
                  <div class="swiper-button-prev"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- What people say slider: End -->
        <hr class="m-0" />
   <!-- Logo slider: Start -->
<div class="container">
  <div class="logo-carousel py-4 my-lg-2 d-flex justify-content-center align-items-center flex-wrap gap-4">
    <img src="img/xl.png" alt="XL Axiata" class="client-logo" />
    <img src="img/telkomsel.png" alt="Telkomsel" class="client-logo" />
    <img src="img/indihome.png" alt="IndiHome" class="client-logo" />
    <img src="img/biznet.png" alt="Biznet" class="client-logo" />
    <img src="img/axis.png" alt="AXIS" class="client-logo" />
  </div>
</div>
<!-- Logo slider: End -->

      </section>
      <!-- Real customers reviews: End -->



      <!-- Pricing plans: Start -->
      <section id="landingPricing" class="section-py bg-body landing-pricing">
        <div class="container">
          <div class="text-center mb-3 pb-1">
            <span class="badge bg-label-primary">Choose from our affordable 3 packages!
</span>
          </div>
          <h3 class="text-center mb-1"><span class="section-title">Make the wise decision for your business</h3>
          <p class="text-center mb-4 pb-3">
           Pilih paket anda sesuai kebutuhan anda, disini sudah termurah dari yang lainnya!
          </p>
          
  <div class="row gy-4 pt-lg-3">
  <!-- Gratis Plan -->
  <div class="col-xl-3 col-lg-6">
    <div class="card border">
      <div class="card-header">
        <div class="text-center">
          <img src="assets/img/front-pages/icons/paper-airplane.png" alt="Gratis Plan" class="mb-4 pb-2" />
          <h4 class="mb-1">Gratis Plan</h4>
          <div class="d-flex align-items-center justify-content-center">
            <span class="h3 text-primary fw-bold mb-0">Rp 0</span>
            <sub class="h6 text-muted mb-0 ms-1">/lifetime</sub>
          </div>
        </div>
      </div>
      <div class="card-body">
        <ul class="list-unstyled mb-0">
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>1 Domain</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Unlimited Auto Rotator Link</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Fallback URL / Link Cadangan</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>URL Cloacking via Negara</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>1 Shortlink Premium</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Minimum 5 Menit Pengecekan</li>

		</ul>
        <div class="d-grid mt-4 pt-3">
          <a href="/auth/register" class="btn btn-label-primary">Start Sign Up Now</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Medium Plan -->
  <div class="col-xl-3 col-lg-6">
    <div class="card border">
      <div class="card-header">
        <div class="text-center">
          <img src="assets/img/front-pages/icons/plane.png" alt="Medium Plan" class="mb-4 pb-2" />
          <h4 class="mb-1">Medium Plan</h4>
          <div class="d-flex align-items-center justify-content-center">
            <span class="h3 text-primary fw-bold mb-0">Rp 350.000</span>
            <sub class="h6 text-muted mb-0 ms-1">/month</sub>
          </div>
        </div>
      </div>
      <div class="card-body">
        <ul class="list-unstyled mb-0">
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>3 Domain</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Unlimited Auto Rotator Link</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Fallback URL / Link Cadangan</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>URL Cloacking via Negara</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>3 Shortlink Premium</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Minimum 5 Menit Pengecekan</li>
		
        </ul>
        <div class="d-grid mt-4 pt-3">
          <a href="/auth/register" class="btn btn-label-primary">Start Sign Up Now</a>
        </div>
      </div>
    </div>
  </div>

  <!-- VIP Plan -->
  <div class="col-xl-3 col-lg-6">
    <div class="card border border-primary shadow-lg">
      <div class="card-header">
        <div class="text-center">
          <img src="assets/img/front-pages/icons/shuttle-rocket.png" alt="VIP Plan" class="mb-4 pb-2" />
          <h4 class="mb-1 text-primary">VIP Plan</h4>
          <div class="d-flex align-items-center justify-content-center">
            <span class="h3 text-primary fw-bold mb-0">Rp 650.000</span>
            <sub class="h6 text-muted mb-0 ms-1">/month</sub>
          </div>
        </div>
      </div>
      <div class="card-body">
        <ul class="list-unstyled mb-0">
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>30 Domain</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Unlimited Auto Rotator Link</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Fallback URL / Link Cadangan</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>URL Cloacking via Negara</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>30 Shortlink Premium</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Minimum 1 Menit Pengecekan</li>
        </ul>
        <div class="d-grid mt-4 pt-3">
          <a href="/auth/register" class="btn btn-primary">Start Sign Up Now</a>
        </div>
      </div>
    </div>
  </div>

  <!-- VIP MAX Plan -->
  <div class="col-xl-3 col-lg-6">
    <div class="card border">
      <div class="card-header">
        <div class="text-center">
          <img src="assets/img/front-pages/icons/shuttle-rocket.png" alt="VIP MAX Plan" class="mb-4 pb-2" />
          <h4 class="mb-1 text-danger">VIP MAX Plan</h4>
          <div class="d-flex align-items-center justify-content-center">
            <span class="h3 text-primary fw-bold mb-0">Rp 1.200.000</span>
            <sub class="h6 text-muted mb-0 ms-1">/2 Month</sub>
          </div>
        </div>
      </div>
      <div class="card-body">
        <ul class="list-unstyled mb-0">
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Unlimited Domain</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Unlimited Auto Rotator Link</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Fallback URL / Link Cadangan</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>URL Cloacking via Negara</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Unlimited Shortlink Premium</li>
          <li><span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>Minimum 1 Menit Pengecekan</li>
        </ul>
        <div class="d-grid mt-4 pt-3">
          <a href="/auth/register" class="btn btn-label-primary">Start Sign Up Now</a>
        </div>
      </div>
    </div>
  </div>
</div>
 </div>
      </section>
      <!-- Pricing plans: End -->

      <!-- Fun facts: Start -->
      <section id="landingFunFacts" class="section-py landing-fun-facts">
        <div class="container">
          <div class="row gy-3">
            <div class="col-sm-6 col-lg-3">
              <div class="card border border-label-primary shadow-none">
                <div class="card-body text-center">
                  <img src="assets/img/front-pages/icons/laptop.png" alt="laptop" class="mb-2" />
                  <h5 class="h2 mb-1">772+</h5>
                  <p class="fw-medium mb-0">
                    Shortlink Active
 
                  </p>
                </div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="card border border-label-success shadow-none">
                <div class="card-body text-center">
                  <img src="assets/img/front-pages/icons/user-success.png" alt="laptop" class="mb-2" />
                  <h5 class="h2 mb-1">1k+</h5>
                  <p class="fw-medium mb-0">
                    Active Users<br />
                  
                  </p>
                </div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="card border border-label-info shadow-none">
                <div class="card-body text-center">
                  <img src="assets/img/front-pages/icons/diamond-info.png" alt="laptop" class="mb-2" />
                  <h5 class="h2 mb-1">4.8/5</h5>
                  <p class="fw-medium mb-0">
                    Highly Rated<br />
                  </p>
                </div>
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <div class="card border border-label-warning shadow-none">
                <div class="card-body text-center">
                  <img src="assets/img/front-pages/icons/check-warning.png" alt="laptop" class="mb-2" />
                  <h5 class="h2 mb-1">100%</h5>
                  <p class="fw-medium mb-0">
                    Money Back
                    Guarantee
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
      <!-- Fun facts: End -->

      <!-- FAQ: Start -->
      <section id="landingFAQ" class="section-py bg-body landing-faq">
        <div class="container">
          <div class="text-center mb-3 pb-1">
            <span class="badge bg-label-primary">FAQ</span>
          </div>
          <h3 class="text-center mb-1">Frequently asked <span class="section-title">questions</span></h3>
          <p class="text-center mb-5 pb-3">Browse through these FAQs to find answers to commonly asked questions.</p>
          <div class="row gy-5">
            <div class="col-lg-5">
              <div class="text-center">
                <img
                  src="assets/img/front-pages/landing-page/faq-boy-with-logos.png"
                  alt="faq boy with logos"
                  class="faq-image" />
              </div>
            </div>
            <div class="col-lg-7">
              <div class="accordion accordion-header-primary" id="accordionExample">
                <div class="card accordion-item active">
                  <h2 class="accordion-header" id="headingOne">
                    <button
                      type="button"
                      class="accordion-button"
                      data-bs-toggle="collapse"
                      data-bs-target="#accordionOne"
                      aria-expanded="true"
                      aria-controls="accordionOne">
                     Apa itu SFLINKID?
                    </button>
                  </h2>

                  <div id="accordionOne" class="accordion-collapse collapse show" data-bs-parent="#accordionExample">
                    <div class="accordion-body">
                    SFLINK dirancang untuk melakukan pengecekan setiap saat agar mengatur menjaga kestablian situs anda.
                    </div>
                  </div>
                </div>
                <div class="card accordion-item">
                  <h2 class="accordion-header" id="headingTwo">
                    <button
                      type="button"
                      class="accordion-button collapsed"
                      data-bs-toggle="collapse"
                      data-bs-target="#accordionTwo"
                      aria-expanded="false"
                      aria-controls="accordionTwo">
                      Apa saja yang tersedia disini?
                    </button>
                  </h2>
                  <div
                    id="accordionTwo"
                    class="accordion-collapse collapse"
                    aria-labelledby="headingTwo"
                    data-bs-parent="#accordionExample">
                    <div class="accordion-body">
                     kita menyediakan sebuah tools yaitu: Shortlink Premium, Auto Check Nawala Every Second, Auto Rotator Link, AntiDDoS Protection
                    </div>
                  </div>
                </div>
                <div class="card accordion-item">
                  <h2 class="accordion-header" id="headingThree">
                    <button
                      type="button"
                      class="accordion-button collapsed"
                      data-bs-toggle="collapse"
                      data-bs-target="#accordionThree"
                      aria-expanded="false"
                      aria-controls="accordionThree">
                      Auto Check Domain

                    </button>
                  </h2>
                  <div
                    id="accordionThree"
                    class="accordion-collapse collapse"
                    aria-labelledby="headingThree"
                    data-bs-parent="#accordionExample">
                    <div class="accordion-body">
    Robot kita melakukan pengecekan domain dengan realtime setiap saat agar link anda semakin terjaga, bot juga bisa di setting kirim via telegram juga.
                    </div>
                  </div>
                </div>
                <div class="card accordion-item">
                  <h2 class="accordion-header" id="headingFour">
                    <button
                      type="button"
                      class="accordion-button collapsed"
                      data-bs-toggle="collapse"
                      data-bs-target="#accordionFour"
                      aria-expanded="false"
                      aria-controls="accordionFour">
                      Auto Rotator Link?
                    </button>
                  </h2>
                  <div
                    id="accordionFour"
                    class="accordion-collapse collapse"
                    aria-labelledby="headingFour"
                    data-bs-parent="#accordionExample">
                    <div class="accordion-body">
                   Robot kita juga mempunyai auto rotator link yang dimana ketika link anda terblokir otomatis akan terganti ke link cadangan yang masih aman.
                    </div>
                  </div>
                </div>
                <div class="card accordion-item">
                  <h2 class="accordion-header" id="headingFive">
                    <button
                      type="button"
                      class="accordion-button collapsed"
                      data-bs-toggle="collapse"
                      data-bs-target="#accordionFive"
                      aria-expanded="false"
                      aria-controls="accordionFive">
                     Shortlink Premium?
                    </button>
                  </h2>
                  <div
                    id="accordionFive"
                    class="accordion-collapse collapse"
                    aria-labelledby="headingFive"
                    data-bs-parent="#accordionExample">
                    <div class="accordion-body">
                      Shortlink Robot premium juga ada sistem dimana ketika link keblokir, robot tidak akan mengirim/mengarahkan ke link yang diblokir (akan tetap ke link yang aman) cocok untuk menjaga kestabilan bisnis.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
      <!-- FAQ: End -->

      <!-- CTA: Start -->
      <section id="landingCTA" class="section-py landing-cta p-lg-0 pb-0">
        <div class="container">
          <div class="row align-items-center gy-5 gy-lg-0">
            <div class="col-lg-6 text-center text-lg-start">
              <h6 class="h2 text-primary fw-bold mb-1">Siap untuk mencoba?</h6>
              <p class="fw-medium mb-4">Silahkan langsung beralih ke halaman register klik tombol register sekarang!</p>
              <a href="auth/register" class="btn btn-primary">Register Now</a>
            </div>
            <div class="col-lg-6 pt-lg-5 text-center text-lg-end">
              <img
                src="img/banner.png" height="60%" width="60%" style="border-radius:15px;"
                alt="cta dashboard"
                class="img-fluid" />
            </div>
          </div>
        </div>
      </section>
      <!-- CTA: End -->

    
    </div>

    <!-- / Sections:End -->

    <!-- Footer: Start -->
    <footer class="landing-footer bg-body footer-text">
      <div class="footer-top">
        <div class="container">
          <div class="row gx-0 gy-4 g-md-5">
            <div class="col-lg-5">
             <a href="/" class="app-brand-link">
              <span class="app-brand-logo" >
                <img src="https://sflink.id/logo.png" width="20%"alt="SFLINK.ID" class="login-logo">
            </span></a>
              <p class="footer-text footer-logo-description mb-4">
               SFLINK merupakan sebuah tools yang akan menjaga link anda setiap saat, dengan layanan robot auto check nonstop ketika ada masalah apapun dengan link anda!
              </p>
              <form class="footer-form">
                <label for="footer-email" class="small">Subscribe to newsletter</label>
                <div class="d-flex mt-1">
                  <input
                    type="email"
                    class="form-control rounded-0 rounded-start-bottom rounded-start-top"
                    id="footer-email"
                    placeholder="Your email" />
                  <button
                    type="submit"
                    class="btn btn-primary shadow-none rounded-0 rounded-end-bottom rounded-end-top">
                    Subscribe
                  </button>
                </div>
              </form>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
        
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
              
            </div>
            <div class="col-lg-3 col-md-4">
              <h6 class="footer-title mb-4">Download our app</h6>
              <a href="javascript:void(0);" class="d-block footer-link mb-3 pb-2"
                ><img src="assets/img/front-pages/landing-page/apple-icon.png" alt="apple icon"
              /></a>
              <a href="javascript:void(0);" class="d-block footer-link"
                ><img src="assets/img/front-pages/landing-page/google-play-icon.png" alt="google play icon"
              /></a>
            </div>
          </div>
        </div>
      </div>
      <div class="footer-bottom py-3">
        <div
          class="container d-flex flex-wrap justify-content-between flex-md-row flex-column text-center text-md-start">
          <div class="mb-2 mb-md-0">
            <span class="footer-text"
              >©
              <script>
                document.write(new Date().getFullYear());
              </script>
            </span>
            <a href="/" target="_blank" class="fw-medium text-white footer-link">SFLINK.ID</a>
          </div>
          <div>
            <a href="#" class="footer-link me-3" target="_blank">
              <img
                src="assets/img/front-pages/icons/github-light.png"
                alt="github icon"
                data-app-light-img="front-pages/icons/github-light.png"
                data-app-dark-img="front-pages/icons/github-dark.png" />
            </a>
            <a href="#" class="footer-link me-3" target="_blank">
              <img
                src="assets/img/front-pages/icons/facebook-light.png"
                alt="facebook icon"
                data-app-light-img="front-pages/icons/facebook-light.png"
                data-app-dark-img="front-pages/icons/facebook-dark.png" />
            </a>
            <a href="#" class="footer-link me-3" target="_blank">
              <img
                src="assets/img/front-pages/icons/twitter-light.png"
                alt="twitter icon"
                data-app-light-img="front-pages/icons/twitter-light.png"
                data-app-dark-img="front-pages/icons/twitter-dark.png" />
            </a>
            <a href="#" class="footer-link" target="_blank">
              <img
                src="assets/img/front-pages/icons/instagram-light.png"
                alt="google icon"
                data-app-light-img="front-pages/icons/instagram-light.png"
                data-app-dark-img="front-pages/icons/instagram-dark.png" />
            </a>
          </div>
        </div>
      </div>
    </footer>
    <!-- Footer: End -->

    <!-- Core JS -->
    <!-- build:js assets/vendor/js/core.js -->
    <script src="assets/vendor/libs/popper/popper.js"></script>
    <script src="assets/vendor/js/bootstrap.js"></script>

    <!-- endbuild -->

    <!-- Vendors JS -->
    <script src="assets/vendor/libs/nouislider/nouislider.js"></script>
    <script src="assets/vendor/libs/swiper/swiper.js"></script>

    <!-- Main JS -->
    <script src="assets/js/front-main.js"></script>

    <!-- Page JS -->
    <script src="assets/js/front-page-landing.js"></script>
    
    
<script src="https://chat.oey.my/widget/widget-chat.js"></script>
  </body>
</html>

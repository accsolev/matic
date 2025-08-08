<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

// Pastikan user login
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';

// --- INIT VARIABEL
$successMessage = $errorMessage = '';
$domains = [];

// --- Ambil daftar domain aktif user (atau public)
$stmt = $pdo->query("SELECT domain FROM domains ORDER BY domain ASC");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $domains[] = $row['domain'];
}

// --- Proses SUBMIT (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Tangkap semua input
    $urls           = trim($_POST['urls'] ?? '');
    $alias          = trim($_POST['alias'] ?? '');
    $domain         = trim($_POST['domain'] ?? '');
    $fallback_urls  = trim($_POST['fallback_urls'] ?? '');
    $white_page     = trim($_POST['white_page'] ?? '');
    $allowed        = trim($_POST['allowed_countries'] ?? '');
    $blocked        = trim($_POST['blocked_countries'] ?? '');
    // Device targeting = array JSON
    $device_rules   = isset($_POST['device_target']) && is_array($_POST['device_target']) ? $_POST['device_target'] : [];

    // --- Validasi Minimal
    if (empty($urls)) {
        $errorMessage = "Destination URL wajib diisi!";
    } elseif (!empty($alias) && !preg_match('/^[a-zA-Z0-9-_]{3,30}$/', $alias)) {
        $errorMessage = "Alias hanya boleh huruf, angka, strip (-), dan underscore (_), 3-30 karakter!";
    } elseif (!empty($domain) && !in_array($domain, $domains)) {
        $errorMessage = "Domain tidak valid.";
    } else {
        // Cek duplikat alias
        if ($alias) {
            $cek = $pdo->prepare("SELECT COUNT(*) FROM links WHERE short_code=?");
            $cek->execute([$alias]);
            if ($cek->fetchColumn() > 0) {
                $errorMessage = "Alias sudah digunakan, pilih yang lain!";
            }
        }
    }

    // --- PROSES SIMPAN jika valid
    if (empty($errorMessage)) {
        // 1. Simpan link utama
        $userId = $_SESSION['user_id'];
        $shortCode = $alias ?: substr(md5(uniqid(rand(), true)), 0, 8);
        $chosenDomain = $domain ?: ($domains[0] ?? ''); // default pakai domain pertama

        $pdo->beginTransaction();
        try {
            // INSERT ke links
            $stmt = $pdo->prepare("INSERT INTO links (user_id, domain, short_code, white_page, allowed_countries, blocked_countries, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$userId, $chosenDomain, $shortCode, $white_page, $allowed, $blocked]);
            $linkId = $pdo->lastInsertId();

            // Multi destination
            $destUrls = array_filter(array_map('trim', explode("\n", $urls)));
            foreach ($destUrls as $url) {
                $pdo->prepare("INSERT INTO redirect_urls (link_id, url) VALUES (?,?)")
                    ->execute([$linkId, $url]);
            }

            // Fallback URLs
            if (!empty($fallback_urls)) {
                $fallbackArr = array_filter(array_map('trim', explode("\n", $fallback_urls)));
                foreach ($fallbackArr as $fb) {
                    $pdo->prepare("INSERT INTO fallback_urls (link_id, url) VALUES (?,?)")
                        ->execute([$linkId, $fb]);
                }
            }

            // Device Targeting (jika ada, format: [{"device":"mobile","url":"..."}])
            if (!empty($device_rules)) {
                foreach ($device_rules as $devRule) {
                    if (!empty($devRule['device']) && !empty($devRule['url'])) {
                        $pdo->prepare("INSERT INTO device_targets (link_id, device, url) VALUES (?,?,?)")
                            ->execute([$linkId, $devRule['device'], $devRule['url']]);
                    }
                }
            }

            $pdo->commit();
            $successMessage = "Berhasil menambahkan shortlink baru! <br>
                <strong>Link:</strong> <a href='{$chosenDomain}/{$shortCode}' target='_blank'>{$chosenDomain}/{$shortCode}</a>";
        } catch (Exception $ex) {
            $pdo->rollBack();
            $errorMessage = "Gagal menyimpan: " . $ex->getMessage();
        }
    }
}
?>

<!-- UI Shorten Link -->
<div class="row">
  <div class="col-xl-12 custome-width">
    <div id="app-sections">
      <!-- FORM SHORTEN NEW LINK -->
      <div class="card mb-4">
        <div class="card-body">
          <h4 class="mb-3">
           Shorten a New Link
          </h4>

          <!-- Success & Error Alert -->
          <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
              <?= $successMessage ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          <?php endif; ?>

          <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
              <?= $errorMessage ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          <?php endif; ?>

          <!-- Shorten Form -->
          <form method="POST" autocomplete="off">
            <div class="mb-3">
              <label for="destUrls">Destination URLs <small>(pisahkan dengan baris)</small></label>
              <textarea name="urls" class="form-control" rows="5" required placeholder="https://example1.com&#10;https://example2.com&#10;https://example3.com"></textarea>
            </div>
            <!-- Advanced Options (toggle) -->
            <div id="advancedOptions" class="d-none">
              <div class="mb-3">
                <label>Custom Alias <small>(opsional)</small></label>
                <input type="text" name="alias" class="form-control" placeholder="Contoh: linkgacor">
              </div>
              <div class="mb-3">
                <label>Pilih Domain</label>
                <select name="domain" class="form-select">
                  <?php foreach ($domains as $domain): ?>
                    <option value="<?= htmlspecialchars($domain) ?>"><?= htmlspecialchars($domain) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label>Fallback URLs <small>(satu URL per baris)</small></label>
                <textarea name="fallback_urls" class="form-control" rows="5" placeholder="https://example1.com&#10;https://example2.com"></textarea>
                <small class="text-muted">URL ini akan dipakai jika semua destination URL tidak aktif.</small>
              </div>
              <!-- Geotarget & Device (toggle) -->
              <div id="expertGeoLang" class="d-none mt-4">
                <h4>
                  <i class="fa-solid fa-robot"></i> FITUR CLOACKING 
                  <small>(Kosongin Jika Tidak Diperlukan)</small>
                </h4>
                <div class="mb-3">
                  <label>
                    White Page URL
                    <span data-bs-toggle="tooltip" title="Pengunjung dari negara yang diblokir atau tidak diizinkan akan diarahkan ke halaman ini.">
                      <i class="bi bi-question-circle-fill" style="color:#007bff;cursor:pointer;font-size:1rem;"></i>
                    </span>
                  </label>
                  <input type="url" name="white_page" class="form-control" placeholder="https://example.com/whitepage">
                </div>
                <div class="mb-3">
                  <label>
                    Allowed Country Codes
                    <span data-bs-toggle="tooltip" title="Masukkan kode negara yang diizinkan (misal: ID,US,MY). Pisahkan dengan koma. Negara yang dimasukan disini akan di arahkan ke url destination/fallback url.">
                      <i class="bi bi-question-circle-fill" style="color:#007bff;cursor:pointer;font-size:1rem;"></i>
                    </span>
                  </label>
                  <input type="text" name="allowed_countries" class="form-control" placeholder="ID,US,MY">
                </div>
                <div class="mb-3">
                  <label>
                    Blocked Country Codes
                    <span data-bs-toggle="tooltip" title="Semua kode negara yang dimasukkan di sini akan langsung diarahkan ke White Page.">
                      <i class="bi bi-question-circle-fill" style="color:#007bff;cursor:pointer;font-size:1rem;"></i>
                    </span>
                  </label>
                  <input type="text" name="blocked_countries" class="form-control" placeholder="RU,CN">
                </div>
                <div class="mb-3">
                  <label>
                    Device Targeting
                    <span data-bs-toggle="tooltip" title="Atur URL tujuan berbeda untuk Mobile, Desktop, atau Tablet.">
                      <i class="bi bi-question-circle-fill" style="color:#007bff;cursor:pointer;font-size:1rem;"></i>
                    </span>
                  </label>
                  <small class="form-text text-muted">Redirect sesuai perangkat (Mobile, Desktop, Tablet).</small>
                  <div id="deviceList"></div>
                  <button type="button" class="btn btn-sm btn-outline-primary" onclick="addDeviceRow()">+ Add Device Rule</button>
                </div>
              </div>
            </div>
            <!-- /Advanced Options -->

            <div class="d-flex justify-content-between flex-wrap gap-2">
              <button type="submit" class="btn btn-primary">
               Shorten Link
              </button>
              <button type="button" class="btn btn-secondary"
                onclick="
                  document.getElementById('advancedOptions').classList.toggle('d-none');
                  document.getElementById('expertGeoLang').classList.toggle('d-none');
                ">
                Expert Mode
              </button>
            </div>
          </form>
        </div><!-- .card-body -->
      </div><!-- .card -->
    </div><!-- #app-sections -->
  </div><!-- .col -->
</div><!-- .row -->

<script>
function addDeviceRow() {
  var container = document.getElementById('deviceList');
  var idx = container.children.length;
  var row = document.createElement('div');
  row.className = 'row mb-2 align-items-center device-row';
  row.innerHTML = `
    <div class="col-md-5">
      <select class="form-select" name="device_target[${idx}][device]" required>
        <option value="">Pilih Device</option>
        <option value="mobile">Mobile</option>
        <option value="desktop">Desktop</option>
        <option value="tablet">Tablet</option>
      </select>
    </div>
    <div class="col-md-6">
      <input type="url" class="form-control" name="device_target[${idx}][url]" placeholder="https://example.com/device-url" required>
    </div>
    <div class="col-md-1">
      <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.device-row').remove()">
        <i class="fa-solid fa-trash"></i>
      </button>
    </div>
  `;
  container.appendChild(row);
}
</script>

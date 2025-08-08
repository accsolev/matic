	<div class="row">
       <div class="col-xl-12 custome-width">
      <div class="card mb-4">
  <div class="card-body">
    <h5 class="mb-3"><i class="fa-solid fa-plus"></i> Tambahkan Domain</h5>
        <div class="alert alert-info" role="alert">
      📢 <strong>Panduan:</strong> Untuk melakukan pengecekan domain per menit:<br>
      1️⃣ Tambahkan <strong>ID Telegram</strong> kamu di halaman <a href="?menu=user-profile" id="nav-profile">User Profil</a><br>
      2️⃣ Tambahkan domain yang ingin dicek secara otomatis di bawah ini (bisa multiple, 1 per baris)<br>
      3️⃣ Atur interval dan status di menu <a href="?menu=set-timer" id="nav-settimer"><strong>Cron Job</strong></a><br>
      ✅ Setelah itu, bot akan mengirim hasil cek ke Telegram kamu sesuai pengaturan interval.
    </div>
     <div id="addDomainsMsg" class="mt-3"></div>
    <form id="addDomainsForm">
<textarea name="domains" id="inputDomainCheck" class="form-control" rows="5" placeholder="example.com&#10;example2.net" required></textarea>
<small class="text-muted">Tambahkan domain tanpa <code>https://</code> atau <code>http://</code></small>
<ul id="domainCheckMsg" class="list-group list-group-flush mt-2 small"></ul>
      <button class="btn btn-primary mt-2" type="submit">
        <i class="fa-solid fa-paper-plane"></i> Simpan Domain
      </button>
    </form>

    <hr>
<div class="d-flex align-items-center justify-content-between mt-4 mb-2">
  <h5 class="mb-0">
    <i class="fa-solid fa-list"></i>
    Daftar Domain Anda (Status Bot: <span id="statusbot"></span>)
  </h5>
  <div>
    <button type="button" id="toggleBotBtn" class="btn btn-success">Aktifkan Bot Check</button>
  </div>
</div>
<!-- Ganti UL dengan table -->
<div class="white-block">

<div class="table-responsive">
  <table class="table table-bordered">
    <thead class="users-table-info">
      <tr>
        <th>
          <div class="d-flex align-items-center justify-content-between">
            <span>Domain</span>
            <button id="copyAllDomainsBtn" type="button" class="btn btn-sm btn-outline-primary ms-2" title="Copy semua domain">
              <i class="fa fa-copy"> Copy All</i>
            </button>
          </div>
        </th>
        <th>Status</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody id="userDomainList">
      <!-- Baris domain akan di-render di sini -->
    </tbody>
  </table>
</div>
</div>
  </div>
</div>

<script>
  // Utility: ambil hostname saja
  function extractDomain(url) {
    return url.trim()
      .replace(/^https?:\/\//i, '')
      .replace(/^www\./i, '')
      .replace(/\/.*$/, '');
  }

  // Cek TrustPositif via checklist.php, update teks di kolom Status
  function checkListStatus(domain, cell) {
    const d = extractDomain(domain);
    fetch('/dashboard/ajax/checklist.php?domain=' + encodeURIComponent(d))
      .then(res => res.json())
      .then(data => {
        let txt, color;
        if (data.status === 'blocked') {
          txt   = 'Diblokir';
          color = 'red';
        } else if (data.status === 'safe') {
          txt   = 'Aman';
          color = 'green';
        } else {
          txt   = 'Tidak diketahui';
          color = 'orange';
        }
        cell.textContent      = txt;
        cell.style.color      = color;
        cell.style.fontWeight = 'bold';
      })
      .catch(() => {
        cell.textContent      = 'Gagal cek';
        cell.style.color      = 'darkred';
        cell.style.fontWeight = 'bold';
      });
  }

document.getElementById('addDomainsForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  const msgBox = document.getElementById('addDomainsMsg');
  const textarea = form.querySelector('textarea[name="domains"]');
  const inputLines = textarea.value.split('\n').map(x => x.trim()).filter(Boolean);

  // ✅ Regex domain validator
  const validDomainRegex = /^[a-z0-9\-\.]+\.[a-z]{2,}$/i;

  // ❌ Cek apakah semua input valid domain
  const invalidEntries = inputLines.filter(domain => !validDomainRegex.test(domain.replace(/^https?:\/\//i, '')));

  if (invalidEntries.length > 0) {
    msgBox.className = 'alert alert-danger';
    msgBox.innerHTML = '❌ Hanya domain yang dapat didaftarkan!<br>Kesalahan pada:<br><code>' + invalidEntries.join('</code><br><code>') + '</code>';
    return;
  }

  fetch('/dashboard/ajax/add-domains.php', {
    method: 'POST',
    body: formData
  })
  .then(async res => {
    const contentType = res.headers.get("content-type");
    if (!res.ok || !contentType || !contentType.includes("application/json")) {
      throw new Error("Bukan response JSON");
    }
    return res.json();
  })
  .then(data => {
    msgBox.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger');
    msgBox.innerHTML = data.message;
    loadUserDomains();
    if (data.success) form.reset();
  })
  .catch(err => {
    console.error(err);
    msgBox.className = 'alert alert-danger';
    msgBox.textContent = '❌ Terjadi kesalahan jaringan/Domain sudah digunakan.';
  });
});
// Copy semua domain dari tabel
document.getElementById('copyAllDomainsBtn').addEventListener('click', function() {
  const rows = document.querySelectorAll('#userDomainList tr td:first-child');
  const allDomains = Array.from(rows)
    .map(td => td.textContent.trim())
    .filter(x => x)
    .join('\n');
  if (allDomains) {
    navigator.clipboard.writeText(allDomains).then(() => {
      this.innerHTML = '<i class="fa fa-check"></i>';
      setTimeout(() => {
        this.innerHTML = '<i class="fa fa-copy"></i>';
      }, 1200);
    });
  } else {
    alert("Belum ada domain untuk disalin.");
  }
});

function loadUserDomains() {
  fetch('/dashboard/ajax/get-domains.php')
    .then(res => res.json())
    .then(data => {
      const tbody = document.getElementById('userDomainList');
      tbody.innerHTML = '';
      if (data.success && data.domains.length) {
        data.domains.forEach(d => {
          const tr = document.createElement('tr');
          tr.setAttribute('data-id', d.id);

          // Kolom Domain + Tombol Copy
          const tdDomain = document.createElement('td');
          tdDomain.className = "d-flex align-items-center gap-2";
          tdDomain.textContent = d.domain;

          // Tombol Copy
          const copyBtn = document.createElement('button');
          copyBtn.type = "button";
          copyBtn.className = "btn btn-sm btn-outline-primary ms-2";
          copyBtn.innerHTML = '<i class="fa fa-copy"></i>';
          copyBtn.title = "Copy domain";
          copyBtn.onclick = () => {
            navigator.clipboard.writeText(d.domain)
              .then(() => {
                copyBtn.innerHTML = '<i class="fa fa-check"></i>';
                setTimeout(() => {
                  copyBtn.innerHTML = '<i class="fa fa-copy"></i>';
                }, 1200);
              })
              .catch(() => alert("Gagal menyalin domain"));
          };
          tdDomain.appendChild(copyBtn);

          // Status placeholder
          const tdStatus = document.createElement('td');
          tdStatus.textContent = 'Loading…';

          // Aksi (delete)
          const tdAksi = document.createElement('td');
          const btn = document.createElement('button');
          btn.type      = 'button';
          btn.className = 'btn btn-sm btn-danger';
          btn.innerHTML = '<i class="fa-solid fa-trash"></i>';
          btn.onclick   = () => deleteDomain(d.id, tr);
          tdAksi.appendChild(btn);

          tr.append(tdDomain, tdStatus, tdAksi);
          tbody.appendChild(tr);

          checkListStatus(d.domain, tdStatus);
        });

        // Destroy Sortable sebelumnya kalau ada (prevent dobel inisialisasi)
        if (window.domainSortable) {
          window.domainSortable.destroy();
        }
        window.domainSortable = Sortable.create(tbody, {
          animation: 150,
          onEnd: function () {
            const ids = [...tbody.querySelectorAll('tr')].map(tr => tr.getAttribute('data-id'));
            fetch('/dashboard/ajax/save-domain-order.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ order: ids })
            });
          }
        });

      } else {
        tbody.innerHTML = `
          <tr>
            <td colspan="3" class="text-center text-muted">
              Belum ada domain.
            </td>
          </tr>`;
      }
    })
    .catch(() => {
      document.getElementById('userDomainList').innerHTML = `
        <tr>
          <td colspan="3" class="text-center text-danger">
            Gagal memuat domain.
          </td>
        </tr>`;
    });
}
// Jalankan hanya 1x saat DOM siap
document.addEventListener('DOMContentLoaded', loadUserDomains);
// Setelah loadUserDomains() selesai render
if (window.domainSortable) {
  window.domainSortable.destroy();
}
window.domainSortable = Sortable.create(document.getElementById('userDomainList'), {
  animation: 150,
  onEnd: function () {
    // Ambil urutan ID terbaru
    const ids = [...document.querySelectorAll('#userDomainList tr')].map(tr => tr.getAttribute('data-id'));
    // Kirim ke server via fetch
    fetch('/dashboard/ajax/save-domain-order.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order: ids })
    });
  }
});



  // Hapus domain
  function deleteDomain(id, row) {
    if (!confirm('Hapus domain ini?')) return;
    fetch('/dashboard/ajax/delete-domain.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    'id=' + encodeURIComponent(id)
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        row.remove();
      } else {
        alert(data.message);
      }
    })
    .catch(() => alert('Gagal menghapus domain.'));
  }

  document.addEventListener('DOMContentLoaded', loadUserDomains);
</script>

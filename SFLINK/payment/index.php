<!DOCTYPE html>
<html>
<head>
  <title>Pembayaran</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-5">
  <h2>Pembayaran</h2>
  <form method="POST" action="payments/index.php">
    <div class="mb-3">
      <label>Nama</label>
      <input type="text" name="customer_name" required class="form-control">
    </div>
    <div class="mb-3">
      <label>Email</label>
      <input type="email" name="customer_email" required class="form-control">
    </div>
    <div class="mb-3">
      <label>Nominal</label>
      <input type="number" name="amount" required class="form-control">
    </div>
    <div class="mb-3">
      <label>Metode Pembayaran</label>
      <select name="method" class="form-select" required>
        <option value="QRIS">QRIS</option>
        <option value="BNIVA">Virtual Account BNI</option>
        <option value="MANDIRIVA">Virtual Account Mandiri</option>
        <option value="BRIVA">Virtual Account BRI</option>
        <option value="BCAVA">Virtual Account BCA</option>
      </select>
    </div>
    <button class="btn btn-primary">Bayar Sekarang</button>
  </form>
</body>
</html>
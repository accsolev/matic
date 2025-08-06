const express = require('express');
const router = express.Router();
const QRCode = require('qrcode'); // Pastikan library ini sudah diinstal: npm install qrcode

router.get('/', async (req, res) => {
  
  const textToEncode = req.query.url;

  if (!textToEncode) {
    
    return res.status(400).json({ success: false, message: 'Parameter "url" (teks untuk di-encode) wajib diisi.' });
  }

  try {
    const dataUrl = await QRCode.toDataURL(textToEncode);
    
    res.json({
        success: true,
        dataUrl: dataUrl,
        message: 'QR Code berhasil dibuat.'
    });
  } catch (err) {
    console.error('[API /api/qrcode] Gagal membuat QR Code:', err);
    res.status(500).json({ success: false, message: 'Gagal membuat QR Code.' });
  }
});

module.exports = router;

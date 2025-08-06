const express = require('express');
const { exec } = require('child_process');
const router = express.Router();

router.get('/', async (req, res) => {
  const domain = req.query.domain;

  if (!domain) {
    return res.status(400).json({
      error: 'Parameter "domain" dibutuhkan, contoh: ?domain=example.com',
    });
  }

  const cmd = `subfinder -d ${domain} -silent`;

  exec(cmd, (error, stdout, stderr) => {
    if (error) {
      return res.status(500).json({
        error: 'Gagal menjalankan subfinder',
        detail: stderr || error.message,
      });
    }

    const subdomains = stdout
      .split('\n')
      .filter((line) => line.trim() !== '');

    res.json({
      domain,
      total: subdomains.length,
      subdomains,
    });
  });
});

module.exports = router;
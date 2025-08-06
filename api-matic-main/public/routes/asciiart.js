const express = require('express');
const router = express.Router();
const figlet = require('figlet');

router.get('/', async (req, res) => {
    const inputText = req.query.text;
    const font = req.query.font || 'Standard'; 

    if (!inputText) {
        
        return res.status(400).send('Parameter "text" wajib diisi.');
    }

    
    figlet.text(inputText, {
        font: font
    }, function(err, data) {
        if (err) {
            console.error('Error saat membuat ASCII Art:', err);
            
            if (err.message && err.message.includes('font')) {
                 return res.status(400).send(`Error: Font '${font}' tidak ditemukan atau tidak valid.`);
            }
            return res.status(500).send('Terjadi kesalahan server internal saat membuat ASCII Art.');
        }
        
        
        res.status(200).set('Content-Type', 'text/plain').send(data);
    });
});

router.get('/fonts', (req, res) => {
    figlet.fonts(function(err, fonts) {
        if (err) {
            console.error('Error saat mengambil daftar font Figlet:', err);
            return res.status(500).json({
                success: false,
                message: 'Terjadi kesalahan server internal saat mengambil daftar font.',
                error: err.message
            });
        }
        
        res.status(200).json({
            success: true,
            fonts: fonts
        });
    });
});

module.exports = router;


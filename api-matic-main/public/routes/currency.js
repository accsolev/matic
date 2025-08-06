const express = require('express');
const router = express.Router();


const BASE_URL = `https://api.frankfurter.dev/v1`;

router.get('/', async (req, res) => { // <== Endpoint diubah dari '/convert' menjadi '/'
    const { amount, from, to } = req.query; // Menggunakan req.query karena ini GET request

    if (!amount || !from || !to) {
        return res.status(400).json({
            success: false,
            message: 'Parameter "amount", "from", dan "to" wajib diisi.'
        });
    }

    if (isNaN(parseFloat(amount))) {
        return res.status(400).json({
            success: false,
            message: 'Parameter "amount" harus berupa angka yang valid.'
        });
    }

    try {
        
        const response = await fetch(`${BASE_URL}/latest?amount=${amount}&from=${from.toUpperCase()}&to=${to.toUpperCase()}`);
        const data = await response.json();

        if (response.status !== 200 || data.error) {
            
            return res.status(response.status).json({
                success: false,
                message: `Error dari Frankfurter API: ${data.error || 'Terjadi kesalahan tidak dikenal.'}`,
                details: data
            });
        }

        const exchangeRate = data.rates[to.toUpperCase()];
        if (!exchangeRate) {
            return res.status(404).json({
                success: false,
                message: `Nilai tukar untuk mata uang tujuan (${to.toUpperCase()}) tidak ditemukan.`,
                details: data
            });
        }
        
        const convertedAmount = parseFloat(amount) * exchangeRate;

        res.status(200).json({
            success: true,
            originalAmount: parseFloat(amount),
            fromCurrency: from.toUpperCase(),
            toCurrency: to.toUpperCase(),
            exchangeRate: exchangeRate,
            convertedAmount: parseFloat(convertedAmount.toFixed(2)) // Pembulatan 2 desimal
        });

    } catch (error) {
        console.error('Error saat melakukan konversi mata uang dengan Frankfurter:', error.message);
        res.status(500).json({
            success: false,
            message: 'Terjadi kesalahan server internal saat konversi mata uang.',
            error: error.message
        });
    }
});

router.get('/supported', async (req, res) => { // <== Endpoint tetap '/supported'
    try {
        const response = await fetch(`${BASE_URL}/currencies`); // Endpoint untuk daftar mata uang
        const data = await response.json();

        if (response.status !== 200) {
            return res.status(response.status).json({
                success: false,
                message: `Error dari Frankfurter API: ${data.error || 'Terjadi kesalahan tidak dikenal.'}`,
                details: data
            });
        }
        
        
        const supportedCurrencies = Object.keys(data).map(code => ({
            code: code,
            name: data[code]
        }));

        res.status(200).json({
            success: true,
            currencies: supportedCurrencies
        });

    } catch (error) {
        console.error('Error saat mengambil daftar mata uang yang didukung dari Frankfurter:', error.message);
        res.status(500).json({
            success: false,
            message: 'Terjadi kesalahan server internal saat mengambil daftar mata uang.',
            error: error.message
        });
    }
});

module.exports = router;


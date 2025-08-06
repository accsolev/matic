const express = require('express');
const router = express.Router();
const QRISPayment = require('qris-payment'); // Asumsi library ini bisa digunakan untuk GoPay
const fs = require('fs');

// Kredensial GoPay dari .env (hanya API Key dan Logo Path)
const GOPAY_API_KEY = process.env.GOPAY_API_KEY || 'YOUR_GOPAY_API_KEY'; // API Key GoPay Anda
const GOPAY_LOGO_PATH = process.env.GOPAY_LOGO_PATH || 'https://qris.orderkuota.com/qrcode/gopay_logo.png'; // Sesuaikan logo GoPay

router.post('/', async (req, res) => {
    let paymentReference;
    let qrImageDataUrl;
    let finalHttpStatus = 200;

    try {
        const { storeName, merchantId, baseQrString, amount } = req.body; // Ambil semua dari request body

        // Validasi input dari pengguna
        if (!storeName) {
            console.error('[gopay-qris] Validation Error: storeName is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'storeName is required.' });
        }
        if (!merchantId) {
            console.error('[gopay-qris] Validation Error: merchantId is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'merchantId is required.' });
        }
        if (!baseQrString) {
            console.error('[gopay-qris] Validation Error: baseQrString is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'baseQrString is required.' });
        }
        if (!amount || typeof amount !== 'number' || amount <= 0) {
            console.error('[gopay-qris] Validation Error: Amount is missing or invalid.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'Amount is required and must be a positive number.' });
        }
        
        paymentReference = 'GOPAY-' + Date.now(); // Reference khusus GoPay

        console.log(`[gopay-qris] Creating GoPay QRIS payment for amount: ${amount}, reference: ${paymentReference}.`);

        // --- Asumsi: QRISPayment library dapat digunakan untuk GoPay ---
        // Jika tidak, Anda HARUS mengganti ini dengan library GoPay/Midtrans/Doku Anda.
        const gopayIntegration = new QRISPayment({
            storeName: storeName,
            merchantId: merchantId,
            baseQrString: baseQrString,
            apiKey: GOPAY_API_KEY, // API Key GoPay dari .env
            logoPath: GOPAY_LOGO_PATH
        });

        // HANYA Generate QR code (GoPay Specific)
        let qrResult;
        try {
            qrResult = await gopayIntegration.generateQR(amount, paymentReference);
            qrImageDataUrl = `data:image/png;base64,${qrResult.qrBuffer.toString('base64')}`;
        } catch (gopayError) {
            console.error(`[gopay-qris] GoPay QR Generation Error: ${gopayError.message}`);
            finalHttpStatus = 500;
            return res.status(finalHttpStatus).json({ success: false, message: 'Failed to generate GoPay QR code.', error: gopayError.message });
        }
        
        console.log(`[gopay-qris] GoPay QR generated for reference: ${paymentReference}. Responding instantly.`);

        // Mengirim respons instan dengan status PENDING dan QR
        res.status(200).json({
            success: true,
            message: 'GoPay payment QR generated successfully.',
            data: {
                reference: paymentReference,
                amount: amount,
                transactionId: qrResult ? qrResult.transactionId : null,
                qr_image_data_url: qrImageDataUrl, // URL QR GoPay
                status: 'PENDING', // Status awal, belum ada cek pembayaran
                storeNameUsed: storeName,
                merchantIdUsed: merchantId,
                baseQrStringUsed: baseQrString
            },
            error: null
        });

    } catch (error) {
        console.error(`[gopay-qris] Unhandled Error in API endpoint: ${error.message}`);
        res.status(500).json({ success: false, message: 'Failed to process GoPay payment.', error: error.message });
    }
});

module.exports = router;

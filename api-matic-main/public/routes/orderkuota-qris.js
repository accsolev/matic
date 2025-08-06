const express = require('express');
const router = express.Router();
const QRISPayment = require('qris-payment');
const fs = require('fs');

const API_KEY_QRIS = process.env.QRIS_API_KEY || 'YOUR_API_KEY';
const QRIS_LOGO_PATH = process.env.QRIS_LOGO_PATH || 'https://qris.orderkuota.com/qrcode/qris_logo.png';

router.post('/', async (req, res) => {
    let paymentReference;
    let qrImageDataUrl;
    let finalHttpStatus = 200;

    try {
        const { storeName, merchantId, baseQrString, amount } = req.body;

        if (!storeName) {
            console.error('[orderkuota-qris] Validation Error: storeName is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'storeName is required.' });
        }
        if (!merchantId) {
            console.error('[orderkuota-qris] Validation Error: merchantId is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'merchantId is required.' });
        }
        if (!baseQrString) {
            console.error('[orderkuota-qris] Validation Error: baseQrString is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'baseQrString is required.' });
        }
        if (!amount || typeof amount !== 'number' || amount <= 0) {
            console.error('[orderkuota-qris] Validation Error: Amount is missing or invalid.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'Amount is missing or invalid.' });
        }
        
        paymentReference = 'REF' + Date.now();

        console.log(`[orderkuota-qris] Creating QRIS payment for amount: ${amount}, reference: ${paymentReference}.`);

        const qris = new QRISPayment({
            storeName: storeName,
            merchantId: merchantId,
            baseQrString: baseQrString,
            apiKey: API_KEY_QRIS,
            logoPath: QRIS_LOGO_PATH
        });

        // HANYA Generate QR code
        let qrResult;
        try {
            qrResult = await qris.generateQR(amount, paymentReference);
            qrImageDataUrl = `data:image/png;base64,${qrResult.qrBuffer.toString('base64')}`;
        } catch (qrGenError) {
            console.error(`[orderkuota-qris] QR Generation Error: ${qrGenError.message}`);
            finalHttpStatus = 500;
            return res.status(finalHttpStatus).json({ success: false, message: 'Failed to generate QR code.', error: qrGenError.message });
        }
        
        console.log(`[orderkuota-qris] QR generated for reference: ${paymentReference}. Responding instantly.`);

        // Mengirim respons instan dengan status PENDING dan QR
        res.status(200).json({
            success: true,
            message: 'QRIS payment QR generated successfully.',
            data: {
                reference: paymentReference,
                amount: amount,
                transactionId: qrResult.transactionId,
                qr_image_data_url: qrImageDataUrl, // Data URL QR
                status: 'PENDING', // Status awal, belum ada cek pembayaran
                storeNameUsed: storeName,
                merchantIdUsed: merchantId,
                baseQrStringUsed: baseQrString
            },
            error: null
        });

    } catch (error) {
        console.error(`[orderkuota-qris] Unhandled Error in API endpoint: ${error.message}`);
        res.status(500).json({ success: false, message: 'Failed to process payment.', error: error.message });
    }
});

module.exports = router;
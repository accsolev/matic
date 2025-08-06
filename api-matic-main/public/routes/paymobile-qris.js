const express = require('express');
const router = express.Router();
const QRISPayment = require('qris-payment');
const fs = require('fs');

const PAYMOBILE_API_KEY = process.env.PAYMOBILE_API_KEY || 'YOUR_PAYMOBILE_API_KEY';
const PAYMOBILE_LOGO_PATH = process.env.PAYMOBILE_LOGO_PATH || 'https://qris.orderkuota.com/qrcode/paymobile_logo.png';

router.post('/', async (req, res) => {
    let paymentReference;
    let qrImageDataUrl;
    let finalHttpStatus = 200;

    try {
        const { storeName, merchantId, baseQrString, amount } = req.body;

        if (!storeName) {
            console.error('[paymobile-qris] Validation Error: storeName is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'storeName is required.' });
        }
        if (!merchantId) {
            console.error('[paymobile-qris] Validation Error: merchantId is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'merchantId is required.' });
        }
        if (!baseQrString) {
            console.error('[paymobile-qris] Validation Error: baseQrString is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'baseQrString is required.' });
        }
        if (!amount || typeof amount !== 'number' || amount <= 0) {
            console.error('[paymobile-qris] Validation Error: Amount is missing or invalid.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'Amount is required and must be a positive number.' });
        }
        
        paymentReference = 'PAYMOBILE-' + Date.now();

        console.log(`[paymobile-qris] Creating Paymobile QRIS payment for amount: ${amount}, reference: ${paymentReference}.`);

        const paymobileIntegration = new QRISPayment({
            storeName: storeName,
            merchantId: merchantId,
            baseQrString: baseQrString,
            apiKey: PAYMOBILE_API_KEY,
            logoPath: PAYMOBILE_LOGO_PATH
        });

        let qrResult;
        try {
            qrResult = await paymobileIntegration.generateQR(amount, paymentReference);
            qrImageDataUrl = `data:image/png;base64,${qrResult.qrBuffer.toString('base64')}`;
        } catch (paymobileError) {
            console.error(`[paymobile-qris] Paymobile QR Generation Error: ${paymobileError.message}`);
            finalHttpStatus = 500;
            return res.status(finalHttpStatus).json({ success: false, message: 'Failed to generate Paymobile QR code.', error: paymobileError.message });
        }
        
        console.log(`[paymobile-qris] Paymobile QR generated for reference: ${paymentReference}. Responding instantly.`);

        res.status(200).json({
            success: true,
            message: 'Paymobile payment QR generated successfully.',
            data: {
                reference: paymentReference,
                amount: amount,
                transactionId: qrResult ? qrResult.transactionId : null,
                qr_image_data_url: qrImageDataUrl,
                status: 'PENDING',
                storeNameUsed: storeName,
                merchantIdUsed: merchantId,
                baseQrStringUsed: baseQrString
            },
            error: null
        });

    } catch (error) {
        console.error(`[paymobile-qris] Unhandled Error in API endpoint: ${error.message}`);
        res.status(500).json({ success: false, message: 'Failed to process Paymobile payment.', error: error.message });
    }
});

module.exports = router;

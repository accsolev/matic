const express = require('express');
const router = express.Router();
const QRISPayment = require('qris-payment');
const fs = require('fs');

const DANA_API_KEY = process.env.DANA_API_KEY || 'YOUR_DANA_API_KEY';
const DANA_LOGO_PATH = process.env.DANA_LOGO_PATH || 'https://qris.orderkuota.com/qrcode/dana_logo.png';

router.post('/', async (req, res) => {
    let paymentReference;
    let qrImageDataUrl;
    let finalHttpStatus = 200;

    try {
        const { storeName, merchantId, baseQrString, amount } = req.body;

        if (!storeName) {
            console.error('[dana-qris] Validation Error: storeName is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'storeName is required.' });
        }
        if (!merchantId) {
            console.error('[dana-qris] Validation Error: merchantId is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'merchantId is required.' });
        }
        if (!baseQrString) {
            console.error('[dana-qris] Validation Error: baseQrString is missing.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'baseQrString is required.' });
        }
        if (!amount || typeof amount !== 'number' || amount <= 0) {
            console.error('[dana-qris] Validation Error: Amount is missing or invalid.');
            finalHttpStatus = 400;
            return res.status(finalHttpStatus).json({ success: false, message: 'Amount is required and must be a positive number.' });
        }
        
        paymentReference = 'DANA-' + Date.now();

        console.log(`[dana-qris] Creating Dana QRIS payment for amount: ${amount}, reference: ${paymentReference}.`);

        const danaIntegration = new QRISPayment({
            storeName: storeName,
            merchantId: merchantId,
            baseQrString: baseQrString,
            apiKey: DANA_API_KEY,
            logoPath: DANA_LOGO_PATH
        });

        let qrResult;
        try {
            qrResult = await danaIntegration.generateQR(amount, paymentReference);
            qrImageDataUrl = `data:image/png;base64,${qrResult.qrBuffer.toString('base64')}`;
        } catch (danaError) {
            console.error(`[dana-qris] Dana QR Generation Error: ${danaError.message}`);
            finalHttpStatus = 500;
            return res.status(finalHttpStatus).json({ success: false, message: 'Failed to generate Dana QR code.', error: danaError.message });
        }
        
        console.log(`[dana-qris] Dana QR generated for reference: ${paymentReference}. Responding instantly.`);

        res.status(200).json({
            success: true,
            message: 'Dana payment QR generated successfully.',
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
        console.error(`[dana-qris] Unhandled Error in API endpoint: ${error.message}`);
        res.status(500).json({ success: false, message: 'Failed to process Dana payment.', error: error.message });
    }
});

module.exports = router;

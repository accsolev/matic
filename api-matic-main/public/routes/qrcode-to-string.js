const express = require('express');
const router = express.Router();
const multer = require('multer');
const Jimp = require('jimp').Jimp;
const jsQR = require('jsqr');
const fs = require('fs');

const upload = multer({ dest: 'uploads/' });

router.post('/', upload.single('qrImage'), async (req, res) => {
    try {
        if (!req.file) {
            return res.status(400).json({
                success: false,
                message: 'No QR image file uploaded.',
                error: 'File is required.'
            });
        }

        const { path: tempFilePath, originalname, mimetype } = req.file;

        let qrString = null;
        try {
            const image = await Jimp.read(tempFilePath);
            
            const imageData = {
                data: new Uint8ClampedArray(image.bitmap.data),
                width: image.bitmap.width,
                height: image.bitmap.height,
            };

            const code = jsQR(imageData.data, imageData.width, imageData.height);

            if (code && code.data) {
                qrString = code.data;
            } else {
                fs.unlink(tempFilePath, (err) => {
                    if (err) console.error(`[QR to String] Failed to delete temp file (decode fail):`, err);
                });
                return res.status(400).json({
                    success: false,
                    message: 'No QR code found or could not be decoded from the image.',
                    error: 'QR code not found or unreadable.'
                });
            }
        } catch (imageProcessError) {
            console.error(`[QR to String] Image processing or decoding error for ${originalname}: ${imageProcessError.message}`);
            fs.unlink(tempFilePath, (err) => {
                if (err) console.error(`[QR to String] Failed to delete temp file (process error):`, err);
            });
            return res.status(500).json({
                success: false,
                message: 'Failed to process QR image.',
                error: imageProcessError.message
            });
        } finally {
            if (fs.existsSync(tempFilePath)) {
                fs.unlink(tempFilePath, (err) => {
                    if (err) console.error(`[QR to String] Final cleanup failed to delete temp file:`, err);
                });
            }
        }

        res.json({
            success: true,
            message: 'QR Code decoded successfully.',
            data: {
                filename: originalname,
                mimetype: mimetype,
                decodedString: qrString
            }
        });

    } catch (error) {
        console.error(`[QR to String] Unhandled error: ${error.message}`);
        res.status(500).json({
            success: false,
            message: 'Internal server error.',
            error: error.message
        });
    }
});

module.exports = router;
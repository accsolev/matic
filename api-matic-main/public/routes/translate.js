require('dotenv').config();

const express = require('express');
const router = express.Router();
const deepl = require('deepl-node');

const authKey = process.env.DEEPL_AUTH_KEY;

if (!authKey) {
    console.error("DeepL API key tidak ditemukan di file .env. Harap atur DEEPL_AUTH_KEY.");
    // Menghentikan aplikasi jika API key tidak ada, karena ini adalah dependensi krusial
    process.exit(1);
}

const translator = new deepl.Translator(authKey);

router.post('/', async (req, res) => { // Endpoint untuk terjemahan utama adalah '/'
    const { text, targetLang, sourceLang } = req.body;

    // Validasi input
    if (!text || !targetLang || !sourceLang) {
        return res.status(400).json({
            success: false,
            message: 'Parameter "text", "targetLang", dan "sourceLang" wajib diisi.'
        });
    }

    try {
        const options = {
            sourceLang: sourceLang,
            // Anda bisa menambahkan opsi lain di sini sesuai dokumentasi deepl-node
        };

        const result = await translator.translateText(text, options.sourceLang, targetLang, options);

        res.status(200).json({
            success: true,
            originalText: text,
            translatedText: result.text,
            detectedSourceLang: result.detectedSourceLang,
            targetLang: targetLang
        });

    } catch (error) {
        console.error('Error saat menerjemahkan teks dengan DeepL:', error.message);

        if (error.response && error.response.status) {
            return res.status(error.response.status).json({
                success: false,
                message: `DeepL API Error: ${error.message}`,
                statusCode: error.response.status
            });
        }
        res.status(500).json({
            success: false,
            message: 'Terjadi kesalahan server internal saat terjemahan.',
            error: error.message
        });
    }
});

router.get('/supported-languages', async (req, res) => {
    try {
        const sourceLanguages = await translator.getSourceLanguages();
        const targetLanguages = await translator.getTargetLanguages();

        res.status(200).json({
            success: true,
            sourceLanguages: sourceLanguages.map(lang => ({ code: lang.code, name: lang.name })),
            targetLanguages: targetLanguages.map(lang => ({ code: lang.code, name: lang.name }))
        });
    } catch (error) {
        console.error('Error saat mengambil bahasa yang didukung dari DeepL:', error.message);
        res.status(500).json({
            success: false,
            message: 'Terjadi kesalahan server internal saat mengambil bahasa yang didukung.',
            error: error.message
        });
    }
});

module.exports = router;


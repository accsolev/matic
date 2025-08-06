const { processApiRequest } = require('../../lib/orderkuota-logic.js');

module.exports = async function(req, res) {
    try {
        // Ambil parameter yang dibutuhkan dari body request
        const { username, authToken, amount } = req.body;

        // Validasi parameter
        if (!username || !authToken || !amount) {
            return res.status(400).json({ success: false, message: "Parameters 'username', 'authToken', and 'amount' are required." });
        }

        // Siapkan body untuk dikirim ke processApiRequest
        const requestBody = { 
            action: 'buat_qris_ajaib',
            username: username,
            authToken: authToken,
            amount: amount
        };

        // Panggil fungsi logika utama
        const result = await processApiRequest(requestBody);

        // Kirim respons berdasarkan hasil dari processApiRequest
        if (result.success === false) {
            res.status(400).json(result);
        } else {
            res.status(200).json(result);
        }
    } catch (error) {
        // Tangani error tak terduga di server
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
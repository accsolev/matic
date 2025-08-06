const { OrderKuotaChecker } = require('../../lib/orderkuota-checker-logic.js');

module.exports = async function(req, res) {
    try {
        const { user_id } = req.body;

        if (!user_id) {
            return res.status(400).json({ success: false, message: "Parameter 'user_id' is required." });
        }
        
        const checkerApi = new OrderKuotaChecker();
        const result = await checkerApi.checkGameId('ml', user_id);

        // --- BLOK LOGIKA BARU UNTUK MENGUBAH PESAN ERROR ---
        if (result?.status === 'failed' && result?.message === 'Update aplikasi kamu untuk melanjutkan transaksi.') {
            return res.status(400).json({
                status: "failed",
                message: "AKUN TIDAK TERDAFTAR/SALAH ID"
            });
        }
        // ----------------------------------------------------

        if (result?.status === 'success' || result?.success === true) {
            res.status(200).json(result);
        } else {
            res.status(400).json(result);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
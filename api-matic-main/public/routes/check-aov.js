const { OrderKuotaChecker } = require('../../lib/orderkuota-checker-logic.js');

module.exports = async function(req, res) {
    try {
        const { user_id } = req.body;
        const gameCode = 'aov';

        if (!user_id) {
            return res.status(400).json({ success: false, message: "Parameter 'user_id' is required." });
        }
        
        const checkerApi = new OrderKuotaChecker();
        const result = await checkerApi.checkGameId(gameCode, user_id);

        if (result?.status === 'failed') {
            return res.status(400).json({
                status: "failed",
                message: "AKUN TIDAK TERDAFTAR/SALAH ID"
            });
        }

        if (result?.success === true) {
            res.status(200).json(result);
        } else {
            res.status(400).json(result);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
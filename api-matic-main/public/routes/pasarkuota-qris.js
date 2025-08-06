const { PasarKuotaQris } = require('../../lib/pasarkuota-qris-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username, amount } = req.body;

        if (!auth_token || !auth_username || !amount) {
            return res.status(400).json({ success: false, message: "Parameter 'auth_token', 'auth_username', dan 'amount' dibutuhkan." });
        }
        
        const qrisApi = new PasarKuotaQris();
        const result = await qrisApi.createQris(auth_token, auth_username, amount);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

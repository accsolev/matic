const { PasarKuotaTransfer } = require('../../lib/pasarkuota-transfer-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username, voucher_id, target_number } = req.body;

        if (!auth_token || !auth_username || !voucher_id || !target_number) {
            return res.status(400).json({ success: false, message: "Parameter 'auth_token', 'auth_username', 'voucher_id', dan 'target_number' dibutuhkan." });
        }
        
        const transferApi = new PasarKuotaTransfer();
        const result = await transferApi.orderTransfer(auth_token, auth_username, voucher_id, target_number);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, data: error });
    }
};

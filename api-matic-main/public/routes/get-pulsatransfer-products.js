const { PasarKuotaTransfer } = require('../../lib/pasarkuota-transfer-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username } = req.body;

        if (!auth_token || !auth_username) {
            return res.status(400).json({ success: false, message: "Parameter 'auth_token' dan 'auth_username' dibutuhkan." });
        }
        
        const transferApi = new PasarKuotaTransfer();
        const result = await transferApi.getTransferProducts(auth_token, auth_username);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, data: error });
    }
};

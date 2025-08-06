const { OrkutDepositQris } = require('../../lib/orkut-deposit-qris.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username, amount } = req.body;

        if (!auth_token || !auth_username || !amount) {
            return res.status(400).json({ success: false, message: "Parameters 'auth_token', 'auth_username', and 'amount' are required." });
        }
        
        const depositApi = new OrkutDepositQris();
        const result = await depositApi.createQrisDeposit(auth_token, auth_username, amount);

        if (result.success) {
            res.status(200).json(result);
        } else {
            res.status(400).json(result);
        }

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
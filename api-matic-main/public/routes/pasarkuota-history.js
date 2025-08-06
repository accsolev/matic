const { PasarKuotaHistory } = require('../../lib/pasarkuota-history-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username, status, page } = req.body;

        if (!auth_token || !auth_username) {
            return res.status(400).json({ success: false, message: "Parameter 'auth_token' dan 'auth_username' dibutuhkan." });
        }
        
        const historyApi = new PasarKuotaHistory();
        const result = await historyApi.getHistory(auth_token, auth_username, status, page);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

const { OrkutQrisMutasi } = require('../../lib/orkut-qrmutasi-new.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username, page } = req.body;

        if (!auth_token || !auth_username) {
            return res.status(400).json({ success: false, message: "Parameters 'auth_token' and 'auth_username' are required." });
        }

        const options = { page };
        
        const mutasiApi = new OrkutQrisMutasi();
        const result = await mutasiApi.getHistory(auth_token, auth_username, options);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

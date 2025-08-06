const { OrkutQRMutasi } = require('../../lib/orkut-logicqrmutasi.js');

module.exports = async function(req, res) {
    try {
        const { merchant, auth_username, auth_token } = req.body;

        if (!merchant || !auth_username || !auth_token) {
            return res.status(400).json({ 
                success: false, 
                message: "Parameter 'merchant', 'auth_username', dan 'auth_token' diperlukan." 
            });
        }
        
        const mutasiApi = new OrkutQRMutasi();
        const result = await mutasiApi.getKasirQrisMutasi(merchant, auth_username, auth_token);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

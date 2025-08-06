const { OrkutQR } = require('../../lib/orkut-logicqr.js');

module.exports = async function(req, res) {
    try {
        const { merchant, nominal, key } = req.body;

        if (!merchant || !nominal) {
            return res.status(400).json({ success: false, message: "Parameters 'merchant' and 'nominal' are required." });
        }
        
        const qrApi = new OrkutQR();
        // 'key' bisa jadi undefined, dan fungsi logic akan menggunakan nilai default
        const result = await qrApi.createKasirQrisImage(merchant, nominal, key);

        if (result.success) {
            res.status(200).json(result);
        } else {
            res.status(400).json(result);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
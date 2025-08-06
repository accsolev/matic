const { OrderKuotaChecker } = require('../../lib/orderkuota-checker-logic.js');

module.exports = async function(req, res) {
    try {
        const { provider, phone_number } = req.body;

        if (!provider || !phone_number) {
            return res.status(400).json({ success: false, message: "Parameters 'provider' and 'phone_number' are required." });
        }
        
        const checkerApi = new OrderKuotaChecker();
        const result = await checkerApi.checkEwalletName(provider, phone_number);

        if (result?.status === 'success' || result?.success === true) {
            res.status(200).json(result);
        } else {
            res.status(400).json(result);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
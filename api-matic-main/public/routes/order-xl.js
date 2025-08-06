const { OrderKuotaXl } = require('../../lib/orderkuota-xl-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username, voucher_id, target_number } = req.body;

        if (!auth_token || !auth_username || !voucher_id || !target_number) {
            return res.status(400).json({ 
                success: false, 
                message: "Parameters 'auth_token', 'auth_username', 'voucher_id', and 'target_number' are required." 
            });
        }
        
        const xlApi = new OrderKuotaXl();
        const result = await xlApi.orderXl(auth_token, auth_username, voucher_id, target_number);

        // Langsung kirim hasil asli dari API
        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
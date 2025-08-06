const { OrderKuotaToken } = require('../../lib/orderkuota-token-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username, voucher_id, target_customer_id } = req.body;

        if (!auth_token || !auth_username || !voucher_id || !target_customer_id) {
            return res.status(400).json({ 
                success: false, 
                message: "Parameters 'auth_token', 'auth_username', 'voucher_id', and 'target_customer_id' are required." 
            });
        }
        
        const tokenApi = new OrderKuotaToken();
        const result = await tokenApi.orderToken(auth_token, auth_username, voucher_id, target_customer_id);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

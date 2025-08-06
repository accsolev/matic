const { OrderKuotaTelkomsel } = require('../../lib/orderkuota-telkomsel-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username, voucher_id, target_number } = req.body;

        if (!auth_token || !auth_username || !voucher_id || !target_number) {
            return res.status(400).json({ 
                success: false, 
                message: "Parameters 'auth_token', 'auth_username', 'voucher_id', and 'target_number' are required." 
            });
        }
        
        const telkomselApi = new OrderKuotaTelkomsel();
        const result = await telkomselApi.orderTelkomsel(auth_token, auth_username, voucher_id, target_number);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
const { OrderKuotaTelkomsel } = require('../../lib/orderkuota-telkomsel-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username } = req.body;

        if (!auth_token || !auth_username) {
            return res.status(400).json({ 
                success: false, 
                message: "Parameters 'auth_token' and 'auth_username' are required." 
            });
        }
        
        const telkomselApi = new OrderKuotaTelkomsel();
        const result = await telkomselApi.getTelkomselProducts(auth_token, auth_username);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

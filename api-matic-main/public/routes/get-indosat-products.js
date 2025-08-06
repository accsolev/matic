const { OrderKuotaIndosat } = require('../../lib/orderkuota-indosat-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username } = req.body;

        if (!auth_token || !auth_username) {
            return res.status(400).json({ 
                success: false, 
                message: "Parameters 'auth_token' and 'auth_username' are required." 
            });
        }
        
        const indosatApi = new OrderKuotaIndosat();
        const result = await indosatApi.getIndosatProducts(auth_token, auth_username);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

const { OrderKuotaDigital } = require('../../lib/orderkuota-digital-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username } = req.body;
        if (!auth_token || !auth_username) { 
            return res.status(400).json({ success: false, message: "Parameters 'auth_token' and 'auth_username' are required." }); 
        }
        
        const digitalApi = new OrderKuotaDigital();
        const result = await digitalApi.getDigitalProducts(auth_token, auth_username);

        if (result.success && result.vouchers?.results) {
            result.vouchers.results = result.vouchers.results.filter(v => v.provider?.name === 'Cek Produk Digital H2H');
        }

        res.status(200).json(result);
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

const { OrderKuotaPulsa } = require('../../lib/orderkuota-pulsa-logic.js');

module.exports = async function(req, res) {
    // TAMBAHKAN BARIS INI UNTUK DEBUGGING
    console.log('--- Handler /api/get-pulsa-products berhasil terpanggil! ---'); 
    
    try {
        const { auth_token, auth_username } = req.body;

        if (!auth_token || !auth_username) {
            return res.status(400).json({ success: false, message: "Parameters 'auth_token' and 'auth_username' are required." });
        }
        
        const pulsaApi = new OrderKuotaPulsa();
        const result = await pulsaApi.getPulsaProducts(auth_token, auth_username);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

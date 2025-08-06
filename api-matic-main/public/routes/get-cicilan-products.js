const { OrderKuotaCicilan } = require('../../lib/orderkuota-cicilan-logic.js');

module.exports = async function(req, res) {
    try {
        // Kredensial diambil langsung dari skrip, mengabaikan input pengguna
        const authToken = "2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8";
        const authUsername = "defac";
        
        const cicilanApi = new OrderKuotaCicilan();
        const result = await cicilanApi.getCicilanProducts(authToken, authUsername);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

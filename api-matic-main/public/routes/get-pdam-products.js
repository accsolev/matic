const { OrderKuotaPdam } = require('../../lib/orderkuota-pdam-logic.js');

module.exports = async function(req, res) {
    try {
        const authToken = "2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8";
        const authUsername = "defac";
        
        const pdamApi = new OrderKuotaPdam();
        const result = await pdamApi.getPdamProducts(authToken, authUsername);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

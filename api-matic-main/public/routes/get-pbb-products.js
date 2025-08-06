const { OrderKuotaPbb } = require('../../lib/orderkuota-pbb-logic.js');

module.exports = async function(req, res) {
    try {
        const authToken = "2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8";
        const authUsername = "defac";
        
        const pbbApi = new OrderKuotaPbb();
        const result = await pbbApi.getPbbProducts(authToken, authUsername);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

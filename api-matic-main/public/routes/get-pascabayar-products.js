const { OrderKuotaPascabayar } = require('../../lib/orderkuota-pascabayar-logic.js');

module.exports = async function(req, res) {
    try {
        const authToken = "2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8";
        const authUsername = "defac";
        
        const pascabayarApi = new OrderKuotaPascabayar();
        const result = await pascabayarApi.getPascabayarProducts(authToken, authUsername);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

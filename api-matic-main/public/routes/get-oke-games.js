const { OkeconnectProduct } = require('../../lib/okeconnect-product-logic.js');

module.exports = async function(req, res) {
    try {
        const authToken = '2476730 0a377c01b769753f1b22a3b48825387408d1892e';
        
        const productApi = new OkeconnectProduct();
        const result = await productApi.getGameList(authToken);

        if (result.success) {
            res.status(200).json(result);
        } else {
            res.status(400).json(result);
        }

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

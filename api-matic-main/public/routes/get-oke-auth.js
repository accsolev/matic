const { getOkeconnectToken } = require('../../lib/okeconnect-auth-logic.js');

module.exports = async function(req, res) {
    try {
        const orderkuota_token = '2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8';
        const orderkuota_username = 'defac';
        
        const result = await getOkeconnectToken(orderkuota_token, orderkuota_username);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

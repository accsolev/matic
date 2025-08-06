const { processApiRequest } = require('../../lib/orderkuota-logic.js');

module.exports = async function(req, res) {
    try {
        const { username, authToken } = req.body;

        if (!username || !authToken) {
            return res.status(400).json({ success: false, message: "Parameters 'username' and 'authToken' are required." });
        }
        
        const requestBody = { 
            action: 'check_qris_ajaib_status',
            ...req.body
        };

        const result = await processApiRequest(requestBody);

        if (result.success === false) {
            res.status(400).json(result);
        } else {
            res.status(200).json(result);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
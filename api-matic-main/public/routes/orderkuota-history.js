const { processApiRequest } = require('../../lib/orderkuota-logic.js');

module.exports = async function(req, res) {
    try {
        const requestBody = { ...req.body, action: 'get_history' };
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


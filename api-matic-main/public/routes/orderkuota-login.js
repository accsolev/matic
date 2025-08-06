const { processApiRequest } = require('../../lib/orderkuota-logic.js');

module.exports = async function(req, res) {
    try {
        const result = await processApiRequest(req.body);
        if (result.success === false) {
            res.status(400).json(result);
        } else {
            res.status(200).json(result);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};


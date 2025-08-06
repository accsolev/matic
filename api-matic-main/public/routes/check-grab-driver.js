const { processApiRequest } = require('../../lib/orderkuota-logic.js');

module.exports = async function(req, res) {
    try {
        const { phone_number } = req.body;

        if (!phone_number) {
            return res.status(400).json({ success: false, message: "Parameter 'phone_number' is required." });
        }
        
        const requestBody = { 
            action: 'check_ewallet_name',
            provider: 'grab_driver',
            phone_number: phone_number
        };

        const result = await processApiRequest(requestBody);

        if (result?.name) {
            res.status(200).json(result);
        } else {
            res.status(400).json(result);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

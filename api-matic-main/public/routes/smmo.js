const { SimpleMMO } = require('../../lib/smmo-logic.js');

module.exports = async function(req, res) {
    try {
        const { destination_id, api_token } = req.body;

        if (!destination_id || !api_token) {
            return res.status(400).json({ success: false, message: "Parameter 'destination_id' dan 'api_token' dibutuhkan." });
        }
        
        const mmoApi = new SimpleMMO();
        const result = await mmoApi.executeTravel(api_token, destination_id);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
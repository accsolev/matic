 const { CekInternet } = require('../../lib/cek-internet-logic.js');

module.exports = async function(req, res) {
    try {
        const { customer_id } = req.body;
        const voucherId = '7555';

        if (!customer_id) {
            return res.status(400).json({ success: false, message: "Parameter 'customer_id' is required." });
        }
        
        const internetApi = new CekInternet();
        const result = await internetApi.getBillDetails(customer_id, voucherId);

        if (result.success && result.details && result.details.content_details) {
            const contentDetails = result.details.content_details;
            delete contentDetails.info;
            res.status(200).json({
                success: true,
                content_details: contentDetails,
                selling_status: result.details.selling_status 
            });
        } else {
            res.status(400).json(result);
        }

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
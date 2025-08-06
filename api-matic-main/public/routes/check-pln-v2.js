const { checkPlnMotionPay } = require('../../lib/pln-logicv2.js');

module.exports = async function(req, res) {
    try {
        const { customer_id } = req.body;

        if (!customer_id) {
            return res.status(400).json({ success: false, message: "Parameter 'customer_id' is required." });
        }
        
        const result = await checkPlnMotionPay(customer_id);
        
        if (result?.rc === '00') {
             res.status(200).json({ success: true, ...result });
        } else {
             res.status(400).json({ success: false, ...result });
        }

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

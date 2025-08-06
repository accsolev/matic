const { PasarKuota } = require('../../lib/pasarkuota-gettoken-logic.js');

module.exports = async function(req, res) {
    try {
        const { action_type, username, password_or_otp } = req.body;

        if (!action_type || !username || !password_or_otp) {
            return res.status(400).json({ success: false, message: "Parameter 'action_type', 'username', dan 'password_or_otp' dibutuhkan." });
        }
        
        const pasarkuotaApi = new PasarKuota();
        let result;

        if (action_type === 'verify_otp') {
            result = await pasarkuotaApi.verifyOtp(username, password_or_otp);
        } else if (action_type === 'request_otp') {
            result = await pasarkuotaApi.requestOtp(username, password_or_otp);
        } else {
            return res.status(400).json({ success: false, message: "Nilai 'action_type' tidak valid." });
        }

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

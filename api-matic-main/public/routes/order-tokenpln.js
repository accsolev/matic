const { PasarKuotaTokenPln } = require('../../lib/pasarkuota-tokenpln-logic.js');

module.exports = async function(req, res) {
    try {
        const { auth_token, auth_username, voucher_id, target_number, registered_phone } = req.body;

        if (!auth_token || !auth_username || !voucher_id || !target_number || !registered_phone) {
            return res.status(400).json({ success: false, message: "Semua parameter (termasuk ID Pelanggan dan No. HP Terdaftar) dibutuhkan." });
        }
        
        const tokenApi = new PasarKuotaTokenPln();
        const result = await tokenApi.orderTokenPln(auth_token, auth_username, voucher_id, target_number, registered_phone);

        res.status(200).json(result);

    } catch (error) {
        res.status(500).json({ success: false, data: error });
    }
};

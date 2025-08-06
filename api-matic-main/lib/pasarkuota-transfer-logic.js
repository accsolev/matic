const axios = require('axios');
const { URLSearchParams } = require('url');

class PasarKuotaTransfer {
    constructor() {
        this.API_URL_GET = 'https://pasarkuota.com/api/v2/get';
        this.API_URL_ORDER = 'https://pasarkuota.com/api/v2/order';
        this.HEADERS = { 'User-Agent': 'okhttp/4.12.0', 'Content-Type': 'application/x-www-form-urlencoded' };
        this.BASE_PAYLOAD = {
            'c_rc': '0',
            'app_reg_id': 'cMCSIMEPTM2VWAuoJSGLdz:APA91bHQFakDwh37Ssy5pH6JrcGozyDe-q4VQVWT0V6X4T5SxAibtL7nQcYd0gtdfEwNk0EbU88dDOBl1RQ4umuxp0qm7RslAFLU27k_BBxtn-6pr_gUark',
            'latitude': '-6.4492', 'c_rswa': '1', 'location_updated': '0', 'c_rswe': '0',
            'c_h2w': '0', 'c_gg': '1', 'c_pn': '1', 'app_version_code': '241031',
            'c_rswa_e': '1', 'vss': '1', 'app_version_name': '24.10.31',
            'ui_mode': 'dark', 'longitude': '106.732'
        };
    }

    async getTransferProducts(authToken, authUsername) {
        const payload = new URLSearchParams({
            ...this.BASE_PAYLOAD,
            'auth_token': authToken,
            'auth_username': authUsername,
            'requests[vouchers][product]': 'pulsa_transfer',
            'requests[2]': 'validators',
            'requests[0]': 'balance'
        }).toString();

        try {
            const response = await axios.post(this.API_URL_GET, payload, { headers: this.HEADERS });
            return response.data;
        } catch (error) {
            throw error.response ? error.response.data : { success: false, message: error.message };
        }
    }

    async orderTransfer(authToken, authUsername, voucherId, targetNumber) {
        const payload = new URLSearchParams({
            ...this.BASE_PAYLOAD,
            'auth_token': authToken,
            'auth_username': authUsername,
            'voucher_id': voucherId,
            'id_plgn': targetNumber,
            'phone': targetNumber,
            'quantity': '1',
            'payment': 'balance',
            'kode_promo': '',
            'pin': ''
        }).toString();
        
        try {
            const response = await axios.post(this.API_URL_ORDER, payload, { headers: this.HEADERS });
            return response.data;
        } catch (error) {
            throw error.response ? error.response.data : { success: false, message: error.message };
        }
    }
}

module.exports = { PasarKuotaTransfer };

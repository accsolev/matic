const axios = require('axios');

class PasarKuotaHistory {
    constructor() {
        this.API_URL = 'https://pasarkuota.com/api/v2/get';
        this.HEADERS = {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept-Encoding': 'gzip',
            'User-Agent': 'okhttp/4.12.0'
        };
        this.BASE_PAYLOAD = {
            'c_rc': '0',
            'app_reg_id': 'cMCSIMEPTM2VWAuoJSGLdz:APA91bHQFakDwh37Ssy5pH6JrcGozyDe-q4VQVWT0V6X4T5SxAibtL7nQcYd0gtdfEwNk0EbU88dDOBl1RQ4umuxp0qm7RslAFLU27k_BBxtn-6pr_gUark',
            'latitude': '-6.4492',
            'c_rswa': '1',
            'location_updated': '0',
            'c_rswe': '0',
            'c_h2w': '0',
            'c_gg': '1',
            'c_pn': '1',
            'app_version_code': '241031',
            'c_rswa_e': '1',
            'vss': '1',
            'app_version_name': '24.10.31',
            'ui_mode': 'dark',
            'longitude': '106.732'
        };
    }

    async getHistory(authToken, authUsername, status = 'ok', page = 1) {
        const payload = {
            ...this.BASE_PAYLOAD,
            'auth_token': authToken,
            'auth_username': authUsername,
            'requests[transactions][status]': status,
            'requests[transactions][page]': page
        };

        try {
            const response = await axios.post(this.API_URL, new URLSearchParams(payload).toString(), { headers: this.HEADERS });
            return response.data;
        } catch (error) {
            throw new Error(`Gagal mengambil histori transaksi: ${error.response ? JSON.stringify(error.response.data) : error.message}`);
        }
    }
}

module.exports = { PasarKuotaHistory };

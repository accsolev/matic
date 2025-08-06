const axios = require('axios');

class PasarKuota {
    constructor() {
        this.API_URL = 'https://pasarkuota.com/api/v2/login';
        this.HEADERS = {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept-Encoding': 'gzip',
            'User-Agent': 'okhttp/4.12.0'
        };
        this.BASE_PAYLOAD = {
            'c_rc': '0',
            'app_reg_id': '',
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

    async requestOtp(username, password) {
        const payload = {
            ...this.BASE_PAYLOAD,
            username: username,
            password: password
        };

        try {
            const response = await axios.post(this.API_URL, new URLSearchParams(payload).toString(), { headers: this.HEADERS });
            return response.data;
        } catch (error) {
            throw new Error(`Gagal meminta OTP: ${error.response ? JSON.stringify(error.response.data) : error.message}`);
        }
    }

    async verifyOtp(username, otpCode) {
        const payload = {
            ...this.BASE_PAYLOAD,
            username: username,
            password: otpCode
        };

        try {
            const response = await axios.post(this.API_URL, new URLSearchParams(payload).toString(), { headers: this.HEADERS });
            return response.data;
        } catch (error) {
            throw new Error(`Gagal verifikasi OTP: ${error.response ? JSON.stringify(error.response.data) : error.message}`);
        }
    }
}

module.exports = { PasarKuota };

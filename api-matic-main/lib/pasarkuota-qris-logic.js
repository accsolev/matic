const axios = require('axios');
const { URLSearchParams } = require('url');

class PasarKuotaQris {
    constructor() {
        this.API_DEPOSIT_URL = 'https://pasarkuota.com/api/v2/deposit';
        this.API_AUTOLOGIN_URL = 'https://pasarkuota.com/api/v2/autologin';
        this.API_INQUIRY_BASE_URL = 'https://pasarkuota.com/payment/qris_winpay/inquiry/deposit/';
        this.HEADERS = { 'User-Agent': 'okhttp/4.12.0' };
        this.BASE_PAYLOAD = {
            'c_rc': '0',
            'app_reg_id': 'cMCSIMEPTM2VWAuoJSGLdz:APA91bHQFakDwh37Ssy5pH6JrcGozyDe-q4VQVWT0V6X4T5SxAibtL7nQcYd0gtdfEwNk0EbU88dDOBl1RQ4umuxp0qm7RslAFLU27k_BBxtn-6pr_gUark',
            'latitude': '-6.4492', 'c_rswa': '1', 'location_updated': '0', 'c_rswe': '0',
            'c_h2w': '0', 'c_gg': '1', 'c_pn': '1', 'app_version_code': '241031',
            'c_rswa_e': '1', 'vss': '1', 'app_version_name': '24.10.31',
            'ui_mode': 'dark', 'longitude': '106.732'
        };
    }

    async _step1_createDeposit(authToken, authUsername, amount) {
        const payload = new URLSearchParams({
            ...this.BASE_PAYLOAD,
            amount: amount,
            payment: 'qris_winpay',
            auth_token: authToken,
            auth_username: authUsername
        }).toString();

        try {
            const response = await axios.post(this.API_DEPOSIT_URL, payload, { headers: { ...this.HEADERS, 'Content-Type': 'application/x-www-form-urlencoded' } });
            const depositId = response.data?.results?.id;
            if (!depositId) {
                throw response.data;
            }
            return depositId;
        } catch (error) {
            throw error.response ? error.response.data : { success: false, message: error.message };
        }
    }

    async _step2_getSessionCookies(authToken, authUsername, depositId) {
        const redirectUrl = `https://pasarkuota.com/akun/deposit/view/${depositId}`;
        const encodedRedirect = Buffer.from(redirectUrl).toString('base64');
        
        const params = new URLSearchParams({
            auth_username: authUsername,
            auth_token: authToken,
            redirect: encodedRedirect
        });

        try {
            const response = await axios.get(`${this.API_AUTOLOGIN_URL}?${params.toString()}`, {
                headers: { 'User-Agent': 'WebView' },
                maxRedirects: 0,
                validateStatus: status => status >= 200 && status < 400
            });
    
            const cookies = response.headers['set-cookie'];
            if (!cookies) {
                throw { success: false, message: "Gagal mendapatkan session cookies dari langkah 2." };
            }
            return cookies.map(c => c.split(';')[0]).join('; ');
        } catch (error) {
            throw error.response ? error.response.data : { success: false, message: error.message };
        }
    }

    async _step3_getQrisDetails(depositId, cookieString) {
        try {
            const response = await axios.get(`${this.API_INQUIRY_BASE_URL}${depositId}`, {
                headers: {
                    ...this.HEADERS,
                    'User-Agent': 'WebView',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cookie': cookieString
                }
            });
            return response.data;
        } catch (error) {
            throw error.response ? error.response.data : { success: false, message: error.message };
        }
    }

    async createQris(authToken, authUsername, amount) {
        try {
            const depositId = await this._step1_createDeposit(authToken, authUsername, amount);
            const cookieString = await this._step2_getSessionCookies(authToken, authUsername, depositId);
            const qrisDetails = await this._step3_getQrisDetails(depositId, cookieString);
            
            return qrisDetails;
        } catch (error) {
            return error;
        }
    }
}

module.exports = { PasarKuotaQris };

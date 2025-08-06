const axios = require('axios');
const qs = require('qs');

class OrkutQrisMutasi {
    static API_HOST = 'app.orderkuota.com';
    static USER_AGENT = 'okhttp/4.12.0';

    async getHistory(authToken, authUsername, options = {}) {
        const url = `https://` + OrkutQrisMutasi.API_HOST + `/api/v2/get`;

        const staticData = {
            'app_reg_id': 'eyRfCn8cS0yT6QxpWQW4fv:APA91bFtwD3n48xqQ-STXF47kz0txNuZ5FtF69fVk-Y-pX3FMRVCkL2q7lixz3kPoaMRMUcajqO5Y_7G_l8rV0q0UfIdCenEGh-ibLGNRLWjvzDNQvaM5kk',
            'phone_uuid': 'eyRfCn8cS0yT6QxpWQW4fv',
            'phone_model': '23124RA7EO',
            'phone_android_version': '15',
            'app_version_code': '250711',
            'app_version_name': '25.07.11',
            'ui_mode': 'light'
        };

        const requestData = {
            ...staticData,
            'auth_username': authUsername,
            'auth_token': authToken,
	    'requests[1]': 'qris_menu',
            'requests[0]': 'qris_history',
            'requests[qris_history][keterangan]': '',
            'requests[qris_history][jumlah]': '',
            'requests[qris_history][page]': options.page || '1',
            'requests[qris_history][dari_tanggal]': '',
            'requests[qris_history][ke_tanggal]': ''
        };

        const headers = {
            'Host': OrkutQrisMutasi.API_HOST,
            'User-Agent': OrkutQrisMutasi.USER_AGENT,
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept-Encoding': 'gzip'
        };

        try {
            const response = await axios.post(url, qs.stringify(requestData), { headers });
            return { success: true, ...response.data };
        } catch (error) {
            const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
            throw new Error(`Failed to get QRIS history: ${errorMessage}`);
        }
    }
}

module.exports = { OrkutQrisMutasi };

const axios = require('axios');

class OrderKuotaIndosat {
    static API_HOST = 'app.orderkuota.com';
    static USER_AGENT = 'okhttp/4.12.0';

    async getIndosatProducts(authToken, authUsername) {
        const url = `https://` + OrderKuotaIndosat.API_HOST + `/api/v2/get`;
        const bodyPayload = new URLSearchParams({
            'app_reg_id': 'fR1V5tOkS729uGKf5CwnEX:APA91bGZonDueIesJj2EleKqqtlpTqXGj20YHHftevWl4-6aE04yGfKZCyqfcvyqxLoLsC4RV9qhcO3ZCL7VeovaWSF_QS25ao0SPU-C3BCO0t_MPLfBl7Y',
            'phone_android_version': '14',
            'app_version_code': '250327',
            'phone_uuid': 'fR1V5tOkS729uGKf5CwnEX',
            'auth_username': authUsername,
            'requests[vouchers][product]': 'kuota_indosat',
            'auth_token': authToken,
            'app_version_name': '25.03.27',
            'ui_mode': 'dark',
            'requests[0]': 'balance',
            'requests[2]': 'validators',
            'phone_model': 'SM-G935F'
        }).toString();
        const headers = {
            'Host': OrderKuotaIndosat.API_HOST,
            'User-Agent': OrderKuotaIndosat.USER_AGENT,
            'Content-Type': 'application/x-www-form-urlencoded'
        };
        try {
            const response = await axios.post(url, bodyPayload, { headers });
            return { success: true, ...response.data };
        } catch (error) {
            const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
            throw new Error(`Failed to get Indosat products: ${errorMessage}`);
        }
    }

    async orderIndosat(authToken, authUsername, voucherId, targetNumber) {
        const url = `https://` + OrderKuotaIndosat.API_HOST + `/api/v2/order`;
        const bodyPayload = new URLSearchParams({
            'quantity': '1',
            'app_reg_id': 'fR1V5tOkS729uGKf5CwnEX:APA91bGZonDueIesJj2EleKqqtlpTqXGj20YHHftevWl4-6aE04yGfKZCyqfcvyqxLoLsC4RV9qhcO3ZCL7VeovaWSF_QS25ao0SPU-C3BCO0t_MPLfBl7Y',
            'phone_uuid': 'fR1V5tOkS729uGKf5CwnEX',
            'id_plgn': '',
            'phone_model': 'SM-G935F',
            'kode_promo': '',
            'phone_android_version': '14',
            'pin': '',
            'app_version_code': '250327',
            'phone': targetNumber,
            'auth_username': authUsername,
            'voucher_id': voucherId,
            'payment': 'balance',
            'auth_token': authToken,
            'app_version_name': '25.03.27',
            'ui_mode': 'dark'
        }).toString();
        const headers = {
            'Host': OrderKuotaIndosat.API_HOST,
            'User-Agent': OrderKuotaIndosat.USER_AGENT,
            'Content-Type': 'application/x-www-form-urlencoded'
        };
        try {
            const response = await axios.post(url, bodyPayload, { headers });
            return response.data;
        } catch (error) {
            const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
            throw new Error(`Failed to place Indosat order: ${errorMessage}`);
        }
    }
}

module.exports = { OrderKuotaIndosat };

const axios = require('axios');

class OrderKuotaPbb {
    static API_HOST = 'app.orderkuota.com';
    static USER_AGENT = 'okhttp/4.12.0';

    async getPbbProducts(authToken, authUsername) {
        const url = `https://` + OrderKuotaPbb.API_HOST + `/api/v2/get`;
        const bodyPayload = new URLSearchParams({
            'app_reg_id': 'fR1V5tOkS729uGKf5CwnEX:APA91bGZonDueIesJj2EleKqqtlpTqXGj20YHHftevWl4-6aE04yGfKZCyqfcvyqxLoLsC4RV9qhcO3ZCL7VeovaWSF_QS25ao0SPU-C3BCO0t_MPLfBl7Y',
            'phone_android_version': '14',
            'app_version_code': '250327',
            'phone_uuid': 'fR1V5tOkS729uGKf5CwnEX',
            'auth_username': authUsername,
            'requests[vouchers][product]': 'tagihan_pbb',
            'auth_token': authToken,
            'app_version_name': '25.03.27',
            'ui_mode': 'dark',
            'requests[0]': 'balance',
            'requests[2]': 'validators',
            'phone_model': 'SM-G935F'
        }).toString();
        const headers = {
            'Host': OrderKuotaPbb.API_HOST,
            'User-Agent': OrderKuotaPbb.USER_AGENT,
            'Content-Type': 'application/x-www-form-urlencoded'
        };
        try {
            const response = await axios.post(url, bodyPayload, { headers });
            return { success: true, ...response.data };
        } catch (error) {
            const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
            throw new Error(`Failed to get PBB products: ${errorMessage}`);
        }
    }

    async checkBill(authToken, authUsername, voucherId, targetCustomerId) {
        const staticParams = {
            'app_reg_id': 'fR1V5tOkS729uGKf5CwnEX:APA91bGZonDueIesJj2EleKqqtlpTqXGj20YHHftevWl4-6aE04yGfKZCyqfcvyqxLoLsC4RV9qhcO3ZCL7VeovaWSF_QS25ao0SPU-C3BCO0t_MPLfBl7Y',
            'phone_uuid': 'fR1V5tOkS729uGKf5CwnEX', 'phone_model': 'SM-G935F', 'phone_android_version': '14',
            'app_version_code': '250327', 'auth_username': authUsername, 'auth_token': authToken,
            'app_version_name': '25.03.27', 'ui_mode': 'dark'
        };

        let transactionId;
        try {
            const orderUrl = `https://` + OrderKuotaPbb.API_HOST + `/api/v2/order`;
            const orderPayload = new URLSearchParams({
                'quantity': '1', 'id_plgn': targetCustomerId, 'kode_promo': '', 'pin': '',
                'phone': '', 'voucher_id': voucherId, 'payment': 'balance', ...staticParams
            }).toString();
            const orderResponse = await axios.post(orderUrl, orderPayload, { 
                headers: { 'Host': OrderKuotaPbb.API_HOST, 'User-Agent': OrderKuotaPbb.USER_AGENT, 'Content-Type': 'application/x-www-form-urlencoded' }
            });
            transactionId = orderResponse.data?.results?.id;
            if (!transactionId) {
                return { success: false, message: "Gagal mendapatkan ID Transaksi dari order awal.", details: orderResponse.data };
            }
        } catch (error) {
            throw new Error(`Langkah 1 (Check PBB Bill) gagal: ${error.response ? JSON.stringify(error.response.data) : error.message}`);
        }

        const detailsUrl = `https://` + OrderKuotaPbb.API_HOST + `/api/v2/get`;
        const detailsPayload = new URLSearchParams({
            'requests[transaction_details][id]': transactionId, ...staticParams
        }).toString();
        
        const maxRetries = 10;
        const retryDelay = 3000;
        let lastKnownDetails;
        for (let i = 0; i < maxRetries; i++) {
            await new Promise(resolve => setTimeout(resolve, i === 0 ? 1000 : retryDelay));
            try {
                const detailsResponse = await axios.post(detailsUrl, detailsPayload, {
                    headers: { 'Host': OrderKuotaPbb.API_HOST, 'User-Agent': OrderKuotaPbb.USER_AGENT, 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                
                const details = detailsResponse.data?.transaction_details?.results;
                lastKnownDetails = detailsResponse.data;

                if (details && details.is_in_process === false) {
                    return { success: true, ...detailsResponse.data };
                }
            } catch (error) {
                 throw new Error(`Langkah 2 (Get Details) gagal: ${error.response ? JSON.stringify(error.response.data) : error.message}`);
            }
        }
        
        return { success: false, message: `Gagal mendapatkan status final setelah ${maxRetries * retryDelay / 1000} detik.`, lastDetails: lastKnownDetails };
    }
}

module.exports = { OrderKuotaPbb };

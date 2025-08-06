const axios = require('axios');

class OrderKuotaConfig {
    static API_HOST = 'app.orderkuota.com';
    static USER_AGENT = 'okhttp/4.12.0';

    async getLatestConfig(authToken, authUsername) {
        const url = `https://` + OrderKuotaConfig.API_HOST + `/api/v2/get`;
        
        const bodyPayload = new URLSearchParams({
            'requests[splash_screen][screen_width]': '1440',
            'requests[9]': 'banner',
            'app_reg_id': 'fR1V5tOkS729uGKf5CwnEX:APA91bGZonDueIesJj2EleKqqtlpTqXGj20YHHftevWl4-6aE04yGfKZCyqfcvyqxLoLsC4RV9qhcO3ZCL7VeovaWSF_QS25ao0SPU-C3BCO0t_MPLfBl7Y',
            'requests[6]': 'config',
            'phone_uuid': 'fR1V5tOkS729uGKf5CwnEX',
            'requests[5]': 'show_hide_image',
            'requests[8]': 'top_menu_v2',
            'requests[7]': 'bottom_menu',
            'phone_model': 'SM-G935F',
            'phone_android_version': '14',
            'app_version_code': '250327',
            'auth_username': authUsername,
            'requests[1]': 'main_page',
            'requests[4]': 'payments',
            'requests[3]': 'product_layout',
            'auth_token': authToken,
            'app_version_name': '25.03.27',
            'ui_mode': 'dark',
            'requests[0]': 'products',
            'requests[splash_screen][screen_height]': '2476'
        }).toString();

        const headers = {
            'Host': OrderKuotaConfig.API_HOST,
            'User-Agent': OrderKuotaConfig.USER_AGENT,
            'Content-Type': 'application/x-www-form-urlencoded'
        };

        try {
            const response = await axios.post(url, bodyPayload, { headers });
            
            const checkerUrl = response.data?.config?.results?.checkers?.url;
            if (checkerUrl) {
                return { success: true, checker_url: checkerUrl };
            }
            
            return { success: false, message: 'Checker URL not found in config response.' };

        } catch (error) {
            const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
            throw new Error(`Failed to get config: ${errorMessage}`);
        }
    }
}

module.exports = { OrderKuotaConfig };

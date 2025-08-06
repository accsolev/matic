const axios = require('axios');
const { OrderKuotaConfig } = require('./config-dinamis.js');

class OrkutPlnPrabayar {
    static CHECKER_HOST = 'checker.orderkuota.com';
    static USER_AGENT = 'okhttp/4.12.0';

    async checkPlnPrabayarName(meterNumber) {
        const configApi = new OrderKuotaConfig();
        const configResult = await configApi.getLatestConfig("2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8", "defac");
        
        if (!configResult.success) {
            throw new Error('Could not retrieve the latest checker URL.');
        }

        const dynamicCheckerUrl = configResult.checker_url.replace('{productcode}', 'pln').replace('{ID}', 'pln');
        
        const bodyPayload = new URLSearchParams({
            phoneNumber: "",
            app_reg_id: "fR1V5tOkS729uGKf5CwnEX:APA91bGZonDueIesJj2EleKqqtlpTqXGj20YHHftevWl4-6aE04yGfKZCyqfcvyqxLoLsC4RV9qhcO3ZCL7VeovaWSF_QS25ao0SPU-C3BCO0t_MPLfBl7Y",
            phone_android_version: "14",
            app_version_code: "250327",
            phone_uuid: "fR1V5tOkS729uGKf5CwnEX",
            auth_username: "defac",
            customerId: meterNumber,
            id: "pln",
            auth_token: "2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8",
            app_version_name: "25.03.27",
            ui_mode: "dark",
            phone_model: "SM-G935F"
        }).toString();

        const headers = {
            'Host': OrkutPlnPrabayar.CHECKER_HOST,
            'User-Agent': OrkutPlnPrabayar.USER_AGENT,
            'Content-Type': 'application/x-www-form-urlencoded'
        };

        try {
            const response = await axios.post(dynamicCheckerUrl, bodyPayload, { headers });
            return response.data;
        } catch (error) {
            const errorMessage = error.response ? (typeof error.response.data === 'object' ? JSON.stringify(error.response.data) : error.message) : error.message;
            throw new Error(errorMessage);
        }
    }
}

module.exports = { OrkutPlnPrabayar };

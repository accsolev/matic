const axios = require('axios');

class OrderKuotaChecker {
    static CHECKER_HOST = 'checker.orderkuota.com';
    static API_HOST = 'app.orderkuota.com';
    static USER_AGENT = 'okhttp/4.12.0';

    #getStaticParams() {
        return {
            app_reg_id: "fR1V5tOkS729uGKf5CwnEX:APA91bGZonDueIesJj2EleKqqtlpTqXGj20YHHftevWl4-6aE04yGfKZCyqfcvyqxLoLsC4RV9qhcO3ZCL7VeovaWSF_QS25ao0SPU-C3BCO0t_MPLfBl7Y",
            phone_android_version: "14",
            app_version_code: "250327",
            phone_uuid: "fR1V5tOkS729uGKf5CwnEX",
            auth_username: "defac",
            auth_token: "2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8",
            app_version_name: "25.03.27",
            ui_mode: "dark",
            phone_model: "SM-G935F"
        };
    }

    async #getLatestCheckerUrl() {
        const url = `https://` + OrderKuotaChecker.API_HOST + `/api/v2/get`;
        const bodyPayload = new URLSearchParams({
            'requests[6]': 'config',
            ...this.#getStaticParams()
        }).toString();
        
        const headers = {
            'Host': OrderKuotaChecker.API_HOST,
            'User-Agent': OrderKuotaChecker.USER_AGENT,
            'Content-Type': 'application/x-www-form-urlencoded'
        };

        try {
            const response = await axios.post(url, bodyPayload, { headers });
            const checkerUrl = response.data?.config?.results?.checkers?.url;
            if (checkerUrl) {
                return checkerUrl;
            }
            throw new Error('Checker URL not found in config response.');
        } catch (error) {
            const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
            throw new Error(`Failed to get config for checker URL: ${errorMessage}`);
        }
    }

    async checkEwalletName(provider, phoneNumber) {
        const urlTemplate = await this.#getLatestCheckerUrl();
        const url = urlTemplate.replace('{ID}', provider);
        
        const bodyPayload = new URLSearchParams({
            phoneNumber: phoneNumber,
            customerId: '',
            id: provider,
            ...this.#getStaticParams()
        }).toString();

        return this.#executeCheck(url, bodyPayload);
    }

    async checkGameId(gameCode, gameUserId) {
        const urlTemplate = await this.#getLatestCheckerUrl();
        const url = urlTemplate.replace('{ID}', gameCode);

        const bodyPayload = new URLSearchParams({
            phoneNumber: this.#getStaticParams().auth_username,
            customerId: gameUserId,
            id: gameCode,
            ...this.#getStaticParams()
        }).toString();

        return this.#executeCheck(url, bodyPayload);
    }

    async #executeCheck(url, bodyPayload) {
        const headers = {
            'Host': OrderKuotaChecker.CHECKER_HOST,
            'User-Agent': OrderKuotaChecker.USER_AGENT,
            'Content-Type': 'application/x-www-form-urlencoded'
        };
        try {
            const response = await axios.post(url, bodyPayload, { headers });
            return response.data;
        } catch (error) {
            const errorMessage = error.response ? (typeof error.response.data === 'object' ? JSON.stringify(error.response.data) : error.message) : error.message;
            throw new Error(errorMessage);
        }
    }
}

module.exports = { OrderKuotaChecker };

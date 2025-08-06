const axios = require('axios');

class CekIndihome {
    static API_HOST = 'app.orderkuota.com';
    static USER_AGENT = 'okhttp/4.12.0';

    async getBillDetails(customerId) {
        const staticParams = {
            app_reg_id: 'fR1V5tOkS729uGKf5CwnEX:APA91bGZonDueIesJj2EleKqqtlpTqXGj20YHHftevWl4-6aE04yGfKZCyqfcvyqxLoLsC4RV9qhcO3ZCL7VeovaWSF_QS25ao0SPU-C3BCO0t_MPLfBl7Y',
            phone_uuid: 'fR1V5tOkS729uGKf5CwnEX',
            phone_model: 'SM-G935F',
            phone_android_version: '14',
            app_version_code: '250327',
            auth_username: 'defac',
            auth_token: '2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8',
            app_version_name: '25.03.27',
            ui_mode: 'dark'
        };

        let transactionId;

        try {
            const inquiryUrl = `https://` + CekIndihome.API_HOST + `/api/v2/order`;
            const inquiryPayload = new URLSearchParams({
                quantity: '1',
                id_plgn: customerId,
                kode_promo: '',
                pin: '',
                phone: '',
                voucher_id: '655',
                payment: 'balance',
                ...staticParams
            }).toString();

            const inquiryResponse = await axios.post(inquiryUrl, inquiryPayload, { 
                headers: { 
                    'Host': CekIndihome.API_HOST,
                    'User-Agent': CekIndihome.USER_AGENT,
                    'Content-Type': 'application/x-www-form-urlencoded' 
                }
            });

            transactionId = inquiryResponse.data?.results?.id;
            if (!transactionId) {
                return { success: false, message: "Failed to get transaction ID from Step 1.", details: inquiryResponse.data };
            }
        } catch (error) {
            const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
            throw new Error(`Step 1 (Inquiry) failed: ${errorMessage}`);
        }

        const detailsUrl = `https://` + CekIndihome.API_HOST + `/api/v2/get`;
        const detailsPayload = new URLSearchParams({
            'requests[transaction_details][id]': transactionId,
            'requests[transaction_details][product_choices_support]': '1',
            'requests[2]': 'print_logo',
            'requests[1]': 'account',
            'requests[0]': 'recaptcha_key',
            ...staticParams
        }).toString();

        const maxRetries = 10;
        const retryDelay = 3000;
        let lastKnownDetails;

        for (let i = 0; i < maxRetries; i++) {
            try {
                const detailsResponse = await axios.post(detailsUrl, detailsPayload, { 
                    headers: { 
                        'Host': CekIndihome.API_HOST,
                        'User-Agent': CekIndihome.USER_AGENT,
                        'Content-Type': 'application/x-www-form-urlencoded' 
                    }
                });
                
                const details = detailsResponse.data?.transaction_details?.results;
                lastKnownDetails = details;
                
                if (details && details.is_in_process === false) {
                    return { success: true, details: details };
                }
                
                await new Promise(resolve => setTimeout(resolve, retryDelay));

            } catch (error) {
                 const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
                 throw new Error(`Step 2 (Get Details) failed: ${errorMessage}`);
            }
        }
        
        return { success: false, message: 'Failed to get final status after multiple retries.', lastDetails: lastKnownDetails };
    }
}

module.exports = { CekIndihome };

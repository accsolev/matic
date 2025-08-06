const axios = require('axios');
const { URLSearchParams } = require('url');

class OrkutQR {
    static KASIR_HOST = 'kasir.orderkuota.com';
    static APP_HOST = 'app.orderkuota.com';
    static USER_AGENT = 'WebView';
    static APP_PACKAGE = 'com.orderkuota.app';

    static AUTH_USERNAME = 'defac';
    static AUTH_TOKEN = '2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8';

    async #getDynamicKey() {
        const autologinUrl = `https://${OrkutQR.APP_HOST}/api/v2/autologin`;
        const autologinParams = new URLSearchParams({
            auth_username: OrkutQR.AUTH_USERNAME,
            auth_token: OrkutQR.AUTH_TOKEN,
            redirect: 'aHR0cHM6Ly9hcHAub3JkZXJrdW90YS5jb20vZGlnaXRhbF9hcHAvcXJpcw=='
        }).toString();

        const autologinHeaders = {
            'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'accept-language': 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
            'priority': 'u=0, i',
            'sec-ch-ua': '"Android WebView";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
            'sec-ch-ua-mobile': '?1',
            'sec-ch-ua-platform': '"Android"',
            'sec-fetch-dest': 'document',
            'sec-fetch-mode': 'navigate',
            'sec-fetch-site': 'none',
            'sec-fetch-user': '?1',
            'upgrade-insecure-requests': '1',
            'user-agent': OrkutQR.USER_AGENT,
            'x-requested-with': OrkutQR.APP_PACKAGE
        };

        const autologinResponse = await axios.get(`${autologinUrl}?${autologinParams}`, {
            headers: autologinHeaders,
            maxRedirects: 0,
            validateStatus: status => status >= 200 && status < 400 
        });

        if (!autologinResponse.headers['set-cookie']) {
            throw new Error('Langkah 1 (autologin) Berhasil, namun tidak menerima cookie.');
        }
        const cookies = autologinResponse.headers['set-cookie'].join('; ');

        const qrisPageUrl = `https://${OrkutQR.APP_HOST}/digital_app/qris`;
        const qrisPageHeaders = {
            'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'user-agent': OrkutQR.USER_AGENT,
            'x-requested-with': OrkutQR.APP_PACKAGE,
            'cookie': cookies
        };
        
        const qrisPageResponse = await axios.get(qrisPageUrl, {
            headers: qrisPageHeaders,
        });
        
        const finalUrl = qrisPageResponse.request.res.responseUrl;
        const keyRegex = /key=([a-f0-9]{32})/;
        const match = finalUrl.match(keyRegex);

        if (match && match[1]) {
            return match[1];
        }

        throw new Error(`Gagal mengekstrak key dari URL final. URL: ${finalUrl}`);
    }

    async createKasirQrisImage(dynamicMerchantId, dynamicAmount) {
        try {
            const dynamicKey = await this.#getDynamicKey();

            const url = `https://` + OrkutQR.KASIR_HOST + `/qris/curl/create_qris_image.php`;
            
            const params = {
                merchant: dynamicMerchantId,
                nominal: dynamicAmount
            };
            
            const headers = {
                'Host': OrkutQR.KASIR_HOST,
                'accept': 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'referer': `https://kasir.orderkuota.com/qris/?id=OK2476730&key=${dynamicKey}`,
                'user-agent': OrkutQR.USER_AGENT,
                'x-requested-with': OrkutQR.APP_PACKAGE
            };

            const response = await axios.get(url, { params, headers, responseType: 'arraybuffer' });
            const imageBase64 = Buffer.from(response.data, 'binary').toString('base64');
            
            return {
                success: true,
                image_data_url: `data:image/png;base64,${imageBase64}`
            };
        } catch (error) {
            const status = error.response ? error.response.status : 'N/A';
            const baseMessage = error.message || `Request Gagal - Status: ${status}`;
            throw new Error(`Server Error: ${baseMessage}`);
        }
    }
}

module.exports = { OrkutQR };

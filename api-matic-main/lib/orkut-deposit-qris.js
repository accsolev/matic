const axios = require('axios');
const cheerio = require('cheerio');
const Buffer = require('buffer').Buffer;

class OrkutDepositQris {
    static API_HOST = 'app.orderkuota.com';
    static USER_AGENT = 'okhttp/4.12.0';

    async createQrisDeposit(authToken, authUsername, amount) {
        const staticParams = {
            app_reg_id: 'fR1V5tOkS729uGKf5CwnEX:APA91bGZonDueIesJj2EleKqqtlpTqXGj20YHHftevWl4-6aE04yGfKZCyqfcvyqxLoLsC4RV9qhcO3ZCL7VeovaWSF_QS25ao0SPU-C3BCO0t_MPLfBl7Y',
            phone_uuid: 'fR1V5tOkS729uGKf5CwnEX',
            phone_model: 'SM-G935F',
            phone_android_version: '14',
            app_version_code: '250327',
            ui_mode: 'dark',
            app_version_name: '25.03.27'
        };

        // Langkah 1: Buat permintaan deposit untuk mendapatkan ID
        let depositId;
        try {
            const createUrl = `https://` + OrkutDepositQris.API_HOST + `/api/v2/deposit`;
            const createPayload = new URLSearchParams({
                amount: amount, payment: 'sinarmas', option_id: '', auth_token: authToken, auth_username: authUsername, ...staticParams
            }).toString();
            const createResponse = await axios.post(createUrl, createPayload, {
                headers: { 'Host': OrkutDepositQris.API_HOST, 'User-Agent': OrkutDepositQris.USER_AGENT, 'Content-Type': 'application/x-www-form-urlencoded' }
            });
            depositId = createResponse.data?.results?.id;
            if (!depositId) throw new Error('server error / make the payment you made previously');
        } catch (error) {
            throw new Error(`Step 1 (Create Deposit) failed: ${error.response ? JSON.stringify(error.response.data) : error.message}`);
        }

        // Langkah 2: Lakukan autologin untuk mendapatkan cookie sesi
        let cookies;
        const depositViewUrl = `https://` + OrkutDepositQris.API_HOST + `/akun/deposit/view/${depositId}`;
        try {
            const autologinUrl = `https://` + OrkutDepositQris.API_HOST + `/api/v2/autologin`;
            const encodedRedirect = Buffer.from(depositViewUrl).toString('base64');
            const autologinResponse = await axios.get(autologinUrl, {
                params: { auth_token: authToken, auth_username: authUsername, redirect: encodedRedirect },
                headers: { 'User-Agent': 'WebView', 'x-requested-with': 'com.orderkuota.app' },
                maxRedirects: 0,
                validateStatus: () => true
            });
            cookies = autologinResponse.headers['set-cookie'];
            if (!cookies) throw new Error('No cookies were set in the autologin response.');
        } catch (error) {
            throw new Error(`Step 2 (Autologin) failed: ${error.message}`);
        }
        
        // Langkah 3: Panggil endpoint AJAX tersembunyi
        try {
            const getQrTriggerUrl = `https://` + OrkutDepositQris.API_HOST + `/payment/sinarmas/get_qris_nb/${depositId}`;
            await axios.get(getQrTriggerUrl, {
                headers: { 'Cookie': cookies.join('; '), 'User-Agent': 'WebView', 'X-Requested-With': 'XMLHttpRequest' }
            });
        } catch (error) {
            // Kita abaikan error di sini karena panggilan ini mungkin tidak selalu memberikan respons yang valid, tujuannya hanya untuk memicu
            console.log("Step 3 (AJAX Trigger) completed or failed, proceeding...");
        }

        // Langkah 4: Ambil halaman final setelah menunggu sebentar
        try {
            await new Promise(resolve => setTimeout(resolve, 2000)); // Beri jeda 2 detik

            const pageResponse = await axios.get(depositViewUrl, {
                headers: { 'Cookie': cookies.join('; '), 'User-Agent': 'WebView', 'x-requested-with': 'com.orderkuota.app' }
            });
            
            const html = pageResponse.data;
            const $ = cheerio.load(html);
            const qrImageUrl = $('.qris img').attr('src');
            
            if (qrImageUrl) {
                return { success: true, qrcode_url: qrImageUrl, depositId: depositId };
            } else {
                throw new Error('Could not find QR code image URL in the final HTML.');
            }
        } catch (error) {
            throw new Error(`Step 4 (Get Page & Parse) failed: ${error.message}`);
        }
    }
}

module.exports = { OrkutDepositQris };

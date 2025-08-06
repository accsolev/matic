const axios = require('axios');
const Buffer = require('buffer').Buffer;

class OrderKuotaKey {
    static APP_HOST = 'app.orderkuota.com';

    async getKasirSessionKey(authToken, authUsername) {
        try {
            const autologinUrl = `https://` + OrderKuotaKey.APP_HOST + `/api/v2/autologin`;
            const redirectTarget = 'https://app.orderkuota.com/digital_app/qris';
            const encodedRedirect = Buffer.from(redirectTarget).toString('base64');

            const response = await axios.get(autologinUrl, {
                params: {
                    auth_username: authUsername,
                    auth_token: authToken,
                    redirect: encodedRedirect
                },
                headers: { 'User-Agent': 'WebView', 'x-requested-with': 'com.orderkuota.app' },
                maxRedirects: 0,
                validateStatus: () => true 
            });

            const cookies = response.headers['set-cookie'];
            if (!cookies || cookies.length === 0) {
                throw new Error('No cookies were set in the autologin response.');
            }

            let userIdEncoded = null;

            cookies.forEach(cookieStr => {
                if (cookieStr.startsWith('user_id=')) {
                    userIdEncoded = cookieStr.split(';')[0].split('=')[1];
                }
            });

            if (userIdEncoded) {
                let userIdDecoded = Buffer.from(userIdEncoded, 'base64').toString('utf-8');
                userIdDecoded = userIdDecoded.trim().replace(/\D/g, '');

                const merchantId = `OK${userIdDecoded}`;

                // --- PERUBAHAN DI SINI ---
                // Mengembalikan objek yang paling ringkas, hanya merchantId
                return {
                    success: true,
                    merchantId: merchantId
                };
            } else {
                throw new Error('user_id not found in cookies.');
            }

        } catch (error) {
            throw new Error(`Failed to get session key: ${error.message}`);
        }
    }
}

module.exports = { OrderKuotaKey };

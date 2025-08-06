const axios = require('axios');
const Buffer = require('buffer').Buffer;

async function getOkeconnectToken(authToken, authUsername) {
    let cookieString = '';

    try {
        const autologinUrl = `https://app.orderkuota.com/api/v2/autologin`;
        const redirectTarget = 'https://app.orderkuota.com/act/digital-app';
        const encodedRedirect = Buffer.from(redirectTarget).toString('base64');
        
        const autologinResponse = await axios.get(autologinUrl, {
            params: { auth_username: authUsername, auth_token: authToken, redirect: encodedRedirect },
            headers: { 'User-Agent': 'WebView', 'x-requested-with': 'com.orderkuota.app' },
            maxRedirects: 0,
            validateStatus: () => true
        });

        if (autologinResponse.status === 302 && autologinResponse.headers['set-cookie']) {
            cookieString = autologinResponse.headers['set-cookie'].join('; ');
        } else {
            throw new Error('Failed to get cookies from autologin step.');
        }

    } catch (error) {
        throw new Error(`Step 1 (Autologin) failed: ${error.message}`);
    }

    try {
        const config = {
            method: 'GET',
            url: 'https://app.orderkuota.com/act/okegaming/0',
            headers: {
                'User-Agent': 'WebView',
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Encoding': 'gzip, deflate, br, zstd',
                'sec-ch-ua': '"Android WebView";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
                'sec-ch-ua-mobile': '?1',
                'sec-ch-ua-platform': '"Android"',
                'upgrade-insecure-requests': '1',
                'x-requested-with': 'com.orderkuota.app',
                'sec-fetch-site': 'same-site',
                'sec-fetch-mode': 'navigate',
                'sec-fetch-user': '?1',
                'sec-fetch-dest': 'document',
                'referer': 'https://igame.orderkuota.com/',
                'accept-language': 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
                'priority': 'u=0, i',
                'Cookie': cookieString
            },
            maxRedirects: 5 
        };

        const response = await axios.request(config);
        
        const finalUrl = response.request.res.responseUrl;

        if (!finalUrl || !finalUrl.includes('igame.orderkuota.com')) {
            throw new Error('Could not retrieve the final igame URL from the response.');
        }
        
        const urlParams = new URL(finalUrl).searchParams;
        const userId = urlParams.get('id');
        const token = urlParams.get('token');

        if (userId && token) {
            const finalAuthToken = `${userId} ${token}`;
            return {
                success: true,
                auth_token: finalAuthToken,
                details: {
                    userId: userId,
                    token: token,
                    name: urlParams.get('name'),
                    session: urlParams.get('session'),
                    finalUrl: finalUrl
                }
            };
        } else {
            throw new Error('Required id or token not found in the final URL.');
        }

    } catch (error) {
        const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
        throw new Error(`The request failed: ${errorMessage}`);
    }
}

module.exports = { getOkeconnectToken };

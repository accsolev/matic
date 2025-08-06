const axios = require('axios');

class SimpleMMO {
    static WEB_HOST = 'simple-mmo.com';
    static API_HOST = 'api.simple-mmo.com';
    static USER_AGENT = 'Mozilla/5.0 (Linux; Android 14; SM-G935F Build/UQ1A.240205.002; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/137.0.7151.115 Mobile Safari/537.36';

    async _getDynamicSession() {
        const url = `https://` + SimpleMMO.WEB_HOST + `/register`;
        const response = await axios.get(url, {
            headers: { 'User-Agent': SimpleMMO.USER_AGENT }
        });
        const cookies = response.headers['set-cookie'];
        const html = response.data;
        const xsrfToken = cookies.find(c => c.startsWith('XSRF-TOKEN')).split(';')[0].split('=')[1];
        const laravelSession = cookies.find(c => c.startsWith('laravelsession')).split(';')[0].split('=')[1];
        const csrfTokenMatch = html.match(/<input type="hidden" name="_token" value="(.+?)">/);
        const csrfToken = csrfTokenMatch ? csrfTokenMatch[1] : null;
        if (!xsrfToken || !laravelSession || !csrfToken) {
            throw new Error('Gagal mendapatkan token dinamis dari halaman register.');
        }
        return { 
            sessionCookies: `XSRF-TOKEN=${xsrfToken}; laravelsession=${laravelSession}`,
            csrfToken: csrfToken
        };
    }

    _findCaptchaUrl(html) {
        if (typeof html !== 'string') return null;
        const captchaUrlMatch = html.match(/href='(\/i-am-not-a-bot\?new_page=true)'/);
        return captchaUrlMatch ? captchaUrlMatch[1] : null;
    }

    async _solveCaptcha(path, cookies) {
        const url = `https://` + SimpleMMO.WEB_HOST + path;
        await axios.get(url, { headers: { 'User-Agent': SimpleMMO.USER_AGENT, 'Cookie': cookies } });
    }
    
    async executeTravel(apiToken, destinationId, isRetry = false) {
        const dynamicSession = await this._getDynamicSession();
        
        const travelUrl = `https://` + SimpleMMO.API_HOST + `/api/action/travel/${destinationId}`;
        const travelPayload = new URLSearchParams({
            '_token': dynamicSession.csrfToken, 
            'api_token': apiToken,
            'd_1': '284', 'd_2': '459', 's': 'false', 'travel_id': '0'
        }).toString();
        
        const travelResponse = await axios.post(travelUrl, travelPayload, {
            headers: {
                'User-Agent': SimpleMMO.USER_AGENT, 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Cookie': dynamicSession.sessionCookies, 'origin': 'https://' + SimpleMMO.WEB_HOST,
                'x-requested-with': 'dawsn.simplemmo', 'referer': 'https://' + SimpleMMO.WEB_HOST + '/travel'
            }
        });
        
        let responseHtml = travelResponse.data?.details?.text || travelResponse.data?.message || (typeof travelResponse.data === 'string' ? travelResponse.data : '');
        
        const captchaUrl = this._findCaptchaUrl(responseHtml);
        if (captchaUrl && !isRetry) {
            await this._solveCaptcha(captchaUrl, dynamicSession.sessionCookies);
            return this.executeTravel(apiToken, destinationId, true);
        } else if (captchaUrl && isRetry) {
            throw new Error("Gagal melewati captcha setelah mencoba kembali.");
        }

        return { success: true, action: 'travel', details: travelResponse.data };
    }
}

module.exports = { SimpleMMO };

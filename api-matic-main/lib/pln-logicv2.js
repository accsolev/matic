const axios = require('axios');
const crypto = require('crypto');
const config = require('./config.js');

function getFormattedTimestamp() {
    const now = new Date();
    const year = now.getFullYear();
    const month = (now.getMonth() + 1).toString().padStart(2, '0');
    const day = now.getDate().toString().padStart(2, '0');
    const hours = now.getHours().toString().padStart(2, '0');
    const minutes = now.getMinutes().toString().padStart(2, '0');
    const seconds = now.getSeconds().toString().padStart(2, '0');
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

async function refreshToken() {
    console.log('Access token expired. Attempting to refresh...');
    
    const hashedPin = crypto.createHash('sha256').update(config.motionPayPin).digest('hex');
    
    const refreshUrl = 'https://api.motionpay.id/coreusers/token/internal/refresh/pin';

    const body = {
        refresh_token: config.motionPayRefreshToken,
        password: hashedPin,
        device_id: config.motionPayDeviceId,
        fds: {
            code_version_app: '31000017',
            device_id: config.motionPayDeviceId,
            device_manufacture: 'samsung',
            device_model: 'SM-G935F',
            device_os: 'android26/samsung/hero2ltexx/hero2lte:8.0.0/R16NW/G935FXXU8EVG3:user/release-keys',
            device_type: 'android',
            geo_location: '-6.449139,106.7319415',
            ip_address: '180.252.173.251',
            msisdn: config.motionPayId,
            name_version_app: '3.5.2',
            sdk_version: '26',
            timestamp: getFormattedTimestamp(),
            timezone: 'Asia/Jakarta',
            user_agent: 'Mozilla/5.0 (Linux; Android 8.0.0; SM-G935F Build/R16NW; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/135.0.7049.113 Mobile Safari/537.36'
        }
    };
    
    const headers = {
        'content-type': 'application/json; charset=UTF-8',
        'user-agent': 'okhttp/4.11.0'
    };

    try {
        const response = await axios.post(refreshUrl, body, { headers });
        const newAccessToken = response.data?.access_token;
        const newRefreshToken = response.data?.refresh_token;

        if (!newAccessToken) {
            throw new Error('Failed to get new access token from refresh response.');
        }

        console.log('Token refreshed successfully.');
        config.motionPayToken = newAccessToken;
        if (newRefreshToken) {
            config.motionPayRefreshToken = newRefreshToken;
        }
        
        return true;

    } catch (error) {
        console.error('Failed to refresh token:', error.response ? error.response.data : error.message);
        return false;
    }
}

async function makeApiCall(plnCustomerId) {
    const baseUrl = 'https://api.motionpay.id/api/v1/biller/inquiry';
    const params = {
        motionpay_id: config.motionPayId,
        mp_product_code: 'MPPLNPOS',
        subscriber_id: plnCustomerId,
        geo_location: '',
        device_id: config.motionPayDeviceId,
        device_model: 'SM-G935F',
        timezone: 'Asia/Jakarta',
        device_type: 'android',
        ip_address: '180.252.173.251',
        code_version_app: '31000017',
        device_os: 'android26/samsung/hero2ltexx/hero2lte:8.0.0/R16NW/G935FXXU8EVG3:user/release-keys',
        name_version_app: '3.5.2',
        device_manufacture: 'samsung',
        sdk_version: '26',
        msisdn: config.motionPayId,
        user_agent: 'Mozilla/5.0 (Linux; Android 8.0.0; SM-G935F Build/R16NW; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/135.0.7049.113 Mobile Safari/537.36',
        timestamp: getFormattedTimestamp()
    };
    const headers = {
        'authorization': `Bearer ${config.motionPayToken}`,
        'accept-encoding': 'gzip',
        'user-agent': 'okhttp/4.11.0',
        'host': 'api.motionpay.id'
    };
    return axios.get(baseUrl, { params, headers });
}

async function checkPlnMotionPay(plnCustomerId) {
    if (!plnCustomerId) {
        throw new Error('plnCustomerId is required.');
    }

    try {
        const response = await makeApiCall(plnCustomerId);
        return response.data;
    } catch (error) {
        if (error.response && error.response.status === 401) {
            const refreshSuccess = await refreshToken();
            if (refreshSuccess) {
                console.log('Retrying the API call with the new token...');
                const response = await makeApiCall(plnCustomerId);
                return response.data;
            }
        }
        const errorMessage = error.response ? (typeof error.response.data === 'object' ? JSON.stringify(error.response.data) : error.message) : error.message;
        throw new Error(errorMessage);
    }
}

module.exports = { checkPlnMotionPay };

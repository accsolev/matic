const axios = require('axios');
const https = require('https');
const { getOkeconnectToken } = require('./okeconnect-auth-logic.js');

async function checkGameUsername(code, idplgn, idplgn_add1 = '') {
    // Langkah 1: Selalu dapatkan token baru yang valid
    const authResult = await getOkeconnectToken('2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8', 'defac');
    if (!authResult.success) {
        throw new Error('Failed to retrieve a new Okeconnect auth token.');
    }
    const authToken = authResult.auth_token;

    // Langkah 2: Lakukan pengecekan nama dengan token yang baru didapat
    const url = `https://golang.okeconnect.com/api/v1/external/check_username`;
    const bodyPayload = {
        code: code,
        idplgn: idplgn,
        idplgn_add1: idplgn_add1
    };
    const headers = {
        'Host': 'golang.okeconnect.com',
        'Authorization': `Bearer ${authToken}`,
        'User-Agent': 'WebView',
        'Content-Type': 'application/json',
        'Accept': 'application/json, text/plain, */*',
        'Origin': 'https://igame.orderkuota.com',
        'Referer': 'https://igame.orderkuota.com/'
    };
    const httpsAgent = new https.Agent({ rejectUnauthorized: false });

    try {
        const response = await axios.post(url, bodyPayload, { headers, httpsAgent });
        return response.data;
    } catch (error) {
        const errorMessage = error.response ? (typeof error.response.data === 'object' ? JSON.stringify(error.response.data) : error.message) : error.message;
        throw new Error(errorMessage);
    }
}

module.exports = { checkGameUsername };

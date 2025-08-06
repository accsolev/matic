const axios = require('axios');

class CekResi {
    static API_URL = 'https://loman.id/resapp/';
    static USER_AGENT = 'Dart/3.6 (dart:io)';

    async getResiDetails(resi, courier) {
        const bodyPayload = new URLSearchParams({
            'resi': resi,
            'ex': courier
        }).toString();

        const headers = {
            'User-Agent': CekResi.USER_AGENT,
            'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8'
        };

        try {
            const response = await axios.post(CekResi.API_URL, bodyPayload, { headers });
            return response.data;
        } catch (error) {
            const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
            throw new Error(`Failed to get tracking details: ${errorMessage}`);
        }
    }
}

module.exports = { CekResi };

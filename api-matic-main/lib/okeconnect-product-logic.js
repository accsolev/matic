const axios = require('axios');

class OkeconnectProduct {
    static API_HOST = 'golang.okeconnect.com';
    static USER_AGENT = 'WebView';

    async getGameList(authToken) {
        const url = `https://` + OkeconnectProduct.API_HOST + `/api/v1/categories/detail/1`;

        const headers = {
            'Host': OkeconnectProduct.API_HOST,
            'Authorization': `Bearer ${authToken}`,
            'User-Agent': OkeconnectProduct.USER_AGENT,
            'Accept': 'application/json, text/plain, */*',
            'Origin': 'https://igame.orderkuota.com',
            'Referer': 'https://igame.orderkuota.com/'
        };

        try {
            const response = await axios.get(url, { headers });
            
            if (response.data?.success && Array.isArray(response.data?.data?.products)) {
                const activeGames = response.data.data.products
                    .filter(product => product.status === 'on')
                    .map(product => ({
                        code: product.code,
                        name: product.name
                    }));
                return { success: true, games: activeGames };
            }

            return { success: false, message: 'Products not found in response.' };

        } catch (error) {
            const errorMessage = error.response ? (typeof error.response.data === 'object' ? JSON.stringify(error.response.data) : error.message) : error.message;
            throw new Error(errorMessage);
        }
    }
}

module.exports = { OkeconnectProduct };

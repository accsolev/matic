const axios = require('axios');

async function getPanganData(searchQuery, category) {
    const url = 'https://data.jabarprov.go.id/api-dashboard-jabar/public/pangan/list-komoditas';
    const params = {
        search: searchQuery || '',
        kategori: category || 'all',
        page: '1',
        limit: '50',
        order: 'asc',
        order_by: 'name'
    };
    const headers = {
        'Accept': 'application/json, text/plain, */*',
        'Origin': 'https://dashboard.jabarprov.go.id',
        'Referer': 'https://dashboard.jabarprov.go.id/',
        'User-Agent': 'Mozilla/5.0 (Linux; Android 14; SM-G935F Build/UQ1A.240205.002; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/137.0.7151.115 Mobile Safari/537.36',
        'X-Requested-With': 'com.sapawarga.jds'
    };

    try {
        const response = await axios.get(url, { params, headers });
        return response.data;
    } catch (error) {
        const errorMessage = error.response ? JSON.stringify(error.response.data) : error.message;
        throw new Error(`Failed to get Pangan Jabar data: ${errorMessage}`);
    }
}

function formatPanganResponse(result) {
    const dataArray = result?.data;
    if (!result || result.success !== 1 || !Array.isArray(dataArray)) {
        console.error("Struktur respons API tidak terduga:", JSON.stringify(result, null, 2));
        return { success: false, message: "Gagal memformat data pangan, struktur respons tidak sesuai." };
    }

    const commodities = dataArray.map(item => ({
        nama_komoditas: item.name,
        harga: `Rp ${parseInt(item.price, 10).toLocaleString('id-ID')}`,
        satuan: item.unit,
        kategori: item.categories,
        kondisi_harga: item.kondisi_harga, // Data baru
        riwayat_harga: item.histories,     // Data baru
        sumber_data: item.source_name,
        diperbarui_pada_tanggal: item.date
    }));

    return {
        success: true,
        message: result.message || "Data harga pangan Jabar berhasil diambil.",
        jumlah_data: result.metadata?.parameters?.total || dataArray.length,
        data_pangan: commodities
    };
}


module.exports = async function(req, res) {
    try {
        const { search, kategori } = req.body;

        const result = await getPanganData(search, kategori);
        const finalResponse = formatPanganResponse(result);

        if (finalResponse.success) {
            res.status(200).json(finalResponse);
        } else {
            res.status(400).json(finalResponse);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
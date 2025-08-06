const { CekResi } = require('../../lib/cek-resi-logic.js');

function formatResiResponse(result, resi, courier) {
    if (!result || result.status !== 'berhasil' || !result.details || !Array.isArray(result.history)) {
        return {
            success: false,
            message: result?.details?.infopengiriman || result?.msg || "Nomor resi tidak ditemukan atau kurir tidak didukung."
        };
    }

    const summary = result.details;
    const history = result.history.map(item => ({
        timestamp: item.tanggal,
        description: item.details
    }));

    return {
        success: true,
        message: "Rincian pengiriman berhasil diambil.",
        summary: {
            nomor_resi: resi,
            kurir: courier.charAt(0).toUpperCase() + courier.slice(1),
            status: summary.status,
            informasi: summary.infopengiriman
        },
        riwayat: history
    };
}

module.exports = async function(req, res) {
    try {
        const { resi, kurir } = req.body;

        if (!resi || !kurir) {
            return res.status(400).json({ success: false, message: "Parameter 'resi' dan 'kurir' dibutuhkan." });
        }

        const resiApi = new CekResi();
        const result = await resiApi.getResiDetails(resi, kurir);

        const finalResponse = formatResiResponse(result, resi, kurir);

        if (finalResponse.success) {
            res.status(200).json(finalResponse);
        } else {
            res.status(400).json(finalResponse);
        }

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
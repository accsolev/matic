const { OrkutPlnPrabayar } = require('../../lib/pln-prabayar-logic.js');

function formatPlnPrabayarResponse(result) {
    // Jika status dari API BUKAN 'success'
    if (!result || result.status !== 'success') {
        return {
            success: false,
            status: "Gagal",
            message: result?.message || "Nomor meter tidak ditemukan atau tidak valid."
        };
    }

    // Jika status 'success', langsung ambil data dari level atas
    return {
        success: true,
        status: "Sukses",
        message: "Data pelanggan berhasil ditemukan.",
        customer_details: {
            meter_number: result.no_pel,
            customer_name: result.nama_pel,
            tariff_power: result.tarif_daya
        }
    };
}

module.exports = async function(req, res) {
    try {
        const { meter_number } = req.body;

        if (!meter_number) {
            return res.status(400).json({ success: false, message: "Parameter 'meter_number' is required." });
        }
        
        const plnApi = new OrkutPlnPrabayar();
        const result = await plnApi.checkPlnPrabayarName(meter_number);

        const finalResponse = formatPlnPrabayarResponse(result);

        if (finalResponse.success) {
            res.status(200).json(finalResponse);
        } else {
            res.status(400).json(finalResponse);
        }
        
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
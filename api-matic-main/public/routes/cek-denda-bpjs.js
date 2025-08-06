const { CekBpjs } = require('../../lib/cek-bpjs-logic.js');

function formatBpjsDendaResponse(result) {
    if (!result || !result.success || !result.details) {
        return { success: false, message: "Data transaksi tidak ditemukan atau tidak valid." };
    }

    const details = result.details;

    if (details.is_success === false) {
        return {
            success: false, status: "Gagal", message: details.status_info,
            transaction_details: { transaction_id: details.id, customer_id: details.customer_id, date: details.full_date }
        };
    }

    if (details.content_details?.body?.["Rincian Transaksi"]) {
        const bodyDetails = Object.fromEntries(details.content_details.body["Rincian Transaksi"]);
        return {
            success: true, status: "Sukses", message: "Tagihan denda BPJS berhasil ditemukan.",
            transaction_details: {
                transaction_id: details.id,
                customer_id: bodyDetails["Nomor VA Keluarga"] || details.customer_id,
                customer_name: bodyDetails["Nama Peserta"],
                penalty_amount: bodyDetails["Jumlah Denda"],
                admin_fee: bodyDetails["Biaya Admin"],
                total_payment: bodyDetails["Total Bayar"],
                date: details.full_date
            }
        };
    }

    return { success: true, status: "Sukses", message: details.status_info, transaction_id: details.id };
}

module.exports = async function(req, res) {
    try {
        const { customer_id } = req.body;
        const voucherId = '6339';

        if (!customer_id) {
            return res.status(400).json({ success: false, message: "Parameter 'customer_id' is required." });
        }
        
        const bpjsApi = new CekBpjs();
        const result = await bpjsApi.getBillDetails(customer_id, voucherId);

        const finalResponse = formatBpjsDendaResponse(result);

        if (finalResponse.success) {
            res.status(200).json(finalResponse);
        } else {
            res.status(400).json(finalResponse);
        }

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
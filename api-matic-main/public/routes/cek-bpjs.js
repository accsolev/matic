const { CekBpjs } = require('../../lib/cek-bpjs-logic.js');

function formatBpjsKesehatanResponse(result) {
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

    const sn = details.sn || "";
    const snParts = sn.split('/');
    const snData = {};
    snParts.slice(1).forEach(part => {
        const [key, ...value] = part.split(':');
        if (key) snData[key.trim()] = value.join(':').trim();
    });

    return {
        success: true,
        status: "Sukses",
        message: "Tagihan BPJS Kesehatan berhasil ditemukan.",
        transaction_details: {
            transaction_id: details.id,
            customer_id: details.customer_id,
            customer_name: snParts[0] || 'N/A',
            bill_amount: snData.TAG ? `Rp ${parseInt(snData.TAG, 10).toLocaleString('id-ID')}` : 'N/A',
            admin_fee: snData.ADMIN ? `Rp ${parseInt(snData.ADMIN, 10).toLocaleString('id-ID')}` : 'N/A',
            total_payment: snData.TTAG ? `Rp ${parseInt(snData.TTAG, 10).toLocaleString('id-ID')}` : 'N/A',
            months_count: snData.JMLBLN,
            period: snData.PERIODE,
            participant_count: snData.PESERTA,
            date: details.full_date
        }
    };
}

module.exports = async function(req, res) {
    try {
        const { customer_id } = req.body;
        const voucherId = '658';

        if (!customer_id) {
            return res.status(400).json({ success: false, message: "Parameter 'customer_id' is required." });
        }
        
        const bpjsApi = new CekBpjs();
        const result = await bpjsApi.getBillDetails(customer_id, voucherId);

        const finalResponse = formatBpjsKesehatanResponse(result);

        if (finalResponse.success) {
            res.status(200).json(finalResponse);
        } else {
            res.status(400).json(finalResponse);
        }

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
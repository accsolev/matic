const { OrderKuotaPbb } = require('../../lib/orderkuota-pbb-logic.js');

function formatPbbResponse(transactionData) {
    if (!transactionData || !transactionData.results) {
        return { success: false, message: "Invalid transaction data received." };
    }
    const details = transactionData.results;
    if (details.is_success === false) {
        return {
            success: false,
            status: "Gagal",
            message: details.status_info,
            transaction_details: {
                transaction_id: details.id,
                nop: details.customer_id,
                date: details.full_date
            }
        };
    }
    if (details.content_details && details.content_details.body && details.content_details.body["Rincian Transaksi"]) {
        const bodyDetails = Object.fromEntries(details.content_details.body["Rincian Transaksi"]);
        return {
            success: true,
            status: "Sukses",
            message: bodyDetails["Keterangan"] || details.status_info,
            transaction_details: {
                transaction_id: details.id,
                nop: bodyDetails["NOP"] || details.customer_id,
                taxpayer_name: bodyDetails["Nama Wajib Pajak"],
                address: bodyDetails["Alamat Objek Pajak"],
                tax_year: bodyDetails["Tahun Pajak"],
                bill_amount: bodyDetails["Jumlah Tagihan"],
                admin_fee: bodyDetails["Biaya Admin"],
                total_payment: bodyDetails["Total Bayar"],
                date: details.full_date
            }
        };
    }
    return {
        success: true,
        status: "Sukses",
        message: details.status_info,
        transaction_id: details.id
    };
}

module.exports = async function(req, res) {
    try {
        const { voucher_id, target_customer_id } = req.body;
        const authToken = "2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8";
        const authUsername = "defac";

        if (!voucher_id || !target_customer_id) {
            return res.status(400).json({ 
                success: false, 
                message: "Parameters 'voucher_id' and 'target_customer_id' are required." 
            });
        }
        
        const pbbApi = new OrderKuotaPbb();
        const result = await pbbApi.checkBill(authToken, authUsername, voucher_id, target_customer_id);

        if (result.success && result.transaction_details) {
            const finalResponse = formatPbbResponse(result.transaction_details);
            res.status(200).json(finalResponse);
        } else {
            res.status(400).json(result);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

const { OrderKuotaPascabayar } = require('../../lib/orderkuota-pascabayar-logic.js');

function formatPascabayarResponse(transactionData) {
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
                customer_id: details.customer_id,
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
                customer_id: bodyDetails["Nomor ID"] || details.customer_id,
                customer_name: bodyDetails["Nama Pelanggan"],
                period: bodyDetails["Periode"],
                bill_amount: bodyDetails["Total Tagihan"] || bodyDetails["Tagihan"],
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
        serial_number: details.sn,
        transaction_id: details.id
    };
}

module.exports = async function(req, res) {
    try {
        const { voucher_id, target_number } = req.body;
        const authToken = "2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8";
        const authUsername = "defac";

        if (!voucher_id || !target_number) {
            return res.status(400).json({ 
                success: false, 
                message: "Parameters 'voucher_id' and 'target_number' are required." 
            });
        }
        
        const pascabayarApi = new OrderKuotaPascabayar();
        const result = await pascabayarApi.checkBill(authToken, authUsername, voucher_id, target_number);

        if (result.success && result.transaction_details) {
            const finalResponse = formatPascabayarResponse(result.transaction_details);
            res.status(200).json(finalResponse);
        } else {
            res.status(400).json(result);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

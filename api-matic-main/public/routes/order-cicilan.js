const { OrderKuotaCicilan } = require('../../lib/orderkuota-cicilan-logic.js');

function formatCicilanResponse(transactionData) {
    if (!transactionData || !transactionData.results) {
        return { success: false, message: "Invalid transaction data received." };
    }

    const details = transactionData.results;
    
    // Jika transaksi GAGAL
    if (details.is_success === false) {
        return {
            success: false,
            status: "Gagal",
            message: details.status_info,
            transaction_details: {
                transaction_id: details.id,
                customer_id: details.customer_id,
                service: details.voucher.name,
                provider: details.provider.name,
                date: details.full_date
            }
        };
    }

    // Jika transaksi SUKSES (fokus pada format tagihan/cicilan)
    if (details.content_details && details.content_details.body && details.content_details.body["Rincian Transaksi"]) {
        const bodyDetails = Object.fromEntries(details.content_details.body["Rincian Transaksi"]);
        return {
            success: true,
            status: "Sukses",
            message: bodyDetails["Keterangan"] || details.status_info,
            transaction_details: {
                transaction_id: details.id,
                customer_id: bodyDetails["ID Pelanggan"] || details.customer_id,
                customer_name: bodyDetails["Nama Pelanggan"],
                period: bodyDetails["Periode"],
                bill_amount: bodyDetails["Total Tagihan"] || bodyDetails["Tagihan"],
                admin_fee: bodyDetails["Biaya Admin"],
                total_payment: bodyDetails["Total Bayar"],
                date: details.full_date,
                serial_number: details.sn
            }
        };
    }

    // Respons fallback jika sukses tapi format tidak dikenali
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
        const { voucher_id, target_customer_id } = req.body;

        const authToken = "2476730:C1nBJfojRgvWZQHY6EcG7zPMldxTrLy8";
        const authUsername = "defac";

        if (!voucher_id || !target_customer_id) {
            return res.status(400).json({ 
                success: false, 
                message: "Parameters 'voucher_id' and 'target_customer_id' are required." 
            });
        }
        
        const cicilanApi = new OrderKuotaCicilan();
        const result = await cicilanApi.orderCicilan(authToken, authUsername, voucher_id, target_customer_id);

        if (result.success && result.transaction_details) {
            const finalResponse = formatCicilanResponse(result.transaction_details);
            res.status(200).json(finalResponse);
        } else {
            res.status(400).json(result);
        }

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};

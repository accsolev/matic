const { processApiRequest } = require('../../lib/orderkuota-logic.js');

function formatElectricityResponse(result) {
    if (!result || !result.transaction_details?.results) {
        return { success: false, message: "Invalid transaction data received." };
    }

    const details = result.transaction_details.results;

    if (details.is_success === false) {
        return {
            success: false,
            status: "Gagal",
            message: details.status_info || "Transaksi Gagal",
            transaction_details: {
                transaction_id: details.id,
                customer_id: details.customer_id,
                date: details.full_date
            }
        };
    }

    if (details.sn) {
        const sn = details.sn;
        const snParts = sn.split('/');
        
        const snData = {};
        snParts.slice(1).forEach(part => {
            const separatorIndex = part.indexOf(':');
            if (separatorIndex > -1) {
                const key = part.substring(0, separatorIndex).trim().toUpperCase();
                const value = part.substring(separatorIndex + 1).trim();
                snData[key] = value;
            }
        });

        const billAmount = snData.TAG ? `Rp ${parseInt(snData.TAG, 10).toLocaleString('id-ID')}` : 'N/A';
        const adminFee = snData.ADMIN ? `Rp ${parseInt(snData.ADMIN, 10).toLocaleString('id-ID')}` : 'N/A';
        const totalPayment = snData.TTAG ? `Rp ${parseInt(snData.TTAG, 10).toLocaleString('id-ID')}` : 'N/A';
        const tariffPower = (snData.TARIF && snData.DAYA) ? `${snData.TARIF}/${snData.DAYA}` : 'N/A';

        return {
            success: true,
            status: "Sukses",
            message: "Tagihan listrik berhasil ditemukan.",
            transaction_details: {
                transaction_id: details.id,
                customer_id: details.customer_id,
                customer_name: snParts[0],
                tariff_power: tariffPower,
                period: snData.PERIODE,
                months_count: snData.JMLBLN,
                bill_amount: billAmount,
                admin_fee: adminFee,
                total_payment: totalPayment,
                date: details.full_date,
                serial_number: details.sn
            }
        };
    }

    return {
        success: true,
        status: "Sukses",
        message: details.status_info || "Transaksi sukses namun detail tidak dapat diparsing.",
        transaction_id: details.id
    };
}


module.exports = async function(req, res) {
    try {
        const { customer_id } = req.body;

        if (!customer_id) {
            return res.status(400).json({ success: false, message: "Parameter 'customer_id' is required." });
        }
        
        const requestBody = { 
            action: 'check_electricity_bill',
            customer_id: customer_id
        };

        const result = await processApiRequest(requestBody);
        
        const finalResponse = formatElectricityResponse(result);

        if (finalResponse.success) {
            res.status(200).json(finalResponse);
        } else {
            res.status(400).json(finalResponse);
        }

    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
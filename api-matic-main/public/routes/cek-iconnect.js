const { CekIconnect } = require('../../lib/cek-iconnect-logic.js');

function findValueInNestedArray(array, key) {
    const found = array.find(item => item[0] === key);
    return found ? found[1] : null;
}

function formatIconnectResponse(result) {
    if (!result || !result.body?.["Rincian Transaksi"] || !result.top) {
        return { success: false, message: "Struktur data transaksi Iconnect tidak valid atau tidak ditemukan." };
    }

    const rincian = result.body["Rincian Transaksi"];
    const topDetails = result.top;
    
    const sn = findValueInNestedArray(rincian, "Serial Number");
    
    if (sn) {
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

        const billAmount = snData.TAG ? `Rp ${parseInt(snData.TAG, 10).toLocaleString('id-ID')}` : (snData.TAGIHAN ? `Rp ${parseInt(snData.TAGIHAN, 10).toLocaleString('id-ID')}` : 'N/A');
        const adminFee = snData.ADMIN ? `Rp ${parseInt(snData.ADMIN, 10).toLocaleString('id-ID')}` : 'N/A';
        const totalPayment = snData.TTAG ? `Rp ${parseInt(snData.TTAG, 10).toLocaleString('id-ID')}` : (snData.TOTAL ? `Rp ${parseInt(snData.TOTAL, 10).toLocaleString('id-ID')}` : 'N/A');

        return {
            success: true,
            status: "Sukses",
            message: "Tagihan PLN Iconnet berhasil ditemukan.",
            bill_details: {
                transaction_id: findValueInNestedArray(topDetails, "ID Transaksi"),
                customer_id: findValueInNestedArray(rincian, "ID Pelanggan"),
                customer_name: snParts[0].trim(),
                period: snData.PERIODE,
                months_count: snData.JMLBLN,
                bill_amount: billAmount,
                admin_fee: adminFee,
                total_payment: totalPayment,
                date: findValueInNestedArray(topDetails, "Tanggal"),
                raw_serial_number: sn
            }
        };
    }

    return {
        success: false,
        status: "Gagal",
        message: "Detail Serial Number (SN) tidak ditemukan dalam respons.",
        raw_response: result
    };
}

module.exports = async function(req, res) {
    try {
        const { customer_id } = req.body;

        if (!customer_id) {
            return res.status(400).json({ success: false, message: "Parameter 'customer_id' dibutuhkan." });
        }
        
        const iconnectApi = new CekIconnect();
        const result = await iconnectApi.getBillDetails(customer_id);
        
        const finalResponse = formatIconnectResponse(result.details.content_details);

        if (finalResponse.success) {
            res.status(200).json(finalResponse);
        } else {
            res.status(400).json(finalResponse);
        }

    } catch (error) {
        console.error("Iconnect handler error:", error);
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
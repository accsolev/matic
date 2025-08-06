const { checkGameUsername } = require('../../lib/okeconnect-game.js');

module.exports = async function(req, res) {
    try {
        const { user_id } = req.body;
        const gameCode = 'MSAWK';

        if (!user_id) {
            return res.status(400).json({ success: false, message: "Parameter 'user_id' is required." });
        }
        
        const result = await checkGameUsername(gameCode, user_id);

        if (result?.success) {
            res.status(200).json(result);
        } else if (result?.data?.code === 'PLAYER_NOT_FOUND') {
            res.status(400).json({ status: "failed", message: "AKUN TIDAK TERDAFTAR/SALAH ID" });
        } else {
            res.status(400).json(result);
        }
    } catch (error) {
        res.status(500).json({ success: false, message: `Server Error: ${error.message}` });
    }
};
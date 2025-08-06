const express = require("express");
const { exec } = require("child_process");
const fs = require("fs");
const path = require("path");
const tmp = require("tmp"); 
const router = express.Router();

const COOKIES_PATH = path.join(__dirname, "..", "data", "youtube-cookies.txt");
const FFMPEG_PATH = "/usr/bin/ffmpeg"; // Sesuaikan dengan lokasi ffmpeg di server Anda

router.post("/", async (req, res) => {
  const url = req.body.url;

  if (!url || !/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/.test(url)) {
    return res.status(400).json({ success: false, error: "URL YouTube tidak valid." });
  }

  let tmpDir; 
  try {
    tmpDir = tmp.dirSync({ unsafeCleanup: true }); 
    const outputTemplate = path.join(tmpDir.name, "%(title)s.%(ext)s");

    const cmd = `yt-dlp --extract-audio --audio-format mp3 --ffmpeg-location "${FFMPEG_PATH}" --cookies "${COOKIES_PATH}" -o "${outputTemplate}" "${url}"`;

    exec(cmd, { timeout: 120000 }, (err, stdout, stderr) => {
      if (err) {
        tmpDir.removeCallback();
        return res.status(500).json({
          success: false,
          error: "Gagal mengunduh MP3. Periksa URL, cookie, atau instalasi yt-dlp/ffmpeg.",
          detail: stderr || stdout,
        });
      }

      const files = fs.readdirSync(tmpDir.name).filter(f => f.endsWith(".mp3"));
      if (files.length === 0) {
        tmpDir.removeCallback();
        return res.status(500).json({
          success: false,
          error: "File MP3 tidak ditemukan setelah proses.",
          detail: "Pastikan video tidak bermasalah atau yt-dlp berhasil mengunduh.",
        });
      }

      const filePath = path.join(tmpDir.name, files[0]);
      const fileName = path.basename(filePath);

      res.download(filePath, fileName, (downloadErr) => {
        if (tmpDir) {
          tmpDir.removeCallback(); 
        }
        if (downloadErr) {
          console.error("Error saat mengirim file:", downloadErr);
        }
      });
    });
  } catch (tmpErr) {
    console.error("Error creating temporary directory:", tmpErr);
    if (tmpDir) {
      tmpDir.removeCallback();
    }
    return res.status(500).json({ success: false, error: "Gagal membuat direktori temporary." });
  }
});

module.exports = router;
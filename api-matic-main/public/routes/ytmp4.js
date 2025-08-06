const express = require("express");
const { spawn } = require("child_process");
const fs = require("fs");
const path = require("path");
const router = express.Router();

const COOKIES_PATH = path.join(__dirname, "..", "data", "youtube-cookies.txt");
const FFMPEG_PATH = "/usr/bin/ffmpeg"; // Diperlukan oleh yt-dlp untuk merge
const YTDLP_PATH = "/usr/local/bin/yt-dlp";

router.post("/", async (req, res) => {
  const url = req.body.url;

  if (!url || !/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/.test(url)) {
    return res.status(400).json({ success: false, error: "URL YouTube tidak valid." });
  }

  res.setHeader('Content-Type', 'video/mp4'); // Pastikan ini video/mp4
  res.setHeader('Content-Disposition', `attachment; filename="youtube_video.mp4"`); // Sesuaikan nama file

  let ytDlpProcess;
  let processesKilled = false;

  const killAllProcesses = () => {
    if (processesKilled) return;
    processesKilled = true;
    console.log('Attempting to kill yt-dlp process...');
    if (ytDlpProcess && !ytDlpProcess.killed) {
      ytDlpProcess.kill('SIGKILL');
      console.log('yt-dlp process killed.');
    }
  };

  try {
    // Hanya panggil yt-dlp, biarkan ia menggunakan ffmpeg secara internal untuk merge
    ytDlpProcess = spawn(YTDLP_PATH, [
      url,
      '--format', 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best',
      '--merge-output-format', 'mp4', // yt-dlp akan memanggil ffmpeg untuk ini
      '--ffmpeg-location', FFMPEG_PATH, // Pastikan yt-dlp tahu lokasi ffmpeg
      '--cookies', COOKIES_PATH,
      '-o', '-', // Output langsung ke stdout
      '--quiet'
    ], { stdio: ['ignore', 'pipe', 'pipe'] });

    ytDlpProcess.stdout.pipe(res); // Langsung pipe output yt-dlp ke response

    ytDlpProcess.stderr.on('data', (data) => {
      console.error(`[yt-dlp stderr]: ${data.toString()}`);
      if (!res.headersSent) {
        res.status(500).json({ success: false, error: "Gagal mengunduh/menggabungkan video (yt-dlp error).", detail: data.toString() });
      } else {
        res.end();
      }
      killAllProcesses();
    });

    ytDlpProcess.on('close', (code) => {
        if (code !== 0) {
            console.error(`yt-dlp process exited with code ${code}`);
            if (!res.headersSent) {
              res.status(500).json({ success: false, error: `yt-dlp gagal dengan kode ${code}.` });
            } else {
              res.end();
            }
        } else {
            console.log("yt-dlp process finished successfully.");
        }
        // Karena yt-dlp selesai, tidak perlu kill proses lain
        // killAllProcesses(); // Tidak perlu lagi memanggil ini di sini
    });

    req.on('close', () => {
      console.log('Client disconnected. Killing yt-dlp process.');
      killAllProcesses();
    });

  } catch (error) {
    console.error("Error initiating streaming process:", error);
    if (!res.headersSent) {
      res.status(500).json({ success: false, error: "Terjadi kesalahan server internal.", detail: error.message });
    } else {
      res.end();
    }
    killAllProcesses();
  }
});

module.exports = router;
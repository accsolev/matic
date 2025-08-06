const fs = require('fs');
const path = require('path');

const OLD_URL = 'https://bovalone.me';
const NEW_URL = 'https://bovalone.me';

const TARGET_DIRS = ['', 'lib', 'public/routes', 'public']; // Folder yang akan diproses
const TARGET_EXTENSIONS = ['.js', '.json', '.html']; // Ekstensi file yang akan diubah

let filesChanged = 0;

function processDirectory(directory) {
    fs.readdir(directory, (err, files) => {
        if (err) {
            console.error(`Gagal membaca direktori: ${directory}`, err);
            return;
        }

        files.forEach(file => {
            const fullPath = path.join(directory, file);

            fs.stat(fullPath, (err, stat) => {
                if (err) {
                    console.error(`Gagal mendapatkan status file: ${fullPath}`, err);
                    return;
                }

                if (stat.isDirectory()) {
                    processDirectory(fullPath);
                } else if (TARGET_EXTENSIONS.includes(path.extname(fullPath))) {
                    replaceInFile(fullPath);
                }
            });
        });
    });
}

function replaceInFile(filePath) {
    fs.readFile(filePath, 'utf8', (err, data) => {
        if (err) {
            console.error(`Gagal membaca file: ${filePath}`, err);
            return;
        }

        if (data.includes(OLD_URL)) {
            const updatedData = data.replace(new RegExp(OLD_URL, 'g'), NEW_URL);
            
            fs.writeFile(filePath, updatedData, 'utf8', (err) => {
                if (err) {
                    console.error(`Gagal menulis ke file: ${filePath}`, err);
                } else {
                    console.log(`✅ URL diperbarui di: ${filePath}`);
                    filesChanged++;
                }
            });
        }
    });
}

console.log("Memulai proses pembaruan URL...");

TARGET_DIRS.forEach(dir => {
    const fullDirPath = path.join(__dirname, dir);
    if (fs.existsSync(fullDirPath)) {
        processDirectory(fullDirPath);
    } else {
        console.warn(`Peringatan: Direktori '${fullDirPath}' tidak ditemukan, dilewati.`);
    }
});

// Memberi tahu kapan proses selesai
process.on('exit', () => {
    console.log(`\n========================================`);
    console.log(`🎉 Proses selesai. Total ${filesChanged} file telah diperbarui.`);
    console.log("========================================");
});

const express = require("express");
const fs = require("fs");
const path = require("path");

const router = express.Router();

let fakelawData = [];

const filePath = path.join(__dirname, "..", "data", "fakelaws.json");

try {
  const raw = fs.readFileSync(filePath, "utf-8");
  const json = JSON.parse(raw);

  if (Array.isArray(json)) {
    fakelawData = json;
  } else if (Array.isArray(json.data)) {
    fakelawData = json.data;
  }
} catch (err) {
  console.error("Failed to read fakelaws.json:", err.message);
}

router.get("/", (req, res) => {
  if (!fakelawData.length) {
    return res.status(500).json({ error: "Fake law data not available." });
  }

  const randomIndex = Math.floor(Math.random() * fakelawData.length);
  const selected = fakelawData[randomIndex];

  res.json(selected);
});

module.exports = router;
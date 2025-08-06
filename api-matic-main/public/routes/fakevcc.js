const express = require("express");
const fs = require("fs");
const path = require("path");

const router = express.Router();

let vccData = [];

const filePath = path.join(__dirname, "..", "data", "fakevcc.json");

try {
  const raw = fs.readFileSync(filePath, "utf-8");
  const json = JSON.parse(raw);

  if (Array.isArray(json)) {
    vccData = json;
  } else if (Array.isArray(json.data)) {
    vccData = json.data;
  }
} catch (err) {
  console.error("Failed to read fakevcc.json:", err.message);
}

router.get("/", (req, res) => {
  if (!vccData.length) {
    return res.status(500).json({ error: "Fake VCC data not available." });
  }

  const randomIndex = Math.floor(Math.random() * vccData.length);
  const selected = vccData[randomIndex];

  res.json(selected);
});

module.exports = router;

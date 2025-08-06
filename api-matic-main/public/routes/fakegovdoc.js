const express = require("express");
const fs = require("fs");
const path = require("path");

const router = express.Router();
let govDocs = [];

const filePath = path.join(__dirname, "..", "data", "fake_government_documents.json");

try {
  const raw = fs.readFileSync(filePath, "utf-8");
  const json = JSON.parse(raw);

  if (Array.isArray(json)) {
    govDocs = json;
  } else if (Array.isArray(json.data)) {
    govDocs = json.data;
  }
} catch (err) {
  console.error("Failed to read fake_government_documents.json:", err.message);
}

router.get("/", (req, res) => {
  if (!govDocs.length) {
    return res.status(500).json({ error: "Fake government document data not available." });
  }

  const randomIndex = Math.floor(Math.random() * govDocs.length);
  const selected = govDocs[randomIndex];

  res.json(selected);
});

module.exports = router;

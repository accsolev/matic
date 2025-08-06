const express = require("express");
const fs = require("fs");
const path = require("path");

const router = express.Router();

let fakeCompanyData = [];

const filePath = path.join(__dirname, "..", "data", "fakecompanies.json");

try {
  const raw = fs.readFileSync(filePath, "utf-8");
  const json = JSON.parse(raw);

  if (Array.isArray(json)) {
    fakeCompanyData = json;
  } else if (Array.isArray(json.data)) {
    fakeCompanyData = json.data;
  }
} catch (err) {
  console.error("Failed to read fakecompanies.json:", err.message);
}

router.get("/", (req, res) => {
  if (!fakeCompanyData.length) {
    return res.status(500).json({ error: "Fake company data not available." });
  }

  const randomIndex = Math.floor(Math.random() * fakeCompanyData.length);
  const selected = fakeCompanyData[randomIndex];

  res.json(selected);
});

module.exports = router;

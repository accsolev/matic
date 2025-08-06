const express = require("express");
const fs = require("fs");
const path = require("path");

const router = express.Router();

let userData = [];

const filePath = path.join(__dirname, "..", "data", "randomuserv2.json");

try {
  const raw = fs.readFileSync(filePath, "utf-8");
  const json = JSON.parse(raw);

  if (Array.isArray(json)) {
    userData = json;
  } else if (Array.isArray(json.data)) {
    userData = json.data;
  }
} catch (err) {
  console.error("Failed to read randomuserv2.json:", err.message);
}

router.get("/", (req, res) => {
  if (!userData.length) {
    return res.status(500).json({ error: "User data not available." });
  }

  const randomIndex = Math.floor(Math.random() * userData.length);
  const selected = userData[randomIndex];

  res.json(selected);
});

module.exports = router;

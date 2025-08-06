const express = require('express');
const router = express.Router();
const { OpenAI } = require('openai');
const { v4: uuidv4 } = require('uuid');

const openai = new OpenAI({
  apiKey: process.env.NVIDIA_API_KEY,
  baseURL: 'https://openrouter.ai/api/v1',
  defaultHeaders: {
    'HTTP-Referer': 'https://yourdomain.com', // Ganti dengan domainmu
    'X-Title': 'MaticAPI',
  }
});

const sessions = {};

router.post('/', async (req, res) => {
  const { prompt, session } = req.body;

  if (!prompt) {
    return res.status(400).json({ success: false, error: 'Prompt wajib diisi' });
  }

  
  const sessionId = session || uuidv4();

  
  if (!sessions[sessionId]) {
    sessions[sessionId] = [];
  }

  
  sessions[sessionId].push({ role: 'user', content: prompt });

  try {
    const chatCompletion = await openai.chat.completions.create({
      model: 'nvidia/llama-3.1-nemotron-ultra-253b-v1:free',
      messages: sessions[sessionId],
    });

    const reply = chatCompletion.choices[0]?.message?.content || '';

    
    sessions[sessionId].push({ role: 'assistant', content: reply });

    res.json({
      success: true,
      response: reply,
      session: sessionId,
    });

  } catch (error) {
    console.error('Gagal dari OpenRouter:', error);
    res.status(500).json({
      success: false,
      error: error.message || 'Terjadi kesalahan tak dikenal',
    });
  }
});

module.exports = router;

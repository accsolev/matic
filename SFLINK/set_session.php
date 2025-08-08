<?php
ini_set('session.cookie_domain', '.sflink.id'); // <--- HARUS SEBELUM session_start()
session_start();
$_SESSION['test'] = 'hello from sflink.id';
echo "Session set!";
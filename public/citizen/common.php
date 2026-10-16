<?php
// public/citizen/common.php — citizen shared: config, base URLs, auth helper
session_start();
$cfg = require __DIR__ . '/../../lib/config.php';
$api_base = $cfg['api_base'];
$citizen_base = '/public/citizen';

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function citizen_user() {
    if (empty($_SESSION['citizen_token'])) return null;
    return $_SESSION['citizen_user'] ?? null;
}
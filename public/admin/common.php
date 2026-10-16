<?php
// public/admin/common.php — admin shared: session, JWT auth, navbar
session_start();
require_once __DIR__ . '/../../lib/auth.php';
$cfg = require __DIR__ . '/../../lib/config.php';
$base = $cfg['admin_base'];
$api_base = $cfg['api_base'];

// Resolve JWT from session (set after login)
if (empty($_SESSION['admin_token'])) {
    // try Authorization header as fallback for CLI/cookie-less access
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        $_SESSION['admin_token'] = $m[1];
    }
}

function admin_user() {
    global $cfg;
    $token = $_SESSION['admin_token'] ?? '';
    if ($token === '') return null;
    return jwt_decode($token, $cfg['jwt_secret']);
}

$user = admin_user();
$is_logged_in = (bool)$user;
$role = $user['role'] ?? null;

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function admin_nav($role) {
    $links = [];
    if ($role === 'admin') {
        $links[] = '<li><a href="' . $base . '/index.php">Dashboard</a></li>';
    }
    $links[] = '<li><a href="' . $base . '/scans.php">Scan Review</a></li>';
    $links[] = '<li><a href="' . $base . '/parcels.php">Parcels</a></li>';
    $links[] = '<li><a href="' . $base . '/rates.php">Zone Rates</a></li>';
    $links[] = '<li><a href="' . $base . '/payments.php">Payment Verify</a></li>';
    if ($role === 'admin') {
        $links[] = '<li><a href="' . $base . '/reports.php">Reports</a></li>';
    }
    return '<ul class="nav">' . implode('', $links) . '</ul>';
}

// api_call(method, path, body, token) — helper for server-side calls to the JSON API.
function api_call($method, $path, $body = null, $token = '') {
    global $api_base;
    $url = $api_base . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = ['Content-Type: application/json'];
    if ($token !== '') { $headers[] = 'Authorization: Bearer ' . $token; }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

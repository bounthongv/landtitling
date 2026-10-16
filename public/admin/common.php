<?php
// public/admin/common.php — admin shared: session, JWT auth, navbar
session_start();
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
    if (empty($_SESSION['admin_token'])) return null;
    return auth_current();
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

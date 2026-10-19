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

/**
 * Sidebar navigation. Basic Data is a parent with a submenu, and the current page is
 * highlighted. Only one zone-rate editor is linked: "Zone Rates" and Basic Data -> Zone
 * edit the same table, so they were pointing at different screens.
 */
function admin_nav($role) {
    global $base;
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $tab  = $_GET['tab'] ?? '';

    $basicDataItems = [
        'province' => 'Province',
        'districts'=> 'District',
        'villages' => 'Village',
        'zones'    => 'Zone rates',
        'years'    => 'Fee years',
        'refdata'  => 'Lookups',
    ];

    $links = [];
    if ($role === 'admin') {
        $links[] = '<li><a href="' . $base . '/index.php">Dashboard</a></li>';
    }
    $links[] = '<li><a href="' . $base . '/scans.php">Scan Review</a></li>';
    $links[] = '<li><a href="' . $base . '/parcels.php">Parcels</a></li>';
    $links[] = '<li><a href="' . $base . '/obligations.php">Obligations</a></li>';
    $links[] = '<li><a href="' . $base . '/payments.php">Payment Verify</a></li>';
    $links[] = '<li><a href="' . $base . '/bank_imports.php">Bank Import</a></li>';

    if ($role === 'admin') {
        $onBasicData = ($page === 'basic_data.php');
        $links[] = '<li><a href="' . $base . '/reports.php">Reports</a></li>';
        $links[] = '<li class="has-sub"><a href="' . $base . '/basic_data.php' . ($onBasicData ? '?tab=' . urlencode($tab ?: 'province') : '') . '">Basic Data</a>';
        if ($onBasicData) {
            $links[] = '<ul class="sub-nav">';
            foreach ($basicDataItems as $k => $label) {
                $active = ((string)$tab === $k) ? ' class="active"' : '';
                $links[] = '<li><a' . $active . ' href="' . $base . '/basic_data.php?tab=' . $k . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
            }
            $links[] = '</ul>';
        }
        $links[] = '</li>';
        $links[] = '<li><a href="' . $base . '/users.php">Users</a></li>';
    }
    return '<ul class="nav">' . implode('', $links) . '</ul>';
}

/** Active province name (basic data), falling back to the seeded default. */
function admin_province_name() {
    global $api_base;
    static $name = null;
    if ($name !== null) {
        return $name;
    }
    $name = 'Savannakhet';
    try {
        $r = api_call('GET', 'refdata.php?table=provinces');
        if (!empty($r['ok']) && !empty($r['data'][0]['name_en'])) {
            $name = $r['data'][0]['name_en'];
        }
    } catch (\Throwable $e) {
        // keep the default
    }
    return $name;
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

// api_upload(path, tmpPath, fileName, mime, token, field) — multipart POST to the JSON API.
function api_upload($path, $tmpPath, $fileName, $mime, $token = '', $field = 'image') {
    global $api_base;
    $url = $api_base . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [$field => new CURLFile($tmpPath, $mime ?: 'application/octet-stream', $fileName)],
        CURLOPT_HTTPHEADER => $token !== '' ? ['Authorization: Bearer ' . $token] : [],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

// api_fetch_raw(method, path, token) — returns the response body as a string.
// Use for non-JSON responses (e.g. CSV exports), which api_call() cannot decode.
function api_fetch_raw($method, $path, $token = '') {
    global $api_base;
    $url = $api_base . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = [];
    if ($token !== '') { $headers[] = 'Authorization: Bearer ' . $token; }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$resp];
}

// api_upload_multipart(path, fileArr, fieldNames, postArr, token, fileField) — multipart POST
// that also forwards selected plain form fields alongside the uploaded file.
function api_upload_multipart($path, array $fileArr, array $fieldNames, array $postArr, $token = '', $fileField = 'file') {
    global $api_base;
    $url = $api_base . '/' . ltrim($path, '/');
    $fields = [];
    foreach ($fieldNames as $n) {
        if (isset($postArr[$n])) { $fields[$n] = (string)$postArr[$n]; }
    }
    $fields[$fileField] = new CURLFile($fileArr['tmp_name'], $fileArr['type'] ?: 'text/csv', $fileArr['name']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => $token !== '' ? ['Authorization: Bearer ' . $token] : [],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

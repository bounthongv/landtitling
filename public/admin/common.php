<?php
// public/admin/common.php — admin shared: session, JWT auth, navbar
session_start();
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/i18n.php';
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
        'refdata'  => 'Land Lookups',
    ];

    // Icons (inline SVG paths) for each nav item
    $icons = [
        'index.php'        => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'scans.php'        => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
        'parcels.php'      => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>',
        'obligations.php'  => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'payments.php'     => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
        'bank_imports.php' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M3 10h18"/><path d="M5 6l7-3 7 3"/><line x1="4" y1="10" x2="4" y2="21"/><line x1="8" y1="10" x2="8" y2="21"/><line x1="12" y1="10" x2="12" y2="21"/><line x1="16" y1="10" x2="16" y2="21"/><line x1="20" y1="10" x2="20" y2="21"/></svg>',
        'reports.php'      => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'basic_data.php'   => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
        'users.php'        => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
    ];

    $links = [];
    if ($role === 'admin') {
        $act = ($page === 'index.php') ? ' class="active"' : '';
        $links[] = '<li' . (($page === 'index.php') ? ' class="active"' : '') . '><a' . $act . ' href="' . $base . '/index.php">' . ($icons['index.php'] ?? '') . '<span>' . te('Dashboard') . '</span></a></li>';
    }

    $navItems = [
        'scans.php'        => 'Scan Review',
        'parcels.php'      => 'Parcels',
        'obligations.php'  => 'Obligations',
        'payments.php'     => 'Payment Verify',
        'bank_imports.php' => 'Bank Import',
    ];
    foreach ($navItems as $file => $label) {
        $act = ($page === $file) ? ' class="active"' : '';
        $liAct = ($page === $file) ? ' class="active"' : '';
        $links[] = '<li' . $liAct . '><a' . $act . ' href="' . $base . '/' . $file . '">' . ($icons[$file] ?? '') . '<span>' . te($label) . '</span></a></li>';
    }

    if ($role === 'admin') {
        $onBasicData = ($page === 'basic_data.php');

        // Reports
        $act = ($page === 'reports.php') ? ' class="active"' : '';
        $liAct = ($page === 'reports.php') ? ' class="active"' : '';
        $links[] = '<li' . $liAct . '><a' . $act . ' href="' . $base . '/reports.php">' . ($icons['reports.php'] ?? '') . '<span>' . te('Reports') . '</span></a></li>';

        // Basic Data with submenu
        $liCls = $onBasicData ? ' class="has-sub active"' : ' class="has-sub"';
        $aCls  = $onBasicData ? ' class="active"' : '';
        $links[] = '<li' . $liCls . '><a' . $aCls . ' href="' . $base . '/basic_data.php' . ($onBasicData ? '?tab=' . urlencode($tab ?: 'province') : '') . '">' . ($icons['basic_data.php'] ?? '') . '<span>' . te('Basic Data') . '</span></a>';
        if ($onBasicData) {
            $links[] = '<ul class="sub-nav">';
            foreach ($basicDataItems as $k => $label) {
                $active = ((string)$tab === $k) ? ' class="active"' : '';
                $links[] = '<li><a' . $active . ' href="' . $base . '/basic_data.php?tab=' . $k . '">' . te($label) . '</a></li>';
            }
            $links[] = '</ul>';
        }
        $links[] = '</li>';

        // Users
        $act = ($page === 'users.php') ? ' class="active"' : '';
        $liAct = ($page === 'users.php') ? ' class="active"' : '';
        $links[] = '<li' . $liAct . '><a' . $act . ' href="' . $base . '/users.php">' . ($icons['users.php'] ?? '') . '<span>' . te('Users') . '</span></a></li>';
    }
    return '<ul class="side-nav">' . implode('', $links) . '</ul>';
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

/** Sidebar top: language toggle (EN | ລາວ) as first line. */
function admin_side_top() {
    return '<div class="lang-toggle">' . lang_switch() . '</div>' .
           '<div class="brand">' . te('Land Tax Collection System') . '</div>';
}

/** Sidebar footer: (empty). */
function admin_side_foot() {
    return '<div class="side-foot"></div>';
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

// api_upload(path, tmpPath, fileName, mime, token, field, extraFields) — multipart POST to the JSON API.
// extraFields: optional associative array of additional form fields to include in the multipart POST.
function api_upload($path, $tmpPath, $fileName, $mime, $token = '', $field = 'image', $extraFields = []) {
    global $api_base;
    $url = $api_base . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $fields = [$field => new CURLFile($tmpPath, $mime ?: 'application/octet-stream', $fileName)];
    // Add any extra fields (e.g., ocr_method)
    foreach ($extraFields as $k => $v) {
        $fields[$k] = $v;
    }
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

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
$is_manager = ($role === 'manager');

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Format a numeric value with thousands separators (###,###,###).
 * Whole numbers stay integer-formatted; values carrying a fractional part keep
 * two decimals. Non-numeric values pass through as strings. Used for summary
 * figures so the big totals read the same as the table cells.
 */
function fmt_num($v): string {
    if (!is_numeric($v)) { return (string)$v; }
    $f = (float)$v;
    return fmod($f, 1.0) === 0.0 ? number_format($f, 0) : number_format($f, 2);
}

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
        'parcel_entry.php' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'parcels.php'      => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>',
        'obligations.php'  => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'payments.php'     => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
        'bank_imports.php' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M3 10h18"/><path d="M5 6l7-3 7 3"/><line x1="4" y1="10" x2="4" y2="21"/><line x1="8" y1="10" x2="8" y2="21"/><line x1="12" y1="10" x2="12" y2="21"/><line x1="16" y1="10" x2="16" y2="21"/><line x1="20" y1="10" x2="20" y2="21"/></svg>',
        'reports.php'      => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'monitor.php'      => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><polyline points="7 15 11 10 14 13 19 6"/></svg>',
        'basic_data.php'   => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
        'users.php'        => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
    ];

    $links = [];
    // Managers are read-only but see every screen an officer sees: they start
    // at the monitoring Overview and then follow the normal officer menu. All
    // writes are refused by the API, so this only changes visibility.
    if ($role === 'manager') {
        $act = ($page === 'monitor.php') ? ' class="active"' : '';
        $links[] = '<li' . $act . '><a' . $act . ' href="' . $base . '/monitor.php">' . ($icons['monitor.php'] ?? '') . '<span>' . te('Overview') . '</span></a></li>';
    }

    // Dashboard is visible to all staff roles (read-only stats).
    $act = ($page === 'index.php') ? ' class="active"' : '';
    $links[] = '<li' . $act . '><a' . $act . ' href="' . $base . '/index.php">' . ($icons['index.php'] ?? '') . '<span>' . te('Dashboard') . '</span></a></li>';

    // Basic Data is read-only for managers; admins edit it.
    if (in_array($role, ['admin', 'manager'], true)) {
        $onBasicData = ($page === 'basic_data.php');

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
    }

    $navItems = [
        'parcel_entry.php' => 'Parcel Entry',
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

    // Reports — read-only, visible to all staff roles.
    $act = ($page === 'reports.php') ? ' class="active"' : '';
    $links[] = '<li' . $act . '><a' . $act . ' href="' . $base . '/reports.php">' . ($icons['reports.php'] ?? '') . '<span>' . te('Reports') . '</span></a></li>';

    // Users — visible to all staff (view list); create/edit is admin-only (enforced in the API).
    $act = ($page === 'users.php') ? ' class="active"' : '';
    $links[] = '<li' . $act . '><a' . $act . ' href="' . $base . '/users.php">' . ($icons['users.php'] ?? '') . '<span>' . te('Users') . '</span></a></li>';
    // Logout link at the bottom
    $links[] = '<li class="logout-li"><a href="' . $base . '/logout.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>' . te('Logout') . '</span></a></li>';
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

/** Sidebar top: language toggle (ລາວ | EN) as first line. */
function admin_side_top() {
    return '<div class="lang-toggle">' . lang_switch() . '</div>' .
           '<div class="brand">' . te('Land Tax Collection System') . '</div>';
}

/** Sidebar footer: district + province (district staff) or province only (province staff).
 *  Names follow the current UI language (Lao names when the UI is in Lao). */
function admin_side_foot() {
    $u = $_SESSION['admin_user'] ?? [];
    $lo = i18n_current() === 'lo';
    $district = ($lo && !empty($u['district_name_lo'])) ? $u['district_name_lo'] : ($u['district_name'] ?? '');
    $province = ($lo && !empty($u['province_name_lo'])) ? $u['province_name_lo'] : ($u['province_name'] ?? '');
    $lines = [];
    if ($district !== '') {
        $lines[] = '<div class="side-foot-district">' . e($district) . '</div>';
    }
    if ($province !== '') {
        $lines[] = '<div class="side-foot-province">' . e($province) . '</div>';
    }
    if (!$lines) return '<div class="side-foot"></div>';
    return '<div class="side-foot">' . implode('', $lines) . '</div>';
}

/**
 * Resolve the report period picker into a from/to date pair plus the interval
 * in words.
 *
 * All five radios submit, so only the controls belonging to the selected mode
 * are read — a value left in the URL from an earlier pick cannot change the
 * period. Every mode collapses to two dates because a date range is the only
 * thing the report API understands. The words ride along because a document
 * signed by two offices has to name the interval the officer actually picked:
 * quarter 2, not two dates nobody typed.
 *
 * @return array{mode:string,from:string,to:string,words:string}  empty dates = no period
 */
function report_period(array $get)
{
    $modes = ['range', 'month', 'quarter', 'half', 'year'];
    $mode  = isset($get['pmode']) && in_array($get['pmode'], $modes, true) ? $get['pmode'] : 'range';

    $bounded = static function ($v, int $min, int $max): int {
        return is_numeric($v) && (int)$v >= $min && (int)$v <= $max ? (int)$v : 0;
    };
    $isoDate = static function ($v): string {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
    };
    $none = ['mode' => $mode, 'from' => '', 'to' => '', 'words' => ''];

    if ($mode === 'range') {
        $from = $isoDate($get['from'] ?? '');
        $to   = $isoDate($get['to'] ?? '');
        if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }
        // Default: current month (first to last day) so the picker never opens blank.
        if ($from === '' && $to === '') {
            $from = date('Y-m-01');
            $to   = date('Y-m-t');
        }
        $dmy = static function ($d) { return date('d/m/Y', strtotime($d)); };
        if ($from !== '' && $to !== '') {
            $words = t('For {from} - {to}', ['from' => $dmy($from), 'to' => $dmy($to)]);
        } elseif ($from !== '') {
            $words = t('From {date}', ['date' => $dmy($from)]);
        } elseif ($to !== '') {
            $words = t('To {date}', ['date' => $dmy($to)]);
        } else {
            $words = '';
        }
        return ['mode' => $mode, 'from' => $from, 'to' => $to, 'words' => $words];
    }

    $yearKey = ['month' => 'p_year_m', 'quarter' => 'p_year_q', 'half' => 'p_year_h', 'year' => 'p_year'];
    $year = $bounded($get[$yearKey[$mode]] ?? 0, 1900, 2999);
    if (!$year) { return $none; }

    $startMonth = 1;
    $endMonth   = 12;
    if ($mode === 'month') {
        $m = $bounded($get['p_month'] ?? 0, 1, 12);
        if (!$m) { return $none; }
        $startMonth = $endMonth = $m;
    } elseif ($mode === 'quarter') {
        $q = $bounded($get['p_quarter'] ?? 0, 1, 4);
        if (!$q) { return $none; }
        $startMonth = 3 * $q - 2;
        $endMonth   = 3 * $q;
    } elseif ($mode === 'half') {
        $h = $bounded($get['p_half'] ?? 0, 1, 2);
        if (!$h) { return $none; }
        $startMonth = $h === 1 ? 1 : 7;
        $endMonth   = $h === 1 ? 6 : 12;
    }

    $from = sprintf('%04d-%02d-01', $year, $startMonth);
    $to   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $endMonth)));
    if ($mode === 'month') {
        $words = t('For month {month} of {year}', ['month' => $startMonth, 'year' => $year]);
    } elseif ($mode === 'quarter') {
        $words = t('For quarter {quarter} of {year}', ['quarter' => (int)(($startMonth + 2) / 3), 'year' => $year]);
    } elseif ($mode === 'half') {
        $words = t('For months {first}-{last} of {year}', ['first' => $startMonth, 'last' => $endMonth, 'year' => $year]);
    } else {
        $words = t('For year {year}', ['year' => $year]);
    }
    return ['mode' => $mode, 'from' => $from, 'to' => $to, 'words' => $words];
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

<?php
// Config: edit per environment (local / dev / prod).
// Secrets come from environment vars, optionally loaded from a repo-root .env
// file (gitignored) so the committed config is identical across environments.

// Load .env from repo root if present (prod secrets live here, not in git).
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if ($k !== '' && !getenv($k)) putenv($k . '=' . $v);
    }
}

// Build a usable base URL from the incoming request (works in local dev and prod).
$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? '') === '443';
$__scheme = $__https ? 'https' : 'http';
$__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

return [
    'pgsql_host'   => getenv('PGHOST') ?: 'localhost',
    'pgsql_port'   => (int)(getenv('PGPORT') ?: 5432),
    'pgsql_db'     => getenv('PGDATABASE') ?: 'landfee',
    'pgsql_user'   => getenv('PGUSER') ?: 'postgres',
    'pgsql_pass'   => getenv('PGPASSWORD') ?: '',
    'timezone'     => 'Asia/Vientiane',
    'uploads'      => getenv('UPLOADS') ?: (dirname(__DIR__) . '/public/uploads/'),
    'jwt_secret'   => getenv('JWT_SECRET') ?: 'landfee-dev-secret-change-me',
    'jwt_ttl'      => 86400, // seconds (24h)
    // Schema name for the landfee tables
    'pgsql_schema' => getenv('PG_SCHEMA') ?: 'landfee',
    // API base (same origin as this app, /api/v1) — dynamic so it works locally + prod.
    // API_BASE env override is for local dev/testing when the API runs on a different port.
    'api_base'     => getenv('API_BASE') ?: ($__scheme . '://' . $__host . '/api/v1'),
    // Admin public base path.
    'admin_base'   => '/public/admin',
    // Dev-only: echo the OTP back in the API response so the login screen can show it.
    // MUST be unset/false in production, otherwise any caller can read the code.
    'otp_debug'    => (getenv('OTP_DEBUG') ?: '0') === '1',
    // Optional dir of improved Tesseract traineddata (e.g. tessdata_best lao model).
    // When set, ocr.php passes --tessdata-dir so the app uses it without touching the
    // system model. Empty = use the system default tessdata.
    'ocr_tessdata_dir' => getenv('OCR_TESSDATA_DIR') ?: '',
    // Gemini API key for OCR (optional). Can also be set via GEMINI_API_KEY env var.
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: '',
    // Outbound email (OTP channel). Requires a system MTA on the host (e.g. Postfix).
    'mail_from'      => getenv('MAIL_FROM') ?: '',
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'Land Fee Savannakhet',
];
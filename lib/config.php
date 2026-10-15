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

return [
    'pgsql_host'   => getenv('PGHOST') ?: 'localhost',
    'pgsql_port'   => (int)(getenv('PGPORT') ?: 5432),
    'pgsql_db'     => getenv('PGDATABASE') ?: 'landfee',
    'pgsql_user'   => getenv('PGUSER') ?: 'postgres',
    'pgsql_pass'   => getenv('PGPASSWORD') ?: '',
    'timezone'     => 'Asia/Vientiane',
    'uploads'      => __DIR__ . '/../public/uploads',
    'jwt_secret'   => getenv('JWT_SECRET') ?: 'landfee-dev-secret-change-me',
    'jwt_ttl'      => 86400, // seconds (24h)
    // Schema name for the landfee tables
    'pgsql_schema' => getenv('PG_SCHEMA') ?: 'landfee',
];
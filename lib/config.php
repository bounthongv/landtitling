<?php
// Config: edit per environment (local / dev / prod)
return [
    // Local dev: fall back to known values when env vars not exported to server
    'pgsql_host' => ($_ENV['PGHOST'] ?? getenv('PGHOST') ?: 'localhost'),
    'pgsql_port' => ((int)($_ENV['PGPORT'] ?? getenv('PGPORT') ?: 5432)),
    'pgsql_db'   => ($_ENV['PGDATABASE'] ?? getenv('PGDATABASE') ?: 'landfee'),
    'pgsql_user' => ($_ENV['PGUSER'] ?? getenv('PGUSER') ?: 'postgres'),
    'pgsql_pass' => ($_ENV['PGPASSWORD'] ?? getenv('PGPASSWORD') ?: 'test1234'), // local dev fallback
    'timezone'   => 'Asia/Vientiane',
    'uploads'    => __DIR__ . '/../public/uploads',
    'jwt_secret' => getenv('JWT_SECRET') ?: 'landfee-dev-secret-change-me',
    'jwt_ttl'    => 86400, // seconds (24h)
    // Schema name for the landfee tables (default = database name, but must match schema.sql)
    'pgsql_schema' => getenv('PG_SCHEMA') ?: 'landfee',
];
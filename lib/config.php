<?php
// Config: edit per environment (local / dev / prod)
return [
    'pgsql_host' => getenv('PGHOST') ?: 'localhost',
    'pgsql_port' => getenv('PGPORT') ?: 5432,
    'pgsql_db'   => getenv('PGDATABASE') ?: 'landfee',
    'pgsql_user' => getenv('PGUSER') ?: 'postgres',
    'pgsql_pass' => getenv('PGPASSWORD') ?: '',
    'timezone'   => 'Asia/Vientiane',
    'uploads'    => __DIR__ . '/../public/uploads',
    'jwt_secret' => getenv('JWT_SECRET') ?: 'landfee-dev-secret-change-me',
    'jwt_ttl'    => 86400, // seconds (24h)
];
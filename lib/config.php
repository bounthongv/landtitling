<?php
// Config: edit per environment (local / dev / prod)
return [
    'mysql_host' => getenv('DB_HOST') ?: 'localhost',
    'mysql_db'   => getenv('DB_NAME') ?: 'landfee_savannakhet',
    'mysql_user' => getenv('DB_USER') ?: 'root',
    'mysql_pass' => getenv('DB_PASS') ?: '',
    'timezone'   => 'Asia/Vientiane',
    'uploads'    => __DIR__ . '/../uploads/',
];

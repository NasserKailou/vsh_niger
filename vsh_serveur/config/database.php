<?php

declare(strict_types=1);

use Vsh\Core\Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', 3306),
    'database' => Env::get('DB_DATABASE', 'vsh_homecare'),
    'username' => Env::get('DB_USERNAME', ''),
    'password' => Env::get('DB_PASSWORD', ''),
];

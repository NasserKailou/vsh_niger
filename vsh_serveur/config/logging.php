<?php

declare(strict_types=1);

use Vsh\Core\Env;

return [
    'path' => VSH_BASE_PATH . '/storage/logs',
    'level' => Env::get('LOG_LEVEL', 'info'),
];

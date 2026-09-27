<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\System\HealthController;

return function (Router $router): void {
    $router->get('/health', [HealthController::class, 'show']);
};

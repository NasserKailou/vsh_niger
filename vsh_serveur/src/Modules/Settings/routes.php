<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Settings\SettingsController;

return function (Router $router): void {
    $router->get('/settings', [SettingsController::class, 'index'], ['auth', 'permission:settings.manage']);
    $router->put('/settings', [SettingsController::class, 'update'], ['auth', 'permission:settings.manage']);
};

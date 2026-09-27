<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Audit\AuditController;

return function (Router $router): void {
    // Lecture seule : le journal d'audit n'est jamais modifiable par l'API.
    $router->group('', ['auth', 'permission:audit.read'], function (Router $router): void {
        $router->get('/audit-logs', [AuditController::class, 'index']);
        $router->get('/audit-logs/facets', [AuditController::class, 'facets']);
    });
};

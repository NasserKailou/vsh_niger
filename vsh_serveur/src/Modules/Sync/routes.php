<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Sync\SyncController;

return function (Router $router): void {
    $router->group('/sync', ['auth'], function (Router $router): void {
        $router->post('/push', [SyncController::class, 'push']);
        $router->get('/pull', [SyncController::class, 'pull']);
        $router->get('/status', [SyncController::class, 'status']);
        $router->get('/conflicts', [SyncController::class, 'conflicts']);
        $router->post('/conflicts/{id:uuid}/resolve', [SyncController::class, 'resolveConflict']);

        $router->get('/patients', [SyncController::class, 'pinned'], ['permission:patients.read']);
        $router->post('/patients/{id:uuid}/pin', [SyncController::class, 'pin'], ['permission:patients.read']);
        $router->delete('/patients/{id:uuid}/pin', [SyncController::class, 'unpin'], ['permission:patients.read']);

        $supervise = ['permission:sync.supervise'];
        $router->get('/devices', [SyncController::class, 'devices'], $supervise);
        $router->post('/devices/{id:uuid}/revoke', [SyncController::class, 'revokeDevice'], $supervise);
        $router->get('/operations', [SyncController::class, 'operations'], $supervise);
    });
};

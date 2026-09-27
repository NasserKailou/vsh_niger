<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Notifications\NotificationController;

return function (Router $router): void {
    $router->get('/notifications', [NotificationController::class, 'index'], ['auth']);
    $router->post('/notifications/read-all', [NotificationController::class, 'markAllRead'], ['auth']);
    $router->post('/notifications/{id:uuid}/read', [NotificationController::class, 'markRead'], ['auth']);

    // D-011 : jeton push de l'appareil de la session, et supervision des envois push et SMS.
    $router->post('/push-tokens', [NotificationController::class, 'registerPushToken'], ['auth']);
    $router->delete('/push-tokens', [NotificationController::class, 'unregisterPushToken'], ['auth']);
    $router->get('/notification-deliveries', [NotificationController::class, 'deliveries'], ['auth', 'permission:sync.supervise']);
};

<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Auth\AuthController;

return function (Router $router): void {
    // Public
    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/refresh', [AuthController::class, 'refresh']);
    $router->post('/auth/password/forgot', [AuthController::class, 'forgotPassword']);
    $router->post('/auth/password/reset', [AuthController::class, 'resetPassword']);

    // Accessibles même lorsqu'un changement de mot de passe est exigé
    $router->get('/me', [AuthController::class, 'me'], ['auth:allow_password_change']);
    $router->put('/auth/password', [AuthController::class, 'changePassword'], ['auth:allow_password_change']);
    $router->post('/auth/logout', [AuthController::class, 'logout'], ['auth:allow_password_change']);

    $router->get('/me/devices', [AuthController::class, 'devices'], ['auth']);
    $router->delete('/me/devices/{id:uuid}', [AuthController::class, 'revokeDevice'], ['auth']);
};

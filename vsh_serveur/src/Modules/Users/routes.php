<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Users\RoleController;
use Vsh\Modules\Users\StaffDirectoryController;
use Vsh\Modules\Users\UserController;

return function (Router $router): void {
    $router->group('', ['auth'], function (Router $router): void {
        $router->get('/users', [UserController::class, 'index'], ['permission:users.read']);
        $router->post('/users', [UserController::class, 'store'], ['permission:users.manage']);
        $router->get('/users/{id:uuid}', [UserController::class, 'show'], ['permission:users.read']);
        $router->put('/users/{id:uuid}', [UserController::class, 'update'], ['permission:users.manage']);
        $router->post('/users/{id:uuid}/suspend', [UserController::class, 'suspend'], ['permission:users.manage']);
        $router->post('/users/{id:uuid}/activate', [UserController::class, 'activate'], ['permission:users.manage']);
        $router->post('/users/{id:uuid}/reset-password', [UserController::class, 'resetPassword'], ['permission:users.manage']);

        $router->get('/roles', [RoleController::class, 'index'], ['permission:users.read']);
        $router->post('/roles', [RoleController::class, 'store'], ['permission:roles.manage']);
        $router->put('/roles/{code}', [RoleController::class, 'update'], ['permission:roles.manage']);
        $router->delete('/roles/{code}', [RoleController::class, 'destroy'], ['permission:roles.manage']);
        $router->get('/permissions', [RoleController::class, 'permissions'], ['permission:roles.manage']);

        // Annuaire des soignants (listes de choix) : droits vérifiés dans StaffDirectory.
        $router->get('/staff/directory', [StaffDirectoryController::class, 'index']);
    });
};

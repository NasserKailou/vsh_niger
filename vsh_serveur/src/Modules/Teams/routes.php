<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Teams\TeamController;

return function (Router $router): void {
    $router->group('', ['auth'], function (Router $router): void {
        $read = ['permission:teams.read'];
        $manage = ['permission:teams.manage'];
        $router->get('/me/teams', [TeamController::class, 'mine']);
        $router->get('/teams', [TeamController::class, 'index'], $read);
        $router->post('/teams', [TeamController::class, 'store'], $manage);
        $router->get('/teams/{id:uuid}', [TeamController::class, 'show'], $read);
        $router->put('/teams/{id:uuid}', [TeamController::class, 'update'], $manage);
        $router->post('/teams/{id:uuid}/members', [TeamController::class, 'addMember'], $manage);
        $router->put('/teams/{id:uuid}/members/{memberId:uuid}', [TeamController::class, 'updateMember'], $manage);
    });
};

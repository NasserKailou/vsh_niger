<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Treatments\TreatmentController;

return function (Router $router): void {
    $router->group('', ['auth'], function (Router $router): void {
        $read = ['permission:treatments.read'];
        $perform = ['permission:treatments.perform'];
        $router->get('/treatments', [TreatmentController::class, 'index'], $read);
        $router->post('/treatments', [TreatmentController::class, 'store'], $perform);
        $router->get('/treatments/{id:uuid}', [TreatmentController::class, 'show'], $read);
        $router->put('/treatments/{id:uuid}', [TreatmentController::class, 'update'], $perform);
        $router->post('/treatments/{id:uuid}/perform', [TreatmentController::class, 'perform'], $perform);
        $router->post('/treatments/{id:uuid}/cancel', [TreatmentController::class, 'cancel'], $perform);
    });
};

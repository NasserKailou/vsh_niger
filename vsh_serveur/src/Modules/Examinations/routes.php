<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Examinations\ExaminationController;

return function (Router $router): void {
    $router->group('', ['auth'], function (Router $router): void {
        $read = ['permission:examinations.read'];
        $perform = ['permission:examinations.perform'];
        $router->get('/examinations', [ExaminationController::class, 'index'], $read);
        $router->post('/examinations', [ExaminationController::class, 'store'], ['permission:examinations.prescribe']);
        $router->get('/examinations/{id:uuid}', [ExaminationController::class, 'show'], $read);
        $router->post('/examinations/{id:uuid}/start', [ExaminationController::class, 'start'], $perform);
        $router->post('/examinations/{id:uuid}/results', [ExaminationController::class, 'results'], $perform);
        $router->post('/examinations/{id:uuid}/complete', [ExaminationController::class, 'complete'], $perform);
        $router->post('/examinations/{id:uuid}/validate', [ExaminationController::class, 'validate'], ['permission:examinations.validate']);
        // Prescripteur ou technicien : contrôle fin dans le service.
        $router->post('/examinations/{id:uuid}/cancel', [ExaminationController::class, 'cancel'], $read);
    });
    $router->get('/me/patients/{id:uuid}/examinations', [ExaminationController::class, 'forOwnPatient'], ['auth', 'permission:self.record.read']);
};

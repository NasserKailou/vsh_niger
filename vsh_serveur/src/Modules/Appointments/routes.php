<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Appointments\AppointmentController;

return function (Router $router): void {
    $router->group('', ['auth'], function (Router $router): void {
        $manage = ['permission:appointments.manage'];
        // Créneaux, création, fiche et annulation : patient ou personnel, contrôle fin dans le service.
        $router->get('/appointments/slots', [AppointmentController::class, 'slots']);
        $router->get('/appointments', [AppointmentController::class, 'index'], ['permission:appointments.read']);
        $router->post('/appointments', [AppointmentController::class, 'store']);
        $router->get('/appointments/{id:uuid}', [AppointmentController::class, 'show']);
        $router->post('/appointments/{id:uuid}/confirm', [AppointmentController::class, 'confirm'], $manage);
        $router->post('/appointments/{id:uuid}/reschedule', [AppointmentController::class, 'reschedule'], $manage);
        $router->post('/appointments/{id:uuid}/cancel', [AppointmentController::class, 'cancel']);
        $router->post('/appointments/{id:uuid}/check-in', [AppointmentController::class, 'checkIn'], $manage);
        $router->post('/appointments/{id:uuid}/no-show', [AppointmentController::class, 'noShow'], $manage);
    });
    $router->get('/me/patients/{id:uuid}/appointments', [AppointmentController::class, 'forOwnPatient'], ['auth', 'permission:self.record.read']);
};

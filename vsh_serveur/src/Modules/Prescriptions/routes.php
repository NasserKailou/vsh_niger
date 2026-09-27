<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Prescriptions\PrescriptionController;
use Vsh\Modules\Prescriptions\PrescriptionTemplateController;

return function (Router $router): void {
    $router->group('', ['auth'], function (Router $router): void {
        $read = ['permission:prescriptions.read'];
        $write = ['permission:prescriptions.write'];
        $router->get('/prescriptions', [PrescriptionController::class, 'index'], $read);
        $router->post('/prescriptions', [PrescriptionController::class, 'store'], $write);
        $router->get('/prescriptions/{id:uuid}', [PrescriptionController::class, 'show'], $read);
        $router->get('/prescriptions/{id:uuid}/pdf', [PrescriptionController::class, 'pdf'], $read);
        $router->put('/prescriptions/{id:uuid}', [PrescriptionController::class, 'update'], $write);
        $router->post('/prescriptions/{id:uuid}/sign', [PrescriptionController::class, 'sign'], ['permission:prescriptions.sign']);
        $router->post('/prescriptions/{id:uuid}/cancel', [PrescriptionController::class, 'cancel'], $write);

        $templates = ['permission:prescription_templates.read'];
        $manage = ['permission:prescription_templates.manage'];
        $router->get('/prescription-templates', [PrescriptionTemplateController::class, 'index'], $templates);
        $router->get('/prescription-templates/suggest', [PrescriptionTemplateController::class, 'suggest'], $templates);
        $router->post('/prescription-templates', [PrescriptionTemplateController::class, 'store'], $manage);
        $router->get('/prescription-templates/{id:uuid}', [PrescriptionTemplateController::class, 'show'], $templates);
        $router->put('/prescription-templates/{id:uuid}', [PrescriptionTemplateController::class, 'update'], $manage);
        $router->post('/prescription-templates/{id:uuid}/approve', [PrescriptionTemplateController::class, 'approve'], ['permission:prescription_templates.approve']);
        $router->post('/prescription-templates/{id:uuid}/archive', [PrescriptionTemplateController::class, 'archive'], $templates); // Gestion ou approbation : contrôle dans le service.
    });
    $router->get('/me/patients/{id:uuid}/prescriptions', [PrescriptionController::class, 'forOwnPatient'], ['auth', 'permission:self.record.read']);
    $router->get('/me/patients/{id:uuid}/prescriptions/{prescriptionId:uuid}/pdf', [PrescriptionController::class, 'pdfForOwnPatient'], ['auth', 'permission:self.record.read']);
};

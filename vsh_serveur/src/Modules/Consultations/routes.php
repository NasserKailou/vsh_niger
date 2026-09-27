<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Consultations\ConsultationController;

return function (Router $router): void {
    $router->group('', ['auth'], function (Router $router): void {
        $read = ['permission:consultations.read'];
        $router->get('/consultations', [ConsultationController::class, 'index'], $read);
        $router->post('/consultations', [ConsultationController::class, 'store'], ['permission:consultations.create']);
        $router->get('/consultations/{id:uuid}', [ConsultationController::class, 'show'], $read);
        $router->put('/consultations/{id:uuid}', [ConsultationController::class, 'update'], ['permission:consultations.update']);
        $router->post('/consultations/{id:uuid}/close', [ConsultationController::class, 'close'], ['permission:consultations.close']);
        $router->post('/consultations/{id:uuid}/cancel', [ConsultationController::class, 'cancel'], ['permission:consultations.update']);
        $router->post('/consultations/{id:uuid}/vitals', [ConsultationController::class, 'addVitals'], ['permission:vitals.record']);
        $router->post('/consultations/{id:uuid}/diagnoses', [ConsultationController::class, 'addDiagnosis'], ['permission:diagnoses.write']);
        $router->delete('/consultations/{id:uuid}/diagnoses/{diagnosisId:uuid}', [ConsultationController::class, 'deleteDiagnosis'], ['permission:diagnoses.write']);
        $router->post('/consultations/{id:uuid}/notes', [ConsultationController::class, 'addNote'], ['permission:consultations.update']);

        $router->get('/patients/{id:uuid}/consultations', [ConsultationController::class, 'forPatient'], $read);
        // Droits médicaux vérifiés par PatientPolicy
        $router->get('/patients/{id:uuid}/vitals', [ConsultationController::class, 'patientVitals'], ['permission:patients.read']);
        $router->post('/patients/{id:uuid}/vitals', [ConsultationController::class, 'addPatientVitals'], ['permission:vitals.record']);
    });
};

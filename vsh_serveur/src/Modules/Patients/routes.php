<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Patients\PatientChildren;
use Vsh\Modules\Patients\PatientController;
use Vsh\Modules\Patients\RegistrationController;

return function (Router $router): void {
    // Public : inscription et portail web patient
    $router->post('/auth/register/code', [RegistrationController::class, 'requestCode']);
    $router->post('/auth/register', [RegistrationController::class, 'register']);
    $router->post('/auth/patient-portal/code', [RegistrationController::class, 'portalCode']);
    $router->post('/auth/patient-portal/login', [RegistrationController::class, 'portalLogin']);

    // Espace patient : uniquement les dossiers rattachés au compte
    $router->group('/me/patients', ['auth', 'permission:self.record.read'], function (Router $router): void {
        $router->get('', [RegistrationController::class, 'myPatients']);
        $router->post('', [RegistrationController::class, 'addDependent']);
        $router->get('/{id:uuid}', [RegistrationController::class, 'myPatient']);
    });

    // Personnel
    $router->group('', ['auth'], function (Router $router): void {
        $read = ['permission:patients.read'];

        $router->get('/patients', [PatientController::class, 'index'], $read);
        $router->post('/patients/search', [PatientController::class, 'search'], $read);
        $router->post('/patients', [PatientController::class, 'store'], ['permission:patients.create']);
        $router->get('/patients/{id:uuid}', [PatientController::class, 'show'], $read);
        $router->put('/patients/{id:uuid}', [PatientController::class, 'update'], ['permission:patients.update']);
        $router->put('/patients/{id:uuid}/attending-physician', [PatientController::class, 'attendingPhysician'], ['permission:patients.assign_attending']);
        $router->post('/patients/{id:uuid}/merge', [PatientController::class, 'merge'], ['permission:patients.merge']);
        // Droits médicaux vérifiés par PatientPolicy
        $router->get('/patients/{id:uuid}/medical-profile', [PatientController::class, 'medicalProfile'], $read);
        $router->put('/patients/{id:uuid}/medical-profile', [PatientController::class, 'updateMedicalProfile'], $read);

        foreach (array_keys(PatientChildren::definitions()) as $child) {
            $base = '/patients/{id:uuid}/' . $child;
            $router->get($base, [PatientController::class, 'childIndex', $child], $read);
            $router->post($base, [PatientController::class, 'childStore', $child], $read);
            $router->put($base . '/{childId:uuid}', [PatientController::class, 'childUpdate', $child], $read);
            $router->delete($base . '/{childId:uuid}', [PatientController::class, 'childDestroy', $child], $read);
        }

        $validate = ['permission:patients.validate_registration'];
        $router->get('/patient-registrations', [RegistrationController::class, 'pending'], $validate);
        $router->post('/patient-registrations/{id:uuid}/approve', [RegistrationController::class, 'approve'], $validate);
        $router->post('/patient-registrations/{id:uuid}/reject', [RegistrationController::class, 'reject'], $validate);
    });
};

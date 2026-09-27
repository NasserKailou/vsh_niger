<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Homecare\HomecareController;

return function (Router $router): void {
    $router->group('', ['auth'], function (Router $router): void {
        $dispatch = ['permission:homecare.dispatch'];
        $intervene = ['permission:homecare.intervene'];
        // Création, fiche, désistement et annulation : patient ou personnel, contrôle fin dans le service.
        $router->get('/homecare', [HomecareController::class, 'index'], ['permission:homecare.read']);
        $router->post('/homecare', [HomecareController::class, 'store']);
        $router->get('/homecare/{id:uuid}', [HomecareController::class, 'show']);
        $router->post('/homecare/{id:uuid}/approve', [HomecareController::class, 'approve'], $dispatch);
        $router->post('/homecare/{id:uuid}/accept', [HomecareController::class, 'accept'], $intervene);
        $router->post('/homecare/{id:uuid}/assign', [HomecareController::class, 'assign'], $dispatch);
        $router->post('/homecare/{id:uuid}/release', [HomecareController::class, 'release']);
        foreach (['depart', 'arrive', 'start', 'complete', 'fail'] as $action) {
            $router->post('/homecare/{id:uuid}/' . $action, [HomecareController::class, 'field', $action], $intervene);
        }
        $router->post('/homecare/{id:uuid}/cancel', [HomecareController::class, 'cancel']);
        $router->post('/homecare/{id:uuid}/track', [HomecareController::class, 'track'], $intervene);
        // Lecture du trajet et relevé du domicile : régulation ou équipe affectée, contrôle dans le service.
        $router->get('/homecare/{id:uuid}/track', [HomecareController::class, 'trackPoints']);
        $router->post('/homecare/{id:uuid}/home-location', [HomecareController::class, 'homeLocation']);
        $router->get('/homecare/{id:uuid}/dispatch-options', [HomecareController::class, 'dispatchOptions'], $dispatch);
        $router->get('/map/homecare', [HomecareController::class, 'map']);
    });
    $router->get('/me/patients/{id:uuid}/homecare', [HomecareController::class, 'forOwnPatient'], ['auth', 'permission:self.record.read']);
};

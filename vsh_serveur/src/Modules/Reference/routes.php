<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Reference\BundleController;
use Vsh\Modules\Reference\ReferenceCatalog;
use Vsh\Modules\Reference\ReferenceController;
use Vsh\Modules\Reference\TariffController;

return function (Router $router): void {
    $router->group('', ['auth'], function (Router $router): void {
        $read = ['permission:reference.read'];
        $manage = ['permission:reference.manage'];

        foreach (ReferenceCatalog::definitions() as $slug => $definition) {
            $router->get('/' . $slug, [ReferenceController::class, 'index', $slug], $read);
            $router->post('/' . $slug, [ReferenceController::class, 'store', $slug], $manage);
            $router->get('/' . $slug . '/{id:uuid}', [ReferenceController::class, 'show', $slug], $read);
            $router->put('/' . $slug . '/{id:uuid}', [ReferenceController::class, 'update', $slug], $manage);

            foreach (array_keys($definition['children'] ?? []) as $child) {
                $base = '/' . $slug . '/{id:uuid}/' . $child;
                $router->get($base, [ReferenceController::class, 'childIndex', $slug, $child], $read);
                $router->post($base, [ReferenceController::class, 'childStore', $slug, $child], $manage);
                $router->put($base . '/{childId:uuid}', [ReferenceController::class, 'childUpdate', $slug, $child], $manage);
            }
        }

        $router->get('/tariffs', [TariffController::class, 'index'], $read);
        $router->get('/tariffs/current', [TariffController::class, 'current'], $read);
        $router->post('/tariffs', [TariffController::class, 'store'], ['permission:tariffs.manage']);
        $router->put('/tariffs/{id:uuid}', [TariffController::class, 'update'], ['permission:tariffs.manage']);

        $router->get('/reference/bundle', [BundleController::class, 'show']);
    });
};

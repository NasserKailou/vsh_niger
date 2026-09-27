<?php

declare(strict_types=1);

use Vsh\Core\Container;
use Vsh\Modules\Patients\PatientChildren;
use Vsh\Modules\Patients\PatientChildService;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Patients\Sync\MedicalProfileSyncHandler;
use Vsh\Modules\Patients\Sync\PatientChildSyncHandler;
use Vsh\Modules\Patients\Sync\PatientSyncHandler;
use Vsh\Modules\Sync\SyncRegistry;

return function (SyncRegistry $registry, Container $container): void {
    $registry->register($container->get(PatientSyncHandler::class));
    $registry->register($container->get(MedicalProfileSyncHandler::class));
    foreach (array_keys(PatientChildren::definitions()) as $name) {
        $registry->register(new PatientChildSyncHandler(
            $name,
            $container->get(PatientChildService::class),
            $container->get(PatientRepository::class),
            $container->get(PatientPolicy::class)
        ));
    }
};

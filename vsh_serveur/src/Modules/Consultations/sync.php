<?php

declare(strict_types=1);

use Vsh\Core\Container;
use Vsh\Modules\Consultations\ConsultationRepository;
use Vsh\Modules\Consultations\ConsultationService;
use Vsh\Modules\Consultations\Sync\ConsultationRecordSyncHandler;
use Vsh\Modules\Consultations\Sync\ConsultationSyncHandler;
use Vsh\Modules\Consultations\VitalSignService;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Sync\SyncRegistry;

return function (SyncRegistry $registry, Container $container): void {
    $registry->register($container->get(ConsultationSyncHandler::class));
    foreach (ConsultationRecordSyncHandler::entities() as $entity) {
        $registry->register(new ConsultationRecordSyncHandler(
            $entity,
            $container->get(ConsultationRepository::class),
            $container->get(ConsultationService::class),
            $container->get(VitalSignService::class),
            $container->get(PatientRepository::class),
            $container->get(PatientPolicy::class)
        ));
    }
};

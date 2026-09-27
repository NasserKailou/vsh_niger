<?php

declare(strict_types=1);

use Vsh\Core\Container;
use Vsh\Modules\Prescriptions\PrescriptionSyncHandler;
use Vsh\Modules\Sync\SyncRegistry;

return function (SyncRegistry $registry, Container $container): void {
    $registry->register($container->get(PrescriptionSyncHandler::class));
};

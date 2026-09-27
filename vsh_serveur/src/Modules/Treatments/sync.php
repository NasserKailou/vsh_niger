<?php

declare(strict_types=1);

use Vsh\Core\Container;
use Vsh\Modules\Sync\SyncRegistry;
use Vsh\Modules\Treatments\TreatmentSyncHandler;

return function (SyncRegistry $registry, Container $container): void {
    $registry->register($container->get(TreatmentSyncHandler::class));
};

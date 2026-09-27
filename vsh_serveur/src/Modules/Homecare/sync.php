<?php

declare(strict_types=1);

use Vsh\Core\Container;
use Vsh\Modules\Homecare\HomecareSyncHandler;
use Vsh\Modules\Sync\SyncRegistry;

return function (SyncRegistry $registry, Container $container): void {
    $registry->register($container->get(HomecareSyncHandler::class));
};

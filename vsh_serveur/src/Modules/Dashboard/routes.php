<?php

declare(strict_types=1);

use Vsh\Core\Routing\Router;
use Vsh\Modules\Dashboard\DashboardController;

return function (Router $router): void {
    // Tableau de bord global ou personnel : chaque bloc est filtré par permission dans le service.
    $router->get('/dashboard', [DashboardController::class, 'show'], ['auth']);
};

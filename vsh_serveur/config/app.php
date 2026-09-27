<?php

declare(strict_types=1);

use Vsh\Core\Env;
use Vsh\Core\Middleware\AuthenticateMiddleware;
use Vsh\Core\Middleware\CorsMiddleware;
use Vsh\Core\Middleware\ErrorBoundaryMiddleware;
use Vsh\Core\Middleware\JsonBodyMiddleware;
use Vsh\Core\Middleware\RequestIdMiddleware;
use Vsh\Core\Middleware\RequirePermissionMiddleware;
use Vsh\Core\Middleware\SecurityHeadersMiddleware;

return [
    'name' => 'Vision Homecare',
    'version' => '0.1.0',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => Env::get('APP_URL', ''),
    'api_prefix' => '/api/v1',
    'max_json_bytes' => 2 * 1024 * 1024,

    // Modules dont les routes sont chargées (src/Modules/<Nom>/routes.php)
    'modules' => [
        'System',
        'Auth',
        'Users',
        'Settings',
        'Reference',
        'Patients',
        'Notifications',
        'Sync',
        'Consultations',
        'Treatments',
        'Examinations',
        'Prescriptions',
        'Teams',
        'Homecare',
        'Billing',
        'Appointments',
        'Dashboard',
        'Audit',
    ],

    'middleware' => [
        // Ordre d'exécution : du premier (extérieur) au dernier (au plus près du contrôleur).
        // ErrorBoundary est placé après les middlewares qui ajoutent des en-têtes, afin que les
        // réponses d'erreur reçoivent aussi l'identifiant de requête, les en-têtes de sécurité et CORS.
        'global' => [
            RequestIdMiddleware::class,
            SecurityHeadersMiddleware::class,
            CorsMiddleware::class,
            ErrorBoundaryMiddleware::class,
        ],
        // Appliqués à toutes les routes /api/v1
        'api' => [
            'json_body',
        ],
        'aliases' => [
            'json_body' => JsonBodyMiddleware::class,
            'auth' => AuthenticateMiddleware::class,
            'permission' => RequirePermissionMiddleware::class,
        ],
    ],
];

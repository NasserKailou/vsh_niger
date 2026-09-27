<?php

declare(strict_types=1);

namespace Vsh\Modules\System;

use Vsh\Core\Config;
use Vsh\Core\Database;
use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;
use Vsh\Core\Support\Clock;

/**
 * Point de contrôle public. Utilisé par l'application mobile pour vérifier que le serveur est
 * réellement joignable (pas seulement que le téléphone a du réseau) et pour détecter un décalage d'horloge.
 * Ne révèle aucune information interne.
 */
final class HealthController
{
    /** @var Database */
    private $db;

    /** @var Config */
    private $config;

    public function __construct(Database $db, Config $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function show(Request $request): Response
    {
        $databaseUp = $this->db->ping();

        return ApiResponse::success(
            [
                'status' => $databaseUp ? 'ok' : 'degraded',
                'database' => $databaseUp ? 'ok' : 'unavailable',
                'api_version' => 'v1',
                'app_version' => (string) $this->config->get('app.version'),
                'server_time' => Clock::now()->format('Y-m-d\TH:i:s\Z'),
            ],
            $databaseUp ? 'Service opérationnel.' : 'Service partiellement indisponible.',
            $databaseUp ? 200 : 503
        );
    }
}

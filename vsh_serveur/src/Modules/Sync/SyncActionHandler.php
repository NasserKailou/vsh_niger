<?php

declare(strict_types=1);

namespace Vsh\Modules\Sync;

use Vsh\Core\Http\Request;

/**
 * Entité qui accepte des actions métier hors ligne (opération ACTION), par exemple
 * « close » pour une consultation ou « perform » pour un soin. Chaque action passe par la
 * machine à états du module : une transition impossible est refusée (REJECTED).
 */
interface SyncActionHandler
{
    /**
     * @return string[] Noms des actions acceptées
     */
    public function actions(): array;

    /**
     * @return array État résultant (avec « version »)
     */
    public function action(string $uuid, string $action, array $payload, Request $request): array;
}

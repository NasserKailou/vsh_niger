<?php

declare(strict_types=1);

namespace Vsh\Core\Sync;

use Vsh\Core\Database;
use Vsh\Core\Support\Clock;

/**
 * Journal des changements (table sync_changes) lu par la synchronisation incrémentale des appareils.
 * Chaque écriture métier y ajoute une ligne dans la même transaction, avec sa portée
 * (patient, équipe ou utilisateur) pour ne transmettre à un appareil que ce qu'il a le droit de voir.
 */
final class ChangeJournal
{
    public const UPSERT = 'UPSERT';
    public const DELETE = 'DELETE';

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function record(
        string $entity,
        string $entityUuid,
        string $operation = self::UPSERT,
        ?int $patientId = null,
        ?int $teamId = null,
        ?int $userId = null
    ): void {
        $this->db->insert('sync_changes', [
            'entity' => $entity,
            'entity_uuid' => $entityUuid,
            'operation' => $operation,
            'patient_id' => $patientId,
            'team_id' => $teamId,
            'user_id' => $userId,
            'changed_at' => Clock::nowForDatabase(),
        ]);
    }
}

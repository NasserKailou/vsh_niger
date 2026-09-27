<?php

declare(strict_types=1);

namespace Vsh\Modules\Sync;

use Vsh\Core\Container;
use Vsh\Core\Database;
use Vsh\Core\Sync\ChangeJournal;

/**
 * Réinscrit un dossier patient complet (identité, données, consultations, soins…) dans le journal de
 * synchronisation. Les changements anciens ont un numéro inférieur au curseur des appareils : sans cet
 * instantané, un appareil dont le périmètre s'élargit ne recevrait pas l'historique du dossier.
 *
 * Le registre est résolu à l'appel (et non à la construction) : les gestionnaires de synchronisation
 * dépendent eux-mêmes des services qui utilisent cet instantané.
 */
final class PatientSnapshot
{
    /** @var Database */
    private $db;

    /** @var ChangeJournal */
    private $journal;

    /** @var Container */
    private $container;

    public function __construct(Database $db, ChangeJournal $journal, Container $container)
    {
        $this->db = $db;
        $this->journal = $journal;
        $this->container = $container;
    }

    public function record(int $patientId): void
    {
        /** @var SyncRegistry $registry */
        $registry = $this->container->get(SyncRegistry::class);
        foreach ($registry->all() as $handler) {
            if (!$handler instanceof PatientScopedEntity) {
                continue;
            }
            foreach ($this->db->fetchAll($handler->snapshotSql(), [$patientId]) as $row) {
                $this->journal->record($handler->entity(), (string) $row['uuid'], ChangeJournal::UPSERT, $patientId);
            }
        }
    }
}

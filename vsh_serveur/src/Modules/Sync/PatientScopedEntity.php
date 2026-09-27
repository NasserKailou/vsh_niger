<?php

declare(strict_types=1);

namespace Vsh\Modules\Sync;

/**
 * Entité rattachée à un patient : elle fait partie de l'« instantané » d'un dossier, réinscrit dans le
 * journal quand le dossier entre dans le périmètre d'un utilisateur (épinglage, médecin traitant).
 */
interface PatientScopedEntity
{
    /**
     * Requête SQL avec un seul paramètre (identifiant interne du patient) renvoyant la colonne « uuid »
     * des éléments actuels (non supprimés) de ce patient.
     */
    public function snapshotSql(): string;
}

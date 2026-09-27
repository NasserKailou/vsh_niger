<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Security\AuthContext;

/**
 * Règles d'accès au dossier patient (en plus des permissions vérifiées sur les routes).
 *
 * D-010 : les données médicales sont accessibles au personnel qui détient la permission correspondante,
 * et chaque consultation est tracée dans le journal d'audit. La restriction selon la relation de soin
 * (équipe, consultation en cours, médecin traitant) sera ajoutée avec les modules Consultations et Homecare.
 */
final class PatientPolicy
{
    public function canReadMedical(AuthContext $auth, array $patient): bool
    {
        if ($this->owns($auth, $patient)) {
            return true;
        }
        return !$auth->isPatient()
            && ($auth->can('patients.medical.read_all') || $auth->can('patients.medical.read'));
    }

    public function canWriteMedical(AuthContext $auth, array $patient): bool
    {
        return !$auth->isPatient() && $auth->can('patients.medical.write');
    }

    public function owns(AuthContext $auth, array $patient): bool
    {
        return $auth->isPatient() && $patient['user_id'] !== null && (int) $patient['user_id'] === $auth->userId();
    }

    public function assertReadMedical(AuthContext $auth, array $patient): void
    {
        if (!$this->canReadMedical($auth, $patient)) {
            throw HttpException::forbidden("Vous n'avez pas accès aux données médicales de ce patient.");
        }
    }

    public function assertWriteMedical(AuthContext $auth, array $patient): void
    {
        if (!$this->canWriteMedical($auth, $patient)) {
            throw HttpException::forbidden("Vous n'avez pas le droit de modifier les données médicales de ce patient.");
        }
    }
}

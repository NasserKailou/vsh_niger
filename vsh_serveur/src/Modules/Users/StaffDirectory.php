<?php

declare(strict_types=1);

namespace Vsh\Modules\Users;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Validation\Validator;

/**
 * Annuaire minimal du personnel soignant actif, pour les listes de choix (praticien d'un rendez-vous,
 * médecin traitant, équipe). Nom et profession seulement : ni téléphone, ni e-mail, ni rôle d'accès.
 * Ouvert à ceux qui affectent du personnel, sans exiger la gestion des utilisateurs.
 */
final class StaffDirectory
{
    private const ALLOWED = ['appointments.manage', 'patients.assign_attending', 'patients.create', 'homecare.dispatch', 'teams.manage', 'users.read'];

    /** @var Database */
    private $db;

    /** @var Validator */
    private $validator;

    public function __construct(Database $db, Validator $validator)
    {
        $this->db = $db;
        $this->validator = $validator;
    }

    public function list(array $query, Request $request): array
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof AuthContext) {
            throw HttpException::unauthorized();
        }
        $allowed = false;
        foreach (self::ALLOWED as $permission) {
            $allowed = $allowed || $auth->can($permission);
        }
        if (!$allowed) {
            throw HttpException::forbidden();
        }
        $data = $this->validator->validate($query, [
            'profession' => 'nullable|in:MEDECIN,INFIRMIER,SAGE_FEMME,TECHNICIEN,ADMINISTRATIF,AUTRE',
        ]);
        $sql = "SELECT u.uuid, u.first_name, u.last_name, sp.profession, sp.speciality
                FROM users u JOIN staff_profiles sp ON sp.user_id = u.id
                WHERE u.account_type = 'STAFF' AND u.status = 'ACTIVE' AND u.deleted_at IS NULL";
        $params = [];
        if (isset($data['profession'])) {
            $sql .= ' AND sp.profession = ?';
            $params[] = $data['profession'];
        } else {
            $sql .= " AND sp.profession IN ('MEDECIN', 'INFIRMIER', 'SAGE_FEMME', 'TECHNICIEN')";
        }
        return array_map(static function (array $row): array {
            return [
                'id' => (string) $row['uuid'],
                'name' => $row['first_name'] . ' ' . $row['last_name'],
                'profession' => (string) $row['profession'],
                'speciality' => $row['speciality'],
            ];
        }, $this->db->fetchAll($sql . ' ORDER BY u.last_name, u.first_name LIMIT 500', $params));
    }
}

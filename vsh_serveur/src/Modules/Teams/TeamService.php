<?php

declare(strict_types=1);

namespace Vsh\Modules\Teams;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Validation\Validator;

/**
 * Équipes médicales et appartenance DATÉE de leurs membres.
 *
 * L'historique n'est jamais effacé : on termine une appartenance (to_date), on ne la supprime pas.
 * Il permet de savoir qui faisait partie de l'équipe à une date donnée (D-005, visites à domicile).
 */
final class TeamService
{
    private const ROLES = 'CHEF,MEDECIN,INFIRMIER,SAGE_FEMME,TECHNICIEN,CHAUFFEUR,AUTRE';

    /** @var Database */
    private $db;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    public function __construct(Database $db, AuditLogger $audit, Validator $validator)
    {
        $this->db = $db;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    public function list(array $query): array
    {
        $data = $this->validator->validate($query, [
            'active' => 'nullable|boolean',
            'mobile' => 'nullable|boolean',
        ]);
        $where = ['deleted_at IS NULL'];
        $params = [];
        if (isset($data['active'])) {
            $where[] = 'active = ?';
            $params[] = $data['active'] ? 1 : 0;
        }
        if (isset($data['mobile'])) {
            $where[] = 'is_mobile = ?';
            $params[] = $data['mobile'] ? 1 : 0;
        }
        $rows = $this->db->fetchAll('SELECT * FROM teams WHERE ' . implode(' AND ', $where) . ' ORDER BY label', $params);
        return array_map(function (array $row): array {
            return $this->present($row);
        }, $rows);
    }

    public function get(string $uuid): array
    {
        return $this->present($this->findOrFail($uuid), true);
    }

    /**
     * Équipes dont l'utilisateur est membre aujourd'hui.
     */
    public function mine(AuthContext $auth): array
    {
        list($sql, $params) = self::teamIdsSql($auth->userId());
        $rows = $this->db->fetchAll(
            'SELECT * FROM teams WHERE id IN (' . $sql . ') AND active = 1 AND deleted_at IS NULL ORDER BY label',
            $params
        );
        return array_map(function (array $row): array {
            return $this->present($row, true);
        }, $rows);
    }

    public function create(array $input, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_\-]+$/'],
            'label' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'is_mobile' => 'nullable|boolean',
        ]);
        if ($this->db->fetchValue('SELECT id FROM teams WHERE code = ?', [$data['code']]) !== null) {
            throw new ValidationException(['code' => ['Ce code est déjà utilisé.']]);
        }
        $uuid = Uuid::v4();
        $now = Clock::nowForDatabase();
        $id = $this->db->insert('teams', [
            'uuid' => $uuid,
            'code' => $data['code'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'is_mobile' => isset($data['is_mobile']) ? ($data['is_mobile'] ? 1 : 0) : 1,
            'active' => 1,
            'created_by' => $auth->userId(),
            'updated_by' => $auth->userId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit->record('TEAM_CREATED', $request, 'team', $uuid, null, ['code' => $data['code']]);
        return $this->present((array) $this->db->fetchOne('SELECT * FROM teams WHERE id = ?', [$id]), true);
    }

    public function update(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $team = $this->findOrFail($uuid);
        $data = $this->validator->validate($input, [
            'label' => 'string|max:150',
            'description' => 'nullable|string|max:500',
            'is_mobile' => 'boolean',
            'active' => 'boolean',
        ]);
        foreach (['is_mobile', 'active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $data[$flag] ? 1 : 0;
            }
        }
        if ($data !== []) {
            $this->db->update('teams', $data + ['updated_by' => $auth->userId(), 'updated_at' => Clock::nowForDatabase()], 'id = ?', [(int) $team['id']]);
            $this->db->execute('UPDATE teams SET version = version + 1 WHERE id = ?', [(int) $team['id']]);
            $this->audit->record('TEAM_UPDATED', $request, 'team', $uuid, null, ['fields' => array_keys($data)]);
        }
        return $this->present((array) $this->db->fetchOne('SELECT * FROM teams WHERE id = ?', [(int) $team['id']]), true);
    }

    public function addMember(string $teamUuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $team = $this->findOrFail($teamUuid);
        $data = $this->validator->validate($input, [
            'user_id' => 'required|uuid',
            'team_role' => 'required|in:' . self::ROLES,
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);
        $userId = $this->db->fetchValue(
            "SELECT id FROM users WHERE uuid = ? AND account_type = 'STAFF' AND status = 'ACTIVE' AND deleted_at IS NULL",
            [$data['user_id']]
        );
        if ($userId === null) {
            throw new ValidationException(['user_id' => ['Membre du personnel introuvable ou inactif.']]);
        }
        $from = $data['from_date'] ?? self::today();
        $to = $data['to_date'] ?? null;
        if ($to !== null && $to < $from) {
            throw new ValidationException(['to_date' => ['La date de fin doit suivre la date de début.']]);
        }
        $overlap = $this->db->fetchValue(
            'SELECT id FROM team_members WHERE team_id = ? AND user_id = ? AND from_date <= ? AND (to_date IS NULL OR to_date >= ?)',
            [(int) $team['id'], (int) $userId, $to ?? '9999-12-31', $from]
        );
        if ($overlap !== null) {
            throw HttpException::conflict('Cette personne est déjà membre de l\'équipe sur cette période.', 'ALREADY_MEMBER');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowForDatabase();
        $this->db->insert('team_members', [
            'uuid' => $uuid,
            'team_id' => (int) $team['id'],
            'user_id' => (int) $userId,
            'team_role' => $data['team_role'],
            'from_date' => $from,
            'to_date' => $to,
            'created_by' => $auth->userId(),
            'updated_by' => $auth->userId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit->record('TEAM_MEMBER_ADDED', $request, 'team', $teamUuid, null, ['member' => $uuid, 'user' => $data['user_id'], 'role' => $data['team_role']]);
        return $this->present($team, true);
    }

    /**
     * Modifie le rôle ou termine l'appartenance (to_date). La date de début n'est pas modifiable.
     */
    public function updateMember(string $teamUuid, string $memberUuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $team = $this->findOrFail($teamUuid);
        $member = $this->db->fetchOne('SELECT * FROM team_members WHERE uuid = ? AND team_id = ?', [$memberUuid, (int) $team['id']]);
        if ($member === null) {
            throw HttpException::notFound('Membre introuvable.');
        }
        $data = $this->validator->validate($input, [
            'team_role' => 'in:' . self::ROLES,
            'to_date' => 'nullable|date',
        ]);
        if (isset($data['to_date']) && $data['to_date'] < (string) $member['from_date']) {
            throw new ValidationException(['to_date' => ['La date de fin doit suivre la date de début.']]);
        }
        if ($data !== []) {
            $this->db->update('team_members', $data + ['updated_by' => $auth->userId(), 'updated_at' => Clock::nowForDatabase()], 'id = ?', [(int) $member['id']]);
            $this->db->execute('UPDATE team_members SET version = version + 1 WHERE id = ?', [(int) $member['id']]);
            $this->audit->record('TEAM_MEMBER_UPDATED', $request, 'team', $teamUuid, null, ['member' => $memberUuid, 'fields' => array_keys($data)]);
        }
        return $this->present($team, true);
    }

    /**
     * Membre de l'équipe à la date du jour.
     */
    public function isActiveMember(int $userId, int $teamId): bool
    {
        list($sql, $params) = self::teamIdsSql($userId);
        return $this->db->fetchValue('SELECT 1 FROM (' . $sql . ') m WHERE m.team_id = ? LIMIT 1', array_merge($params, [$teamId])) !== null;
    }

    /**
     * Équipes MOBILES actives dont l'utilisateur est membre aujourd'hui.
     *
     * @return int[]
     */
    public function activeMobileTeamIds(int $userId): array
    {
        list($sql, $params) = self::teamIdsSql($userId);
        return array_map('intval', array_column($this->db->fetchAll(
            'SELECT id FROM teams WHERE id IN (' . $sql . ') AND is_mobile = 1 AND active = 1 AND deleted_at IS NULL ORDER BY id',
            $params
        ), 'id'));
    }

    /**
     * Sous-requête : identifiants des équipes dont l'utilisateur est membre aujourd'hui.
     *
     * @return array{0: string, 1: array}
     */
    public static function teamIdsSql(int $userId): array
    {
        $today = self::today();
        return [
            'SELECT team_id FROM team_members WHERE user_id = ? AND from_date <= ? AND (to_date IS NULL OR to_date >= ?)',
            [$userId, $today, $today],
        ];
    }

    public function findOrFail(string $uuid): array
    {
        $team = $this->db->fetchOne('SELECT * FROM teams WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($team === null) {
            throw HttpException::notFound('Équipe introuvable.');
        }
        return $team;
    }

    public function present(array $row, bool $withMembers = false): array
    {
        $team = [
            'id' => (string) $row['uuid'],
            'code' => (string) $row['code'],
            'label' => (string) $row['label'],
            'description' => $row['description'],
            'is_mobile' => (bool) $row['is_mobile'],
            'active' => (bool) $row['active'],
            'version' => (int) $row['version'],
            'updated_at' => Clock::toIso((string) $row['updated_at']),
        ];
        if ($withMembers) {
            $today = self::today();
            $members = $this->db->fetchAll(
                'SELECT tm.*, u.uuid AS user_uuid, u.first_name, u.last_name FROM team_members tm JOIN users u ON u.id = tm.user_id
                 WHERE tm.team_id = ? ORDER BY tm.to_date IS NULL DESC, tm.from_date DESC, tm.id',
                [(int) $row['id']]
            );
            $team['members'] = array_map(function (array $member) use ($today): array {
                return [
                    'id' => (string) $member['uuid'],
                    'user' => ['id' => (string) $member['user_uuid'], 'name' => $member['first_name'] . ' ' . $member['last_name']],
                    'team_role' => (string) $member['team_role'],
                    'from_date' => (string) $member['from_date'],
                    'to_date' => $member['to_date'],
                    'is_current' => (string) $member['from_date'] <= $today && ($member['to_date'] === null || (string) $member['to_date'] >= $today),
                ];
            }, $members);
        }
        return $team;
    }

    private static function today(): string
    {
        return Clock::now()->format('Y-m-d');
    }

    private static function auth(Request $request): AuthContext
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof AuthContext) {
            throw HttpException::unauthorized();
        }
        return $auth;
    }
}

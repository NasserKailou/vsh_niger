<?php

declare(strict_types=1);

namespace Vsh\Modules\Reference;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Validation\Validator;

/**
 * Création, modification et lecture des référentiels décrits par ReferenceCatalog.
 */
final class ReferenceService
{
    /** @var Database */
    private $db;

    /** @var ReferenceRepository */
    private $repository;

    /** @var Validator */
    private $validator;

    /** @var AuditLogger */
    private $audit;

    public function __construct(Database $db, ReferenceRepository $repository, Validator $validator, AuditLogger $audit)
    {
        $this->db = $db;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    /**
     * @return array{0: array[], 1: int}
     */
    public function list(string $slug, array $query, Pagination $pagination): array
    {
        $definition = ReferenceCatalog::get($slug);
        $filters = $this->validator->validate($query, [
            'search' => 'nullable|string|max:100',
            'active' => 'nullable|boolean',
        ]);
        list($rows, $total) = $this->repository->paginate(
            $definition['table'],
            $definition['search'],
            $filters['search'] ?? null,
            $filters['active'] ?? null,
            $definition['order'],
            $pagination->perPage(),
            $pagination->offset()
        );
        return [array_map(function (array $row) use ($definition): array {
            return self::present($definition, $row);
        }, $rows), $total];
    }

    public function get(string $slug, string $uuid): array
    {
        $definition = ReferenceCatalog::get($slug);
        $row = $this->findOrFail($definition, $uuid);
        $item = self::present($definition, $row);
        foreach ($definition['children'] ?? [] as $childName => $child) {
            $item[$childName] = array_map(function (array $childRow) use ($child): array {
                return self::present($child, $childRow);
            }, $this->repository->children($child['table'], $child['foreign_key'], (int) $row['id'], $child['order']));
        }
        return $item;
    }

    public function create(string $slug, array $input, Request $request): array
    {
        $definition = ReferenceCatalog::get($slug);
        $data = $this->validator->validate($input, $definition['fields']);
        $this->assertUnique($definition, $data, null);

        return $this->db->transaction(function () use ($slug, $definition, $data, $request): array {
            $uuid = Uuid::v4();
            $this->repository->insert($definition['table'], self::toRow($definition, $data) + [
                'uuid' => $uuid,
                'created_by' => self::actorId($request),
                'updated_by' => self::actorId($request),
            ]);
            $item = $this->get($slug, $uuid);
            $this->audit->record('REFERENCE_CREATED', $request, $definition['entity'], $uuid, null, $item);
            return $item;
        });
    }

    public function update(string $slug, string $uuid, array $input, Request $request): array
    {
        $definition = ReferenceCatalog::get($slug);
        $row = $this->findOrFail($definition, $uuid);
        $data = $this->validator->validate($input, self::optional($definition['fields']));
        $this->assertUnique($definition, $data, (int) $row['id']);

        return $this->db->transaction(function () use ($slug, $definition, $row, $uuid, $data, $request): array {
            $before = self::present($definition, $row);
            $this->repository->update($definition['table'], (int) $row['id'], self::toRow($definition, $data) + [
                'updated_by' => self::actorId($request),
            ]);
            $after = $this->get($slug, $uuid);
            list($old, $new) = AuditLogger::changes($before, array_intersect_key($after, $before));
            if ($new !== []) {
                $this->audit->record('REFERENCE_UPDATED', $request, $definition['entity'], $uuid, $old, $new);
            }
            return $after;
        });
    }

    public function listChildren(string $slug, string $childName, string $parentUuid): array
    {
        $parent = $this->findOrFail(ReferenceCatalog::get($slug), $parentUuid);
        $child = ReferenceCatalog::child($slug, $childName);
        return array_map(function (array $row) use ($child): array {
            return self::present($child, $row);
        }, $this->repository->children($child['table'], $child['foreign_key'], (int) $parent['id'], $child['order']));
    }

    public function createChild(string $slug, string $childName, string $parentUuid, array $input, Request $request): array
    {
        $definition = ReferenceCatalog::get($slug);
        $parent = $this->findOrFail($definition, $parentUuid);
        $child = ReferenceCatalog::child($slug, $childName);
        $data = $this->validator->validate($input, $child['fields']);
        $this->check($child, $data);
        $this->assertChildCodeUnique($child, $data, (int) $parent['id'], null);

        return $this->db->transaction(function () use ($definition, $parent, $child, $data, $request): array {
            $uuid = Uuid::v4();
            $id = $this->repository->insert($child['table'], self::toRow($child, $data) + [
                'uuid' => $uuid,
                $child['foreign_key'] => (int) $parent['id'],
            ]);
            $this->repository->touch($definition['table'], (int) $parent['id']);
            $item = self::present($child, (array) $this->repository->findById($child['table'], $id));
            $this->audit->record('REFERENCE_CREATED', $request, $child['entity'], $uuid, null, $item);
            return $item;
        });
    }

    public function updateChild(string $slug, string $childName, string $parentUuid, string $uuid, array $input, Request $request): array
    {
        $definition = ReferenceCatalog::get($slug);
        $parent = $this->findOrFail($definition, $parentUuid);
        $child = ReferenceCatalog::child($slug, $childName);
        $row = $this->repository->findByUuid($child['table'], $uuid);
        if ($row === null || (int) $row[$child['foreign_key']] !== (int) $parent['id']) {
            throw HttpException::notFound($child['not_found']);
        }
        $data = $this->validator->validate($input, self::optional($child['fields']));
        $before = self::present($child, $row);
        $this->check($child, array_merge($before, $data));
        $this->assertChildCodeUnique($child, $data, (int) $parent['id'], (int) $row['id']);

        return $this->db->transaction(function () use ($definition, $parent, $child, $row, $uuid, $data, $before, $request): array {
            $this->repository->update($child['table'], (int) $row['id'], self::toRow($child, $data));
            $this->repository->touch($definition['table'], (int) $parent['id']);
            $after = self::present($child, (array) $this->repository->findById($child['table'], (int) $row['id']));
            list($old, $new) = AuditLogger::changes($before, $after);
            if ($new !== []) {
                $this->audit->record('REFERENCE_UPDATED', $request, $child['entity'], $uuid, $old, $new);
            }
            return $after;
        });
    }

    /**
     * Éléments modifiés depuis $since (null = tous), avec leurs sous-ressources.
     */
    public function changedSince(string $slug, ?string $since): array
    {
        $definition = ReferenceCatalog::get($slug);
        $rows = $this->repository->changedSince($definition['table'], $since, $definition['order']);
        $items = [];
        $childrenByName = [];
        foreach ($definition['children'] ?? [] as $childName => $child) {
            $childrenByName[$childName] = $this->repository->childrenOf(
                $child['table'],
                $child['foreign_key'],
                array_map(function (array $row): int {
                    return (int) $row['id'];
                }, $rows),
                $child['order']
            );
        }
        foreach ($rows as $row) {
            $item = self::present($definition, $row);
            foreach ($definition['children'] ?? [] as $childName => $child) {
                $item[$childName] = array_map(function (array $childRow) use ($child): array {
                    return self::present($child, $childRow);
                }, $childrenByName[$childName][(int) $row['id']] ?? []);
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * Identifiant interne d'un élément à partir de son uuid (utilisé par les tarifs et les autres modules).
     */
    public function idFor(string $slug, string $uuid): ?int
    {
        $row = $this->repository->findByUuid(ReferenceCatalog::get($slug)['table'], $uuid);
        return $row === null ? null : (int) $row['id'];
    }

    public static function present(array $definition, array $row): array
    {
        $item = ['id' => (string) $row['uuid']];
        foreach ($definition['fields'] as $field => $rules) {
            $value = $row[$field] ?? null;
            $rules = is_array($rules) ? implode('|', $rules) : $rules;
            if ($value === null) {
                $item[$field] = null;
            } elseif (in_array($field, $definition['json'] ?? [], true)) {
                $item[$field] = json_decode((string) $value, true);
            } elseif (in_array($field, $definition['time'] ?? [], true)) {
                $item[$field] = substr((string) $value, 0, 5);
            } elseif (strpos($rules, 'boolean') !== false) {
                $item[$field] = (bool) $value;
            } elseif (strpos($rules, 'integer') !== false) {
                $item[$field] = (int) $value;
            } elseif (strpos($rules, 'numeric') !== false) {
                $item[$field] = (float) $value;
            } else {
                $item[$field] = (string) $value;
            }
        }
        $item['version'] = (int) $row['version'];
        $item['updated_at'] = Clock::toIso((string) $row['updated_at']);
        return $item;
    }

    private static function toRow(array $definition, array $data): array
    {
        foreach ($definition['json'] ?? [] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = json_encode(array_values($data[$field]), JSON_UNESCAPED_UNICODE);
            }
        }
        foreach ($definition['time'] ?? [] as $field) {
            if (isset($data[$field])) {
                $data[$field] .= ':00';
            }
        }
        return $data;
    }

    /**
     * Règles de modification : mêmes contrôles, mais aucun champ obligatoire.
     */
    private static function optional(array $fields): array
    {
        return array_map(function ($rules): array {
            $rules = is_array($rules) ? $rules : explode('|', $rules);
            return array_values(array_filter($rules, function (string $rule): bool {
                return $rule !== 'required';
            }));
        }, $fields);
    }

    private function check(array $definition, array $data): void
    {
        $errors = [];
        switch ($definition['check'] ?? null) {
            case 'schedule':
                if (isset($data['start_time'], $data['end_time']) && $data['end_time'] <= $data['start_time']) {
                    $errors['end_time'] = ['L\'heure de fin doit être postérieure à l\'heure de début.'];
                }
                break;
            case 'parameter':
                if (isset($data['ref_min'], $data['ref_max']) && $data['ref_max'] < $data['ref_min']) {
                    $errors['ref_max'] = ['La valeur maximale doit être supérieure ou égale à la valeur minimale.'];
                }
                if (isset($data['age_min_months'], $data['age_max_months']) && $data['age_max_months'] < $data['age_min_months']) {
                    $errors['age_max_months'] = ['L\'âge maximal doit être supérieur ou égal à l\'âge minimal.'];
                }
                if (($data['value_type'] ?? null) === 'CHOICE') {
                    $choices = $data['choices'] ?? null;
                    $valid = is_array($choices) && $choices !== [] && count(array_filter($choices, function ($choice): bool {
                        return !is_string($choice) || trim($choice) === '';
                    })) === 0;
                    if (!$valid) {
                        $errors['choices'] = ['Indiquez la liste des valeurs possibles (textes non vides).'];
                    }
                }
                break;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function assertUnique(array $definition, array $data, ?int $exceptId): void
    {
        foreach ($definition['unique'] ?? [] as $field) {
            if (isset($data[$field]) && $this->repository->valueTaken($definition['table'], $field, $data[$field], $exceptId)) {
                throw new ValidationException([$field => ['Cette valeur est déjà utilisée.']]);
            }
        }
    }

    private function assertChildCodeUnique(array $child, array $data, int $parentId, ?int $exceptId): void
    {
        if (isset($data['code'], $child['fields']['code'])
            && $this->repository->valueTaken($child['table'], 'code', $data['code'], $exceptId, [$child['foreign_key'], $parentId])) {
            throw new ValidationException(['code' => ['Ce code est déjà utilisé.']]);
        }
    }

    private function findOrFail(array $definition, string $uuid): array
    {
        $row = $this->repository->findByUuid($definition['table'], $uuid);
        if ($row === null) {
            throw HttpException::notFound($definition['not_found']);
        }
        return $row;
    }

    private static function actorId(Request $request): ?int
    {
        $auth = $request->attribute('auth');
        return $auth instanceof AuthContext ? $auth->userId() : null;
    }
}

<?php

declare(strict_types=1);

namespace Vsh\Modules\Prescriptions;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Validation\Validator;

/**
 * Lignes de médicaments, communes aux modèles et aux ordonnances.
 *
 * Chaque ligne peut viser un médicament du référentiel (libellé proposé : DCI, dosage, forme) ou être
 * saisie librement. Le libellé est figé à l'enregistrement : une évolution du référentiel ne modifie
 * jamais une ordonnance déjà rédigée.
 */
final class PrescriptionItems
{
    public const MAX_ITEMS = 50;

    private const FIELDS = ['dosage', 'form', 'quantity', 'posology', 'frequency', 'duration', 'route', 'instructions'];

    /** @var Database */
    private $db;

    /** @var Validator */
    private $validator;

    public function __construct(Database $db, Validator $validator)
    {
        $this->db = $db;
        $this->validator = $validator;
    }

    /**
     * @return array[] Lignes prêtes à insérer (medication_id interne, libellé figé, sort_order)
     */
    public function validate(array $items): array
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new ValidationException(['items' => [sprintf('%d lignes au plus.', self::MAX_ITEMS)]]);
        }
        $rows = [];
        $errors = [];
        foreach (array_values($items) as $index => $item) {
            $prefix = 'items.' . $index . '.';
            try {
                $data = $this->validator->validate(is_array($item) ? $item : [], [
                    'medication_id' => 'nullable|uuid',
                    'medication_label' => 'nullable|string|max:190',
                    'dosage' => 'nullable|string|max:100',
                    'form' => 'nullable|string|max:60',
                    'quantity' => 'nullable|string|max:60',
                    'posology' => 'nullable|string|max:255',
                    'frequency' => 'nullable|string|max:100',
                    'duration' => 'nullable|string|max:100',
                    'route' => 'nullable|string|max:60',
                    'instructions' => 'nullable|string|max:500',
                ]);
            } catch (ValidationException $exception) {
                foreach ($exception->getErrors() as $field => $messages) {
                    $errors[$prefix . $field] = $messages;
                }
                continue;
            }
            $medication = null;
            if (isset($data['medication_id'])) {
                $medication = $this->db->fetchOne('SELECT * FROM medications WHERE uuid = ? AND deleted_at IS NULL', [$data['medication_id']]);
                if ($medication === null || !(bool) $medication['active']) {
                    $errors[$prefix . 'medication_id'] = ['Médicament introuvable ou désactivé.'];
                    continue;
                }
            }
            $label = $data['medication_label'] ?? ($medication !== null ? self::medicationLabel($medication) : null);
            if ($label === null || trim($label) === '') {
                $errors[$prefix . 'medication_label'] = ['Indiquez le médicament.'];
                continue;
            }
            $row = [
                'medication_id' => $medication !== null ? (int) $medication['id'] : null,
                'medication_label' => trim($label),
                'sort_order' => $index,
            ];
            foreach (self::FIELDS as $field) {
                $row[$field] = $data[$field] ?? null;
            }
            if ($medication !== null) {
                // Valeurs proposées par le référentiel, toujours modifiables par le prescripteur.
                $row['dosage'] = $row['dosage'] ?? $medication['strength'];
                $row['form'] = $row['form'] ?? $medication['form'];
                $row['route'] = $row['route'] ?? $medication['route'];
            }
            $rows[] = $row;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $rows;
    }

    /**
     * Lignes enregistrées → format d'échange (modèle ou ordonnance).
     */
    public function present(array $rows): array
    {
        $medicationIds = array_values(array_unique(array_filter(array_map(function (array $row) {
            return $row['medication_id'] !== null ? (int) $row['medication_id'] : null;
        }, $rows))));
        $uuids = [];
        if ($medicationIds !== []) {
            foreach ($this->db->fetchAll(
                'SELECT id, uuid FROM medications WHERE id IN (' . implode(', ', array_fill(0, count($medicationIds), '?')) . ')',
                $medicationIds
            ) as $medication) {
                $uuids[(int) $medication['id']] = (string) $medication['uuid'];
            }
        }
        return array_map(function (array $row) use ($uuids): array {
            $item = [
                'id' => (string) $row['uuid'],
                'medication_id' => $row['medication_id'] !== null ? ($uuids[(int) $row['medication_id']] ?? null) : null,
                'medication_label' => (string) $row['medication_label'],
            ];
            foreach (self::FIELDS as $field) {
                $item[$field] = $row[$field];
            }
            return $item;
        }, $rows);
    }

    /**
     * Lignes d'un modèle → lignes d'ordonnance (le prescripteur peut ensuite les modifier).
     */
    public function fromTemplate(int $templateId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM prescription_template_items WHERE template_id = ? ORDER BY sort_order, id', [$templateId]);
        return array_map(function (array $row): array {
            $copy = [
                'medication_id' => $row['medication_id'] !== null ? (int) $row['medication_id'] : null,
                'medication_label' => (string) $row['medication_label'],
                'sort_order' => (int) $row['sort_order'],
            ];
            foreach (self::FIELDS as $field) {
                $copy[$field] = $row[$field];
            }
            return $copy;
        }, $rows);
    }

    /**
     * Alertes d'allergie, à titre d'information : médicament déclaré allergène pour ce patient, ou
     * allergène dont le nom figure dans le libellé. Elles ne bloquent jamais : le prescripteur décide.
     */
    public function allergyAlerts(int $patientId, array $rows): array
    {
        $allergies = $this->db->fetchAll('SELECT allergen, medication_id, severity FROM allergies WHERE patient_id = ? AND deleted_at IS NULL', [$patientId]);
        if ($allergies === []) {
            return [];
        }
        $alerts = [];
        foreach (array_values($rows) as $index => $row) {
            $label = mb_strtolower((string) $row['medication_label']);
            foreach ($allergies as $allergy) {
                $byMedication = $allergy['medication_id'] !== null && $row['medication_id'] !== null
                    && (int) $allergy['medication_id'] === (int) $row['medication_id'];
                $allergen = mb_strtolower(trim((string) $allergy['allergen']));
                $byName = mb_strlen($allergen) >= 3 && mb_strpos($label, $allergen) !== false;
                if ($byMedication || $byName) {
                    $alerts[] = [
                        'type' => 'ALLERGY',
                        'item_index' => $index,
                        'medication_label' => (string) $row['medication_label'],
                        'allergen' => (string) $allergy['allergen'],
                        'severity' => (string) $allergy['severity'],
                    ];
                }
            }
        }
        return $alerts;
    }

    private static function medicationLabel(array $medication): string
    {
        return trim(implode(' ', array_filter([(string) $medication['dci'], (string) $medication['strength']], 'strlen')));
    }
}

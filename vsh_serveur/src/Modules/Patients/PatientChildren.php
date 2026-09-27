<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients;

/**
 * Sous-ressources du dossier patient. « medical » = données médicales (droits et audit renforcés).
 */
final class PatientChildren
{
    public static function definitions(): array
    {
        return [
            'contacts' => [
                'table' => 'patient_contacts',
                'entity' => 'patient_contact',
                'medical' => false,
                'not_found' => 'Contact introuvable.',
                'fields' => [
                    'full_name' => 'required|string|max:190',
                    'relationship' => 'nullable|string|max:50',
                    'phone' => 'required|phone',
                    'is_emergency' => 'boolean',
                ],
                'order' => 'is_emergency DESC, full_name',
            ],
            'addresses' => [
                'table' => 'patient_addresses',
                'entity' => 'patient_address',
                'medical' => false,
                'not_found' => 'Adresse introuvable.',
                'fields' => [
                    'label' => 'nullable|string|max:50',
                    'city' => 'nullable|string|max:100',
                    'district' => 'nullable|string|max:100',
                    'address_line' => 'nullable|string|max:255',
                    'landmark' => 'nullable|string|max:255',
                    'latitude' => 'nullable|latitude',
                    'longitude' => 'nullable|longitude',
                    'gps_accuracy_m' => 'nullable|numeric|min:0|max:100000',
                    'gps_captured_at' => 'nullable|datetime',
                    'is_primary' => 'boolean',
                ],
                'datetime' => ['gps_captured_at'],
                'check' => 'coordinates',
                'exclusive' => 'is_primary',
                'order' => 'is_primary DESC, id',
            ],
            'allergies' => [
                'table' => 'allergies',
                'entity' => 'allergy',
                'medical' => true,
                'not_found' => 'Allergie introuvable.',
                'fields' => [
                    'allergen' => 'required|string|max:190',
                    'medication_id' => 'nullable|uuid',
                    'reaction' => 'nullable|string|max:255',
                    'severity' => 'in:LEGERE,MODEREE,SEVERE,INCONNUE',
                ],
                'references' => ['medication_id' => 'medications'],
                'recorded_by' => true,
                'order' => 'allergen',
            ],
            'medical-history' => [
                'table' => 'medical_history',
                'entity' => 'medical_history',
                'medical' => true,
                'not_found' => 'Antécédent introuvable.',
                'fields' => [
                    'history_type' => 'required|in:MEDICAL,SURGICAL,FAMILY,OBSTETRIC,OTHER',
                    'description' => 'required|string|max:5000',
                    'since_date' => 'nullable|date',
                ],
                'recorded_by' => true,
                'order' => 'since_date DESC, id DESC',
            ],
            'current-treatments' => [
                'table' => 'patient_current_treatments',
                'entity' => 'current_treatment',
                'medical' => true,
                'not_found' => 'Traitement introuvable.',
                'fields' => [
                    'medication_id' => 'nullable|uuid',
                    'label' => 'required|string|max:190',
                    'dosage' => 'nullable|string|max:190',
                    'started_on' => 'nullable|date',
                    'ended_on' => 'nullable|date',
                ],
                'references' => ['medication_id' => 'medications'],
                'recorded_by' => true,
                'check' => 'period',
                'order' => 'started_on DESC, id DESC',
            ],
        ];
    }

    public static function get(string $name): array
    {
        $definitions = self::definitions();
        if (!isset($definitions[$name])) {
            throw new \LogicException(sprintf('Sous-ressource patient inconnue : %s', $name));
        }
        return $definitions[$name];
    }

    /**
     * Clé JSON utilisée dans la représentation du dossier (medical-history → medical_history).
     */
    public static function key(string $name): string
    {
        return str_replace('-', '_', $name);
    }
}

<?php

declare(strict_types=1);

namespace Vsh\Modules\Reference;

/**
 * Description déclarative des référentiels administrables. Chaque entrée produit les routes
 * GET/POST /{ressource} et GET/PUT /{ressource}/{id}, ainsi que ses sous-ressources.
 *
 * Aucune suppression physique : un élément n'est plus proposé lorsqu'il est désactivé (active = false),
 * ce qui préserve l'historique (consultations, factures…).
 */
final class ReferenceCatalog
{
    private const CODE = ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9_\-]{0,29}$/'];
    private const TIME = ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];

    public static function definitions(): array
    {
        return [
            'services' => [
                'table' => 'services',
                'entity' => 'service',
                'not_found' => 'Service introuvable.',
                'fields' => [
                    'code' => self::CODE,
                    'label' => 'required|string|max:150',
                    'description' => 'nullable|string|max:500',
                    'accepts_appointments' => 'boolean',
                    'active' => 'boolean',
                ],
                'unique' => ['code'],
                'search' => ['code', 'label'],
                'order' => 'label',
                'children' => [
                    'schedules' => [
                        'table' => 'service_schedules',
                        'entity' => 'service_schedule',
                        'foreign_key' => 'service_id',
                        'not_found' => 'Plage horaire introuvable.',
                        'fields' => [
                            'weekday' => 'required|integer|min:1|max:7',
                            'start_time' => self::TIME,
                            'end_time' => self::TIME,
                            'slot_minutes' => 'required|integer|min:5|max:480',
                            'capacity_per_slot' => 'integer|min:1|max:100',
                            'active' => 'boolean',
                        ],
                        'time' => ['start_time', 'end_time'],
                        'order' => 'weekday, start_time',
                        'check' => 'schedule',
                    ],
                ],
            ],
            'medical-acts' => [
                'table' => 'medical_acts',
                'entity' => 'medical_act',
                'not_found' => 'Acte introuvable.',
                'fields' => [
                    'code' => self::CODE,
                    'label' => 'required|string|max:190',
                    'category' => 'nullable|string|max:50',
                    'active' => 'boolean',
                ],
                'unique' => ['code'],
                'search' => ['code', 'label', 'category'],
                'order' => 'label',
            ],
            'treatment-types' => [
                'table' => 'treatment_types',
                'entity' => 'treatment_type',
                'not_found' => 'Type de soin introuvable.',
                'fields' => [
                    'code' => self::CODE,
                    'label' => 'required|string|max:190',
                    'description' => 'nullable|string|max:500',
                    'active' => 'boolean',
                ],
                'unique' => ['code'],
                'search' => ['code', 'label'],
                'order' => 'label',
            ],
            'examination-types' => [
                'table' => 'examination_types',
                'entity' => 'examination_type',
                'not_found' => 'Type d\'examen introuvable.',
                'fields' => [
                    'code' => self::CODE,
                    'label' => 'required|string|max:190',
                    'category' => 'nullable|string|max:50',
                    'sample_type' => 'nullable|string|max:100',
                    'instructions' => 'nullable|string|max:500',
                    'active' => 'boolean',
                ],
                'unique' => ['code'],
                'search' => ['code', 'label', 'category'],
                'order' => 'label',
                'children' => [
                    'parameters' => [
                        'table' => 'examination_type_parameters',
                        'entity' => 'examination_type_parameter',
                        'foreign_key' => 'examination_type_id',
                        'not_found' => 'Paramètre introuvable.',
                        'fields' => [
                            'code' => self::CODE,
                            'label' => 'required|string|max:190',
                            'value_type' => 'required|in:NUMERIC,TEXT,CHOICE',
                            'choices' => 'nullable|array|max:50',
                            'unit' => 'nullable|string|max:30',
                            'ref_min' => 'nullable|numeric',
                            'ref_max' => 'nullable|numeric',
                            'ref_text' => 'nullable|string|max:190',
                            'sex' => 'nullable|in:M,F',
                            'age_min_months' => 'nullable|integer|min:0|max:1500',
                            'age_max_months' => 'nullable|integer|min:0|max:1500',
                            'sort_order' => 'integer|min:0|max:1000',
                            'active' => 'boolean',
                        ],
                        'json' => ['choices'],
                        'order' => 'sort_order, label',
                        'check' => 'parameter',
                    ],
                ],
            ],
            'medications' => [
                'table' => 'medications',
                'entity' => 'medication',
                'not_found' => 'Médicament introuvable.',
                'fields' => [
                    'dci' => 'required|string|max:190',
                    'commercial_name' => 'nullable|string|max:190',
                    'form' => 'nullable|string|max:60',
                    'strength' => 'nullable|string|max:60',
                    'route' => 'nullable|string|max:60',
                    'active' => 'boolean',
                ],
                'unique' => [],
                'search' => ['dci', 'commercial_name'],
                'order' => 'dci, strength',
            ],
        ];
    }

    public static function get(string $slug): array
    {
        $definitions = self::definitions();
        if (!isset($definitions[$slug])) {
            throw new \LogicException(sprintf('Référentiel inconnu : %s', $slug));
        }
        return $definitions[$slug];
    }

    public static function child(string $slug, string $child): array
    {
        $definition = self::get($slug);
        if (!isset($definition['children'][$child])) {
            throw new \LogicException(sprintf('Sous-ressource inconnue : %s/%s', $slug, $child));
        }
        return $definition['children'][$child];
    }
}

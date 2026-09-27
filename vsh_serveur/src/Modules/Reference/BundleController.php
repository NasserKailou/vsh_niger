<?php

declare(strict_types=1);

namespace Vsh\Modules\Reference;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Prescriptions\PrescriptionTemplateService;
use Vsh\Modules\Settings\SettingsService;

/**
 * Paquet de référence pour l'application mobile (fonctionnement hors ligne).
 *
 * GET /reference/bundle            → tout le référentiel
 * GET /reference/bundle?since=…    → uniquement ce qui a changé depuis (valeur « generated_at » du paquet précédent)
 *
 * Les éléments désactivés sont transmis avec active=false pour que l'appareil cesse de les proposer.
 * Un compte patient ne reçoit que les services et les paramètres publics.
 */
final class BundleController
{
    /** @var ReferenceService */
    private $references;

    /** @var TariffService */
    private $tariffs;

    /** @var SettingsService */
    private $settings;

    /** @var Validator */
    private $validator;

    /** @var PrescriptionTemplateService */
    private $templates;

    public function __construct(
        ReferenceService $references,
        TariffService $tariffs,
        SettingsService $settings,
        Validator $validator,
        PrescriptionTemplateService $templates
    ) {
        $this->templates = $templates;
        $this->references = $references;
        $this->tariffs = $tariffs;
        $this->settings = $settings;
        $this->validator = $validator;
    }

    public function show(Request $request): Response
    {
        /** @var AuthContext $auth */
        $auth = $request->attribute('auth');
        $data = $this->validator->validate((array) $request->query(), ['since' => 'nullable|datetime']);
        $since = $data['since'] ?? null;
        // Horodatage pris avant les lectures : rien ne peut être manqué au prochain appel (comparaison inclusive).
        $generatedAt = Clock::now()->format('Y-m-d\TH:i:s\Z');

        $bundle = [
            'generated_at' => $generatedAt,
            'full' => $since === null,
            'settings' => $this->settings->values(true),
            'services' => $this->references->changedSince('services', $since),
        ];

        if (!$auth->isPatient()) {
            if (!$auth->can('reference.read')) {
                throw HttpException::forbidden();
            }
            $bundle['medical_acts'] = $this->references->changedSince('medical-acts', $since);
            $bundle['treatment_types'] = $this->references->changedSince('treatment-types', $since);
            $bundle['examination_types'] = $this->references->changedSince('examination-types', $since);
            $bundle['medications'] = $this->references->changedSince('medications', $since);
            $bundle['tariffs'] = $this->tariffs->changedSince($since);
            if ($auth->can('prescription_templates.read')) {
                $bundle['prescription_templates'] = $this->templates->changedSince($since);
            }
        }

        return ApiResponse::success($bundle);
    }
}

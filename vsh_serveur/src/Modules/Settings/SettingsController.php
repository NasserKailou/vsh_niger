<?php

declare(strict_types=1);

namespace Vsh\Modules\Settings;

use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class SettingsController
{
    /** @var SettingsService */
    private $service;

    public function __construct(SettingsService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        return ApiResponse::success($this->service->list());
    }

    /**
     * Corps attendu : {"values": {"homecare.dispatch_mode": "BOTH", "appointments.max_days_ahead": 30}}
     */
    public function update(Request $request): Response
    {
        $values = $request->json()['values'] ?? null;
        if (!is_array($values)) {
            throw new ValidationException(['values' => ['Objet « values » attendu.']]);
        }
        return ApiResponse::success($this->service->update($values, $request), 'Paramètres enregistrés.');
    }
}

<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class RegistrationController
{
    /** @var RegistrationService */
    private $service;

    public function __construct(RegistrationService $service)
    {
        $this->service = $service;
    }

    public function requestCode(Request $request): Response
    {
        $sent = $this->service->requestRegistrationCode($request->json(), $request);
        return ApiResponse::success(
            ['code_required' => $sent],
            $sent ? 'Un code de vérification vient d\'être envoyé par SMS.' : 'Aucun code n\'est nécessaire.'
        );
    }

    public function register(Request $request): Response
    {
        return ApiResponse::created(
            $this->service->register($request->json(), $request),
            'Inscription enregistrée. Votre dossier sera validé par la clinique.'
        );
    }

    public function portalCode(Request $request): Response
    {
        $this->service->requestPortalCode($request->json(), $request);
        return ApiResponse::success(null, 'Si ces informations correspondent à un dossier, un code vient d\'être envoyé par SMS.');
    }

    public function portalLogin(Request $request): Response
    {
        return ApiResponse::success($this->service->portalLogin($request->json(), $request), 'Connexion réussie.');
    }

    public function myPatients(Request $request): Response
    {
        return ApiResponse::success($this->service->myPatients($request));
    }

    public function myPatient(Request $request): Response
    {
        return ApiResponse::success($this->service->myPatient((string) $request->param('id'), $request));
    }

    public function addDependent(Request $request): Response
    {
        return ApiResponse::created(
            $this->service->addDependent($request->json(), $request),
            'Dossier ajouté. Il sera validé par la clinique.'
        );
    }

    public function pending(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->service->pending($pagination);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function approve(Request $request): Response
    {
        return ApiResponse::success($this->service->approve((string) $request->param('id'), $request), 'Inscription validée.');
    }

    public function reject(Request $request): Response
    {
        return ApiResponse::success($this->service->reject((string) $request->param('id'), $request->json(), $request), 'Inscription refusée.');
    }
}

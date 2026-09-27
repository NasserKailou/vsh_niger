<?php

declare(strict_types=1);

namespace Vsh\Modules\Prescriptions;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class PrescriptionTemplateController
{
    /** @var PrescriptionTemplateService */
    private $service;

    public function __construct(PrescriptionTemplateService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->service->list((array) $request->query(), $pagination, $request);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function suggest(Request $request): Response
    {
        return ApiResponse::success($this->service->suggest((array) $request->query(), $request));
    }

    public function show(Request $request): Response
    {
        return ApiResponse::success($this->service->get((string) $request->param('id'), $request));
    }

    public function store(Request $request): Response
    {
        return ApiResponse::created($this->service->create($request->json(), $request), 'Modèle enregistré (brouillon).');
    }

    public function update(Request $request): Response
    {
        return ApiResponse::success($this->service->update((string) $request->param('id'), $request->json(), $request), 'Modèle mis à jour.');
    }

    public function approve(Request $request): Response
    {
        return ApiResponse::success($this->service->approve((string) $request->param('id'), $request), 'Modèle approuvé.');
    }

    public function archive(Request $request): Response
    {
        return ApiResponse::success($this->service->archive((string) $request->param('id'), $request), 'Modèle archivé.');
    }
}

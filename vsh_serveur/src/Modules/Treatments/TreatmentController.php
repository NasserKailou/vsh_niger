<?php

declare(strict_types=1);

namespace Vsh\Modules\Treatments;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class TreatmentController
{
    /** @var TreatmentService */
    private $service;

    public function __construct(TreatmentService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->service->list((array) $request->query(), $pagination, $request);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function show(Request $request): Response
    {
        return ApiResponse::success($this->service->get((string) $request->param('id'), $request));
    }

    public function store(Request $request): Response
    {
        return ApiResponse::created($this->service->create($request->json(), $request), 'Soin enregistré.');
    }

    public function update(Request $request): Response
    {
        return ApiResponse::success($this->service->update((string) $request->param('id'), $request->json(), $request), 'Soin mis à jour.');
    }

    public function perform(Request $request): Response
    {
        return ApiResponse::success($this->service->perform((string) $request->param('id'), $request->json(), $request), 'Soin réalisé.');
    }

    public function cancel(Request $request): Response
    {
        return ApiResponse::success($this->service->cancel((string) $request->param('id'), $request->json(), $request), 'Soin annulé.');
    }
}

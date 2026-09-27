<?php

declare(strict_types=1);

namespace Vsh\Modules\Prescriptions;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class PrescriptionController
{
    /** @var PrescriptionService */
    private $service;

    public function __construct(PrescriptionService $service)
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
        return ApiResponse::created($this->service->create($request->json(), $request), 'Ordonnance enregistrée.');
    }

    public function update(Request $request): Response
    {
        return ApiResponse::success($this->service->update((string) $request->param('id'), $request->json(), $request), 'Ordonnance mise à jour.');
    }

    public function sign(Request $request): Response
    {
        return ApiResponse::success($this->service->sign((string) $request->param('id'), $request), 'Ordonnance signée.');
    }

    public function cancel(Request $request): Response
    {
        return ApiResponse::success($this->service->cancel((string) $request->param('id'), $request->json(), $request), 'Ordonnance annulée.');
    }

    public function forOwnPatient(Request $request): Response
    {
        return ApiResponse::success($this->service->forOwnPatient((string) $request->param('id'), $request));
    }

    public function pdf(Request $request): Response
    {
        $document = $this->service->pdf((string) $request->param('id'), $request);
        return ApiResponse::file($document['content'], $document['filename']);
    }

    public function pdfForOwnPatient(Request $request): Response
    {
        $document = $this->service->pdfForOwnPatient((string) $request->param('id'), (string) $request->param('prescriptionId'), $request);
        return ApiResponse::file($document['content'], $document['filename']);
    }
}

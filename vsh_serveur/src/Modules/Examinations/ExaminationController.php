<?php

declare(strict_types=1);

namespace Vsh\Modules\Examinations;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class ExaminationController
{
    /** @var ExaminationService */
    private $service;

    public function __construct(ExaminationService $service)
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
        return ApiResponse::created($this->service->prescribe($request->json(), $request), 'Examen prescrit.');
    }

    public function start(Request $request): Response
    {
        return ApiResponse::success($this->service->start((string) $request->param('id'), $request), 'Examen démarré.');
    }

    public function results(Request $request): Response
    {
        return ApiResponse::success($this->service->recordResults((string) $request->param('id'), $request->json(), $request), 'Résultats enregistrés.');
    }

    public function complete(Request $request): Response
    {
        return ApiResponse::success($this->service->complete((string) $request->param('id'), $request), 'Examen terminé.');
    }

    public function validate(Request $request): Response
    {
        return ApiResponse::success($this->service->validate((string) $request->param('id'), $request), 'Résultats validés.');
    }

    public function cancel(Request $request): Response
    {
        return ApiResponse::success($this->service->cancel((string) $request->param('id'), $request->json(), $request), 'Examen annulé.');
    }

    public function forOwnPatient(Request $request): Response
    {
        return ApiResponse::success($this->service->forOwnPatient((string) $request->param('id'), $request));
    }
}

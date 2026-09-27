<?php

declare(strict_types=1);

namespace Vsh\Modules\Appointments;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class AppointmentController
{
    /** @var AppointmentService */
    private $service;

    public function __construct(AppointmentService $service)
    {
        $this->service = $service;
    }

    public function slots(Request $request): Response
    {
        return ApiResponse::success($this->service->slots((array) $request->query(), $request));
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
        return ApiResponse::created($this->service->create($request->json(), $request), 'Rendez-vous enregistré.');
    }

    public function confirm(Request $request): Response
    {
        return ApiResponse::success($this->service->confirm((string) $request->param('id'), $request->json(), $request), 'Rendez-vous confirmé.');
    }

    public function reschedule(Request $request): Response
    {
        return ApiResponse::success($this->service->reschedule((string) $request->param('id'), $request->json(), $request), 'Rendez-vous déplacé.');
    }

    public function cancel(Request $request): Response
    {
        return ApiResponse::success($this->service->cancel((string) $request->param('id'), $request->json(), $request), 'Rendez-vous annulé.');
    }

    public function checkIn(Request $request): Response
    {
        return ApiResponse::success($this->service->checkIn((string) $request->param('id'), $request), 'Arrivée enregistrée.');
    }

    public function noShow(Request $request): Response
    {
        return ApiResponse::success($this->service->noShow((string) $request->param('id'), $request), 'Absence enregistrée.');
    }

    public function forOwnPatient(Request $request): Response
    {
        return ApiResponse::success($this->service->forOwnPatient((string) $request->param('id'), $request));
    }
}

<?php

declare(strict_types=1);

namespace Vsh\Modules\Homecare;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class HomecareController
{
    private const FIELD_MESSAGES = [
        'depart' => 'Départ enregistré.',
        'arrive' => 'Arrivée enregistrée.',
        'start' => 'Visite démarrée.',
        'complete' => 'Visite terminée.',
        'fail' => 'Échec enregistré.',
    ];

    /** @var HomecareService */
    private $service;

    public function __construct(HomecareService $service)
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
        return ApiResponse::created($this->service->create($request->json(), $request), 'Demande de visite à domicile enregistrée.');
    }

    public function approve(Request $request): Response
    {
        return ApiResponse::success($this->service->approve((string) $request->param('id'), $request), 'Demande validée.');
    }

    public function accept(Request $request): Response
    {
        return ApiResponse::success($this->service->accept((string) $request->param('id'), $request->json(), $request), 'Visite prise en charge.');
    }

    public function assign(Request $request): Response
    {
        return ApiResponse::success($this->service->assign((string) $request->param('id'), $request->json(), $request), 'Équipe affectée.');
    }

    public function release(Request $request): Response
    {
        return ApiResponse::success($this->service->release((string) $request->param('id'), $request->json(), $request), 'Visite remise en attente.');
    }

    public function field(Request $request, string $action): Response
    {
        return ApiResponse::success(
            $this->service->fieldAction((string) $request->param('id'), $action, $request->json(), $request),
            self::FIELD_MESSAGES[$action] ?? 'Opération effectuée.'
        );
    }

    public function cancel(Request $request): Response
    {
        return ApiResponse::success($this->service->cancel((string) $request->param('id'), $request->json(), $request), 'Visite annulée.');
    }

    public function track(Request $request): Response
    {
        return ApiResponse::success($this->service->track((string) $request->param('id'), $request->json(), $request), 'Trajet enregistré.');
    }

    public function trackPoints(Request $request): Response
    {
        return ApiResponse::success($this->service->trackPoints((string) $request->param('id'), $request));
    }

    public function homeLocation(Request $request): Response
    {
        return ApiResponse::success(
            $this->service->updateHomeLocation((string) $request->param('id'), $request->json(), $request),
            'Position du domicile enregistrée.'
        );
    }

    public function dispatchOptions(Request $request): Response
    {
        return ApiResponse::success($this->service->dispatchOptions((string) $request->param('id'), $request));
    }

    public function forOwnPatient(Request $request): Response
    {
        return ApiResponse::success($this->service->forOwnPatient((string) $request->param('id'), $request));
    }

    public function map(Request $request): Response
    {
        return ApiResponse::success($this->service->map($request));
    }
}

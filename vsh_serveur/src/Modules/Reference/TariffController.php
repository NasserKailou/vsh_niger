<?php

declare(strict_types=1);

namespace Vsh\Modules\Reference;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class TariffController
{
    /** @var TariffService */
    private $service;

    public function __construct(TariffService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        return ApiResponse::success($this->service->list((array) $request->query()));
    }

    public function current(Request $request): Response
    {
        $tariff = $this->service->current((array) $request->query());
        if ($tariff === null) {
            throw HttpException::notFound('Aucun tarif applicable à cette date.');
        }
        return ApiResponse::success($tariff);
    }

    public function store(Request $request): Response
    {
        return ApiResponse::created($this->service->create($request->json(), $request), 'Tarif enregistré.');
    }

    public function update(Request $request): Response
    {
        return ApiResponse::success(
            $this->service->update((string) $request->param('id'), $request->json(), $request),
            'Tarif modifié.'
        );
    }
}

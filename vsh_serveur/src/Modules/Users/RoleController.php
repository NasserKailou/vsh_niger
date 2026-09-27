<?php

declare(strict_types=1);

namespace Vsh\Modules\Users;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class RoleController
{
    /** @var RoleService */
    private $service;

    public function __construct(RoleService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        return ApiResponse::success($this->service->list());
    }

    public function permissions(Request $request): Response
    {
        return ApiResponse::success($this->service->permissions());
    }

    public function store(Request $request): Response
    {
        return ApiResponse::created($this->service->create($request->json(), $request), 'Rôle créé.');
    }

    public function update(Request $request): Response
    {
        return ApiResponse::success($this->service->update((string) $request->param('code'), $request->json(), $request), 'Rôle mis à jour.');
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete((string) $request->param('code'), $request);
        return ApiResponse::success(null, 'Rôle supprimé.');
    }
}

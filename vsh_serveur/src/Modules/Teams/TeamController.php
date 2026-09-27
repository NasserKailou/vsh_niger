<?php

declare(strict_types=1);

namespace Vsh\Modules\Teams;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;
use Vsh\Core\Security\AuthContext;

final class TeamController
{
    /** @var TeamService */
    private $service;

    public function __construct(TeamService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        return ApiResponse::success($this->service->list((array) $request->query()));
    }

    public function mine(Request $request): Response
    {
        /** @var AuthContext $auth */
        $auth = $request->attribute('auth');
        return ApiResponse::success($this->service->mine($auth));
    }

    public function show(Request $request): Response
    {
        return ApiResponse::success($this->service->get((string) $request->param('id')));
    }

    public function store(Request $request): Response
    {
        return ApiResponse::created($this->service->create($request->json(), $request), 'Équipe créée.');
    }

    public function update(Request $request): Response
    {
        return ApiResponse::success($this->service->update((string) $request->param('id'), $request->json(), $request), 'Équipe mise à jour.');
    }

    public function addMember(Request $request): Response
    {
        return ApiResponse::created($this->service->addMember((string) $request->param('id'), $request->json(), $request), 'Membre ajouté.');
    }

    public function updateMember(Request $request): Response
    {
        return ApiResponse::success(
            $this->service->updateMember((string) $request->param('id'), (string) $request->param('memberId'), $request->json(), $request),
            'Membre mis à jour.'
        );
    }
}

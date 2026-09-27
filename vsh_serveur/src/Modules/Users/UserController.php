<?php

declare(strict_types=1);

namespace Vsh\Modules\Users;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class UserController
{
    /** @var UserService */
    private $service;

    public function __construct(UserService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->service->list((array) $request->query(), $pagination);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function show(Request $request): Response
    {
        return ApiResponse::success($this->service->get((string) $request->param('id')));
    }

    public function store(Request $request): Response
    {
        $result = $this->service->createStaff($request->json(), $request);
        return ApiResponse::created(
            $result,
            'Compte créé. Communiquez le mot de passe temporaire à l\'utilisateur : il devra le changer à la première connexion.'
        );
    }

    public function update(Request $request): Response
    {
        return ApiResponse::success($this->service->update((string) $request->param('id'), $request->json(), $request), 'Compte mis à jour.');
    }

    public function suspend(Request $request): Response
    {
        return ApiResponse::success($this->service->suspend((string) $request->param('id'), $request), 'Compte suspendu. Ses sessions ont été fermées.');
    }

    public function activate(Request $request): Response
    {
        return ApiResponse::success($this->service->activate((string) $request->param('id'), $request), 'Compte réactivé.');
    }

    public function resetPassword(Request $request): Response
    {
        return ApiResponse::success(
            $this->service->resetPassword((string) $request->param('id'), $request),
            'Mot de passe réinitialisé. Les sessions de l\'utilisateur ont été fermées.'
        );
    }
}

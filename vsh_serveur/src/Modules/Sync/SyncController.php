<?php

declare(strict_types=1);

namespace Vsh\Modules\Sync;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;
use Vsh\Core\Security\AuthContext;

final class SyncController
{
    /** @var SyncService */
    private $sync;

    /** @var ConflictService */
    private $conflicts;

    /** @var SyncScope */
    private $scope;

    /** @var SyncAdminService */
    private $admin;

    public function __construct(SyncService $sync, ConflictService $conflicts, SyncScope $scope, SyncAdminService $admin)
    {
        $this->sync = $sync;
        $this->conflicts = $conflicts;
        $this->scope = $scope;
        $this->admin = $admin;
    }

    public function push(Request $request): Response
    {
        return ApiResponse::success($this->sync->push($request->json(), $request, self::auth($request)), 'Opérations traitées.');
    }

    public function pull(Request $request): Response
    {
        return ApiResponse::success($this->sync->pull((array) $request->query(), $request, self::auth($request)));
    }

    public function status(Request $request): Response
    {
        return ApiResponse::success($this->sync->status(self::auth($request)));
    }

    public function conflicts(Request $request): Response
    {
        return ApiResponse::success($this->conflicts->list((array) $request->query(), self::auth($request)));
    }

    public function resolveConflict(Request $request): Response
    {
        return ApiResponse::success(
            $this->conflicts->resolve((string) $request->param('id'), $request->json(), $request, self::auth($request)),
            'Conflit résolu.'
        );
    }

    public function pinned(Request $request): Response
    {
        return ApiResponse::success($this->scope->pinned(self::auth($request)));
    }

    public function pin(Request $request): Response
    {
        $this->scope->pin((string) $request->param('id'), $request, self::auth($request));
        return ApiResponse::success(null, 'Dossier disponible hors ligne à la prochaine synchronisation.');
    }

    public function unpin(Request $request): Response
    {
        $this->scope->unpin((string) $request->param('id'), $request, self::auth($request));
        return ApiResponse::success(null, 'Dossier retiré des dossiers hors ligne.');
    }

    public function devices(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->admin->devices($pagination);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function operations(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->admin->operations((array) $request->query(), $pagination);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function revokeDevice(Request $request): Response
    {
        $this->admin->revokeDevice((string) $request->param('id'), $request, self::auth($request));
        return ApiResponse::success(null, 'Appareil révoqué. Ses sessions ont été fermées.');
    }

    private static function auth(Request $request): AuthContext
    {
        /** @var AuthContext $auth */
        $auth = $request->attribute('auth');
        return $auth;
    }
}

<?php

declare(strict_types=1);

namespace Vsh\Modules\Audit;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class AuditController
{
    /** @var AuditService */
    private $service;

    public function __construct(AuditService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->service->list((array) $request->query(), $pagination);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function facets(Request $request): Response
    {
        return ApiResponse::success($this->service->facets());
    }
}

<?php

declare(strict_types=1);

namespace Vsh\Modules\Dashboard;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class DashboardController
{
    /** @var DashboardService */
    private $service;

    public function __construct(DashboardService $service)
    {
        $this->service = $service;
    }

    public function show(Request $request): Response
    {
        return ApiResponse::success($this->service->summary($request));
    }
}

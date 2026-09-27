<?php

declare(strict_types=1);

namespace Vsh\Modules\Users;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class StaffDirectoryController
{
    /** @var StaffDirectory */
    private $directory;

    public function __construct(StaffDirectory $directory)
    {
        $this->directory = $directory;
    }

    public function index(Request $request): Response
    {
        return ApiResponse::success($this->directory->list((array) $request->query(), $request));
    }
}

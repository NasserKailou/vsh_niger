<?php

declare(strict_types=1);

namespace Vsh\Core\Middleware;

use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

interface MiddlewareInterface
{
    /**
     * @param callable $next   function (Request $request): Response
     * @param string[] $params Paramètres déclarés dans la route ("alias:param1,param2")
     */
    public function process(Request $request, callable $next, array $params = []): Response;
}

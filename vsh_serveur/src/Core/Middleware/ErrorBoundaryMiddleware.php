<?php

declare(strict_types=1);

namespace Vsh\Core\Middleware;

use Vsh\Core\ErrorHandler;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

/**
 * Convertit les exceptions en réponses JSON à l'intérieur de la chaîne, pour que les middlewares
 * extérieurs (identifiant de requête, sécurité, CORS) s'appliquent aussi aux réponses d'erreur.
 */
final class ErrorBoundaryMiddleware implements MiddlewareInterface
{
    /** @var ErrorHandler */
    private $errorHandler;

    public function __construct(ErrorHandler $errorHandler)
    {
        $this->errorHandler = $errorHandler;
    }

    public function process(Request $request, callable $next, array $params = []): Response
    {
        try {
            return $next($request);
        } catch (\Throwable $exception) {
            return $this->errorHandler->render($exception, $request);
        }
    }
}

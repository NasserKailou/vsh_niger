<?php

declare(strict_types=1);

namespace Vsh\Core\Middleware;

use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;
use Vsh\Core\Logger;
use Vsh\Core\Support\Uuid;

/**
 * Identifiant de corrélation : repris de l'en-tête X-Request-Id s'il est bien formé, sinon généré.
 * Il est écrit dans chaque ligne de journal et renvoyé au client (utile pour le support).
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    /** @var Logger */
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function process(Request $request, callable $next, array $params = []): Response
    {
        $requestId = $request->header('x-request-id');
        if ($requestId === null || preg_match('/^[A-Za-z0-9\-]{8,64}$/', $requestId) !== 1) {
            $requestId = Uuid::v4();
        }
        $request->setAttribute('request_id', $requestId);
        $this->logger->setRequestId($requestId);

        return $next($request)->withHeader('X-Request-Id', $requestId);
    }
}

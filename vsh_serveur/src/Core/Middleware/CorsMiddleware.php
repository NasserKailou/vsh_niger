<?php

declare(strict_types=1);

namespace Vsh\Core\Middleware;

use Vsh\Core\Config;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

/**
 * CORS pour les interfaces web servies depuis une autre origine. Seules les origines listées dans
 * CORS_ALLOWED_ORIGINS reçoivent les en-têtes d'autorisation. L'application mobile n'est pas concernée.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Authorization, Content-Type, X-Request-Id, X-Device-Id, X-CSRF-Token';
    private const EXPOSED_HEADERS = 'X-Request-Id, Retry-After';

    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function process(Request $request, callable $next, array $params = []): Response
    {
        $origin = $request->header('origin');
        $allowed = $origin !== null
            && in_array($origin, (array) $this->config->get('security.cors.allowed_origins', []), true);

        if ($request->method() === 'OPTIONS' && $request->header('access-control-request-method') !== null) {
            $response = new Response('', 204);
            if ($allowed) {
                $response = $this->withCorsHeaders($response, (string) $origin)
                    ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
                    ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
                    ->withHeader('Access-Control-Max-Age', '600');
            }
            return $response->withHeader('Vary', 'Origin');
        }

        $response = $next($request);
        if ($allowed) {
            $response = $this->withCorsHeaders($response, (string) $origin);
        }
        return $response->withHeader('Vary', 'Origin');
    }

    private function withCorsHeaders(Response $response, string $origin): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Expose-Headers', self::EXPOSED_HEADERS);
    }
}

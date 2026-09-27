<?php

declare(strict_types=1);

namespace Vsh\Core\Middleware;

use Vsh\Core\Config;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

/**
 * En-têtes de sécurité. Les réponses de l'API ne sont jamais mises en cache (données médicales).
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function process(Request $request, callable $next, array $params = []): Response
    {
        $response = $next($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer');

        $apiPrefix = (string) $this->config->get('app.api_prefix', '/api');
        if (strpos($request->path(), $apiPrefix) === 0) {
            if (!$response->hasHeader('Cache-Control')) {
                $response = $response->withHeader('Cache-Control', 'no-store');
            }
            $response = $response->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        if ($this->config->get('security.hsts', false) && $request->isSecure()) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }
}

<?php

declare(strict_types=1);

namespace Vsh\Core\Http;

use Vsh\Core\Container;
use Vsh\Core\ErrorHandler;
use Vsh\Core\Middleware\MiddlewareInterface;
use Vsh\Core\Routing\Router;

/**
 * Cycle de vie d'une requête : middlewares globaux → routage → middlewares de route → contrôleur.
 *
 * Un middleware se déclare par nom de classe ou par alias, avec des paramètres optionnels :
 * "permission:patients.read,patients.update".
 */
final class Kernel
{
    /** @var Container */
    private $container;

    /** @var Router */
    private $router;

    /** @var string[] */
    private $globalMiddleware;

    /** @var array<string,string> */
    private $aliases;

    public function __construct(Container $container, Router $router, array $globalMiddleware, array $aliases)
    {
        $this->container = $container;
        $this->router = $router;
        $this->globalMiddleware = $globalMiddleware;
        $this->aliases = $aliases;
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->runPipeline($this->globalMiddleware, $request, function (Request $request): Response {
                return $this->dispatch($request);
            });
        } catch (\Throwable $exception) {
            // Dernier recours si l'erreur survient hors de ErrorBoundaryMiddleware.
            return $this->container->get(ErrorHandler::class)->render($exception, $request);
        }
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request->method(), $request->path());
        $request->setAttribute('route_params', $match['params']);
        $route = $match['route'];

        return $this->runPipeline($route['middleware'], $request, function (Request $request) use ($route): Response {
            return $this->callHandler($route['handler'], $request);
        });
    }

    private function runPipeline(array $middleware, Request $request, callable $core): Response
    {
        $next = $core;
        foreach (array_reverse($middleware) as $definition) {
            list($instance, $params) = $this->resolveMiddleware((string) $definition);
            $next = function (Request $request) use ($instance, $params, $next): Response {
                return $instance->process($request, $next, $params);
            };
        }
        return $next($request);
    }

    /**
     * @return array{0: MiddlewareInterface, 1: string[]}
     */
    private function resolveMiddleware(string $definition): array
    {
        $parts = explode(':', $definition, 2);
        $name = $parts[0];
        $params = (isset($parts[1]) && $parts[1] !== '') ? explode(',', $parts[1]) : [];
        $class = $this->aliases[$name] ?? $name;
        $instance = $this->container->get($class);
        if (!$instance instanceof MiddlewareInterface) {
            throw new \LogicException(sprintf('%s n\'est pas un middleware.', $class));
        }
        return [$instance, $params];
    }

    /**
     * @param mixed $handler [NomDeClasse::class, 'méthode', ...arguments supplémentaires] ou callable
     */
    private function callHandler($handler, Request $request): Response
    {
        $arguments = [$request];
        if (is_array($handler) && count($handler) >= 2 && is_string($handler[0])) {
            $arguments = array_merge($arguments, array_slice($handler, 2));
            $handler = [$this->container->get($handler[0]), $handler[1]];
        }
        if (!is_callable($handler)) {
            throw new \LogicException('Gestionnaire de route invalide.');
        }
        $response = call_user_func_array($handler, $arguments);
        if (!$response instanceof Response) {
            throw new \LogicException('Un contrôleur doit retourner une instance de Response.');
        }
        return $response;
    }
}

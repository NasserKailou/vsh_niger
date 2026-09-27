<?php

declare(strict_types=1);

namespace Vsh\Core\Routing;

use Vsh\Core\Exceptions\HttpException;

/**
 * Routeur par expressions régulières.
 * Paramètres : {id} (tout segment), {id:uuid}, {id:int}. Les routes GET répondent aussi à HEAD.
 */
final class Router
{
    private const PARAM_PATTERNS = [
        'any' => '[^/]+',
        'int' => '[0-9]+',
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
    ];

    /** @var array<int,array> */
    private $routes = [];

    /** @var string */
    private $prefix = '';

    /** @var string[] */
    private $groupMiddleware = [];

    /**
     * @param mixed $handler
     */
    public function get(string $path, $handler, array $middleware = []): void
    {
        $this->add(['GET'], $path, $handler, $middleware);
    }

    /**
     * @param mixed $handler
     */
    public function post(string $path, $handler, array $middleware = []): void
    {
        $this->add(['POST'], $path, $handler, $middleware);
    }

    /**
     * @param mixed $handler
     */
    public function put(string $path, $handler, array $middleware = []): void
    {
        $this->add(['PUT'], $path, $handler, $middleware);
    }

    /**
     * @param mixed $handler
     */
    public function patch(string $path, $handler, array $middleware = []): void
    {
        $this->add(['PATCH'], $path, $handler, $middleware);
    }

    /**
     * @param mixed $handler
     */
    public function delete(string $path, $handler, array $middleware = []): void
    {
        $this->add(['DELETE'], $path, $handler, $middleware);
    }

    /**
     * @param string[] $methods
     * @param mixed $handler
     */
    public function add(array $methods, string $path, $handler, array $middleware = []): void
    {
        $fullPath = self::normalize($this->prefix . '/' . ltrim($path, '/'));
        $this->routes[] = [
            'methods' => array_map('strtoupper', $methods),
            'path' => $fullPath,
            'regex' => $this->compile($fullPath),
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;
        $this->prefix = rtrim($previousPrefix . '/' . trim($prefix, '/'), '/');
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);
        try {
            $callback($this);
        } finally {
            $this->prefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }

    /**
     * @return array{route: array, params: array<string,string>}
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        $path = self::normalize($path);
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            if (!in_array($lookup, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }
            return ['route' => $route, 'params' => $params];
        }

        if ($allowed !== []) {
            throw HttpException::methodNotAllowed(array_values(array_unique($allowed)));
        }
        throw HttpException::notFound("Point d'accès introuvable.");
    }

    private function compile(string $path): string
    {
        $quoted = preg_quote($path, '#');
        $regex = preg_replace_callback(
            '#\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)(?:\\\\?:([a-z]+))?\\\\\}#',
            function (array $matches): string {
                $type = (isset($matches[2]) && $matches[2] !== '') ? $matches[2] : 'any';
                if (!isset(self::PARAM_PATTERNS[$type])) {
                    throw new \LogicException(sprintf('Type de paramètre de route inconnu : %s', $type));
                }
                return '(?P<' . $matches[1] . '>' . self::PARAM_PATTERNS[$type] . ')';
            },
            $quoted
        );
        return '#^' . $regex . '$#';
    }

    private static function normalize(string $path): string
    {
        $path = (string) preg_replace('#/+#', '/', $path);
        return '/' . trim($path, '/');
    }
}

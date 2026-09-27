<?php

declare(strict_types=1);

namespace Vsh\Core\Http;

use Vsh\Core\Exceptions\HttpException;

final class Request
{
    /** @var string */
    private $method;

    /** @var string */
    private $path;

    /** @var array */
    private $query;

    /** @var array<string,string> En-têtes, noms en minuscules */
    private $headers;

    /** @var string|null Corps brut ; null = lu à la demande depuis php://input */
    private $rawBody;

    /** @var array */
    private $server;

    /** @var string[] */
    private $trustedProxies;

    /** @var array<string,mixed> */
    private $attributes = [];

    /** @var array|null */
    private $json;

    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        ?string $rawBody = null,
        array $server = [],
        array $trustedProxies = []
    ) {
        $this->method = strtoupper($method);
        $this->path = self::normalizePath($path);
        $this->query = $query;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
        $this->rawBody = $rawBody;
        $this->server = $server;
        $this->trustedProxies = $trustedProxies;
    }

    public static function fromGlobals(array $trustedProxies = []): self
    {
        $server = $_SERVER;
        $path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $basePath = self::detectBasePath($server);
        if ($basePath !== '' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (strpos($key, 'HTTP_') === 0) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }
        if (!isset($headers['AUTHORIZATION']) && isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['AUTHORIZATION'] = (string) $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return new self(
            (string) ($server['REQUEST_METHOD'] ?? 'GET'),
            $path === '' ? '/' : $path,
            $_GET,
            $headers,
            null,
            $server,
            $trustedProxies
        );
    }

    /**
     * Préfixe d'URL sous lequel l'application est installée (ex. /vsh_niger/vsh_serveur/public).
     */
    public static function detectBasePath(array $server): string
    {
        $directory = str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/')));
        return ($directory === '/' || $directory === '.') ? '' : rtrim($directory, '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function query(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);
        return array_key_exists($name, $this->headers) ? $this->headers[$name] : $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null || preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    /**
     * @param int|null $maxBytes Lecture limitée (protection mémoire) ; au-delà, le contenu est tronqué à $maxBytes
     */
    public function rawBody(?int $maxBytes = null): string
    {
        if ($this->rawBody === null) {
            $stream = fopen('php://input', 'rb');
            $content = $stream === false ? false : stream_get_contents($stream, $maxBytes === null ? -1 : $maxBytes);
            $this->rawBody = $content === false ? '' : $content;
        }
        return $this->rawBody;
    }

    /**
     * Corps JSON décodé (objet ou tableau). Corps vide = tableau vide.
     */
    public function json(): array
    {
        if ($this->json === null) {
            $raw = $this->rawBody();
            if (trim($raw) === '') {
                $this->json = [];
            } else {
                $decoded = json_decode($raw, true, 64);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    throw HttpException::badRequest("Le corps de la requête n'est pas un JSON valide.", 'INVALID_JSON');
                }
                $this->json = $decoded;
            }
        }
        return $this->json;
    }

    public function param(string $name): ?string
    {
        $params = $this->attribute('route_params', []);
        return isset($params[$name]) ? (string) $params[$name] : null;
    }

    /**
     * @param mixed $value
     */
    public function setAttribute(string $name, $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function attribute(string $name, $default = null)
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    /**
     * Adresse IP du client. X-Forwarded-For n'est pris en compte que si la connexion vient d'un proxy de confiance.
     */
    public function ip(): ?string
    {
        $remote = isset($this->server['REMOTE_ADDR']) ? (string) $this->server['REMOTE_ADDR'] : null;
        $forwarded = $this->header('x-forwarded-for');
        if ($remote === null || $forwarded === null || !in_array($remote, $this->trustedProxies, true)) {
            return $remote;
        }
        $chain = array_reverse(array_map('trim', explode(',', $forwarded)));
        foreach ($chain as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) !== false && !in_array($address, $this->trustedProxies, true)) {
                return $address;
            }
        }
        return $remote;
    }

    public function userAgent(): ?string
    {
        $agent = $this->header('user-agent');
        return $agent === null ? null : mb_substr($agent, 0, 255);
    }

    public function isSecure(): bool
    {
        $https = isset($this->server['HTTPS']) ? strtolower((string) $this->server['HTTPS']) : '';
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        $remote = isset($this->server['REMOTE_ADDR']) ? (string) $this->server['REMOTE_ADDR'] : '';
        return in_array($remote, $this->trustedProxies, true)
            && strtolower((string) $this->header('x-forwarded-proto', '')) === 'https';
    }

    private static function normalizePath(string $path): string
    {
        $path = (string) preg_replace('#/+#', '/', $path);
        return '/' . trim($path, '/');
    }
}

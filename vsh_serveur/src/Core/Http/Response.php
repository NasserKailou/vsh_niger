<?php

declare(strict_types=1);

namespace Vsh\Core\Http;

/**
 * Réponse HTTP immuable (les méthodes with* renvoient une copie).
 */
final class Response
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /** @var int */
    private $status;

    /** @var array<string,string> */
    private $headers = [];

    /** @var string */
    private $body;

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        foreach ($headers as $name => $value) {
            $this->headers[(string) $name] = (string) $value;
        }
    }

    /**
     * @param mixed $payload
     */
    public static function json($payload, int $status = 200, array $headers = []): self
    {
        $body = json_encode($payload, self::JSON_FLAGS);
        if ($body === false) {
            $status = 500;
            $body = '{"success":false,"message":"Une erreur interne est survenue.","code":"SERVER_ERROR","errors":{}}';
        }
        return new self($body, $status, array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        foreach (array_keys($clone->headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                unset($clone->headers[$key]);
            }
        }
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function send(bool $withBody = true): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        if ($withBody) {
            echo $this->body;
        }
    }
}

<?php

declare(strict_types=1);

namespace Vsh\Core\Exceptions;

/**
 * Erreur métier ou HTTP attendue : son message est destiné à l'utilisateur et renvoyé tel quel.
 */
class HttpException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var string */
    private $errorCode;

    /** @var array */
    private $errors;

    /** @var array<string,string> */
    private $headers;

    public function __construct(
        int $status,
        string $errorCode,
        string $message,
        array $errors = [],
        array $headers = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->errors = $errors;
        $this->headers = $headers;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function badRequest(string $message = 'Requête invalide.', string $code = 'BAD_REQUEST'): self
    {
        return new self(400, $code, $message);
    }

    public static function unauthorized(string $message = 'Authentification requise.', string $code = 'UNAUTHENTICATED'): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(
        string $message = "Vous n'avez pas les droits nécessaires pour cette action.",
        string $code = 'FORBIDDEN'
    ): self {
        return new self(403, $code, $message);
    }

    public static function notFound(string $message = 'Ressource introuvable.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    /**
     * @param string[] $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(
            405,
            'METHOD_NOT_ALLOWED',
            "Méthode non autorisée pour ce point d'accès.",
            [],
            ['Allow' => implode(', ', $allowedMethods)]
        );
    }

    public static function conflict(string $message, string $code = 'CONFLICT', array $errors = []): self
    {
        return new self(409, $code, $message, $errors);
    }

    public static function payloadTooLarge(): self
    {
        return new self(413, 'PAYLOAD_TOO_LARGE', 'Le contenu envoyé est trop volumineux.');
    }

    public static function unsupportedMediaType(): self
    {
        return new self(415, 'UNSUPPORTED_MEDIA_TYPE', 'Le contenu doit être envoyé au format JSON (application/json).');
    }

    public static function tooManyRequests(int $retryAfterSeconds): self
    {
        return new self(
            429,
            'RATE_LIMITED',
            'Trop de tentatives. Veuillez réessayer plus tard.',
            [],
            ['Retry-After' => (string) max(1, $retryAfterSeconds)]
        );
    }
}

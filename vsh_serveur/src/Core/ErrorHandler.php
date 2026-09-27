<?php

declare(strict_types=1);

namespace Vsh\Core;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

/**
 * Transforme toute erreur en réponse JSON standard. Les détails internes (message, fichier, trace)
 * ne sont écrits que dans le journal ; ils ne sont renvoyés au client qu'en mode debug (jamais en production).
 */
final class ErrorHandler
{
    private const FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /** @var Logger */
    private $logger;

    /** @var bool */
    private $debug;

    public function __construct(Logger $logger, bool $debug)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }

    public function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Les avertissements et notices deviennent des exceptions : aucune erreur silencieuse.
     */
    public function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], self::FATAL_ERRORS, true)) {
            return;
        }
        $this->logger->critical('Erreur fatale', [
            'message' => $error['message'],
            'location' => $error['file'] . ':' . $error['line'],
        ]);
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            $this->serverErrorResponse()->send();
        }
    }

    public function render(\Throwable $exception, ?Request $request = null): Response
    {
        if ($exception instanceof HttpException) {
            if ($exception->getStatus() >= 500) {
                $this->logger->error($exception->getMessage(), $this->requestContext($request));
            }
            $response = ApiResponse::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getStatus(),
                $exception->getErrors()
            );
            foreach ($exception->getHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            return $response;
        }

        $this->logger->error('Exception non gérée', array_merge($this->requestContext($request), [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'location' => $exception->getFile() . ':' . $exception->getLine(),
            'trace' => self::traceWithoutArguments($exception),
        ]));

        if (!$this->debug) {
            return $this->serverErrorResponse();
        }
        return ApiResponse::error('SERVER_ERROR', 'Une erreur interne est survenue.', 500, [], [
            'debug' => [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'location' => $exception->getFile() . ':' . $exception->getLine(),
                'trace' => self::traceWithoutArguments($exception),
            ],
        ]);
    }

    private function serverErrorResponse(): Response
    {
        return ApiResponse::error('SERVER_ERROR', 'Une erreur interne est survenue. Veuillez réessayer plus tard.', 500);
    }

    private function requestContext(?Request $request): array
    {
        if ($request === null) {
            return [];
        }
        return ['method' => $request->method(), 'path' => $request->path()];
    }

    /**
     * Trace sans les valeurs des arguments, qui peuvent contenir des mots de passe ou des données médicales.
     *
     * @return string[]
     */
    private static function traceWithoutArguments(\Throwable $exception): array
    {
        $lines = [];
        foreach (array_slice($exception->getTrace(), 0, 15) as $frame) {
            $location = isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? 0) : '[interne]';
            $function = (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '') . ($frame['function'] ?? '');
            $lines[] = $location . ' ' . $function . '()';
        }
        return $lines;
    }
}

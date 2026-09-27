<?php

declare(strict_types=1);

namespace Vsh\Core\Middleware;

use Vsh\Core\Config;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

/**
 * Contrôle du corps des requêtes d'écriture : taille maximale, type application/json, JSON bien formé.
 * Les envois de fichiers (multipart/form-data) sont contrôlés par le module Attachments.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    private const METHODS_WITH_BODY = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function process(Request $request, callable $next, array $params = []): Response
    {
        if (!in_array($request->method(), self::METHODS_WITH_BODY, true)) {
            return $next($request);
        }
        $contentType = strtolower((string) $request->header('content-type', ''));
        if (strpos($contentType, 'multipart/form-data') === 0) {
            return $next($request);
        }

        $maxBytes = (int) $this->config->get('app.max_json_bytes', 2097152);
        if ((int) $request->header('content-length', '0') > $maxBytes) {
            throw HttpException::payloadTooLarge();
        }
        $body = $request->rawBody($maxBytes + 1);
        if (strlen($body) > $maxBytes) {
            throw HttpException::payloadTooLarge();
        }
        if (trim($body) === '') {
            return $next($request);
        }
        if (strpos($contentType, 'application/json') !== 0) {
            throw HttpException::unsupportedMediaType();
        }
        $request->json();

        return $next($request);
    }
}

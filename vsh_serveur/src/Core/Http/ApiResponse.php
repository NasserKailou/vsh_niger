<?php

declare(strict_types=1);

namespace Vsh\Core\Http;

/**
 * Enveloppe JSON unique de l'API :
 *   succès : {"success": true,  "message": "…", "data": …, "meta": {…}}
 *   échec  : {"success": false, "message": "…", "code": "…", "errors": {…}}
 */
final class ApiResponse
{
    /**
     * @param mixed $data
     */
    public static function success(
        $data = null,
        string $message = 'Opération effectuée.',
        int $status = 200,
        ?array $meta = null
    ): Response {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data === null ? new \stdClass() : $data,
        ];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        return Response::json($payload, $status);
    }

    /**
     * @param mixed $data
     */
    public static function created($data, string $message = 'Ressource créée.'): Response
    {
        return self::success($data, $message, 201);
    }

    public static function paginated(
        array $items,
        int $page,
        int $perPage,
        int $total,
        string $message = 'Opération effectuée.'
    ): Response {
        return self::success($items, $message, 200, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ]);
    }

    /**
     * @param array $extra Clés additionnelles (ex. "debug" hors production)
     */
    public static function error(string $code, string $message, int $status, array $errors = [], array $extra = []): Response
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $code,
            'errors' => $errors === [] ? new \stdClass() : $errors,
        ];
        return Response::json(array_merge($payload, $extra), $status);
    }

    /**
     * Document généré (PDF…) à télécharger : jamais mis en cache (données personnelles), nom de fichier
     * réduit à des caractères sûrs pour l'en-tête.
     */
    public static function file(string $content, string $filename, string $contentType = 'application/pdf'): Response
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'document';
        return new Response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}

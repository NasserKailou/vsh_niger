<?php

declare(strict_types=1);

namespace Vsh\Core\Middleware;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;
use Vsh\Core\Security\AccessControl;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Security\TokenService;
use Vsh\Core\Support\Clock;

/**
 * Alias « auth ». Vérifie le jeton Bearer et place un AuthContext dans l'attribut « auth » de la requête.
 *
 * Paramètre facultatif « allow_password_change » : la route reste accessible à un utilisateur qui doit
 * changer son mot de passe (toutes les autres routes lui renvoient 403 PASSWORD_CHANGE_REQUIRED).
 */
final class AuthenticateMiddleware implements MiddlewareInterface
{
    /** @var TokenService */
    private $tokens;

    /** @var AccessControl */
    private $accessControl;

    public function __construct(TokenService $tokens, AccessControl $accessControl)
    {
        $this->tokens = $tokens;
        $this->accessControl = $accessControl;
    }

    public function process(Request $request, callable $next, array $params = []): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw HttpException::unauthorized();
        }
        $row = $this->tokens->findAccessToken($token);
        if ($row === null || $row['revoked_at'] !== null || $row['user_deleted_at'] !== null) {
            throw HttpException::unauthorized('Session invalide. Veuillez vous reconnecter.');
        }
        if ((string) $row['expires_at'] <= Clock::nowForDatabase()) {
            throw HttpException::unauthorized('Session expirée.', 'TOKEN_EXPIRED');
        }
        if ($row['device_revoked_at'] !== null) {
            throw HttpException::unauthorized('Cet appareil a été révoqué. Contactez la clinique.', 'DEVICE_REVOKED');
        }
        $status = (string) $row['status'];
        $allowedPending = $status === 'PENDING' && $row['account_type'] === 'PATIENT';
        if ($status !== 'ACTIVE' && !$allowedPending) {
            throw HttpException::unauthorized('Ce compte est désactivé.', 'ACCOUNT_DISABLED');
        }

        $mustChangePassword = (bool) $row['must_change_password'];
        if ($mustChangePassword && !in_array('allow_password_change', $params, true)) {
            throw new HttpException(403, 'PASSWORD_CHANGE_REQUIRED', 'Vous devez changer votre mot de passe avant de continuer.');
        }

        $access = $this->accessControl->rolesAndPermissions((int) $row['user_id']);
        $request->setAttribute('auth', new AuthContext(
            (int) $row['user_id'],
            (string) $row['user_uuid'],
            (string) $row['account_type'],
            $access['roles'],
            $access['permissions'],
            $row['device_id'] !== null ? (int) $row['device_id'] : null,
            (int) $row['token_id'],
            $mustChangePassword
        ));
        $this->tokens->touch((int) $row['token_id'], $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null);

        return $next($request);
    }
}

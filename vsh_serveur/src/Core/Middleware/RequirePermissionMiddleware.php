<?php

declare(strict_types=1);

namespace Vsh\Core\Middleware;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;
use Vsh\Core\Security\AuthContext;

/**
 * Alias « permission ». Exige TOUTES les permissions listées : 'permission:users.read,users.manage'.
 * À placer après « auth ». Le contrôle par ligne (quel patient, quelle équipe…) est fait par les Policies.
 */
final class RequirePermissionMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next, array $params = []): Response
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof AuthContext) {
            throw new \LogicException('Le middleware « permission » doit être précédé de « auth ».');
        }
        foreach ($params as $permission) {
            if (!$auth->can($permission)) {
                throw HttpException::forbidden();
            }
        }
        return $next($request);
    }
}

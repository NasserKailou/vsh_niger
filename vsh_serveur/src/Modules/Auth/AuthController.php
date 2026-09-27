<?php

declare(strict_types=1);

namespace Vsh\Modules\Auth;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class AuthController
{
    /** @var AuthService */
    private $service;

    public function __construct(AuthService $service)
    {
        $this->service = $service;
    }

    public function login(Request $request): Response
    {
        return ApiResponse::success($this->service->login($request->json(), $request), 'Connexion réussie.');
    }

    public function refresh(Request $request): Response
    {
        return ApiResponse::success($this->service->refresh($request->json(), $request), 'Session renouvelée.');
    }

    public function logout(Request $request): Response
    {
        $this->service->logout($request);
        return ApiResponse::success(null, 'Déconnexion effectuée.');
    }

    public function me(Request $request): Response
    {
        return ApiResponse::success($this->service->me($request));
    }

    public function changePassword(Request $request): Response
    {
        $this->service->changePassword($request->json(), $request);
        return ApiResponse::success(null, 'Mot de passe modifié. Vos autres appareils ont été déconnectés.');
    }

    public function forgotPassword(Request $request): Response
    {
        $this->service->forgotPassword($request->json(), $request);
        return ApiResponse::success(null, 'Si ce numéro correspond à un compte, un code de vérification vient d\'être envoyé par SMS.');
    }

    public function resetPassword(Request $request): Response
    {
        $this->service->resetPassword($request->json(), $request);
        return ApiResponse::success(null, 'Mot de passe réinitialisé. Vous pouvez vous connecter.');
    }

    public function devices(Request $request): Response
    {
        return ApiResponse::success($this->service->devices($request));
    }

    public function revokeDevice(Request $request): Response
    {
        $this->service->revokeDevice((string) $request->param('id'), $request);
        return ApiResponse::success(null, 'Appareil révoqué. Ses sessions ont été fermées.');
    }
}

<?php

declare(strict_types=1);

namespace Vsh\Modules\Notifications;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;
use Vsh\Core\Security\AuthContext;

final class NotificationController
{
    /** @var NotificationService */
    private $service;

    /** @var PushTokenService */
    private $pushTokens;

    /** @var NotificationDispatcher */
    private $dispatcher;

    public function __construct(NotificationService $service, PushTokenService $pushTokens, NotificationDispatcher $dispatcher)
    {
        $this->service = $service;
        $this->pushTokens = $pushTokens;
        $this->dispatcher = $dispatcher;
    }

    public function index(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total, $unread) = $this->service->list(
            self::auth($request)->userId(),
            $pagination,
            $request->query('unread') === '1'
        );
        return ApiResponse::success($items, 'Opération effectuée.', 200, [
            'page' => $pagination->page(),
            'per_page' => $pagination->perPage(),
            'total' => $total,
            'total_pages' => (int) ceil($total / $pagination->perPage()),
            'unread' => $unread,
        ]);
    }

    public function markRead(Request $request): Response
    {
        $this->service->markRead(self::auth($request)->userId(), (string) $request->param('id'));
        return ApiResponse::success(null, 'Notification lue.');
    }

    public function markAllRead(Request $request): Response
    {
        $count = $this->service->markAllRead(self::auth($request)->userId());
        return ApiResponse::success(['updated' => $count], 'Notifications marquées comme lues.');
    }

    /**
     * Enregistrement (ou renouvellement) du jeton push de l'appareil de la session.
     * push_enabled indique à l'application si le serveur envoie réellement des push.
     */
    public function registerPushToken(Request $request): Response
    {
        $this->pushTokens->register($request->json(), $request, self::auth($request));
        return ApiResponse::success(['push_enabled' => $this->service->pushEnabled()], 'Appareil enregistré pour les notifications.');
    }

    public function unregisterPushToken(Request $request): Response
    {
        $this->pushTokens->unregisterDevice(self::auth($request)->deviceId());
        return ApiResponse::success(null, 'Notifications push désactivées sur cet appareil.');
    }

    public function deliveries(Request $request): Response
    {
        return ApiResponse::success($this->dispatcher->summary());
    }

    private static function auth(Request $request): AuthContext
    {
        /** @var AuthContext $auth */
        $auth = $request->attribute('auth');
        return $auth;
    }
}

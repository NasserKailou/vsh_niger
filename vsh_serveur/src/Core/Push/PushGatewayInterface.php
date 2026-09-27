<?php

declare(strict_types=1);

namespace Vsh\Core\Push;

/**
 * Passerelle de notifications push (D-011). Le pilote est choisi par PUSH_DRIVER : none | log | fcm.
 */
interface PushGatewayInterface
{
    /** Message accepté par le service. */
    public const SENT = 'SENT';

    /** Jeton inconnu ou expiré (application désinstallée) : il doit être supprimé. */
    public const INVALID_TOKEN = 'INVALID_TOKEN';

    /**
     * Faux pour le pilote « none » : les envois push sont alors ignorés.
     */
    public function enabled(): bool;

    /**
     * @return string self::SENT ou self::INVALID_TOKEN
     * @throws PushException Échec temporaire, à retenter
     */
    public function send(string $token, PushMessage $message): string;
}

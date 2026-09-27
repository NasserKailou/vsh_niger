<?php

declare(strict_types=1);

namespace Vsh\Core\Push;

/**
 * Échec temporaire d'envoi (service injoignable, quota, erreur serveur) : l'envoi sera retenté.
 */
final class PushException extends \RuntimeException
{
}

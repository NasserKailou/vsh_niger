<?php

declare(strict_types=1);

namespace Vsh\Core\Sms;

/**
 * Passerelle d'envoi de SMS (D-002). Le pilote est choisi par SMS_DRIVER : log | twilio | http.
 */
interface SmsGatewayInterface
{
    /**
     * @param string $to Numéro au format E.164
     * @throws SmsException
     */
    public function send(string $to, string $message): void;
}

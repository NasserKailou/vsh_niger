<?php

declare(strict_types=1);

namespace Vsh\Modules\Settings;

use Vsh\Core\Pdf\Letterhead;

/**
 * Identité de l'établissement sur les documents imprimés, lue dans les paramètres (`app.clinic_name`,
 * `documents.letterhead`) : aucune adresse ni aucun numéro n'est écrit dans le code. Les champs vides
 * sont simplement omis.
 */
final class DocumentBranding
{
    /** @var SettingsService */
    private $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    public function letterhead(): Letterhead
    {
        $config = $this->config();
        return new Letterhead($this->clinicName(), [$config['address'], $config['phone'], $config['email']]);
    }

    public function footer(string $document): string
    {
        $config = $this->config();
        return $document === 'prescription' ? $config['prescription_footer'] : $config['invoice_footer'];
    }

    public function clinicName(): string
    {
        return (string) $this->settings->get('app.clinic_name', 'Vision Homecare');
    }

    public function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) $this->settings->get('app.timezone', 'Africa/Niamey'));
        } catch (\Exception $e) {
            return new \DateTimeZone('Africa/Niamey');
        }
    }

    /** Date (et heure) locales d'une valeur DATETIME stockée en UTC : « 26/09/2026 à 14:05 ». */
    public function localDateTime(?string $databaseValue, bool $withTime = true): string
    {
        if ($databaseValue === null || $databaseValue === '') {
            return '';
        }
        $date = (new \DateTimeImmutable($databaseValue, new \DateTimeZone('UTC')))->setTimezone($this->timezone());
        return $withTime ? $date->format('d/m/Y \à H:i') : $date->format('d/m/Y');
    }

    /** Téléphone lisible : « +227 96 44 55 66 » (numéros nigériens E.164), sinon tel quel. */
    public static function phone(string $phone): string
    {
        if (preg_match('/^\+227(\d{2})(\d{2})(\d{2})(\d{2})$/', $phone, $m) === 1) {
            return sprintf('+227 %s %s %s %s', $m[1], $m[2], $m[3], $m[4]);
        }
        return $phone;
    }

    /** Montant entier en unités de la devise : « 12 500 FCFA ». */
    public static function money(int $amount, string $currency): string
    {
        $label = $currency === 'XOF' ? 'FCFA' : $currency;
        return number_format($amount, 0, ',', ' ') . ' ' . $label;
    }

    /**
     * @return array{address: string, phone: string, email: string, invoice_footer: string, prescription_footer: string}
     */
    private function config(): array
    {
        $value = $this->settings->get('documents.letterhead', []);
        $value = is_array($value) ? $value : [];
        $text = function (string $key) use ($value): string {
            return isset($value[$key]) && is_string($value[$key]) ? trim($value[$key]) : '';
        };
        return [
            'address' => $text('address'),
            'phone' => $text('phone'),
            'email' => $text('email'),
            'invoice_footer' => $text('invoice_footer'),
            'prescription_footer' => $text('prescription_footer'),
        ];
    }
}

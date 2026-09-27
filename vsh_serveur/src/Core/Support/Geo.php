<?php

declare(strict_types=1);

namespace Vsh\Core\Support;

use Vsh\Core\Exceptions\ValidationException;

/**
 * Outils géographiques : distance entre deux points et contrôle des positions reçues des appareils.
 * Les distances sont « à vol d'oiseau » (formule de haversine) : une indication, pas un trajet routier.
 */
final class Geo
{
    private const EARTH_RADIUS_M = 6371008.8;

    /**
     * Distance en mètres entre deux points (degrés décimaux WGS 84).
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /**
     * Refuse la position (0, 0) : valeur renvoyée par des GPS qui n'ont pas encore de position,
     * jamais un domicile réel (golfe de Guinée).
     *
     * @throws ValidationException
     */
    public static function assertUsable(array $data, string $latitudeKey = 'latitude', string $longitudeKey = 'longitude', ?string $errorKey = null): void
    {
        if (!isset($data[$latitudeKey], $data[$longitudeKey])) {
            return;
        }
        if ((float) $data[$latitudeKey] === 0.0 && (float) $data[$longitudeKey] === 0.0) {
            throw new ValidationException([
                $errorKey ?? $latitudeKey => ['Position invalide (0, 0) : le GPS n\'avait pas encore trouvé de position. Réessayez à découvert.'],
            ]);
        }
    }
}

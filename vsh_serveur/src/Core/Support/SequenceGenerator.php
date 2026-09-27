<?php

declare(strict_types=1);

namespace Vsh\Core\Support;

use Vsh\Core\Database;

/**
 * Numérotation séquentielle sans doublon ni trou (n° de dossier, n° de facture).
 * Le verrou de ligne (SELECT … FOR UPDATE) sérialise les attributions concurrentes ; le numéro n'est
 * définitivement consommé que si la transaction englobante est validée.
 */
final class SequenceGenerator
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function next(string $name, string $period): int
    {
        return (int) $this->db->transaction(function () use ($name, $period): int {
            $now = Clock::nowForDatabase();
            $this->db->execute(
                'INSERT INTO number_sequences (name, period, last_value, updated_at) VALUES (?, ?, 0, ?)
                 ON DUPLICATE KEY UPDATE name = name',
                [$name, $period, $now]
            );
            $value = 1 + (int) $this->db->fetchValue(
                'SELECT last_value FROM number_sequences WHERE name = ? AND period = ? FOR UPDATE',
                [$name, $period]
            );
            $this->db->execute(
                'UPDATE number_sequences SET last_value = ?, updated_at = ? WHERE name = ? AND period = ?',
                [$value, $now, $name, $period]
            );
            return $value;
        });
    }
}

<?php

declare(strict_types=1);

namespace Vsh\Modules\Sync;

use Vsh\Core\Http\Request;

/**
 * Adaptateur entre la synchronisation et un module métier, pour une entité (« patient », « allergy »…).
 * Chaque méthode passe par les services du module : validations, droits, audit et journal de
 * synchronisation sont identiques à ceux de l'API en ligne. Un appareil ne peut rien faire de plus
 * que ce que l'utilisateur pourrait faire en ligne.
 */
interface SyncEntityHandler
{
    public function entity(): string;

    /**
     * @param string $operation CREATE | UPDATE | DELETE
     */
    public function supports(string $operation): bool;

    /**
     * État actuel tel que l'utilisateur a le droit de le voir (avec « version »), ou null si l'élément
     * n'existe pas, a été supprimé ou n'est pas accessible.
     */
    public function current(string $uuid, Request $request): ?array;

    /**
     * Création avec l'identifiant généré par l'appareil. Doit être idempotente : si l'élément existe déjà,
     * son état actuel est renvoyé.
     */
    public function create(string $uuid, array $payload, Request $request): array;

    /**
     * @param array $fields Champs à modifier (déjà arbitrés par la fusion)
     */
    public function update(string $uuid, array $fields, Request $request): array;

    /**
     * Suppression idempotente (sans effet si l'élément est déjà supprimé).
     */
    public function delete(string $uuid, Request $request): void;
}

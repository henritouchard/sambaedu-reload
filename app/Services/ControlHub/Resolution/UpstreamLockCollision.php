<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

/**
 * Story 30.5 — DTO immuable d'une **collision verrou/verrou insoluble** prédite
 * par {@see UpstreamLockCollisionDetector} à l'assignation d'un label / au
 * rattachement d'un poste à un parc labellisé.
 *
 * Porte la collision STRUCTURÉE (pas une sous-chaîne fragile) : la propriété
 * exclusive en conflit (`exclusiveKey` + `providerType`/`scope` qui la
 * discriminent), les DEUX côtés contradictoires (`{label, sourceId, value}` ×2 —
 * ordonnés de façon stable par `sourceId` croissant) et le périmètre touché
 * (liste triée des `workstationId`). Sert à la fois le message FR affichable
 * ({@see \App\Exceptions\ControlHub\UpstreamLockCollisionException}) ET les
 * assertions structurées des tests.
 *
 * Les deux valeurs sont **normalisées** par l'adaptateur amont
 * ({@see UpstreamPayloadAdapter::toPayload()} → `int`/`string`/`list<string>`,
 * jamais de float — §4.1) : c'est la forme comparée par le détecteur.
 *
 * ⚠️ GARDE-FOU R3 : aucun « central ». Vocabulaire « amont » / `Upstream` /
 * `label`. [Source: prd-contrat-manage-se5.md#R3]
 */
final readonly class UpstreamLockCollision
{
    /**
     * @param  int|string|list<string>  $valueA
     * @param  int|string|list<string>  $valueB
     * @param  list<int>  $workstationIds  postes touchés, triés (déterminisme NFR4)
     */
    public function __construct(
        public string $exclusiveKey,
        public string $providerType,
        public string $scope,
        public string $labelA,
        public int $sourceIdA,
        public mixed $valueA,
        public string $labelB,
        public int $sourceIdB,
        public mixed $valueB,
        public array $workstationIds,
    ) {}

    /** Représentation affichable de la valeur A (liste → `[a, b]`). */
    public function displayValueA(): string
    {
        return self::renderValue($this->valueA);
    }

    /** Représentation affichable de la valeur B (liste → `[a, b]`). */
    public function displayValueB(): string
    {
        return self::renderValue($this->valueB);
    }

    /** @param  int|string|list<string>  $value */
    private static function renderValue(mixed $value): string
    {
        if (is_array($value)) {
            return '['.implode(', ', array_map(static fn ($v): string => (string) $v, $value)).']';
        }

        return (string) $value;
    }
}

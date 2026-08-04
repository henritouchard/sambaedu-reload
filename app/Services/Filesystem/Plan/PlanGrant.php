<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Plan;

use App\Exceptions\Filesystem\PlanResolutionException;

/**
 * Story 60.1 — OCTROI porté par un nœud de plan : « ce sujet a cet accès ici ».
 *
 * **Positif, toujours.** Le niveau d'accès est borné à `ro|rw` — le vocabulaire
 * neutre déjà en service côté assignations. Il n'existe AUCUN moyen d'exprimer
 * une interdiction : pas de champ de refus, pas de valeur « aucun », pas de
 * priorité. L'absence d'octroi est la seule restriction, et l'union au plus
 * permissif reste la doctrine en cas de conflit. Les vraies interdictions vivent
 * ailleurs, dans un mécanisme machine, et n'ont rien à faire dans un plan de
 * fichiers.
 *
 * **Trois états, à ne jamais confondre** (le backend les traduira différemment) :
 *
 *  | état                        | signification                                   |
 *  |-----------------------------|-------------------------------------------------|
 *  | octroi ACTIF                | le rôle a l'accès                               |
 *  | octroi SUSPENDU             | l'octroi existe, il est temporairement vide,    |
 *  |                             | le dossier et les données restent               |
 *  | rôle dans la CLÔTURE du nœud| le rôle n'a JAMAIS reçu d'octroi ici            |
 *
 * Un octroi suspendu n'est PAS une omission : il est sérialisé, il se compare, et
 * un backend doit pouvoir le rendre comme un octroi explicitement vide. Le
 * distinguer de l'absence est ce qui empêche une désactivation de se transformer
 * en suppression.
 *
 * `roleKey` référence une `key` de la spécification de rôles de la recette, ou le
 * jeton réservé du membre énuméré (nœuds par membre). Ce jeton n'étant pas un
 * rôle de recette, un octroi nominatif ne « décharge » aucun rôle de la clôture —
 * exactement ce qu'on veut : le dossier personnel d'un élève ne doit rien accorder
 * à la classe entière.
 */
final class PlanGrant
{
    /**
     * Vocabulaire d'accès. Valeurs IDENTIQUES à celles des assignations du socle
     * (épinglé par test) mais redéclarées ici : le plan ne connaît aucun modèle
     * d'exécution. Aucun mode POSIX n'apparaît jamais dans un plan.
     */
    public const ACCESS_RO = 'ro';

    public const ACCESS_RW = 'rw';

    /** @var list<string> */
    public const ACCESSES = [self::ACCESS_RO, self::ACCESS_RW];

    public readonly string $roleKey;

    public readonly PlanSubject $subject;

    public readonly string $access;

    public readonly bool $suspendable;

    public readonly bool $suspended;

    public function __construct(
        string $roleKey,
        PlanSubject $subject,
        string $access,
        bool $suspendable = false,
        bool $suspended = false,
    ) {
        if ($roleKey === '') {
            throw PlanResolutionException::make('un octroi doit référencer un rôle.');
        }
        if (! in_array($access, self::ACCESSES, true)) {
            throw PlanResolutionException::make(sprintf(
                'accès inconnu « %s » (attendu : %s).',
                $access,
                implode('|', self::ACCESSES),
            ));
        }
        if ($suspended && ! $suspendable) {
            throw PlanResolutionException::make(
                'un octroi non suspendable ne peut pas être suspendu (l\'équipe garde son accès quand l\'échange est fermé).'
            );
        }

        $this->roleKey = $roleKey;
        $this->subject = $subject;
        $this->access = $access;
        $this->suspendable = $suspendable;
        $this->suspended = $suspended;
    }

    /** L'octroi porte-t-il effectivement son accès ? (`false` = suspendu). */
    public function isActive(): bool
    {
        return ! $this->suspended;
    }

    /** Le même octroi, suspendu. Sans effet s'il n'est pas suspendable. */
    public function suspend(): self
    {
        if (! $this->suspendable || $this->suspended) {
            return $this;
        }

        return new self($this->roleKey, $this->subject, $this->access, true, true);
    }

    /** Clé de tri STABLE : (type de sujet, id, rôle d'arête, accès, rôle). */
    public function sortKey(): string
    {
        return $this->subject->sortKey() . "\0" . $this->access . "\0" . $this->roleKey;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'role' => $this->roleKey,
            'subject' => $this->subject->toArray(),
            'access' => $this->access,
            'suspendable' => $this->suspendable,
            'suspended' => $this->suspended,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $subject = $data['subject'] ?? null;
        if (! is_array($subject)) {
            throw PlanResolutionException::make('octroi sérialisé sans sujet.');
        }

        return new self(
            (string) ($data['role'] ?? ''),
            PlanSubject::fromArray($subject),
            (string) ($data['access'] ?? ''),
            (bool) ($data['suspendable'] ?? false),
            (bool) ($data['suspended'] ?? false),
        );
    }
}

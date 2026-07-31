<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 56.1 — État de la dernière synchronisation d'une source d'extensions.
 *
 * **Le registre EST le cache local (NFR7)** : il n'y a pas de fichier de
 * catalogue à côté des tables. Les lignes `extensions` en base sont le dernier
 * catalogue **vérifié** de la source ; ce statut dit ce qu'on a le droit d'en
 * faire.
 *
 * | statut         | cause                          | `available` proposées ? | `integrated` | prune ? |
 * |----------------|--------------------------------|-------------------------|--------------|---------|
 * | `Ok`           | dernière synchro vérifiée      | oui                     | intactes     | oui (borné, invariants 54.1) |
 * | `Unreachable`  | réseau / HTTP / 3xx            | **oui** (dernier index vérifié) | intactes, tuiles intactes | **jamais** |
 * | `Error`        | signature ou contenu invalide  | **non** (fail-closed)   | intactes, signalées | **jamais** |
 *
 * La distinction `Unreachable` / `Error` est la traduction de NFR7 vs NFR2 :
 * un dépôt momentanément injoignable ne doit RIEN changer pour l'admin (le
 * dernier catalogue vérifié reste bon), alors qu'un contenu dont la signature
 * ne se vérifie plus ne doit plus rien proposer du tout.
 *
 * `enabled = false` (choix admin) masque les `available` comme `Error`, sans
 * toucher les `integrated` : désactiver, c'est GELER une source, jamais
 * dé-intégrer ce qui a été installé (invariant 54.1 #4).
 *
 * Convention de libellé : le libellé nomme le SUJET dans son ÉTAT, jamais une
 * action à faire.
 */
enum ExtensionSourceSyncStatus: string
{
    case Ok = 'ok';
    case Unreachable = 'unreachable';
    case Error = 'error';

    /** Libellé FR affiché sur la page des sources. */
    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Catalogue vérifié',
            self::Unreachable => 'Dépôt injoignable',
            self::Error => 'Catalogue refusé',
        };
    }

    /** Classe DaisyUI du badge d'état. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Ok => 'badge-success',
            self::Unreachable => 'badge-warning',
            self::Error => 'badge-error',
        };
    }

    /** Icône Font Awesome associée (jamais la couleur SEULE — accessibilité). */
    public function icon(): string
    {
        return match ($this) {
            self::Ok => 'fa-solid fa-circle-check',
            self::Unreachable => 'fa-solid fa-plug-circle-exclamation',
            self::Error => 'fa-solid fa-shield-halved',
        };
    }

    /**
     * Une source dans cet état peut-elle encore PROPOSER ses extensions
     * `available` ? (fail-closed : seul `Error` masque — un dépôt injoignable
     * garde son dernier catalogue vérifié, NFR7.)
     */
    public function proposesAvailableExtensions(): bool
    {
        return $this !== self::Error;
    }
}

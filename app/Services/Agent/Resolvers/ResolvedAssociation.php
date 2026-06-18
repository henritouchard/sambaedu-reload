<?php

declare(strict_types=1);

namespace App\Services\Agent\Resolvers;

use App\Models\FileAssociation;

/**
 * Story 27.11 — Résultat de {@see AssociationResolver::resolve()} : la traduction
 * d'un choix admin *(extension X, app A)* en cible technique que l'agent applique
 * déjà (provider/handler/hash de 27.3bis INCHANGÉS).
 *
 * Porte EXACTEMENT les trois colonnes serveur-only que 27.3bis alimente déjà sur
 * `file_associations` (`progid`/`source`/`wpkg_package`) — aucune migration de
 * `file_associations` (le payload aval reste `{identifier, progid, type}`).
 *
 * - `progid`       : la cible UserChoice. Soit un ProgId RICHE (déclaré par un
 *                    paquet WPKG pour cette extension, ou canonique d'une native
 *                    curée), soit le GÉNÉRIQUE `Applications\<exe>` fabriqué.
 * - `source`       : `native` (built-in toujours applicable) | `wpkg` (applicable
 *                    si le paquet est déployé sur le parc — validation prédictive).
 * - `wpkgPackage`  : `<package id>` WPKG (= {@see \App\Models\Application::$app_id})
 *                    pour `source=wpkg` ; `null` pour `native`.
 * - `generic`      : le ProgId a-t-il été FABRIQUÉ (`Applications\<exe>`) faute de
 *                    ProgId riche déclaré ? Pilote l'auto-enregistrement per-user
 *                    agent (AC6) et l'affichage « best-effort » côté UI.
 */
final class ResolvedAssociation
{
    public function __construct(
        public readonly string $progid,
        public readonly string $source,
        public readonly ?string $wpkgPackage,
        public readonly bool $generic,
    ) {}

    public function isNative(): bool
    {
        return $this->source === FileAssociation::SOURCE_NATIVE;
    }
}

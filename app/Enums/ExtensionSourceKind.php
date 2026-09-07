<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nature (TRANSPORT) d'une source d'extensions.
 *
 * - `Bundled` : source EMBARQUÉE dans le dépôt SE5 — les manifests vivent sous
 *   `resources/extensions/<id>/manifest.json` (chemin surchargeable par
 *   `config('extensions.bundled_path')`). C'est la seule source réellement
 *   alimentée.
 * - `Remote`  : source DISTANTE (dépôt d'extensions tiers). **Inutilisée à ce
 *   jour** : le modèle multi-sources est posé dès le socle
 *   pour éviter une migration de rupture, mais aucune UI d'ajout de source,
 *   aucun téléchargement et aucune vérification de signature n'existent.
 *
 * ⚠️ `kind` (transport) et `is_official` (confiance/provenance) sont DEUX
 * axes DISTINCTS : une source distante peut être officielle, une source
 * embarquée pourrait ne pas l'être. Ne pas les confondre.
 */
enum ExtensionSourceKind: string
{
    case Bundled = 'bundled';
    case Remote = 'remote';

    /** Libellé FR affiché sur la fiche. */
    public function label(): string
    {
        return match ($this) {
            self::Bundled => 'Embarquée',
            self::Remote => 'Distante',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 54.1 — Nature d'une extension du registre SE5.
 *
 * Le `type` est un champ OBLIGATOIRE du manifest v1 (contrat public FR5) : une
 * valeur hors de cette enum fait REJETER le manifest
 * ({@see \App\Services\Extensions\ExtensionManifestValidator}).
 *
 * - `Link` : une simple TUILE de lanceur pointant une URL déjà servie par
 *   l'instance (ex. la documentation publique `/doc`, Alias Apache Epic 52).
 *   Aucun composant n'est installé : « intégrer » = rendre la tuile visible
 *   (cycle livré en 54.2).
 * - `App` : une application embarquée disposant de son propre cycle
 *   d'installation (Epic 57+). Aucune extension `app` n'existe en 54.1 — le
 *   type est déclaré dès maintenant parce qu'il fait partie du contrat public
 *   du manifest, qu'on ne veut pas faire évoluer story après story.
 *
 * Cases PascalCase, valeurs snake_case (convention maison).
 */
enum ExtensionType: string
{
    case Link = 'link';
    case App = 'app';

    /** Libellé FR affiché en bibliothèque et sur la fiche. */
    public function label(): string
    {
        return match ($this) {
            self::Link => 'Lien',
            self::App => 'Application',
        };
    }

    /** Icône Font Awesome du badge de type. */
    public function icon(): string
    {
        return match ($this) {
            self::Link => 'fa-solid fa-link',
            self::App => 'fa-solid fa-cube',
        };
    }
}

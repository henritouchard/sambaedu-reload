<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nature d'une extension du registre SE5.
 *
 * Le `type` est un champ OBLIGATOIRE du manifest v1 (contrat public) : une
 * valeur hors de cette enum fait REJETER le manifest
 * ({@see \App\Services\Extensions\ExtensionManifestValidator}).
 *
 * - `Link` : une simple TUILE de lanceur pointant une URL déjà servie par
 * L'instance (ex. la documentation publique `/doc`, Alias Apache).
 *   Aucun composant n'est installé : « intégrer » = rendre la tuile visible.
 * - `App` : une application embarquée disposant de son propre cycle
 *   d'installation. Aucune extension `app` n'existe à ce jour — le
 *   type est déclaré dès maintenant parce qu'il fait partie du contrat public
 *   du manifest, qu'on ne veut pas faire évoluer version après version.
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

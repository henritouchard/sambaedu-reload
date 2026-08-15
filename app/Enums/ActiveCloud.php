<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 63.1 — LE CLOUD ACTIF EST UNE VALEUR, PAS DEUX BOOLÉENS.
 *
 * SE5 sait parler à deux produits cloud (Nextcloud, OpenCloud), mais une
 * instance n'en active jamais qu'UN SEUL à la fois (décision Henri du
 * 2026-08-15, cadrage §2 : « il n'y a qu'un seul cloud possible »). Deux
 * booléens indépendants (`nextcloud_actif`, `opencloud_actif`) laisseraient
 * l'état « les deux vrais » PARFAITEMENT REPRÉSENTABLE — il faudrait alors une
 * garde applicative pour l'empêcher, et une garde applicative se contourne (un
 * appel direct au setter, un import de configuration). Une enum à cases
 * exclusives ferme la question au niveau du TYPE : il n'existe littéralement
 * aucune valeur qui signifie « les deux ». Même figure que
 * {@see FileBackendName} : un vocabulaire fermé plutôt qu'une garde qu'il
 * faudrait se souvenir de rejouer partout.
 *
 * **`Aucun` est une CASE, PAS un `null`.** Un `?FileBackendName` ou un
 * `?ActiveCloud` rouvrirait un état « non décidé » — c'est exactement ce que
 * la garde n° 3 du cadrage interdit (« aucune valeur nulle, aucun repli
 * silencieux ») : chaque appelant devrait alors traiter un cas `null` en plus
 * des cas connus, et un seul appelant qui l'oublierait ferait planter un
 * `match` non exhaustif ou, pire, traiterait silencieusement `null` comme
 * `Nextcloud`. Trois cases fermées ne laissent ce choix à personne.
 *
 * Cases PascalCase, valeurs snake_case (convention maison, iso
 * {@see FileBackendName}).
 */
enum ActiveCloud: string
{
    /** Aucun cloud n'est l'autorité active — l'instance ne sert que le serveur de fichiers. */
    case Aucun = 'aucun';

    /** Nextcloud est le cloud actif de l'instance. */
    case Nextcloud = 'nextcloud';

    /** OpenCloud est le cloud actif de l'instance. */
    case OpenCloud = 'opencloud';

    /** Libellé FR — aucune valeur technique brute à l'écran (iso {@see FileBackendName::label()}). */
    public function label(): string
    {
        return match ($this) {
            self::Aucun => 'Aucun cloud',
            self::Nextcloud => 'Nextcloud',
            self::OpenCloud => 'OpenCloud',
        };
    }

    /**
     * Le backend de fichiers correspondant, ou `null` pour `Aucun`.
     *
     * C'est la seule couture entre ce vocabulaire et celui, plus large, de
     * {@see FileBackendName} : un emplacement ne peut désigner que le backend
     * du cloud actif (ou `Posix`), jamais un backend cloud arbitraire — c'est
     * la garde n° 2 du cadrage, rejouée par
     * `\App\Services\Filesystem\FileLocations::make()` (FQCN dans le texte, et
     * pas un `use` : le vocabulaire fermé est la couche la plus basse, il ne
     * dépend d'aucun service).
     */
    public function backend(): ?FileBackendName
    {
        return match ($this) {
            self::Aucun => null,
            self::Nextcloud => FileBackendName::Nextcloud,
            self::OpenCloud => FileBackendName::OpenCloud,
        };
    }

    /** `true` si la valeur brute appartient au vocabulaire fermé. */
    public static function isKnown(mixed $value): bool
    {
        return is_string($value) && self::tryFrom($value) !== null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

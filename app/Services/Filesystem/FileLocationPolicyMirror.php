<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Enums\ActiveCloud;
use App\Services\FilePolicyService;

/**
 * Story 63.3 — LE MIROIR DÉRIVÉ : `files.locations` est la SOURCE,
 * `files.policy` en reçoit la projection, et JAMAIS L'INVERSE.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI CE MIROIR EXISTE.** Les quatre booléens historiques de
 * `files.policy` ont encore des lecteurs vivants que cette story ne touche
 * pas : les deux objets de configuration de connexion (`\App\Services\Nextcloud\
 * NextcloudConnectionConfig::current()` et son jumeau OpenCloud, cités en FQCN)
 * s'éteignent fail-closed si leur capacité passe à `false`, et la posabilité
 * d'une autorité d'écriture à la création d'un répertoire géré les lit aussi.
 * Cesser de les écrire éteindrait toute la chaîne cloud.
 *
 * Le miroir est donc écrit DANS LE MÊME GESTE que la source, par un service et
 * jamais depuis un gabarit — un écran qui recopierait onze arguments finirait
 * par en oublier un.
 *
 * **MONO-DIRECTIONNEL.** Aucun chemin de code ne relit `files.policy` pour en
 * déduire un emplacement : la dérivation inverse est le geste EXPLICITE et
 * unique de `php artisan files:adopt-locations`, qui peut dire non. La faire à
 * la volée inventerait un emplacement dans le cas que cette commande refuse
 * précisément de deviner.
 *
 * **« LES DEUX CLOUDS » DEVIENT IRREPRÉSENTABLE DANS `files.policy` AUSSI.**
 * Le cloud actif est une valeur à trois positions : la projection écrit donc
 * toujours exactement un des deux booléens à `true`, ou aucun.
 * ---------------------------------------------------------------------------
 *
 * ⚠️ **LE PIÈGE, ET IL EST DANS `setGlobal()`.** Dix de ses paramètres sont
 * nullables et conservent le persisté — mais `$nextcloudServerUrl` est un
 * `string` de défaut `''`, **toujours écrit**. Un miroir qui ne le nommerait pas
 * effacerait l'adresse de l'instance à chaque enregistrement, ferait lever
 * l'objet de configuration, et éteindrait la chaîne entière.
 *
 * **Correction de revue** : ce service ne recopie plus les treize paramètres
 * positionnels. Il nomme les QUATRE valeurs dérivées et passe par
 * {@see FilePolicyService::patchGlobal()}, désormais le seul endroit du dépôt
 * qui connaisse l'ordre des paramètres de `setGlobal()` — le piège ci-dessus y
 * est fermé une fois, au lieu d'être réévité à chaque site d'appel.
 */
final class FileLocationPolicyMirror
{
    /**
     * Projette la décision sur `files.policy`, en préservant clé pour clé tout
     * ce qui n'est pas dérivé des emplacements.
     */
    public function write(FileLocations $locations): void
    {
        FilePolicyService::patchGlobal([
            'home' => $locations->espacePersoSurSmb(),
            'shares' => $locations->espacePartageSurSmb(),
            'nextcloud' => $locations->cloudActif === ActiveCloud::Nextcloud,
            'opencloud' => $locations->cloudActif === ActiveCloud::OpenCloud,
        ]);
    }
}

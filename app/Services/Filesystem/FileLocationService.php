<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\FileLocationException;
use App\Models\SystemSetting;

/**
 * Story 63.1 — LE FOYER DES DEUX EMPLACEMENTS ET DU CLOUD ACTIF.
 *
 * Ne fait QU'UNE chose : lire et écrire une ligne de réglage
 * ({@see SystemSetting}, clé {@see self::SETTING_KEY}). Aucun appel HTTP,
 * aucune sonde, aucun accès disque, aucune UI, aucun appel à un backend de
 * fichiers, à `NetworkShareService`, à `AclService` ou à `ShareService` —
 * c'est ce que l'AC5 exige, et c'est ce qui permet à ce service de rester
 * scanné (et de passer) par `PlanNamespaceIsolationTest`.
 *
 * **Clé distincte de `files.policy`** (`\App\Services\FilePolicyService`, cité
 * en FQCN et non importé : ce service ne dépend de lui ni à la compilation ni
 * à l'exécution), qui reste intacte et continue de gouverner exactement ce
 * qu'il gouverne aujourd'hui — cette story pose le nouveau modèle À CÔTÉ, elle
 * ne l'éteint pas.
 *
 * **La garde de cohérence de {@see FileLocations::make()} est rejouée ICI, à
 * la LECTURE, pas seulement à l'écriture** : une ligne de `system_settings`
 * se modifie en SQL, en tinker, ou par un import de configuration — une
 * garde qui ne vit que dans {@see self::set()} protège l'étourderie, pas la
 * ligne forgée (transposition littérale de la doctrine de
 * `\App\Services\Filesystem\Backend\FileBackendSelection`, citée en FQCN pour
 * la même raison : aucune dépendance réelle vers le plan d'exécution).
 *
 * **Pas de mémoïsation, pas de cache** (patron `FilePolicyService` ;
 * `Cache::lock()` est de toute façon inutilisable sous APCu — mémoire
 * projet). Chaque appel relit `system_settings`.
 */
final class FileLocationService
{
    /** Clé SystemSetting du réglage d'instance — distincte de `files.policy`. */
    public const SETTING_KEY = 'files.locations';

    /** Clé de payload : autorité de l'espace perso ({@see FileBackendName}). */
    public const KEY_ESPACE_PERSO = 'espace_perso.autorite';

    /** Clé de payload : autorité de l'espace partagé ({@see FileBackendName}). */
    public const KEY_ESPACE_PARTAGE = 'espace_partage.autorite';

    /** Clé de payload : le cloud actif de l'instance ({@see ActiveCloud}). */
    public const KEY_CLOUD_ACTIF = 'cloud.actif';

    /**
     * Les défauts — EXACTEMENT ce que produisent les quatre booléens
     * historiques `home✓ shares✓ nextcloud✗ opencloud✗`
     * (`\App\Services\FilePolicyService::defaults()`).
     */
    public static function defaults(): FileLocations
    {
        return FileLocations::make(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Aucun);
    }

    /**
     * L'état actuel — ne rend JAMAIS `null` et ne connaît aucun état « non
     * décidé » observable par un appelant : clé ABSENTE ⇒ les défauts ; clé
     * présente ⇒ les trois valeurs sont exigées, résolues par le vocabulaire
     * fermé, puis rejouées par {@see FileLocations::make()}.
     *
     * **L'absence et l'illisibilité ne sont PAS le même état.** Seule
     * l'absence rend les défauts. Une ligne présente qui ne porte pas un objet
     * à trois clés est une corruption : elle REFUSE en nommant
     * ({@see FileLocationException::malformedPayload()}), parce qu'un repli
     * silencieux inventerait là une décision que personne n'a prise.
     *
     * @throws FileLocationException si la ligne persistée est illisible,
     *                               incomplète, hors vocabulaire, ou
     *                               structurellement incohérente
     */
    public static function current(): FileLocations
    {
        $stored = SystemSetting::get(self::SETTING_KEY);

        if ($stored === null) {
            return self::defaults();
        }

        if (! is_array($stored)) {
            throw FileLocationException::malformedPayload($stored);
        }

        return FileLocations::make(
            self::resolveAuthority('l\'espace perso', $stored, self::KEY_ESPACE_PERSO),
            self::resolveAuthority('l\'espace partagé', $stored, self::KEY_ESPACE_PARTAGE),
            self::resolveActiveCloud($stored),
        );
    }

    /**
     * Écrit les TROIS valeurs en une seule écriture `SystemSetting::set()` —
     * pas de paramètre nullable « qui conserve la valeur persistée » (le
     * piège de `FilePolicyService::setGlobal()`) : les trois vont ensemble,
     * parce que les gardes portent sur leur COMBINAISON, et parce que
     * `SystemSetting::set()` est un upsert non transactionnel sur une seule
     * ligne — les éclater en trois écritures exposerait un état transitoire
     * incohérent à un lecteur concurrent.
     */
    public static function set(FileLocations $locations): void
    {
        SystemSetting::set(self::SETTING_KEY, $locations->toArray());
    }

    /**
     * `true` si une décision a déjà été enregistrée (clé PRÉSENTE), qu'elle
     * égale ou non les défauts, et **qu'elle soit lisible ou non** : une ligne
     * corrompue est une décision existante qu'il faut réparer, pas une
     * décision absente qu'on pourrait écrire par-dessus sans le dire.
     * Consommé par la commande de reprise pour ne jamais écraser
     * silencieusement une décision existante.
     */
    public static function isDecided(): bool
    {
        return SystemSetting::get(self::SETTING_KEY) !== null;
    }

    /**
     * @param  array<mixed>  $stored
     *
     * @throws FileLocationException
     */
    private static function resolveAuthority(string $objet, array $stored, string $key): FileBackendName
    {
        if (! array_key_exists($key, $stored)) {
            throw FileLocationException::missingValue($objet);
        }

        $name = self::vocabularyMatch($stored[$key], FileBackendName::class);

        if ($name === null) {
            throw FileLocationException::unknownAuthority($objet, $stored[$key]);
        }

        return $name;
    }

    /**
     * @param  array<mixed>  $stored
     *
     * @throws FileLocationException
     */
    private static function resolveActiveCloud(array $stored): ActiveCloud
    {
        if (! array_key_exists(self::KEY_CLOUD_ACTIF, $stored)) {
            throw FileLocationException::missingValue('le cloud actif');
        }

        $cloud = self::vocabularyMatch($stored[self::KEY_CLOUD_ACTIF], ActiveCloud::class);

        if ($cloud === null) {
            throw FileLocationException::unknownActiveCloud($stored[self::KEY_CLOUD_ACTIF]);
        }

        return $cloud;
    }

    /**
     * Trim puis comparaison STRICTE, sensible à la casse, sur le vocabulaire
     * (les enums sont en snake_case minuscule) : `'POSIX '` n'est pas
     * `posix`, et rien n'est normalisé au-delà d'un `trim()` — le reste est
     * refusé plutôt que d'ouvrir une normalisation qui accepterait n'importe
     * quoi.
     *
     * @template T of FileBackendName|ActiveCloud
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function vocabularyMatch(mixed $raw, string $enum): FileBackendName|ActiveCloud|null
    {
        $value = is_string($raw) ? trim($raw) : $raw;

        return is_string($value) ? $enum::tryFrom($value) : null;
    }
}

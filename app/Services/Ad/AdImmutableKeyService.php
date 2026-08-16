<?php

declare(strict_types=1);

namespace App\Services\Ad;

use App\LdapModels\LdapUser;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LA CLÉ IMMUABLE D'IDENTITÉ — calcul et pose.
 *
 * Le POURQUOI est dans `config/ad_identity.php` ; ce fichier n'en garde que le
 * strict nécessaire à la lecture du code.
 *
 * Deux responsabilités, volontairement séparées :
 *
 *  - **le calcul** ({@see canonicalFromRaw()}, {@see canonicalFromHex()}) est PUR —
 *    aucun annuaire, aucune configuration. C'est là que vit le seul détail
 *    réellement piégeux du sujet, le boutisme, et c'est pour ça qu'il est isolé et
 *    testable sans banc ;
 *  - **la pose** ({@see ensure()}) lit l'`objectGUID` de l'entrée, compare, et
 *    n'écrit QUE si l'attribut est vide (ou si l'appelant force explicitement).
 *
 * ⚠️ **LE BOUTISME EST LE SUJET.** Un `objectGUID` d'Active Directory est un champ
 * binaire de 16 octets dont la forme texte n'est pas unique : Microsoft rend les
 * trois premiers champs en PETIT-BOUTISTE et les 8 derniers octets tels quels. La
 * lecture naïve (`bin2hex`, ou un `uuid` gros-boutiste) donne une chaîne DIFFÉRENTE
 * pour le même compte. Les deux se ressemblent assez pour qu'une inversion passe
 * inaperçue à l'œil, et assez peu pour que rien ne se retrouve.
 *
 * La forme produite ici est la forme Microsoft — celle que Nextcloud utilise déjà
 * comme uid par défaut quand `ldapExpertUsernameAttr` est vide. Choisir l'autre
 * forme donnerait une clé cohérente avec elle-même mais étrangère à Nextcloud, donc
 * deux identités pour une personne.
 *
 * ⚠️ **CE QUI REND LA POSE IDEMPOTENTE, ET CE QUI NE LA REND PAS.** L'idempotence
 * exige que l'attribut porteur soit RELU sur l'entrée passée à {@see ensure()}.
 * `LdapUser::$columns` **ne garantit rien** : cette propriété est une convention du
 * dépôt que LdapRecord ne consulte pas (aucune occurrence dans son modèle ni dans
 * son constructeur de requête). Ce qui garantit la relecture, c'est la **sélection
 * de la requête** — soit la sélection par défaut `['*']`, soit une sélection
 * explicite qui inclut l'attribut. **Un appelant qui hydrate ses entrées par un
 * `select()` explicite doit donc y inclure {@see attribute()}**, faute de quoi
 * {@see currentFor()} rendra `null` et la même valeur sera réécrite à chaque
 * passage — en silence, en sollicitant la réplication de l'annuaire à chaque fois.
 * {@see ADDITIONAL_SELECT} existe pour que cet appelant n'ait pas à deviner la liste.
 */
final class AdImmutableKeyService
{
    /**
     * Les attributs qu'une requête doit sélectionner pour que {@see ensure()} soit
     * idempotent — hors l'attribut porteur, qui est un réglage et s'ajoute par
     * {@see selectFor()}.
     */
    public const ADDITIONAL_SELECT = ['cn', 'objectguid'];

    /**
     * La sélection complète à passer à `select()` pour un traitement de masse.
     *
     * Rendre cette liste explicite est le seul moyen de faire tenir l'idempotence :
     * elle documente la dépendance au lieu de la subir, et évite au passage de
     * charger tout l'annuaire (`memberOf` complets, photos) pour trois champs.
     *
     * @return list<string>
     */
    public function selectFor(): array
    {
        return [...self::ADDITIONAL_SELECT, $this->attribute()];
    }

    /**
     * De quoi NOMMER une entrée dans un journal ou un rapport.
     *
     * `getLogin()` lit `cn`, présent dans {@see ADDITIONAL_SELECT} — contrairement à
     * `samaccountname`. Aller chercher ce dernier directement rendrait `null` et
     * ferait retomber tous les messages sur un DN, illisible dans un rapport de
     * rattrapage.
     */
    public function label(LdapUser $ldapUser): string
    {
        $login = $ldapUser->getLogin();

        // `getDn()` rend `null` sur une entrée non enregistrée : le repli du repli
        // évite qu'un journal d'échec échoue à son tour.
        return $login !== '' ? $login : ((string) ($ldapUser->getDn() ?? '(entrée sans DN)'));
    }

    /**
     * L'attribut d'annuaire qui porte la clé, en minuscules (LdapRecord normalise).
     */
    public function attribute(): string
    {
        return strtolower((string) config('ad_identity.attribute', 'employeetype'));
    }

    public function setOnCreate(): bool
    {
        return (bool) config('ad_identity.set_on_create', true);
    }

    /**
     * 16 octets bruts d'`objectGUID` → forme texte Microsoft canonique.
     *
     * Rend `null` sur toute entrée qui n'est pas exactement 16 octets : mieux vaut
     * ne rien poser que poser une clé tronquée, qui serait pire qu'absente.
     */
    public function canonicalFromRaw(string $raw): ?string
    {
        if (strlen($raw) !== 16) {
            return null;
        }

        return $this->canonicalFromHex(bin2hex($raw));
    }

    /**
     * 32 caractères hexadécimaux (l'ordre BRUT des octets, tel que stocké dans
     * `users.ad_guid` par `UserService::persistUserToSql`) → forme Microsoft.
     *
     * Accepte indifféremment majuscules et minuscules ; rend toujours en minuscules.
     */
    public function canonicalFromHex(string $hex): ?string
    {
        $hex = strtolower(trim($hex));

        if (! preg_match('/^[0-9a-f]{32}$/', $hex)) {
            return null;
        }

        // Les trois premiers champs sont PETIT-BOUTISTES : on renverse leurs octets.
        // Les deux derniers sont pris tels quels. C'est toute la conversion.
        $reverse = static fn (string $chunk): string => implode('', array_reverse(str_split($chunk, 2)));

        return sprintf(
            '%s-%s-%s-%s-%s',
            $reverse(substr($hex, 0, 8)),   // 4 octets, renversés
            $reverse(substr($hex, 8, 4)),   // 2 octets, renversés
            $reverse(substr($hex, 12, 4)),  // 2 octets, renversés
            substr($hex, 16, 4),            // tels quels
            substr($hex, 20, 12),           // tels quels
        );
    }

    /**
     * La clé attendue pour cette entrée d'annuaire, ou `null` si l'`objectGUID`
     * est absent ou inexploitable.
     */
    public function expectedFor(LdapUser $ldapUser): ?string
    {
        $raw = $ldapUser->getFirstAttribute('objectguid');

        return is_string($raw) ? $this->canonicalFromRaw($raw) : null;
    }

    /**
     * La valeur BRUTE actuellement portée par l'attribut, ou `null` s'il est absent
     * ou littéralement vide.
     *
     * ⚠️ **Aucun nettoyage.** Nettoyer avant de comparer déclarerait « conforme » une
     * valeur du genre `" f7f9fa6e-…"`, alors que le produit distant prend la chaîne
     * **telle quelle** comme identifiant de compte : le rapport dirait « tout est
     * conforme » sur un compte dont l'identité cloud ne correspond à aucun octroi
     * calculé. Un écart d'espace est un écart.
     */
    public function currentFor(LdapUser $ldapUser): ?string
    {
        $value = $ldapUser->getFirstAttribute($this->attribute());

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Pose la clé si l'attribut est vide. IDEMPOTENT : une entrée déjà conforme ne
     * produit AUCUNE écriture d'annuaire.
     *
     * Une valeur **divergente non vide** n'est PAS écrasée sans `$force` : voir
     * {@see AdImmutableKeyOutcome::Divergent}. L'appelant qui force est responsable
     * de l'avoir montrée d'abord.
     *
     * N'émet jamais d'exception — le verdict est la valeur de retour. Cette pose est
     * greffée sur la création d'utilisateur, où elle ne doit rien pouvoir casser.
     */
    public function ensure(LdapUser $ldapUser, bool $dryRun = false, bool $force = false): AdImmutableKeyOutcome
    {
        $expected = $this->expectedFor($ldapUser);

        if ($expected === null) {
            Log::warning('Clé immuable : objectGUID absent ou inexploitable, rien posé', [
                'action' => 'ad.immutable_key.unresolved',
                'login' => $this->label($ldapUser),
            ]);

            return AdImmutableKeyOutcome::Unresolved;
        }

        $current = $this->currentFor($ldapUser);

        if ($current === $expected) {
            return AdImmutableKeyOutcome::Conforme;
        }

        if ($current !== null && ! $force) {
            Log::warning('Clé immuable : valeur divergente NON écrasée', [
                'action' => 'ad.immutable_key.divergent',
                'login' => $this->label($ldapUser),
                'attribute' => $this->attribute(),
                'current' => $current,
                'expected' => $expected,
            ]);

            return AdImmutableKeyOutcome::Divergent;
        }

        if ($dryRun) {
            return AdImmutableKeyOutcome::Written;
        }

        try {
            $ldapUser->setAttribute($this->attribute(), $expected);
            $ldapUser->save();

            Log::info('Clé immuable posée', [
                'action' => 'ad.immutable_key.written',
                'login' => $this->label($ldapUser),
                'attribute' => $this->attribute(),
                'key' => $expected,
                'overwrote' => $current,
            ]);

            return AdImmutableKeyOutcome::Written;
        } catch (Throwable $e) {
            Log::error('Clé immuable : échec de la pose', [
                'action' => 'ad.immutable_key.failed',
                'login' => $this->label($ldapUser),
                'attribute' => $this->attribute(),
                'error' => $e->getMessage(),
            ]);

            return AdImmutableKeyOutcome::Failed;
        }
    }
}

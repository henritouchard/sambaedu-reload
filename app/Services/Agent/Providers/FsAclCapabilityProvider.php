<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\CapabilityProjection;
use Illuminate\Support\Facades\Log;

/**
 * Story 36.1 — provider `fs_acl` CAPABILITY-FIRST, portée **Machine** (le
 * service SYSTEM est le SEUL acteur des ACE NTFS ; le compagnon n'a pas les
 * droits, et le type n'existe pas côté session).
 *
 * Premier mécanisme HORS-REGISTRE (doctrine Epic 36 : « mécanisme = code payé
 * une fois, capacité = donnée »). Il EXPANSE une capacité → items de contrat
 * CONCRETS `{path, trustee, ace_type, rights, applies_to, ensure}` (6 clés,
 * strings — §7.7), exactement comme {@see AbstractRegistryListCapabilityProvider}
 * SURCHARGE l'interpréteur `expand()` du provider abstrait sans toucher
 * `StateCompiler` (D2). Il réutilise TOUTE la mécanique capacité de
 * {@see AbstractCapabilityStateProvider} : Broadcast (défaut diffusé) + overrides
 * par maille, `resolveKeyValue()` (map/littéral), `UNMANAGED`, lecture Postgres
 * pure (NFR7 — la résolution SID est côté POSTE, LSA).
 *
 * **Jetons d'audience (Q1).** Un `trustee` peut être un jeton `@eleves|@profs|
 * @personnels` résolu par {@see AudienceTokens} (enum FERMÉ en dur + existence
 * dans `user_groups`) ou un littéral verbatim (`Domain Users`). Jeton
 * irrésoluble ⇒ entrée NON émise + log warning (JAMAIS de payload avec un jeton
 * brut).
 *
 * **`exclusiveKey() = {path|trustee|ace_type}` minuscules** (3 segments) : la
 * maille la plus spécifique gagne CETTE ACE ; deux ACE d'identités distinctes
 * (mêmes `path`, trustees différents) COEXISTENT (cumul assumé, piège #2). La
 * précédence par maille se joue sur identité ÉGALE via le compilateur INTOUCHÉ.
 *
 * **Pas de ciblage par utilisateur (piège #10).** `scope() = Machine` ⇒ le
 * service SYSTEM fetch sans `?user` (`userGroupIds = []`) : un override
 * UserGroup/User d'une capacité `fs_acl` est SANS EFFET. « Quel utilisateur est
 * bridé » = le `trustee` DANS le payload, « quels postes » = les assignations
 * parc/salle/poste/broadcast.
 *
 * **`hive()` non applicable** (piège #14) : `expand()` est surchargé
 * intégralement — `hive()`/`handlesHive()` (mécanique de filtrage par ruche
 * registre) ne sont JAMAIS consultés ici.
 */
final class FsAclCapabilityProvider extends AbstractCapabilityStateProvider
{
    /**
     * Résolution des jetons d'audience mémoïsée pour la durée de vie de CETTE
     * instance de provider (une compilation = ≤ 1 requête d'existence). Créée
     * paresseusement (une instance de provider peut ne jamais expanser d'ACE à
     * jeton).
     */
    private ?AudienceTokens $audience = null;

    public function scope(): StateScope
    {
        return StateScope::Machine;
    }

    protected function mechanism(): string
    {
        return CapabilityProjection::MECHANISM_FS_ACL;
    }

    /**
     * Non applicable au mécanisme `fs_acl` — `expand()` est surchargé
     * intégralement, `handlesHive()` n'est JAMAIS appelé (piège #14). Implémentée
     * pour satisfaire le contrat de la classe abstraite (registre-specific).
     */
    protected function hive(): string
    {
        return '';
    }

    /**
     * Identité d'une ACE gérée exclusive : `{path|trustee|ace_type}` minuscules
     * (3 segments). NTFS/Windows sont insensibles à la casse sur les chemins →
     * normalisation minuscules pour la STABILITÉ de la sélection (déterministe,
     * ETag 23.5).
     */
    public function exclusiveKey(array $payload): string
    {
        $path = strtolower((string) ($payload['path'] ?? ''));
        $trustee = strtolower((string) ($payload['trustee'] ?? ''));
        $aceType = strtolower((string) ($payload['ace_type'] ?? ''));

        return $path.'|'.$trustee.'|'.$aceType;
    }

    /**
     * Interpréteur de `spec` du mécanisme `fs_acl`. La projection porte
     * `spec = { "aces": [ {path, trustee, ace_type, rights, applies_to,
     * ensure?}, … ] }`. Pour CHAQUE entrée :
     *   - `ace_type`/`rights`/`applies_to` sont des enums FIXES (mots métier) —
     *     hors domaine ⇒ entrée NON émise (défensif ; le guard refuse déjà en
     *     amont) ;
     *   - `trustee` et `ensure` sont chacun littéral OU map valeur-capacité,
     *     résolus par {@see resolveKeyValue()} : clé de map absente ⇒ UNMANAGED
     *     ⇒ entrée non émise ; forme assoc inattendue ⇒ non émise défensif
     *     (jamais d'exception au render) ; `ensure` défaut `present` (piège #13,
     *     TOUJOURS émis au payload) ;
     *   - un `trustee` commençant par `@` est résolu par {@see AudienceTokens}
     *     (jeton irrésoluble ⇒ non émis + warning), un littéral part verbatim.
     * Le payload résultant est CONCRET : EXACTEMENT 6 clés strings, zéro float,
     * jamais d'id de capacité (invariant 27.12).
     *
     * @return list<array<string,string>> un payload 6 clés par ACE émise
     */
    protected function expand(CapabilityProjection $projection, string $capabilityValue): array
    {
        $spec = $projection->spec;
        $aces = is_array($spec) && isset($spec['aces']) && is_array($spec['aces'])
            ? $spec['aces']
            : [];

        $payloads = [];

        foreach ($aces as $ace) {
            if (! is_array($ace)) {
                continue;
            }

            $path = (string) ($ace['path'] ?? '');
            if ($path === '') {
                continue;
            }

            // Enums FIXES bornés (défensif — le guard d'authoring refuse en amont).
            $aceType = strtolower((string) ($ace['ace_type'] ?? ''));
            $rights = strtolower((string) ($ace['rights'] ?? ''));
            $appliesTo = strtolower((string) ($ace['applies_to'] ?? ''));
            if (! in_array($aceType, FsAclAuthoringGuard::ACE_TYPES, true)
                || ! in_array($rights, FsAclAuthoringGuard::RIGHTS, true)
                || ! in_array($appliesTo, FsAclAuthoringGuard::APPLIES_TO, true)) {
                continue;
            }

            // Résolution trustee (littéral OU map valeur-capacité).
            $resolvedTrustee = $this->resolveKeyValue($ace['trustee'] ?? null, $capabilityValue);
            if ($resolvedTrustee === self::UNMANAGED || is_array($resolvedTrustee)) {
                continue; // clé de map absente / forme inattendue ⇒ non émis.
            }
            $trusteeRaw = (string) $resolvedTrustee;
            if (trim($trusteeRaw) === '') {
                continue;
            }

            // Jeton d'audience → nom conventionnel (Q1). Irrésoluble ⇒ non émis
            // + warning (JAMAIS de payload avec un jeton brut).
            $trustee = $this->audienceTokens()->resolve($trusteeRaw);
            if ($trustee === null) {
                Log::warning('fs_acl : trustee irrésoluble, entrée non émise (jeton inconnu ou groupe absent de user_groups).', [
                    'capability_id' => $projection->capability_id,
                    'trustee' => $trusteeRaw,
                    'path' => $path,
                ]);

                continue;
            }

            // Résolution ensure (littéral OU map ; défaut `present`, TOUJOURS
            // émis — piège #13).
            $ensure = $this->resolveEnsure($ace['ensure'] ?? null, $capabilityValue);
            if ($ensure === null) {
                continue; // UNMANAGED / forme inattendue / enum hors domaine ⇒ non émis.
            }

            $payloads[] = [
                'path' => $path,
                'trustee' => $trustee,
                'ace_type' => $aceType,
                'rights' => $rights,
                'applies_to' => $appliesTo,
                'ensure' => $ensure,
            ];
        }

        return $payloads;
    }

    /**
     * Résout le champ `ensure` d'une entrée `aces[]` : absent ⇒ `present`
     * (défaut) ; littéral OU map valeur-capacité via {@see resolveKeyValue()}
     * (UNMANAGED / forme assoc / enum hors domaine ⇒ `null` = entrée non émise).
     */
    private function resolveEnsure(mixed $raw, string $capabilityValue): ?string
    {
        if ($raw === null) {
            return 'present';
        }

        $resolved = $this->resolveKeyValue($raw, $capabilityValue);
        if ($resolved === self::UNMANAGED || is_array($resolved)) {
            return null;
        }

        $ensure = strtolower((string) $resolved);

        return in_array($ensure, FsAclAuthoringGuard::ENSURE, true) ? $ensure : null;
    }

    /**
     * Instance {@see AudienceTokens} mémoïsée pour la durée de vie de ce
     * provider (une compilation ⇒ ≤ 1 requête d'existence des groupes).
     */
    private function audienceTokens(): AudienceTokens
    {
        return $this->audience ??= new AudienceTokens();
    }
}

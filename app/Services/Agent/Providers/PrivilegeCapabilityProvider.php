<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\CapabilityProjection;
use Illuminate\Support\Facades\Log;

/**
 * Story 35.6 — provider `privilege` CAPABILITY-FIRST, portée **Machine** (le
 * service SYSTEM est le SEUL acteur de la LSA locale ; le compagnon n'a pas les
 * droits, et le type n'existe pas côté session).
 *
 * Troisième mécanisme HORS-REGISTRE (jumeau structurel de
 * {@see FsAclCapabilityProvider} / {@see FirewallCapabilityProvider}, doctrine
 * Epic 36 : « mécanisme = code payé une fois, capacité = donnée »). Il EXPANSE
 * une capacité → AU PLUS UN item de contrat CONCRET 2 clés
 * `{privilege, accounts}` (§7.9) — `privilege` ∈ enum FERMÉ des 5 droits
 * `SeDeny*` ({@see PrivilegeAuthoringGuard::ALLOWED_PRIVILEGES}), `accounts` =
 * liste TRIÉE de NOMS Windows. Il SURCHARGE l'interpréteur `expand()` du
 * provider abstrait sans toucher `StateCompiler` (D2) et réutilise
 * `resolveKeyValue()` (map/littéral) + `UNMANAGED` hérités ; lecture Postgres
 * pure (NFR7 — la résolution SID est côté POSTE, LSA D5).
 *
 * **Propriété du CONTENEUR SANS store (D4, iso `firewall` — PAS `fs_acl`).**
 * Un privilège LSA porte une liste de titulaires ÉNUMÉRABLE
 * (`LsaEnumerateAccountsWithUserRight`) : l'agent possède la liste EN ENTIER et
 * la réconcilie à chaque cycle (accorde les manquants, révoque les
 * surnuméraires) — AUCUN store n'est nécessaire. Le « conteneur » est le
 * privilège lui-même. C'est SÛR uniquement parce que les `SeDeny*` sont VIDES
 * par défaut ET que l'authoring interdit tout droit *grant* (piège #3 — un
 * « owns-entire-list » sur un grant verrouillerait la machine).
 *
 * **`exclusiveKey() = <privilège>` minuscule (1 segment)** : la maille la plus
 * spécifique gagne la liste `accounts` ENTIÈRE — NON cumulatif (piège #4,
 * contrairement aux ACE `fs_acl` cumulables). C'est VOULU : le ciblage « qui
 * est refusé » vit DANS la liste (`@eleves` seul → les profs, absents de la
 * liste, gardent le RDP), pas dans un ciblage par utilisateur.
 *
 * **Jetons d'audience (D6, RÉUTILISE {@see AudienceTokens} de 36.1).** Un
 * compte `@eleves|@profs|@personnels` est résolu par convention vers le groupe
 * principal global SI ce groupe existe dans `user_groups`. Jeton irrésoluble ⇒
 * **item ENTIER non émis + log warning** (jamais une liste partielle qui
 * SOUS-REFUSERAIT — piège #8/#10 : un item émis sans l'élève irrésoluble
 * laisserait un trou silencieux). Un compte littéral (`Domain Users`) part
 * VERBATIM — l'agent le résout via LSA (échec ⇒ erreur d'item, visible).
 *
 * **`accounts: []` (off) est ÉMIS** : l'agent VIDE le privilège (révoque tous
 * les titulaires) — c'est le retrait PROPRE (piège #6 : `unmanaged` cesse
 * d'émettre → le handler n'est plus invoqué → privilège orphelin).
 *
 * **Pas de ciblage par utilisateur (piège #11).** `scope() = Machine` ⇒ le
 * service SYSTEM fetch sans `?user` (`userGroupIds = []`) : un override
 * UserGroup/User d'une capacité `privilege` est SANS EFFET.
 *
 * **`hive()` non applicable** : `expand()` est surchargé intégralement —
 * `handlesHive()` n'est JAMAIS appelé (iso {@see FsAclCapabilityProvider}).
 */
final class PrivilegeCapabilityProvider extends AbstractCapabilityStateProvider
{
    /**
     * Résolution des jetons d'audience mémoïsée pour la durée de vie de CETTE
     * instance de provider (une compilation = ≤ 1 requête d'existence). Créée
     * paresseusement.
     */
    private ?AudienceTokens $audience = null;

    public function scope(): StateScope
    {
        return StateScope::Machine;
    }

    protected function mechanism(): string
    {
        return CapabilityProjection::MECHANISM_PRIVILEGE;
    }

    /**
     * Non applicable au mécanisme `privilege` — `expand()` est surchargé
     * intégralement, `handlesHive()` n'est JAMAIS appelé. Implémentée pour
     * satisfaire le contrat de la classe abstraite (registre-specific).
     */
    protected function hive(): string
    {
        return '';
    }

    /**
     * Identité d'un privilège géré exclusif : le NOM du privilège en minuscules
     * (1 segment — piège #4 : la maille gagnante prend la liste ENTIÈRE).
     * Normalisé pour la STABILITÉ de la sélection (déterministe, ETag 23.5).
     */
    public function exclusiveKey(array $payload): string
    {
        return strtolower((string) ($payload['privilege'] ?? ''));
    }

    /**
     * Interpréteur de `spec` du mécanisme `privilege`. La projection porte
     * `spec = { "privilege": "SeDeny…", "accounts": <liste OU map valeur-capacité> }` :
     *   - `privilege` est borné à l'enum SeDeny* (défensif — le guard refuse
     *     déjà en amont) : hors domaine ⇒ item NON émis ;
     *   - `accounts` est résolu par {@see resolveKeyValue()} : liste littérale
     *     OU map `{capValue: [comptes]}` ; clé de map absente ⇒ UNMANAGED ⇒
     *     item non émis (sentinelle) ; forme inattendue (scalaire, assoc
     *     imbriquée) ⇒ non émis défensif, jamais d'exception au render ;
     *   - chaque compte passe par {@see AudienceTokens} (INJECTÉ, réutilisé de
     *     36.1) : jeton irrésoluble ⇒ item ENTIER non émis + warning (jamais
     *     une liste partielle qui sous-refuserait, piège #8/#10) ; littéral
     *     verbatim ;
     *   - une liste résolue VIDE (valeur `off`) est ÉMISE (privilège vidé).
     * Le payload résultant est CONCRET : EXACTEMENT 2 clés, `accounts` liste
     * TRIÉE de strings (byte-identité du hash, piège #13), zéro float, jamais
     * d'id de capacité (invariant 27.12).
     *
     * @return list<array<string,mixed>> zéro ou un payload 2 clés
     */
    protected function expand(CapabilityProjection $projection, string $capabilityValue): array
    {
        $spec = $projection->spec;
        if (! is_array($spec)) {
            return [];
        }

        // Privilège borné à l'enum SeDeny* (défensif — casse d'authoring
        // préservée au payload, comparaison insensible à la casse).
        $privilege = trim((string) ($spec['privilege'] ?? ''));
        if (! $this->isAllowedPrivilege($privilege)) {
            // Symétrique du compte irrésoluble (l.157) : une projection portant
            // un privilège hors SeDeny* (seedée en contournant l'observer, ou
            // grant laissé passer) est écartée SILENCIEUSEMENT à la compilation
            // sinon — on trace pour que l'anomalie soit observable.
            Log::warning('privilege : projection écartée, privilège hors allowlist SeDeny* (item non émis).', [
                'capability_id' => $projection->capability_id,
                'privilege' => $privilege,
            ]);

            return [];
        }

        // Résolution accounts (liste littérale OU map valeur-capacité).
        $resolved = $this->resolveKeyValue($spec['accounts'] ?? null, $capabilityValue);
        if ($resolved === self::UNMANAGED) {
            return []; // clé de map absente ⇒ sentinelle (rien émis).
        }
        if (! is_array($resolved) || ! array_is_list($resolved)) {
            return []; // forme inattendue ⇒ non émis défensif.
        }

        // Chaque compte : jeton d'audience → nom conventionnel, littéral →
        // verbatim. Jeton irrésoluble ⇒ item ENTIER non émis + warning.
        $accounts = [];
        foreach ($resolved as $raw) {
            if (! is_scalar($raw)) {
                return []; // entrée non scalaire ⇒ non émis défensif.
            }
            $account = trim((string) $raw);
            if ($account === '') {
                continue; // entrée vide écartée (défensif).
            }

            $name = $this->audienceTokens()->resolve($account);
            if ($name === null) {
                Log::warning('privilege : compte irrésoluble, item entier non émis (jeton inconnu ou groupe absent de user_groups) — jamais de liste partielle qui sous-refuserait.', [
                    'capability_id' => $projection->capability_id,
                    'account' => $account,
                    'privilege' => $privilege,
                ]);

                return [];
            }
            $accounts[] = $name;
        }

        // Liste TRIÉE (l'ordre n'est pas porteur de sens — byte-identité du
        // hash et comparaison anti-drift, piège #13). Une liste VIDE est ÉMISE
        // (off réel : l'agent vide le privilège).
        $accounts = array_values(array_unique($accounts));
        sort($accounts, SORT_STRING);

        return [[
            'privilege' => $privilege,
            'accounts' => $accounts,
        ]];
    }

    /**
     * Le nom est-il un des 5 SeDeny* (insensible à la casse) ? Délègue le
     * VOCABULAIRE à la constante du guard (une seule autorité, D3).
     */
    private function isAllowedPrivilege(string $privilege): bool
    {
        foreach (PrivilegeAuthoringGuard::ALLOWED_PRIVILEGES as $allowed) {
            if (strcasecmp($privilege, $allowed) === 0) {
                return true;
            }
        }

        return false;
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

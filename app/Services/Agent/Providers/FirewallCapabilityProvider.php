<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\CapabilityProjection;

/**
 * Story 36.2 — provider `firewall` CAPABILITY-FIRST, portée **Machine** (le
 * service SYSTEM est le SEUL acteur des règles pare-feu Windows ; le compagnon
 * n'a pas les droits, et le type n'existe pas côté session).
 *
 * Deuxième mécanisme HORS-REGISTRE (jumeau structurel de {@see FsAclCapabilityProvider},
 * doctrine Epic 36 : « mécanisme = code payé une fois, capacité = donnée »). Il
 * EXPANSE une capacité → items de contrat CONCRETS `{rule_id, direction, action,
 * remote_scope, protocol, ensure}` (+ `remote_addresses` ssi `explicit`, +
 * `ports` ssi tcp|udp) — enums fermés de MOTS MÉTIER, AUCUNE syntaxe netsh/SDDL
 * (D3). Il SURCHARGE l'interpréteur `expand()` du provider abstrait sans toucher
 * `StateCompiler` (D2). Il réutilise `resolveKeyValue()` (map/littéral) et
 * `UNMANAGED` hérités ; lecture Postgres pure (NFR7).
 *
 * **Propriété PAR CONTENEUR (D4).** Contrairement à `fs_acl` (store « dernier
 * appliqué »), une règle pare-feu PORTE son marqueur de propriété : son champ
 * `Grouping = SambaEdu-Agent`. L'agent possède le GROUPE en entier et le
 * réconcilie — AUCUN store n'est nécessaire ici. La traduction
 * `remote_scope: internet` en plages inverses-RFC1918 vit dans le HANDLER (D6),
 * jamais dans la donnée.
 *
 * **`exclusiveKey() = rule_id`** (1 segment, minuscule) : la maille la plus
 * spécifique gagne CETTE règle ; deux règles de `rule_id` distincts COEXISTENT
 * (cumul dans le groupe). ⚠️ `rule_id` est une identité GLOBALE inter-capacités
 * (deux capacités émettant le même `rule_id` collisionnent au compilateur — la
 * plus spécifique gagne LA règle).
 *
 * **Écart assumé `ensure` (piège #2).** L'epic parlait d'un enum « sans verbe
 * ensure » ; la précédence par maille arbitre par IDENTITÉ (`exclusiveKey`)
 * entre items ÉMIS par maille : une valeur qui n'émet RIEN ne peut JAMAIS battre
 * une maille plus large qui émet quelque chose. `ensure ∈ present|absent`
 * (TOUJOURS émis) porte donc la précédence dans les DEUX sens (`on` émet le
 * MÊME `rule_id` en `ensure:absent` → même identité → override de parc `on`
 * annule un broadcast `off`, et le groupe finit VIDE = l'AC epic « on ⇒ groupe
 * vide » à la lettre).
 *
 * **Pas de ciblage par utilisateur (Q4).** `scope() = Machine` ⇒ le service
 * SYSTEM fetch sans `?user` : un override UserGroup/User d'une capacité
 * `firewall` est SANS EFFET (limitation Windows assumée — « couper Internet »
 * se cible par parc/salle).
 *
 * **`hive()` non applicable** : `expand()` est surchargé intégralement —
 * `handlesHive()` n'est JAMAIS appelé (iso {@see FsAclCapabilityProvider}).
 */
final class FirewallCapabilityProvider extends AbstractCapabilityStateProvider
{
    public function scope(): StateScope
    {
        return StateScope::Machine;
    }

    protected function mechanism(): string
    {
        return CapabilityProjection::MECHANISM_FIREWALL;
    }

    /**
     * Non applicable au mécanisme `firewall` — `expand()` est surchargé
     * intégralement, `handlesHive()` n'est JAMAIS appelé (piège #14). Implémentée
     * pour satisfaire le contrat de la classe abstraite (registre-specific).
     */
    protected function hive(): string
    {
        return '';
    }

    /**
     * Identité d'une règle gérée exclusive : `rule_id` minuscule (1 segment). Le
     * `rule_id` est une identité GLOBALE (inter-capacités) → normalisée pour la
     * STABILITÉ de la sélection (déterministe, ETag 23.5).
     */
    public function exclusiveKey(array $payload): string
    {
        return strtolower((string) ($payload['rule_id'] ?? ''));
    }

    /**
     * Interpréteur de `spec` du mécanisme `firewall`. La projection porte
     * `spec = { "rules": [ {rule_id, direction, action, remote_scope, protocol,
     * ports?, remote_addresses?, ensure?}, … ] }`. Pour CHAQUE entrée :
     *   - `direction`/`action`/`remote_scope`/`protocol` sont des enums FIXES
     *     (mots métier) — hors domaine ⇒ entrée NON émise (défensif ; le guard
     *     refuse déjà en amont) ;
     *   - `ensure` est littéral OU map valeur-capacité, résolu par
     *     {@see resolveKeyValue()} : clé de map absente ⇒ UNMANAGED ⇒ entrée non
     *     émise ; forme assoc inattendue ⇒ non émise défensif (jamais d'exception
     *     au render) ; défaut `present` (piège #13, TOUJOURS émis) ;
     *   - cohérence conditionnelle : `remote_addresses` présent SSI `explicit`
     *     (et non vide), `ports` présent SSI `protocol ∈ tcp|udp` — toute
     *     incohérence ⇒ entrée non émise (défensif). Zéro float.
     * Le payload résultant est CONCRET : `{rule_id, direction, action,
     * remote_scope, protocol, ensure}` (+ `remote_addresses`/`ports`
     * conditionnels), tout en strings/tableaux de strings, jamais d'id de
     * capacité (invariant 27.12).
     *
     * @return list<array<string,mixed>> un payload par règle émise
     */
    protected function expand(CapabilityProjection $projection, string $capabilityValue): array
    {
        $spec = $projection->spec;
        $rules = is_array($spec) && isset($spec['rules']) && is_array($spec['rules'])
            ? $spec['rules']
            : [];

        $payloads = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            // `rule_id` émis en MINUSCULE (corr. review #3), cohérent avec
            // {@see exclusiveKey()} : le nom de règle Windows dérivé côté agent
            // (`SambaEdu-Agent: <rule_id>`) hérite ainsi d'une casse stable — pas
            // de règle orpheline si un `Remove` s'avérait sensible à la casse.
            $ruleId = strtolower((string) ($rule['rule_id'] ?? ''));
            if ($ruleId === '') {
                continue;
            }

            // Enums FIXES bornés (défensif — le guard d'authoring refuse en amont).
            $direction = strtolower((string) ($rule['direction'] ?? ''));
            $action = strtolower((string) ($rule['action'] ?? ''));
            $remoteScope = strtolower((string) ($rule['remote_scope'] ?? ''));
            $protocol = strtolower((string) ($rule['protocol'] ?? ''));
            if (! in_array($direction, FirewallAuthoringGuard::DIRECTIONS, true)
                || ! in_array($action, FirewallAuthoringGuard::ACTIONS, true)
                || ! in_array($remoteScope, FirewallAuthoringGuard::REMOTE_SCOPES, true)
                || ! in_array($protocol, FirewallAuthoringGuard::PROTOCOLS, true)) {
                continue;
            }

            // Résolution ensure (littéral OU map ; défaut `present`, TOUJOURS
            // émis — piège #2/#13).
            $ensure = $this->resolveEnsure($rule['ensure'] ?? null, $capabilityValue);
            if ($ensure === null) {
                continue; // UNMANAGED / forme inattendue / enum hors domaine ⇒ non émis.
            }

            $payload = [
                'rule_id' => $ruleId,
                'direction' => $direction,
                'action' => $action,
                'remote_scope' => $remoteScope,
                'protocol' => $protocol,
                'ensure' => $ensure,
            ];

            // `remote_addresses` : présent SSI `explicit` et non vide (liste de
            // strings). `internet` AVEC des adresses = incohérent ⇒ non émis.
            $addresses = $this->stringList($rule['remote_addresses'] ?? null);
            if ($remoteScope === 'explicit') {
                if ($addresses === []) {
                    continue; // explicit sans adresses ⇒ non émis (défensif).
                }
                $payload['remote_addresses'] = $addresses;
            } elseif ($addresses !== []) {
                continue; // internet AVEC adresses ⇒ non émis (forme unique).
            }

            // `ports` : présent SSI `protocol ∈ tcp|udp` (et ciblés). `any` AVEC
            // ports = incohérent ⇒ non émis.
            $ports = $this->stringList($rule['ports'] ?? null);
            if ($ports !== []) {
                if ($protocol === 'any') {
                    continue; // ports avec protocol any ⇒ non émis.
                }
                $payload['ports'] = $ports;
            }

            $payloads[] = $payload;
        }

        return $payloads;
    }

    /**
     * Résout le champ `ensure` d'une entrée `rules[]` : absent ⇒ `present`
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

        return in_array($ensure, FirewallAuthoringGuard::ENSURE, true) ? $ensure : null;
    }

    /**
     * Normalise une valeur `remote_addresses`/`ports` en liste de strings NON
     * vide, ou `[]` : seule une liste (`array_is_list`) de scalaires est admise
     * (les entrées vides sont écartées) ; toute autre forme (map, scalaire nu,
     * null) ⇒ `[]`.
     *
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $v) {
            if (! is_scalar($v)) {
                continue;
            }
            $s = trim((string) $v);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\CapabilityProjection;
use App\Models\UserGroup;
use App\Services\Agent\Providers\AudienceTokens;
use Illuminate\Support\Facades\DB;

/**
 * Story 36.4 (D5) — Validation PRÉDICTIVE des règles d'accès aux dossiers.
 *
 * Service de **PURE LECTURE** (Postgres), calque de
 * {@see \App\Services\Filesystem\NetworkShareValidator} : il PRÉDIT, AVANT
 * émission, deux avertissements NON bloquants — il n'écrit RIEN, n'émet AUCUN
 * candidat, n'introduit AUCUNE précédence (D2 reste confiné au `StateCompiler`).
 *
 *  - **Recouvrement de capacité** ({@see capabilityOverlaps()}) : la règle porte
 *    la MÊME identité `{path|trustee|ace_type}` qu'une entrée `aces[]` d'une
 *    capacité `windows/fs_acl` ACTIVE. Ce n'est PAS une erreur (la collision est
 *    arbitrée par le compilateur, maille/récence — D1) mais un AVERTISSEMENT
 *    nommant la capacité. Les trustees d'une capacité sont résolus STATIQUEMENT :
 *    littéral verbatim, CHAQUE valeur d'une map, jeton `@…` → map fermée
 *    {@see AudienceTokens::TOKENS} (pas de requête d'existence — c'est un
 *    avertissement, pas une émission).
 *  - **Groupe sans correspondance AD** ({@see missingAdDn()}) : le trustee sera
 *    dérivé du `name` folded (piège #4/D9) → résolution LSA potentiellement
 *    impossible au poste. Avertissement à la création.
 *
 * Une collision règle↔règle sur la même identité n'est PAS un warning (même
 * provider, arbitrée par le compilateur — D1).
 */
class FolderAccessRuleValidator
{
    /**
     * Clés/labels des capacités ACTIVES dont une entrée `fs_acl` a la MÊME
     * identité `{path|trustee|ace_type}` (insensible à la casse, iso
     * `FsAclCapabilityProvider::exclusiveKey()`). Liste vide = aucun recouvrement.
     *
     * @return list<string>
     */
    public function capabilityOverlaps(string $path, string $trustee, string $aceType): array
    {
        $target = $this->identity($path, $trustee, $aceType);

        $rows = DB::table('capabilities as c')
            ->join('capability_projections as p', 'p.capability_id', '=', 'c.id')
            ->where('c.is_active', true)
            ->where('p.os', 'windows')
            ->where('p.mechanism', CapabilityProjection::MECHANISM_FS_ACL)
            ->get(['c.key as key', 'p.spec as spec']);

        $overlaps = [];

        foreach ($rows as $row) {
            $spec = $this->decodeSpec($row->spec);
            $aces = is_array($spec['aces'] ?? null) ? $spec['aces'] : [];

            foreach ($aces as $ace) {
                if (! is_array($ace)) {
                    continue;
                }
                $acePath = (string) ($ace['path'] ?? '');
                $aceAceType = (string) ($ace['ace_type'] ?? '');
                if ($acePath === '' || $aceAceType === '') {
                    continue;
                }

                foreach ($this->possibleTrustees($ace['trustee'] ?? null) as $candidate) {
                    if ($this->identity($acePath, $candidate, $aceAceType) === $target) {
                        $overlaps[] = (string) $row->key;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($overlaps));
    }

    /**
     * `true` si le groupe n'a AUCUN `ad_dn` (le trustee sera dérivé du `name`
     * folded — piège #4/D9). Avertissement non bloquant à la création.
     */
    public function missingAdDn(int $userGroupId): bool
    {
        $group = UserGroup::find($userGroupId);
        if ($group === null) {
            return false;
        }
        $adDn = $group->ad_dn;

        return $adDn === null || trim((string) $adDn) === '';
    }

    // =========================================================================
    // Interne
    // =========================================================================

    /**
     * Identité normalisée `{path|trustee|ace_type}` (minuscules) — MÊME clé que
     * {@see \App\Services\Agent\Providers\FsAclCapabilityProvider::exclusiveKey()}.
     */
    private function identity(string $path, string $trustee, string $aceType): string
    {
        return strtolower(trim($path)).'|'.strtolower(trim($trustee)).'|'.strtolower(trim($aceType));
    }

    /**
     * Trustees possibles d'un champ `trustee` de `spec` : littéral (1) OU chaque
     * valeur scalaire d'une map valeur-capacité ; un jeton `@…` est résolu
     * STATIQUEMENT vers le nom conventionnel ({@see AudienceTokens::TOKENS} —
     * pas de requête d'existence, c'est un avertissement).
     *
     * @return list<string>
     */
    private function possibleTrustees(mixed $raw): array
    {
        $values = [];
        if (is_array($raw)) {
            if (array_is_list($raw)) {
                return []; // ni littéral ni map valide
            }
            foreach ($raw as $v) {
                if (is_scalar($v)) {
                    $values[] = (string) $v;
                }
            }
        } elseif (is_scalar($raw)) {
            $values[] = (string) $raw;
        }

        $out = [];
        foreach ($values as $value) {
            if (AudienceTokens::isToken($value)) {
                $conventional = AudienceTokens::TOKENS[strtolower($value)] ?? null;
                if ($conventional !== null) {
                    $out[] = $conventional;
                }

                continue;
            }
            $out[] = $value;
        }

        return $out;
    }

    /**
     * Décode une `spec` telle que rendue par `DB::table` (string JSON) ou déjà
     * tableau. Toute forme inattendue ⇒ tableau vide (défensif).
     *
     * @return array<string,mixed>
     */
    private function decodeSpec(mixed $spec): array
    {
        if (is_array($spec)) {
            return $spec;
        }
        if (is_string($spec)) {
            $decoded = json_decode($spec, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}

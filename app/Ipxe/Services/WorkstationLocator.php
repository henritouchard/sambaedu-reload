<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Support\MacAddressNormalizer;
use App\Ipxe\Support\UuidNormalizer;
use App\Models\Workstation;

/**
 * Story 3.1 — D4 / AC2.1.
 *
 * Résout un poste de travail à partir de ses identifiants iPXE
 * (`mac`, `uuid`, `product`).
 *
 * **Algorithme iso-legacy** (`sambaedu/ipxe/boot.php:22-42`) :
 *
 *  1. Normalisation `MAC` via {@see MacAddressNormalizer::normalize()} —
 *     accepte les variantes `aa:bb:..`, `AA-BB-..`, `aabbcc..` (mixed case).
 *  2. Normalisation `UUID` via {@see UuidNormalizer::normalize()} — trim +
 *     lowercase.
 *  3. **Si `product` est vide ET mac/uuid sont valides** : applique la
 *     transformation hexadécimal iso-legacy `boot.php:36-41` qui reconstruit
 *     l'UUID via la MAC. Cette transformation est cruciale pour les postes
 *     anciens dont le firmware iPXE ne pose pas `product` — le legacy
 *     stockait alors un UUID composite.
 *  4. **Lookup PostgreSQL** :
 *      - Étape A : `Workstation::where('uuid', $normalized)` (priorité).
 *      - Étape B : `Workstation::where('mac', $normalized)` (fallback).
 *  5. Si trouvé : eager load `physicalRoom`, `groups`, `appProfiles` pour
 *     usage 3.2+ (pas utilisé en 3.1 mais évite le N+1 en aval).
 *  6. Si non trouvé : retourner `null` → menu default minimal (D6).
 *
 * **Source de vérité** : PostgreSQL exclusivement. **Aucun appel** LdapRecord
 * ni `search_machine()` legacy (architecture.md §"Modèle de Données — Source
 * de Vérité").
 *
 * **Pas d'effet de bord** : pas d'update de la Workstation trouvée, pas de
 * création à la volée d'une nouvelle row (= scope 3.3 enrollment).
 */
final class WorkstationLocator
{
    /**
     * Tente de résoudre la Workstation correspondant aux identifiants iPXE.
     *
     * @param  string|null  $mac      Adresse MAC brute (formats variés).
     * @param  string|null  $uuid     UUID brut (peut être malformé/mixed case).
     * @param  string|null  $product  Modèle matériel optionnel — déclenche la
     *                                transformation hexa legacy si vide.
     * @return Workstation|null       Modèle trouvé (avec relations eager-loaded)
     *                                ou `null` si poste inconnu.
     */
    public function locate(?string $mac, ?string $uuid, ?string $product = null): ?Workstation
    {
        $normalizedMac = $mac !== null ? MacAddressNormalizer::normalize($mac) : null;
        $normalizedUuid = $uuid !== null ? UuidNormalizer::normalize($uuid) : null;
        $normalizedProduct = $product !== null ? trim($product) : '';

        // Décision DO-3 : transformation product-empty appliquée AVANT le
        // lookup. Sans ça, le matching échouerait pour les postes legacy
        // dont l'UUID stocké est composite (cf. boot.php:36-41).
        if ($normalizedProduct === '' && $normalizedMac !== null && $normalizedUuid !== null) {
            $normalizedUuid = $this->applyLegacyProductEmptyTransformation(
                $normalizedMac,
                $normalizedUuid,
            );
        }

        $relations = ['physicalRoom', 'groups', 'appProfiles'];

        // Étape 1 — priorité UUID (iso-legacy `boot.php:42` get_action()
        // qui priorise l'UUID composite).
        if ($normalizedUuid !== null) {
            $ws = Workstation::query()
                ->with($relations)
                ->where('uuid', $normalizedUuid)
                ->whereNotNull('uuid')
                ->first();
            if ($ws instanceof Workstation) {
                return $ws;
            }
        }

        // Étape 2 — fallback MAC.
        if ($normalizedMac !== null) {
            $ws = Workstation::query()
                ->with($relations)
                ->where('mac', $normalizedMac)
                ->first();
            if ($ws instanceof Workstation) {
                return $ws;
            }
        }

        // Étape 3 — poste inconnu (= menu default minimal D6).
        return null;
    }

    /**
     * Applique la transformation hexadécimal legacy `boot.php:36-41` :
     *
     * ```php
     * $uuids = explode("-", $uuid);
     * $dm = hexdec(implode("", explode(":", $mac)));
     * $finx = dechex($dm);
     * $uuid = $uuids[0]."-".$uuids[1]."-".$uuids[2]."-".$uuids[3]."-".$finx;
     * ```
     *
     * Cas d'usage : poste legacy dont le firmware iPXE ne pose pas le
     * paramètre `product`. Le legacy stockait alors un UUID composite formé
     * des 4 premiers segments de l'UUID original + un 5ème segment = `dechex`
     * de la MAC concaténée en décimal. On reproduit fidèlement le calcul.
     *
     * **Précondition** : `$mac` et `$uuid` sont déjà normalisés (lowercase
     * canonique `xx:xx:..`). Si l'UUID a moins de 4 segments séparés par `-`,
     * on retourne l'UUID tel quel — la transformation est inapplicable.
     */
    private function applyLegacyProductEmptyTransformation(string $mac, string $uuid): string
    {
        $uuidSegments = explode('-', $uuid);
        if (count($uuidSegments) < 4) {
            // UUID malformé < 4 segments : on ne peut pas appliquer la
            // transformation. Retour identité.
            return $uuid;
        }

        // Retire les `:` de la MAC et convertit l'hex concaténé en décimal,
        // puis re-convertit en hex (équivalent legacy `dechex(hexdec(...))`).
        $macHex = str_replace(':', '', $mac);
        $decimal = (int) hexdec($macHex);
        $finx = dechex($decimal);

        return $uuidSegments[0] . '-'
            . $uuidSegments[1] . '-'
            . $uuidSegments[2] . '-'
            . $uuidSegments[3] . '-'
            . $finx;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use Illuminate\Support\Facades\Log;

/**
 * Story 33.1 — Référentiel du schéma d'ÉCHANGE versionné du contrat amont (controlHub ↔ SE5).
 *
 * Ce référentiel est la source de vérité CÔTÉ CODE de la version du format de payload
 * échangé entre l'autorité amont (controlHub) et SE5. Il accompagne l'artefact partagé
 * {@see _bmad-output/planning-artifacts/schema-echange-controlhub-se5.md} qui documente
 * le format pour les DEUX BMAD (R2 — référence unique vérifiable).
 *
 * ⚠️ NE PAS CONFONDRE avec {@see \App\Services\Agent\StateContract} / `ContractV1` : ce
 * dernier versionne le **contrat AGENT** (desired-state émis VERS l'agent, figé/golden).
 * Le présent versionnement est celui du **schéma d'ÉCHANGE amont→SE5** (champ `schema_version`
 * à la racine du payload reçu). Les deux versionnements sont DISTINCTS et ne se couplent jamais.
 * Le versionnement de schéma est **serveur-only**, invisible de l'agent.
 *
 * --- Politique de compatibilité (Q2 — semver chaîne) ---
 *
 * La version est une **chaîne semver** (ex. `'1.0'`). « Supportée » signifie, en Story 33.1,
 * **égalité stricte** avec une version de {@see self::SUPPORTED_VERSIONS} (en 33.1, la seule
 * version courante). La **compatibilité sur le MAJOR** (accepter toute `1.x`) est documentée
 * mais **ouverte à la Story 33.2** : 33.1 ne livre que le chemin heureux (conforme/absent).
 *
 * --- Garde-fou R3 ---
 *
 * Aucun identifiant/constante/message de cette classe ne contient le mot « central ».
 * Vocabulaire imposé : « amont » / `ControlHub*` / `upstream` / `authority`.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
final class ControlHubContractSchema
{
    /**
     * Version courante du schéma d'échange (Q2 — semver chaîne).
     *
     * C'est la version attribuée par défaut à un payload qui n'en déclare pas (Q1=A,
     * rétro-compatibilité des payloads 28.2 dépourvus de `schema_version`).
     */
    public const CURRENT_VERSION = '1.0';

    /**
     * Versions supportées par cette instance SE5.
     *
     * En Story 33.1 : la seule version courante (égalité stricte). La liste est le point
     * d'extension naturel pour 33.2 (négociation de compatibilité, ajout d'une 2ᵉ version).
     *
     * @var list<string>
     */
    public const SUPPORTED_VERSIONS = [self::CURRENT_VERSION];

    /**
     * Résout la version de schéma à enregistrer pour un payload **conforme ou absent**.
     *
     * Contrat de la Story 33.1 (chemin heureux uniquement) :
     * - `null` / absente → {@see self::CURRENT_VERSION} (Q1=A — défaut tolérant, rétro-compat 28.2) ;
     * - version **supportée** (∈ {@see self::SUPPORTED_VERSIONS}) → elle-même.
     *
     * Le **rejet** d'une version non supportée n'est **PAS** implémenté ici (Story 33.2). Une
     * version déclarée mais non supportée retombe aujourd'hui sur la version courante via le seam
     * ci-dessous ; 33.2 substituera à ce repli un rejet gracieux tracé (« reçue vs supportées »).
     *
     * @param  string|null  $declared  Valeur brute de `schema_version` du payload (ou null si absent).
     */
    public static function negotiate(?string $declared): string
    {
        if ($declared === null || $declared === '') {
            // Q1=A — payload sans version : défaut = version courante (rétro-compat 28.2).
            return self::CURRENT_VERSION;
        }

        if (self::isSupported($declared)) {
            return $declared;
        }

        // Story 33.2 — rejet gracieux d'une version incompatible.
        // En 33.1, aucune version n'est rejetée : on retombe sur la version courante (chemin
        // heureux). 33.2 remplacera ce repli par une négociation stricte (trace de l'écart,
        // état inchangé) en s'appuyant sur ce même seam.
        //
        // Observabilité (review 33.1, #3) : une version déclarée NON supportée n'est ni conforme
        // ni absente — on trace le repli (non bloquant) pour ne pas masquer un écart d'émission
        // amont entre 33.1 et 33.2. Ce log NE rejette PAS (le rejet reste 33.2).
        Log::warning('ControlHubContractSchema: version de schéma non supportée — repli sur la version courante (seam Story 33.2)', [
            'declared' => $declared,
            'fallback' => self::CURRENT_VERSION,
            'supported' => self::SUPPORTED_VERSIONS,
        ]);

        return self::CURRENT_VERSION;
    }

    /**
     * Une version est « supportée » par égalité stricte à une version de
     * {@see self::SUPPORTED_VERSIONS} (politique 33.1 ; compat sur le MAJOR ouverte à 33.2).
     */
    public static function isSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED_VERSIONS, true);
    }
}

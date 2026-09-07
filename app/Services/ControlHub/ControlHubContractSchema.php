<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Exceptions\ControlHub\UnsupportedSchemaVersionException;
use Illuminate\Support\Facades\Log;

/**
 * Référentiel du schéma d'ÉCHANGE versionné du contrat amont (controlHub ↔ SE5).
 *
 * Ce référentiel est la source de vérité CÔTÉ CODE de la version du format de payload
 * échangé entre l'autorité amont (controlHub) et SE5. Il accompagne l'artefact partagé
 * {@see docs/controlhub-schema-echange.md} qui documente
 * le format pour SE5 comme pour l'amont.
 *
 * ⚠️ NE PAS CONFONDRE avec {@see \App\Services\Agent\StateContract} / `ContractV1` : ce
 * dernier versionne le **contrat AGENT** (desired-state émis VERS l'agent, figé/golden).
 * Le présent versionnement est celui du **schéma d'ÉCHANGE amont→SE5** (champ `schema_version`
 * à la racine du payload reçu). Les deux versionnements sont DISTINCTS et ne se couplent jamais.
 * Le versionnement de schéma est **serveur-only**, invisible de l'agent.
 *
 * **Politique de compatibilité**
 *
 * La version est une **chaîne semver** (ex. `'1.0'`). « Supportée » signifie **égalité stricte**
 * avec une version de {@see self::SUPPORTED_VERSIONS} (à ce jour, la seule version courante). La
 * **compatibilité sur le MAJOR** (accepter toute `1.x`) est **différée tant qu'une seule version
 * existe** : la coder sans 2ᵉ version réelle à confronter serait spéculatif et non testable de
 * bout en bout (anti sur-engineering). {@see self::SUPPORTED_VERSIONS}/{@see self::isSupported()}
 * restent le point d'extension propre le jour où une 2ᵉ version apparaît.
 *
 * La négociation est **stricte** : une version **déclarée** non supportée est
 * **rejetée** ({@see UnsupportedSchemaVersionException}), plus de repli tolérant. Le chemin heureux
 * antérieur (absent → courant, supporté → accepté) est strictement inchangé.
 *
 * **Garde-fou R3**
 *
 * Aucun identifiant/constante/message de cette classe ne contient le mot « central ».
 * Vocabulaire imposé : « amont » / `ControlHub*` / `upstream` / `authority`.
 */
final class ControlHubContractSchema
{
    /**
     * Version courante du schéma d'échange (chaîne semver).
     *
     * C'est la version attribuée par défaut à un payload qui n'en déclare pas
     * (rétro-compatibilité des payloads dépourvus de `schema_version`).
     */
    public const CURRENT_VERSION = '1.0';

    /**
     * Versions supportées par cette instance SE5.
     *
     * À ce jour, la seule version courante (égalité stricte). La liste est le point
     * d'extension naturel le jour où une 2ᵉ version apparaît.
     *
     * @var list<string>
     */
    public const SUPPORTED_VERSIONS = [self::CURRENT_VERSION];

    /**
     * Résout la version de schéma à enregistrer, ou **rejette** une version incompatible.
     *
     * Chemin heureux :
     * - `null` / absente → {@see self::CURRENT_VERSION} (défaut tolérant, rétro-compat) ;
     * - version **supportée** (∈ {@see self::SUPPORTED_VERSIONS}) → elle-même.
     *
     * Négociation **stricte** : une version **déclarée** (chaîne non vide) mais non
     * supportée est **rejetée** par {@see UnsupportedSchemaVersionException} (plus de repli sur la
     * version courante). Le rejet est tracé (log structuré `{declared, supported}`) au point de
     * décision. Comme l'ingestion négocie en phase de validation PURE (avant toute écriture), la
     * levée garantit l'état persisté strictement inchangé (rollback total trivial).
     *
     * @param  string|null  $declared  Valeur brute de `schema_version` du payload (ou null si absent).
     *
     * @throws UnsupportedSchemaVersionException si `$declared` est une chaîne non vide non supportée.
     */
    public static function negotiate(?string $declared): string
    {
        if ($declared === null || $declared === '') {
            // Payload sans version : défaut = version courante (rétro-compat).
            return self::CURRENT_VERSION;
        }

        if (self::isSupported($declared)) {
            return $declared;
        }

        // Rejet gracieux d'une version incompatible. Une version déclarée NON
        // supportée n'est ni conforme ni absente : on TRACE l'écart (log structuré, condition
        // attendue et gérée ⇒ niveau `warning`) PUIS on lève l'exception dédiée. L'ingestion
        // négocie AVANT d'ouvrir sa transaction : la levée garantit zéro écriture (état inchangé).
        Log::warning('ControlHubContractSchema: version de schéma d\'échange amont non supportée — payload rejeté', [
            'declared' => $declared,
            'supported' => self::SUPPORTED_VERSIONS,
        ]);

        throw UnsupportedSchemaVersionException::for($declared, self::SUPPORTED_VERSIONS);
    }

    /**
     * Une version est « supportée » par **égalité stricte** à une version de
     * {@see self::SUPPORTED_VERSIONS} (la compat sur le MAJOR est différée tant qu'une seule
     * version existe ; ne pas introduire de comparaison MAJOR ici).
     */
    public static function isSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED_VERSIONS, true);
    }
}

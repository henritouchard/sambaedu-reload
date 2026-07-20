<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\StateScope;

/**
 * Source unique du contrat d'état cible `se5.desired-state/v1`.
 *
 * Le nom du schéma est un irréversible figé (NFR12) : c'est une **constante**,
 * jamais une variable d'environnement. Un agent déployé fige le wire format ;
 * toute évolution passe par un bump explicite (`v2`) + golden files mis à jour
 * (cf. `docs/agent/contract-v1.md`, règle d'évolution).
 *
 * Cette story ne crée **pas** `config/agent.php` (relève de 23.5) : le serveur
 * et les tests référencent ces constantes directement.
 */
final class StateContract
{
    /** Version du contrat. L'agent refuse un major inconnu. */
    public const SCHEMA = 'se5.desired-state/v1';

    /** Clés d'enveloppe des trois portées (= valeurs de {@see StateScope}). */
    public const SCOPE_MACHINE = 'machine';

    public const SCOPE_SESSION = 'session';

    public const SCOPE_MACHINE_USER = 'machine_user';

    /**
     * Identifiants de type de ressource publiés (§7 contrat v1 — NFR12).
     *
     * Clé de voûte du contrat, partagés serveur / agent / JSON / DB / UI :
     * **figés une fois publiés** — jamais de renommage en place (déprécier +
     * ajouter en cas d'erreur). Liste FERMÉE consommée par la validation de
     * l'ingestion des rapports (Story 24.1) : un type inconnu → 422 (un
     * nouveau type = bump de contrat de toute façon). Constante ADDITIVE :
     * seuls golden files + `contract-v1.md` + hash figé sont intouchables.
     *
     * @var list<string>
     */
    public const RESOURCE_TYPES = [
        'wallpaper',
        'lockscreen',
        'overlay',
        'shortcuts',
        'printers',
        'drives',
        'associations',
        'registry',
        'app_config',
        'applications',
        // Story 35.2 (D1) — listes registre à sous-valeurs indexées `\1..\N`
        // (ExtensionInstallForcelist, DisallowRun). Ajout ADDITIF : la
        // constante est consommée par `ReportRequest` (Rule::in) — l'ingestion
        // accepte le type sans autre changement. Un agent ≤ 2.3.0 IGNORE ce
        // type en silence (contrat §8) → release 2.4.0 à publier.
        'registry_list',
        // Story 36.1 (D1) — mécanisme HORS-REGISTRE `fs_acl` : ACE NTFS gérées
        // sur le poste (chirurgie DACL, service SYSTEM, portée Machine). Payload
        // EXACTEMENT 6 clés `{path, trustee, ace_type, rights, applies_to,
        // ensure}` — enums fermés de mots métier, aucun masque brut ni SDDL
        // (§7.7). Ajout ADDITIF : `ReportRequest` (Rule::in) accepte le type
        // sans autre changement. Un agent ≤ 2.5.0 IGNORE ce type EN SILENCE
        // (contrat §8 — aucun statut au rapport) → release 2.6.0 à publier.
        'fs_acl',
        // Story 36.2 (D1) — mécanisme HORS-REGISTRE `firewall` : règles pare-feu
        // Windows POSSÉDÉES PAR GROUPE (`SambaEdu-Agent`) sur le poste (service
        // SYSTEM, portée Machine). Payload `{rule_id, direction, action,
        // remote_scope, protocol, ensure}` + `remote_addresses` ssi `explicit`
        // + `ports` ssi tcp|udp (§7.8) — enums fermés de mots métier, AUCUNE
        // syntaxe netsh/SDDL. Ajout ADDITIF : `ReportRequest` (Rule::in) accepte
        // le type sans autre changement. Un agent ≤ 2.6.0 IGNORE ce type EN
        // SILENCE (contrat §8 — aucun statut au rapport) → release 2.7.0 à publier.
        'firewall',
        // Story 35.6 (D1) — mécanisme HORS-REGISTRE `privilege` : droits de
        // logon LSA `SeDeny*` gérés sur le poste (réconciliation de CONTENEUR
        // sans store — le privilège EST le conteneur, titulaires énumérables via
        // LsaEnumerateAccountsWithUserRight ; service SYSTEM, portée Machine).
        // Payload EXACTEMENT 2 clés `{privilege, accounts}` — enum FERMÉ des 5
        // droits SeDeny* (tout droit *grant* est INEXPRIMABLE : une convergence
        // « possède la liste entière » sur un grant verrouillerait la machine),
        // `accounts` = liste TRIÉE de noms Windows (jamais de SID ni de LUID,
        // §7.9). Ajout ADDITIF : `ReportRequest` (Rule::in) accepte le type sans
        // autre changement. Un agent ≤ 2.7.0 IGNORE ce type EN SILENCE (contrat
        // §8 — aucun statut au rapport) → release 2.8.0 à publier.
        'privilege',
        // Story 38.3 (D1) — nettoyage des crochets legacy SE4 du poste
        // (`legacy_cleanup`) : suppression idempotente par SCAN sans store du
        // catalogue d'artefacts legacy LOCAUX versionné DANS l'agent (blobs
        // applications-*, tâches WPKG, scripts GPO locale, helpers, autologon
        // se4install, paires Mozilla `sambaedu.default` — Q5-a VANILLA).
        // Payload EXACTEMENT 1 clé `{mozilla: "vanilla"}` (enum FERMÉ 1
        // valeur, §7.10) — le serveur GATE (capacité `legacy_hooks_cleanup`),
        // l'agent sait QUOI nettoyer (D3). Ajout ADDITIF : `ReportRequest`
        // (Rule::in) accepte le type sans autre changement. Un agent ≤ 2.8.0
        // IGNORE ce type EN SILENCE (contrat §8 — aucun statut au rapport) →
        // release 2.9.0 à publier.
        'legacy_cleanup',
    ];

    /**
     * Types acceptés AU RAPPORT SEULEMENT — jamais servis dans le contrat
     * desired-state (aucun provider, aucune maille, absents des golden files).
     *
     * Ce sont des CANAUX DE SIGNALEMENT de l'agent vers le serveur : ils
     * décrivent la santé de l'agent lui-même, pas la conformité d'une ressource
     * du poste. `RESOURCE_TYPES` reste donc la liste FERMÉE de ce que le
     * serveur SERT ; `reportableTypes()` est celle de ce qu'il ACCEPTE.
     *
     * - `agent_update` : échec du dernier cycle d'auto-update (Story 25.2,
     *   décision n° 7 — émis par `drainUpdateReportItems`).
     * - `companion` : le compagnon de session ne donne plus signe de vie alors
     *   qu'une session interactive est ouverte. Sans lui, une tâche compagnon
     *   qui échoue au lancement (ACL du binaire, droit de logon, crash) est
     *   TOTALEMENT muette côté serveur — le service SYSTEM continuant de
     *   rapporter normalement, le poste paraît sain. Diagnostiqué à la main le
     *   2026-07-20 (DACL orpheline, 0x80070005).
     *
     * @var list<string>
     */
    public const REPORT_ONLY_TYPES = [
        'agent_update',
        'companion',
    ];

    /**
     * Types acceptés à l'ingestion d'un rapport : ce que le serveur sert, PLUS
     * les canaux de signalement de l'agent. Liste fermée — un type inconnu
     * reste un 422 (un agent forgé ne gonfle pas la table).
     *
     * @return list<string>
     */
    public static function reportableTypes(): array
    {
        return [...self::RESOURCE_TYPES, ...self::REPORT_ONLY_TYPES];
    }

    /**
     * Les trois portées de l'enveloppe, dans l'ordre canonique.
     *
     * @return list<string>
     */
    public static function scopes(): array
    {
        return [
            StateScope::Machine->value,
            StateScope::Session->value,
            StateScope::MachineUser->value,
        ];
    }
}

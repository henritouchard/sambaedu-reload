<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\AgentResourceStatus;
use App\Enums\ResourceSemantics;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit du contrat v1 figé `se5.desired-state/v1` — Story 23.1 (AC1, AC2,
 * AC3, AC5).
 *
 * Garde-fous de régression sur les **golden files** normatifs
 * `tests/Fixtures/Agent/{state,report}.v1.json` : structure, énumérations,
 * cohérence des hashes et hash d'état figé. Toute dérive de canonicalisation ou
 * d'invariant de contrat casse ces tests (effet recherché — le wire format est
 * un irréversible).
 */
class ContractV1Test extends TestCase
{
    /**
     * Hash d'état figé du golden file `state.v1.json` (calculé par
     * {@see StateHasher::hashState}). Garde-fou : toute évolution du golden
     * file ou de la canonicalisation doit mettre cette valeur à jour
     * sciemment (+ bump de version, cf. règle d'évolution du contrat).
     */
    // Bumpé SCIEMMENT par la Story 27.1 (évolution MINEURE du contrat, §9) :
    // le payload `shortcuts` du golden est passé du squelette illustratif
    // (`{name, target, location}`) au payload v1 RÉEL owné par
    // `ShortcutsStateProvider` (`{name, target, args, icon, place,
    // desktop_path}`). Champ/payload ajouté = forward-compatible, pas un major.
    //
    // Re-bumpé SCIEMMENT (mode debug du poste, §9) : ajout du champ d'enveloppe
    // `debug` (bool) à côté de `ttl_seconds`. Champ ajouté = forward-compatible
    // (l'agent ignore les champs d'enveloppe inconnus) ; il entre dans le hash
    // pour que le toggle franchisse le cache 304.
    //
    // Re-bumpé SCIEMMENT par la Story 27.2 (évolution MINEURE du contrat, §9) :
    // ajout de DEUX items réels en portée `session` — `printers` (payload v1
    // `{cups_name, connection, description, location, is_default}` owné par
    // PrintersStateProvider) et `drives` (payload v1 `{letter, unc, label}` owné
    // par DrivesStateProvider). Types DÉJÀ figés §7 ; payloads ajoutés =
    // forward-compatible, pas un major. Le jumeau Go (hasher_test.go) est bumpé
    // à la même valeur (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 27.7 (évolution MINEURE du contrat, §9) :
    // le payload `shortcuts` du golden gagne `{icon_asset, icon_checksum}`
    // (icône UPLOADÉE content-addressed, AC2/AC6) ET illustre une icône uploadée
    // (nom nu `icon`). Champs AJOUTÉS = forward-compatible, pas un major. Le
    // jumeau Go (hasher_test.go::frozenStateHash) est bumpé à la même valeur
    // (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 27.8 (§9) : la clé `mode` est RETIRÉE de
    // chaque item d'état (item 5 clés → 4 : type/semantics/payload/hash —
    // convergence STRICT inconditionnelle). Le hash de chaque item ET le hash
    // d'état changent. Bumpé à l'IDENTIQUE côté Go (hasher_test.go::frozenStateHash).
    //
    // Re-bumpé SCIEMMENT par la Story 27.10 (§9) : la SALLE passe de la portée
    // session (ancien item identity `{kind, login, fullname, room}`) à la portée
    // MACHINE — nouvel item overlay `{kind:"machine", room}` (cache persistant,
    // préchargement poste+salle au logon). L'item identity session perd `room`.
    //
    // Re-bumpé SCIEMMENT par la Story 27.3 (évolution MINEURE du contrat, §9) :
    // ajout d'UN item `registry` en portée `session` — payload v1 réel
    // `{hive, path, name, type, value}` owné par les providers registry
    // (RegistryMachineStateProvider HKLM / RegistryUserStateProvider HKCU). Type
    // DÉJÀ figé §7 ; payload ajouté = forward-compatible, pas un major.
    //
    // Rebase 27.3 sur main (27.10 inclus) : le golden combine désormais l'item
    // overlay machine-scope (room) ET l'item registry session → 7 items, hash
    // d'état RECALCULÉ. Le jumeau Go (hasher_test.go::frozenStateHash) porte la
    // même valeur (test croisé NFR13 — canonicalisation équivalente PHP↔Go).
    //
    // Re-bumpé SCIEMMENT par la Story 27.3bis (évolution MINEURE du contrat, §9) :
    // ajout d'UN item `associations` en portée `session` — payload v1 réel
    // `{identifier, progid, type}` owné par AssociationsStateProvider. Le hash
    // UserChoice anti-tamper N'EST JAMAIS au payload (calculé 100 % côté agent à
    // partir du SID/temps/experience du poste). Type DÉJÀ figé §7 ; payload
    // ajouté = forward-compatible, pas un major → 8 items, hash d'état RECALCULÉ.
    // Le jumeau Go porte la même valeur (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 27.4 (évolution MINEURE du contrat, §9) :
    // ajout d'UN item `app_config` (aggregate) — payload v1 réel
    // `{app_kind, policies}` owné par AppConfigStateProvider (projection des
    // policies résolues `policies.json` Firefox/Thunderbird, story 4.8). Les
    // policies sont CONCRÈTES (jamais un id de scope/customization), sans float
    // (§4.1). Type DÉJÀ figé §7 ; payload ajouté = forward-compatible, pas un
    // major → 9 items, hash d'état RECALCULÉ. Le jumeau Go
    // (hasher_test.go::frozenStateHash) porte la même valeur (test croisé NFR13).
    //
    // Correctif post-review 2026-06-17 (review #1) : l'item `app_config` passe de
    // la portée `session` à la portée `machine` — `policies.json` est
    // machine-wide (admin-write, écrit par le service SYSTEM), résolu PAR PARC
    // (niveaux 1-4, `$user = null`). Le par-user de Firefox = le profil
    // (Mécanisme B / roaming, hors 27.4). Le déplacement de portée RECALCULE le
    // hash d'état (machine = 2 items, session = 6) ; le jumeau Go porte la même
    // valeur (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 27.5 (évolution MINEURE du contrat, §9) :
    // ajout d'UN item `applications` (aggregate) en portée `machine` — payload
    // v1 réel `{app_id, name}` owné par ApplicationsStateProvider (projection de
    // l'ensemble cible WPKG, WorkstationPackagesResolver::computePackages NON
    // CACHÉE). app_id/name CONCRETS (jamais un id de catalogue/pivot/scope),
    // strings only (§4.1). Type DÉJÀ figé §7 ; payload ajouté =
    // forward-compatible, pas un major → machine = 3 items, 10 items au total,
    // hash d'état RECALCULÉ. Le jumeau Go (hasher_test.go::frozenStateHash) porte
    // la même valeur (test croisé NFR13 — canonicalisation équivalente PHP↔Go).
    //
    // Re-bumpé (lecteurs réseau natifs, décision Henri 2026-06-29) : le
    // `DrivesStateProvider` n'émet plus un lecteur de CLASSE sur K: (qui écrasait
    // le home natif AD) mais le jeu standard FIXE {K: home `\\<se4fs>\users\<user>\`,
    // H: classes `\\<se4fs>\classes\`}. Le golden passe d'UN à DEUX items `drives`
    // (session = 7 items, 11 items au total) → hash de chaque item drives ET hash
    // d'état RECALCULÉS. Bumpé à l'IDENTIQUE côté Go (hasher_test.go::frozenStateHash).
    //
    // Re-bumpé SCIEMMENT par la Story 35.1 (évolution MINEURE du contrat, §9) :
    // champ additif `ensure ∈ present|absent` sur les items `registry` — le golden
    // gagne UN item de SUPPRESSION en portée `machine` (payload 4 clés
    // `{hive, path, name, ensure:"absent"}`, clé DNSClient\EnableMulticast de
    // `llmnr_disabled`, ni `type` ni `value`). Champ OPTIONNEL dont l'absence vaut
    // `present` : les items d'écriture existants restent BYTE-IDENTIQUES (le
    // serveur n'émet jamais `ensure:"present"` explicite) → forward-compatible,
    // pas un major. `report.v1.json` est INCHANGÉ : les items de rapport
    // `{type, status, hash[, detail]}` ne portent aucun payload — le verbe
    // `ensure` n'y a pas de surface. machine = 4 items, 12 items au total, hash
    // d'état RECALCULÉ. Le jumeau Go (hasher_test.go::frozenStateHash) porte la
    // même valeur (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 35.2 (évolution MINEURE du contrat, §9) :
    // NOUVEAU type `registry_list` (D1, listes registre à sous-valeurs indexées
    // `\1..\N`) — le golden gagne UN item en portée `machine` (conteneur
    // Forcelist Chrome de `pix_extension_forced`, payload EXACTEMENT 4 clés
    // `{hive, path, entry_type, values}`, `values` = liste ORDONNÉE de chaînes —
    // l'ordre est porteur de sens, la canonicalisation NE trie PAS les listes
    // §4). Type AJOUTÉ (constante RESOURCE_TYPES additive) = forward-compatible,
    // pas un major : un agent ≤ 2.3.0 IGNORE le type EN SILENCE (§8 — aucun
    // statut au rapport), d'où publication de release 2.4.0 obligatoire.
    // `report.v1.json` est INCHANGÉ : les items de rapport
    // `{type, status, hash[, detail]}` ne portent aucun payload et le nouveau
    // type entre dans ReportRequest via Rule::in(RESOURCE_TYPES) — zéro autre
    // changement d'ingestion. machine = 5 items, 13 items au total, hash
    // d'état RECALCULÉ. Le jumeau Go (hasher_test.go::frozenStateHash) porte la
    // même valeur (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 36.1 (évolution MINEURE du contrat, §9) :
    // NOUVEAU type `fs_acl` (D1, mécanisme HORS-REGISTRE — ACE NTFS gérées,
    // chirurgie DACL, portée Machine) — le golden gagne UN item en portée
    // `machine` (`deny list_folder folder_only` sur `C:\Program Files` pour le
    // trustee `Eleves`, payload EXACTEMENT 6 clés `{path, trustee, ace_type,
    // rights, applies_to, ensure}` — enums fermés de mots métier, aucun masque
    // brut ni SDDL). Type AJOUTÉ (constante RESOURCE_TYPES additive) =
    // forward-compatible, pas un major : un agent ≤ 2.5.0 IGNORE le type EN
    // SILENCE (§8 — aucun statut au rapport), d'où publication de release 2.6.0
    // obligatoire. `report.v1.json` est INCHANGÉ : les items de rapport
    // `{type, status, hash[, detail]}` ne portent aucun payload et le nouveau
    // type entre dans ReportRequest via Rule::in(RESOURCE_TYPES). machine = 6
    // items, 14 items au total, hash d'état RECALCULÉ. Le jumeau Go
    // (hasher_test.go::frozenStateHash) porte la même valeur (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 36.2 (évolution MINEURE du contrat, §9) :
    // NOUVEAU type `firewall` (D1, mécanisme HORS-REGISTRE — règles pare-feu
    // possédées par groupe, portée Machine) — le golden gagne UN item en portée
    // `machine` (`internet-block` : `out block internet any present`, payload
    // EXACTEMENT 6 clés `{rule_id, direction, action, remote_scope, protocol,
    // ensure}` — enums fermés de mots métier, aucune syntaxe netsh/SDDL, pas de
    // `remote_addresses`/`ports` ici). Type AJOUTÉ (constante RESOURCE_TYPES
    // additive) = forward-compatible, pas un major : un agent ≤ 2.6.0 IGNORE le
    // type EN SILENCE (§8 — aucun statut au rapport), d'où publication de release
    // 2.7.0 obligatoire (qui livre AUSSI la 2.6.0 fs_acl jamais publiée).
    // `report.v1.json` est INCHANGÉ : les items de rapport
    // `{type, status, hash[, detail]}` ne portent aucun payload et le nouveau
    // type entre dans ReportRequest via Rule::in(RESOURCE_TYPES). machine = 7
    // items, 15 items au total, hash d'état RECALCULÉ. Le jumeau Go
    // (hasher_test.go::frozenStateHash) porte la même valeur (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 35.6 (évolution MINEURE du contrat, §9) :
    // NOUVEAU type `privilege` (D1, mécanisme HORS-REGISTRE — droits de logon
    // LSA `SeDeny*` gérés, réconciliation de CONTENEUR sans store, portée
    // Machine) — le golden gagne UN item en portée `machine`
    // (`SeDenyRemoteInteractiveLogonRight` refusé au groupe `Eleves`, payload
    // EXACTEMENT 2 clés `{privilege, accounts}` — enum FERMÉ des 5 SeDeny*,
    // `accounts` = liste TRIÉE de noms Windows, jamais de SID/LUID). Type
    // AJOUTÉ (constante RESOURCE_TYPES additive) = forward-compatible, pas un
    // major : un agent ≤ 2.7.0 IGNORE le type EN SILENCE (§8 — aucun statut au
    // rapport), d'où publication de release 2.8.0 obligatoire (qui livre AUSSI
    // les 2.6.0 fs_acl et 2.7.0 firewall jamais publiées). `report.v1.json`
    // est INCHANGÉ : les items de rapport `{type, status, hash[, detail]}` ne
    // portent aucun payload et le nouveau type entre dans ReportRequest via
    // Rule::in(RESOURCE_TYPES). machine = 8 items, 16 items au total, hash
    // d'état RECALCULÉ. Le jumeau Go (hasher_test.go::frozenStateHash) porte la
    // même valeur (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 38.3 (évolution MINEURE du contrat, §9) :
    // NOUVEAU type `legacy_cleanup` (D1, nettoyage des crochets legacy SE4 du
    // poste — suppression idempotente par SCAN sans store du catalogue
    // d'artefacts legacy LOCAUX versionné DANS l'agent, portée Machine) — le
    // golden gagne UN item en portée `machine` (payload EXACTEMENT 1 clé
    // `{mozilla: "vanilla"}` — enum FERMÉ 1 valeur, trace contractuelle de la
    // décision Q5-a : traitement VANILLA des paires profiles.ini/installs.ini
    // référençant `sambaedu.default`, aucun profil forcé posé). Type AJOUTÉ
    // (constante RESOURCE_TYPES additive) = forward-compatible, pas un major :
    // un agent ≤ 2.8.0 IGNORE le type EN SILENCE (§8 — aucun statut au
    // rapport), d'où publication de release 2.9.0 obligatoire (qui livre AUSSI
    // les 2.6.0 fs_acl, 2.7.0 firewall et 2.8.0 privilege jamais publiées).
    // `report.v1.json` est INCHANGÉ : les items de rapport
    // `{type, status, hash[, detail]}` ne portent aucun payload (le `detail`
    // « artefacts supprimés » de l'AC5 utilise le champ EXISTANT du §6, déjà
    // illustré au golden) et le nouveau type entre dans ReportRequest via
    // Rule::in(RESOURCE_TYPES). machine = 9 items, 17 items au total, hash
    // d'état RECALCULÉ. Le jumeau Go (hasher_test.go::frozenStateHash) porte la
    // même valeur (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 43.3 (ttl_seconds volatil, §9) : le
    // champ `ttl_seconds` de l'enveloppe entre désormais dans
    // `StateHasher::VOLATILE_STATE_KEYS` (AC3, D6) — il dépend du CONTEXTE
    // compilé (bascule sensible ou non, {@see \App\Services\Agent\AgentTtlResolver})
    // et un changement de TTL seul ne doit pas invalider l'ETag. Le golden
    // `state.v1.json` lui-même est INCHANGÉ (le champ reste dans l'enveloppe,
    // seulement exclu du hash) : seule l'exclusion recalcule le hash d'état.
    // 17 items au total (inchangé). Le jumeau Go
    // (hasher_test.go::frozenStateHash) porte la même valeur (test croisé
    // NFR13) — AUCUN bump de `agent/shared/version.go` (HashState Go sans
    // appelant runtime, cf. Dev Agent Record de la story).
    //
    // Re-bumpé SCIEMMENT par la Story 43.2 (hint `refresh` au payload, §7.1/
    // §7.6, §9) : champ additif OPTIONNEL `refresh` (vocabulaire fermé
    // shell_notify|policy_broadcast|explorer_restart), recopié UNIQUEMENT sur
    // les payloads émis en portée session/machine_user (jamais machine/HKU) —
    // (a) l'item session `registry` existant (HideFileExt) gagne
    // `"refresh": "shell_notify"` (D7, cohérent avec le retrofit conservateur
    // D4) ; (b) AJOUT d'UN item session `registry_list` (conteneur
    // `…\Policies\Explorer\DisallowRun`, `"refresh": "policy_broadcast"`) — la
    // portée session n'avait AUCUN item registry_list avant cette story.
    // Champ additif + type DÉJÀ figé (registry_list existait depuis 35.2) =
    // forward-compatible, pas un major : un agent ≤ 2.9.0 ignore le champ
    // inconnu SANS ERREUR (§9, champ ajouté). session = 8 items, 18 items au
    // total, hash d'état RECALCULÉ. Le jumeau Go
    // (hasher_test.go::frozenStateHash) porte la même valeur (test croisé
    // NFR13) — AUCUN bump de `agent/shared/version.go` (le mécanisme agent
    // 2.10.0 qui LIT `payload["refresh"]` est déjà livré par la 43.1 mergée ;
    // seul le hash gelé de TEST bouge, cf. Dev Agent Record de la story).
    //
    // Re-bumpé SCIEMMENT par la Story 35.7 (champ `writer` au payload, §7.1/
    // §7.6, §9) : champ additif OPTIONNEL `writer` (enum fermé, seule valeur
    // publiée `"system"`) déclarant que l'item est appliqué par le SERVICE
    // SYSTEM dans `HKU\<SID>` de la session du contexte — jamais par le
    // compagnon (trees `HKCU\…\Policies\*` en lecture seule pour l'utilisateur
    // standard sur poste joint au domaine). Les DEUX items session concernés
    // sont MODIFIÉS (jamais ajoutés — comptages 9/8/1 préservés) : (a) l'item
    // `registry` session devient le flag `…\Policies\Explorer!DisallowRun = 1`
    // marqué `writer: "system"` (la forme réelle émise post-retrofit
    // 2026_07_13_100000) ; (b) l'item `registry_list` session (conteneur
    // `…\Policies\Explorer\DisallowRun`) gagne `writer: "system"`. Les deux
    // PERDENT leur hint `refresh` (exclusion mutuelle refresh/writer, piège
    // n°6 de la story : `refresh` n'est émis QUE sur les items appliqués par
    // le compagnon) — la couverture de la forme `refresh` vit dans les tests
    // dédiés providers/handlers (43.1/43.2), plus dans le golden. Champ
    // additif = forward-compatible, pas un major : un agent ≤ 2.11.x IGNORE
    // le marqueur EN SILENCE (compagnon : « Accès refusé » statu quo ;
    // service : rien d'appliqué) → publier la release 2.12.0 AVANT le
    // retrofit. `report.v1.json` INCHANGÉ (le rapport ne porte pas `writer`).
    // 18 items au total (inchangé), hash d'état RECALCULÉ. Le jumeau Go
    // (hasher_test.go::frozenStateHash) porte la même valeur (test croisé
    // NFR13).
    // Re-bumpé SCIEMMENT par la Story 36.5 (évolution MINEURE du contrat, §9) :
    // AJOUT d'UN item `app_profile` (§7.11, mécanisme HORS-REGISTRE — redirection
    // du profil applicatif Firefox vers le home réseau, aggregate) en portée
    // SESSION → session = 9 items, 19 items au total, hash d'état RECALCULÉ. Type
    // AJOUTÉ (constante RESOURCE_TYPES additive) = forward-compatible, pas un
    // major : un binaire ≤ 2.12.4 IGNORE le type EN SILENCE (§8 — aucun statut au
    // rapport), d'où publication de release 2.13.0 obligatoire. Le type entre dans
    // ReportRequest via Rule::in(RESOURCE_TYPES). Le jumeau Go
    // (hasher_test.go::frozenStateHash) porte la même valeur (test croisé NFR13).
    // Re-bumpé SCIEMMENT par la Story 27.21 (arbitrage « option A » de la review,
    // champ additif `desktop_sweep_paths` au payload `shortcuts`, §7/§9) :
    // l'unique item `shortcuts` (portée machine_user) gagne la LISTE des
    // emplacements Bureau que l'agent doit BALAYER — notion DISTINCTE de
    // `desktop_path` (où il POSE). Le golden illustre un parc `shared_local`,
    // seul environnement à porter les DEUX emplacements
    // (`[\\<se4fs>\users\<user>\Bureau\, %USERPROFILE%\Desktop\]`) ; un parc
    // perdir/nomade n'en porterait qu'un (le local) — il n'a aucune autorité sur
    // le Bureau réseau, PARTAGÉ entre tous les postes de l'utilisateur.
    // Champ additif = forward-compatible, pas un major : un agent ≤ 2.13.0
    // IGNORE le champ inconnu SANS ERREUR (§9) et conserve son balayage LOCAL
    // (au pire des fantômes bénins à l'ancien emplacement). ATTENTION : la
    // 2.14.0 est une EXCEPTION — son « balayage précédent » est le balayage
    // réseau INCONDITIONNEL du finding #1 (guerre de suppression inter-postes),
    // d'où sa répudiation (jamais construire ni publier, cf. version.go). La
    // cible est 2.15.0. 19 items au total (INCHANGÉ, le
    // champ est ajouté à un item existant), hash d'item RECALCULÉ
    // (1ff7dadf… → e3fa179d…) et hash d'état RECALCULÉ. Le jumeau Go
    // (hasher_test.go::frozenStateHash) porte la même valeur (test croisé NFR13).
    private const FROZEN_STATE_HASH = '34b4f15b5a9e7cf5f0883d24c52bc6deb5b4d65582eee1c6502c89264b28b869';

    private StateHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new StateHasher;
    }

    #[Test]
    public function state_golden_file_has_valid_envelope_and_scopes(): void
    {
        $state = $this->loadGolden('state.v1.json');

        $this->assertSame(StateContract::SCHEMA, $state['schema']);
        $this->assertArrayHasKey('generated_at', $state);
        $this->assertArrayHasKey('ttl_seconds', $state);
        $this->assertIsInt($state['ttl_seconds']);

        // Mode debug du poste — champ d'enveloppe (bool), à côté de ttl_seconds.
        $this->assertArrayHasKey('debug', $state);
        $this->assertIsBool($state['debug']);

        // Les trois portées sont présentes et sont des listes ordonnées
        // (une map changerait l'ordre canonique via le tri des clés).
        foreach (StateContract::scopes() as $scope) {
            $this->assertArrayHasKey($scope, $state, "portée manquante: {$scope}");
            $this->assertIsArray($state[$scope]);
            $this->assertTrue(array_is_list($state[$scope]), "portée {$scope} : doit être une liste, pas une map");
        }

        // Story 27.10 — la portée `machine` porte désormais l'item overlay
        // `{kind:"machine", room}` (salle préchargée au logon) : elle n'est plus
        // le « tableau vide » illustratif. Le contrat tolère toujours une portée
        // vide (les trois sont des listes, éventuellement vides — vérifié
        // ci-dessus) ; le golden illustre maintenant les trois portées peuplées.
        $this->assertNotSame([], $state[StateContract::SCOPE_MACHINE]);
        $this->assertSame('overlay', $state[StateContract::SCOPE_MACHINE][0]['type']);
        $this->assertSame('machine', $state[StateContract::SCOPE_MACHINE][0]['payload']['kind']);
    }

    #[Test]
    public function every_state_item_has_the_four_contract_keys_and_valid_enums(): void
    {
        $state = $this->loadGolden('state.v1.json');

        $itemCount = 0;
        foreach (StateContract::scopes() as $scope) {
            foreach ($state[$scope] as $item) {
                $itemCount++;

                // AC1 — exactement les 4 clés du contrat, ni plus ni moins
                // (Story 27.8 : la clé `mode` est retirée — STRICT inconditionnel).
                $this->assertSame(
                    ['type', 'semantics', 'payload', 'hash'],
                    array_keys($item),
                    "item de portée {$scope} : clés non conformes",
                );

                $this->assertIsString($item['type']);
                $this->assertNotNull(ResourceSemantics::tryFrom($item['semantics']));
                $this->assertIsArray($item['payload']);
            }
        }

        $this->assertGreaterThan(0, $itemCount, 'le golden state doit porter des items');
    }

    #[Test]
    public function each_state_item_hash_matches_state_hasher(): void
    {
        $state = $this->loadGolden('state.v1.json');

        foreach (StateContract::scopes() as $scope) {
            foreach ($state[$scope] as $item) {
                $this->assertSame(
                    $this->hasher->hashItem($item),
                    $item['hash'],
                    "hash incohérent pour l'item {$item['type']} (portée {$scope})",
                );
            }
        }
    }

    #[Test]
    public function state_hash_is_frozen_regression_guard(): void
    {
        $state = $this->loadGolden('state.v1.json');

        $this->assertSame(
            self::FROZEN_STATE_HASH,
            $this->hasher->hashState($state),
            'Le hash du golden state a changé : dérive de canonicalisation ou '
            .'évolution de contrat non versionnée.',
        );
    }

    #[Test]
    public function report_golden_file_has_valid_structure_and_three_statuses(): void
    {
        $report = $this->loadGolden('report.v1.json');

        $this->assertSame(StateContract::SCHEMA, $report['schema']);
        $this->assertArrayHasKey('generated_at', $report);
        $this->assertArrayHasKey('agent_version', $report);
        $this->assertIsString($report['agent_version']);

        // Identité du poste (gap 2) : hostname + uuid (contrat §6).
        $this->assertArrayHasKey('workstation', $report);
        $this->assertArrayHasKey('hostname', $report['workstation']);
        $this->assertArrayHasKey('uuid', $report['workstation']);

        $this->assertIsArray($report['items']);

        $statuses = [];
        foreach ($report['items'] as $item) {
            $this->assertArrayHasKey('type', $item);
            $this->assertArrayHasKey('status', $item);
            $this->assertArrayHasKey('hash', $item);

            $status = AgentResourceStatus::tryFrom($item['status']);
            $this->assertNotNull($status, "statut inconnu: {$item['status']}");
            $statuses[$item['status']] = true;

            // AC3 — un statut `error` doit porter un `detail` non vide.
            if ($status === AgentResourceStatus::Error) {
                $this->assertArrayHasKey('detail', $item);
                $this->assertNotSame('', $item['detail']);
            }
        }

        // AC3 — les trois statuts sont illustrés (Story 27.8 : `drifted_allowed`
        // retiré → compliant, drift, error).
        foreach (AgentResourceStatus::cases() as $case) {
            $this->assertArrayHasKey(
                $case->value,
                $statuses,
                "statut non illustré dans le golden report: {$case->value}",
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadGolden(string $name): array
    {
        $path = base_path("tests/Fixtures/Agent/{$name}");

        return json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}

package shared

import (
	"fmt"
	"sort"
)

// Passe SYSTEM PAR-SESSION (Story 35.7, contrat §7.1/§7.6 — champ `writer`).
//
// Les capacités Session écrivant sous `HKCU\…\Policies\*` (blocage
// d'exécutables, regedit) échouent en « Accès refusé » quand le COMPAGNON
// (contexte user) tente de les écrire : sur machine jointe au domaine, TOUT le
// sous-arbre `HKCU\…\Policies\*` — y compris `CurrentVersion\Policies` — est
// en lecture seule pour l'utilisateur standard (durcissement ACL
// anti-contournement de GPO). Le serveur marque ces items `writer: "system"`
// (par CLÉ) : le SERVICE SYSTEM les applique dans `HKU\<SID de la session dont
// provient le contrat>` — comme une GPO user policy ; le compagnon les ÉCARTE.
//
// FRONTIÈRE STRUCTURANTE avec le fan-out HKU de 35.3 (piège n°3) :
//   - 35.3 (`hive: HKU`, portée machine) : UNE cible logique appliquée à
//     `.DEFAULT` + TOUTES les ruches chargées, AUCUN ciblage user ;
//   - 35.7 (`writer: system`, portée session/machine_user, hive HKCU) :
//     UN SID — la ruche de LA session ciblée, les overrides UserGroup/User
//     ATTEIGNENT l'item (state par-session `GET /state?user=`).
// La traduction HKCU → `HKU\<SID>` est un DÉCORATEUR d'ops (sessionHiveOps),
// invisible des handlers : RegistryHandler/RegistryListHandler sont réutilisés
// TELS QUELS (réconciliation de conteneur D3/35.2 incluse), `engine.go` et les
// parseurs restent byte-identiques — la partition vit AVANT le moteur (D4).
//
// Le routage se décide sur le CHAMP `writer` du payload brut, JAMAIS par
// path-sniffing (piège n°2 : le serveur DÉCLARE, l'agent ROUTE).

// writerSystem : seule valeur publiée de l'enum fermé `writer` (§7.1). La
// passe SYSTEM sélectionne sur ÉGALITÉ STRICTE ; le compagnon skippe sur
// PRÉSENCE du champ (une valeur future inconnue est skippée par les DEUX
// acteurs sans erreur — forward-compat, iso « type inconnu ignoré » §8).
const writerSystem = "system"

// payloadWriter : extrait le champ additif `writer` du payload BRUT d'un item
// (générique tous types — le filtre vit avant le moteur, piège n°5).
// present=false si le payload n'est pas un objet ou ne porte pas le champ ;
// une valeur non-string rend present=true avec writer="" (champ présent mais
// hors enum : skippé par les deux acteurs, défensif).
func payloadWriter(item StateItem) (writer string, present bool) {
	payload, ok := item.Payload.(map[string]any)
	if !ok || payload == nil {
		return "", false
	}
	raw, exists := payload["writer"]
	if !exists {
		return "", false
	}
	writer, _ = raw.(string)

	return writer, true
}

// SplitSystemWriterItems : partition des items d'une portée par EXÉCUTANT
// (D4, piège n°5) — AVANT le moteur, sur le payload brut :
//   - companion : items SANS champ `writer` (l'exécutant par défaut de la
//     portée reste le compagnon — chemin historique byte-identique) ;
//   - system : items `writer == "system"` STRICTEMENT (passe SYSTEM
//     par-session).
//
// Un item porteur d'une valeur `writer` INCONNUE (future) ne tombe dans AUCUNE
// des deux listes : le compagnon skippe sur PRÉSENCE du champ, la passe SYSTEM
// sélectionne sur ÉGALITÉ stricte — skip silencieux des deux côtés
// (forward-compat).
func SplitSystemWriterItems(items []StateItem) (companion, system []StateItem) {
	companion = make([]StateItem, 0, len(items))
	for _, item := range items {
		writer, present := payloadWriter(item)
		if !present {
			companion = append(companion, item)

			continue
		}
		if writer == writerSystem {
			system = append(system, item)
		}
		// Valeur inconnue : ni compagnon, ni SYSTEM (skip silencieux).
	}

	return companion, system
}

// sessionHiveOps : DÉCORATEUR d'ops registre par session (D5) — traduit la
// cible LOGIQUE `hive: HKCU` (la ruche de l'utilisateur ciblé, telle que le
// serveur l'émet) vers la cible PHYSIQUE `{hive: "HKU", path: "<SID>\<path>"}`
// que le service SYSTEM peut écrire. Type PUR (testable hôte) ; les handlers
// ne voient JAMAIS la traduction (leurs specs restent HKCU — l'identité
// logique et les logs sont ceux du payload).
//
// SONDE RACE-LOGOFF HÉRITÉE GRATUITEMENT (piège n°4, review 35.3 #1) : l'op
// `Write` Windows sonde déjà la racine de fan-out HKU (premier segment du
// path = ici le SID) avant d'écrire — ruche démontée entre l'énumération et
// l'écriture ⇒ no-op nil, JAMAIS de clé orpheline matérialisée sous
// HKEY_USERS ; la session disparaît de l'énumération au cycle suivant. Rien
// n'est ré-implémenté ici.
//
// Rafraîchissement shell : les handlers accumulent leur besoin sur le spec
// HKCU (isUserHive vrai) — SANS EFFET : la passe SYSTEM ne consomme JAMAIS
// TakeRefreshRequest (iso MachineEngine — aucun geste depuis la session 0) et
// les handlers sont instanciés PAR PASSE (l'accumulation meurt avec eux).
// L'effet est au LOGON SUIVANT (comportement GPO user policy, AC7).
type sessionHiveOps struct {
	ops RegistryOps
	sid string
}

// translate : HKCU → (HKU, <SID>\<path>). Toute autre ruche passe VERBATIM
// (défensif — les items marqués sont HKCU par construction, guard serveur).
func (o *sessionHiveOps) translate(hive, path string) (string, string) {
	if isUserHive(hive) {
		return "HKU", o.sid + `\` + path
	}

	return hive, path
}

func (o *sessionHiveOps) Read(hive, path, name string) (RegistryValue, bool, error) {
	h, p := o.translate(hive, path)

	return o.ops.Read(h, p, name)
}

func (o *sessionHiveOps) Write(spec RegistrySpec) error {
	spec.Hive, spec.Path = o.translate(spec.Hive, spec.Path)

	return o.ops.Write(spec)
}

func (o *sessionHiveOps) Delete(hive, path, name string) error {
	h, p := o.translate(hive, path)

	return o.ops.Delete(h, p, name)
}

func (o *sessionHiveOps) ValueNames(hive, path string) ([]string, error) {
	h, p := o.translate(hive, path)

	return o.ops.ValueNames(h, p)
}

// UserHives : ERREUR FRANCHE (D5) — jamais appelé en contexte par-session.
// Les items marqués restent `hive: HKCU` par construction (le guard serveur
// refuse `writer` sur HKU) : un appel signifierait qu'un item HKU a atteint
// la passe par-session — le fan-out 35.3 n'est PAS ce chemin (piège n°3).
func (o *sessionHiveOps) UserHives() ([]string, error) {
	return nil, fmt.Errorf("UserHives jamais appelé en contexte par-session (items HKCU par construction — le fan-out multi-ruches 35.3 n'est pas ce chemin)")
}

// convergeSessionSystem : passe SYSTEM par-session (D6) — pour CHAQUE session
// de la DERNIÈRE énumération WTS (celle de fetchSessionStates, `activeSIDs` —
// jamais de second appel, piège n°12), applique les items `writer: "system"`
// des portées session + machine_user du cache `cache\sessions\<SID>\state.json`
// dans `HKU\<SID>` via le décorateur d'ops.
//
// Deux déclencheurs, UN seul code (décision 24.3 n°4) : le cycle du service
// (après fetchSessionStates — verdicts drainés au POST /report du cycle via
// machineReportItems + MergeReportItemsByType, piège n°9) ET la tâche
// at-logon `agent.exe session-fetch` (converge SANS rapporter — pas de canal
// POST ; le cycle du service re-testera, level-triggered).
//
// Invariants :
//   - SessionSystemOps nil = passe INERTE (tests hôte, console de debug,
//     plateforme sans registre — patron MachineEngine) ;
//   - quarantaine = passe SAUTÉE (aucun traitement d'état — le flag peut
//     aussi être TOMBÉ pendant le fetch, garde locale) ;
//   - fetch en échec/offline : la passe applique sur le DERNIER cache
//     existant (level-triggered, iso compagnon) ; cache absent = skip ;
//   - applied-state PAR SID (`cache\sessions\<SID>\applied-state.json`,
//     piège n°8) : ni l'applied-state machine, ni le per-user du compagnon —
//     sans lui chaque cycle serait un « premier passage §5 » et le drift ne
//     serait jamais rapporté. Écrit par SYSTEM dans le répertoire per-SID
//     existant (ACL héritée <SID>:R — lecture user inoffensive :
//     hashes/timestamps opaques, iso applied-state machine) ;
//   - isolation : une session en échec n'empêche ni les autres sessions ni le
//     cycle (best-effort loggé, iso convergeMachine ; l'isolation par type
//     vit déjà dans le moteur) ;
//   - concurrence at-logon ⟷ cycle (Story 35.7 review #3) : les DEUX
//     déclencheurs peuvent converger le MÊME SID simultanément (le service
//     in-process et le processus éphémère `session-fetch` sont distincts). Le
//     double-apply est ASSUMÉ et sûr, PAS verrouillé (pas de sur-conception) :
//     WriteAppliedState est atomique (temp+rename, jamais de fichier corrompu),
//     l'application registre est STRICT/idempotente (re-poser la même valeur =
//     no-op), et le pire cas est un travail redondant + un lost-update bénin
//     sur l'applied-state per-SID que le cycle suivant re-teste (level-triggered).
func (a *Agent) convergeSessionSystem() {
	if a.SessionSystemOps == nil {
		return // plateforme sans ops registre (tests hôte, console) : inerte.
	}
	if a.quarantined {
		return // quarantaine : plus AUCUN traitement d'état.
	}
	if len(a.activeSIDs) == 0 {
		return // aucune session vivante (ou énumération indisponible).
	}

	// Ordre déterministe des sessions (logs stables).
	sids := make([]string, 0, len(a.activeSIDs))
	for sid := range a.activeSIDs {
		sids = append(sids, sid)
	}
	sort.Strings(sids)

	for _, sid := range sids {
		a.convergeSessionSid(sid)
	}
}

// convergeSessionSid : la passe d'UNE session — lecture du cache per-SID,
// partition D4, moteur registry+registry_list sur ops décorées D5,
// applied-state per-SID, verdicts vers machineReportItems.
func (a *Agent) convergeSessionSid(sid string) {
	raw, err := a.Store.ReadSessionStateCache(sid)
	if err != nil {
		// Cache jamais écrit (premier fetch en échec / session toute neuve) :
		// rien à appliquer — le prochain fetch le posera.
		a.Log.Debugf("Passe SYSTEM par-session %s sautée : cache absent (%v).", sid, err)

		return
	}
	state, err := ParseState(raw)
	if err != nil {
		a.Log.Warningf("Passe SYSTEM par-session %s sautée : cache illisible (%v).", sid, err)

		return
	}

	// Mêmes portées que le compagnon (session + machine_user), ordre serveur —
	// la partition retient le SOUS-ENSEMBLE writer=system (piège n°5).
	items := ItemsFromScope(state.Session, a.Log)
	items = append(items, ItemsFromScope(state.MachineUser, a.Log)...)
	_, system := SplitSystemWriterItems(items)
	if len(system) == 0 {
		return // aucun item délégué : rien à faire (contrat §8 — pas de purge).
	}

	// Handlers instanciés PAR PASSE (l'accumulation refresh éventuelle meurt
	// avec eux — jamais consommée : aucun geste depuis la session 0) sur les
	// ops RÉELLES décorées par LE SID de cette session (ciblage un-SID, D5).
	ops := &sessionHiveOps{ops: a.SessionSystemOps, sid: sid}
	engine := &Engine{
		Handlers: map[string]Handler{
			"registry":      &RegistryHandler{Ops: ops, Log: a.Log},
			"registry_list": &RegistryListHandler{Ops: ops, Log: a.Log},
		},
		Log: a.Log,
	}

	// Dernier-appliqué PAR SID (piège n°8) — corrompu = repart sans mémoire
	// (premier passage §5, jamais interprété comme une dérive humaine).
	applied, corrupted := ReadAppliedState(a.Store.SessionAppliedStatePath(sid))
	if corrupted {
		a.Log.Warningf("applied-state par-session %s corrompu : repart sans mémoire (premier passage §5).", sid)
	}

	reportItems := engine.RunPass(system, applied)

	// Écriture atomique dans le répertoire per-SID EXISTANT (créé/ACLé par le
	// fetch — le fichier hérite de l'ACL <SID>:R, jamais de ré-ACL).
	if err := WriteAppliedState(a.Store.SessionAppliedStatePath(sid), applied); err != nil {
		a.Log.Warningf("Persistance de l'applied-state par-session %s en échec : %v", sid, err)
	}

	// Verdicts vers le rapport du cycle service (piège n°9) : rejoignent les
	// items machine + drops compagnon — MergeReportItemsByType (pire statut
	// gagne) préserve l'unicité des types §6. La tâche at-logon accumule sans
	// jamais poster (processus éphémère sans canal POST).
	a.machineReportItems = append(a.machineReportItems, reportItems...)

	a.Log.Infof("Passe SYSTEM par-session %s : %d item(s) writer=system, %d verdict(s).",
		sid, len(system), len(reportItems))
}

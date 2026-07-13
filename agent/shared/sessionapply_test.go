package shared

import (
	"fmt"
	"os"
	"strings"
	"testing"
)

// Tests de la passe SYSTEM PAR-SESSION (Story 35.7, AC3/AC4) : partition par
// exécutant (SplitSystemWriterItems), décorateur d'ops un-SID (sessionHiveOps)
// et orchestration convergeSessionSystem — fake RegistryOps RÉUTILISÉ
// (handler_registry_test.go), jamais dupliqué.

const (
	applySidA = "S-1-5-21-1111111111-2222222222-3333333333-1001"
	applySidB = "S-1-5-21-1111111111-2222222222-3333333333-2002"
)

// Items JSON (payloads §7.1/§7.6) — les identités reprennent la cible métier
// réelle (blocked_executables / registry_editing_disabled re-routées).
const (
	itemFlagWriter = `{"type":"registry","semantics":"exclusive","hash":"flag-h",` +
		`"payload":{"hive":"HKCU","path":"Software\\P\\Explorer","name":"DisallowRun","type":"REG_DWORD","value":1,"writer":"system"}}`
	itemRegeditWriter = `{"type":"registry","semantics":"exclusive","hash":"regedit-h",` +
		`"payload":{"hive":"HKCU","path":"Software\\P\\System","name":"DisableRegistryTools","type":"REG_DWORD","value":1,"writer":"system"}}`
	itemFlagAbsentWriter = `{"type":"registry","semantics":"exclusive","hash":"flag-off-h",` +
		`"payload":{"hive":"HKCU","path":"Software\\P\\Explorer","name":"DisallowRun","ensure":"absent","writer":"system"}}`
	itemListWriter = `{"type":"registry_list","semantics":"exclusive","hash":"list-h",` +
		`"payload":{"hive":"HKCU","path":"Software\\P\\Explorer\\DisallowRun","entry_type":"REG_SZ","values":["cmd.exe"],"writer":"system"}}`
	itemCompanionPlain = `{"type":"registry","semantics":"exclusive","hash":"plain-h",` +
		`"payload":{"hive":"HKCU","path":"Software\\X\\Advanced","name":"Hidden","type":"REG_DWORD","value":1}}`
	itemUnknownWriter = `{"type":"registry","semantics":"exclusive","hash":"unknown-h",` +
		`"payload":{"hive":"HKCU","path":"Software\\P\\Future","name":"K","type":"REG_DWORD","value":1,"writer":"companion_v2"}}`
)

func sessionEnvelope(items ...string) string {
	return `{"schema":"se5.desired-state/v1","generated_at":"2026-07-13T08:00:00+00:00","ttl_seconds":3600,"machine":[],"session":[` +
		strings.Join(items, ",") + `],"machine_user":[]}`
}

// newSessionApplyAgent : Agent minimal pour la passe par-session — Store de
// test, ops injectées, SID vivants renseignés (comme fetchSessionStates le
// ferait), caches per-SID écrits.
func newSessionApplyAgent(t *testing.T, ops RegistryOps, caches map[string]string) *Agent {
	t.Helper()
	store := newTestStore(t)
	agent := &Agent{
		Store:            store,
		Log:              &Logger{},
		SessionSystemOps: ops,
		activeSIDs:       map[string]bool{},
	}
	for sid, body := range caches {
		agent.activeSIDs[sid] = true
		if err := store.WriteSessionStateCache(sid, []byte(body), `"e"`, nil); err != nil {
			t.Fatal(err)
		}
	}

	return agent
}

// --- Partition D4 (SplitSystemWriterItems) -----------------------------------

func TestSplitSystemWriterItemsPartitionsByExecutor(t *testing.T) {
	state, err := ParseState([]byte(sessionEnvelope(itemFlagWriter, itemCompanionPlain, itemUnknownWriter, itemListWriter)))
	if err != nil {
		t.Fatal(err)
	}
	items := ItemsFromScope(state.Session, nil)

	companion, system := SplitSystemWriterItems(items)

	// Compagnon : UNIQUEMENT l'item sans champ writer (présence = skip,
	// valeur inconnue incluse — forward-compat, piège n°5).
	if len(companion) != 1 || companion[0].Hash != "plain-h" {
		t.Fatalf("compagnon : seul l'item non marqué attendu, got %+v", companion)
	}
	// SYSTEM : égalité STRICTE writer == "system" — la valeur future inconnue
	// ne tombe dans AUCUNE liste.
	if len(system) != 2 || system[0].Hash != "flag-h" || system[1].Hash != "list-h" {
		t.Fatalf("system : les 2 items writer=system attendus (ordre serveur), got %+v", system)
	}
}

func TestSplitSystemWriterItemsNonStringWriterSkippedByBoth(t *testing.T) {
	// Champ writer présent mais non-string (payload dégénéré) : skippé par
	// les DEUX acteurs, jamais une erreur.
	items := []StateItem{{
		Type: "registry", Hash: "h",
		Payload: map[string]any{"hive": "HKCU", "path": `P`, "name": "N", "type": "REG_DWORD", "value": 1, "writer": 42},
	}}

	companion, system := SplitSystemWriterItems(items)
	if len(companion) != 0 || len(system) != 0 {
		t.Fatalf("writer non-string : skip des deux côtés attendu, got companion=%d system=%d", len(companion), len(system))
	}
}

// --- Décorateur D5 (sessionHiveOps) ------------------------------------------

func TestSessionHiveOpsTranslatesHkcuToTargetSidOnly(t *testing.T) {
	fake := newFakeRegistryOps()
	ops := &sessionHiveOps{ops: fake, sid: applySidA}

	if err := ops.Write(RegistrySpec{Hive: "HKCU", Path: `Software\P\Explorer`, Name: "DisallowRun",
		Value: RegistryValue{Kind: "REG_DWORD", Int: 1}}); err != nil {
		t.Fatal(err)
	}

	want := keyID("HKU", applySidA+`\Software\P\Explorer`, "DisallowRun")
	if _, ok := fake.values[want]; !ok {
		t.Fatalf("écriture attendue dans HKU\\<SID>\\… (%s), values=%v", want, fake.values)
	}
	// Lecture décorée symétrique (Read voit ce que Write a posé).
	v, present, err := ops.Read("HKCU", `Software\P\Explorer`, "DisallowRun")
	if err != nil || !present || v.Int != 1 {
		t.Fatalf("Read décoré : present=%v v=%+v err=%v", present, v, err)
	}
}

func TestSessionHiveOpsUserHivesIsAHardError(t *testing.T) {
	// D5 : UserHives = erreur FRANCHE — le fan-out multi-ruches 35.3 n'est
	// jamais le chemin par-session (items HKCU par construction).
	ops := &sessionHiveOps{ops: newFakeRegistryOps(), sid: applySidA}
	if _, err := ops.UserHives(); err == nil {
		t.Fatal("UserHives en contexte par-session : erreur franche attendue")
	}
}

// --- Ciblage UN-SID (AC4 — distinction 35.3 prouvée) --------------------------

func TestSessionApplyTargetsOnlyTheSidOfEachContract(t *testing.T) {
	// 2 sessions aux contrats DIFFÉRENTS ⇒ chaque ruche ne reçoit QUE ses
	// items — jamais .DEFAULT, jamais la ruche de l'autre.
	ops := newFakeRegistryOps()
	agent := newSessionApplyAgent(t, ops, map[string]string{
		applySidA: sessionEnvelope(itemFlagWriter),
		applySidB: sessionEnvelope(itemRegeditWriter),
	})

	agent.convergeSessionSystem()

	wantA := keyID("HKU", applySidA+`\Software\P\Explorer`, "DisallowRun")
	wantB := keyID("HKU", applySidB+`\Software\P\System`, "DisableRegistryTools")
	if _, ok := ops.values[wantA]; !ok {
		t.Fatalf("ruche A : flag attendu (%s), values=%v", wantA, ops.values)
	}
	if _, ok := ops.values[wantB]; !ok {
		t.Fatalf("ruche B : DisableRegistryTools attendu (%s), values=%v", wantB, ops.values)
	}
	if len(ops.values) != 2 {
		t.Fatalf("EXACTEMENT 2 écritures ciblées attendues (jamais .DEFAULT, jamais de croisement), values=%v", ops.values)
	}
	for id := range ops.values {
		if strings.Contains(id, `.default`) {
			t.Fatalf("JAMAIS .DEFAULT en passe par-session (distinction 35.3) : %s", id)
		}
	}
	// Verdicts des DEUX sessions accumulés pour le rapport du cycle.
	if len(agent.machineReportItems) != 2 {
		t.Fatalf("2 verdicts attendus (un par session), got %+v", agent.machineReportItems)
	}
}

// --- STRICT re-drift à travers le moteur + applied-state per-SID (AC4) --------

func TestSessionApplyStrictRedriftThroughEngine(t *testing.T) {
	// Iso TestRegistryAbsentThroughEngineStrictRedrift, mais À TRAVERS la
	// passe par-session : drift + écriture au 1er cycle, compliant stable au
	// 2e, dérive externe RE-IMPOSÉE au 3e (policy STRICT).
	ops := newFakeRegistryOps()
	agent := newSessionApplyAgent(t, ops, map[string]string{
		applySidA: sessionEnvelope(itemFlagWriter),
	})
	target := keyID("HKU", applySidA+`\Software\P\Explorer`, "DisallowRun")

	// Cycle 1 : valeur absente → drift + écriture + applied-state per-SID.
	agent.convergeSessionSystem()
	if len(agent.machineReportItems) != 1 || agent.machineReportItems[0].Status != "drift" {
		t.Fatalf("cycle 1 : drift attendu, got %+v", agent.machineReportItems)
	}
	if ops.values[target].Int != 1 {
		t.Fatalf("cycle 1 : écriture ciblée attendue, values=%v", ops.values)
	}
	appliedPath := agent.Store.SessionAppliedStatePath(applySidA)
	if raw, err := os.ReadFile(appliedPath); err != nil || !strings.Contains(string(raw), "registry") {
		t.Fatalf("applied-state per-SID attendu (%s) : %q %v", appliedPath, raw, err)
	}

	// Cycle 2 : stable → compliant, zéro écriture (idempotence).
	agent.machineReportItems = nil
	ops.writeCnt = 0
	agent.convergeSessionSystem()
	if len(agent.machineReportItems) != 1 || agent.machineReportItems[0].Status != "compliant" {
		t.Fatalf("cycle 2 : compliant attendu, got %+v", agent.machineReportItems)
	}
	if ops.writeCnt != 0 {
		t.Fatalf("cycle 2 : zéro écriture attendue, got %d", ops.writeCnt)
	}

	// Dérive EXTERNE (autre outil réécrit la valeur) → re-drift + re-imposition.
	ops.values[target] = RegistryValue{Kind: "REG_DWORD", Int: 0}
	agent.machineReportItems = nil
	agent.convergeSessionSystem()
	if len(agent.machineReportItems) != 1 || agent.machineReportItems[0].Status != "drift" {
		t.Fatalf("cycle 3 : re-drift attendu (STRICT), got %+v", agent.machineReportItems)
	}
	if ops.values[target].Int != 1 {
		t.Fatalf("cycle 3 : valeur re-imposée attendue, got %+v", ops.values[target])
	}
}

// --- ensure:absent + réconciliation registry_list dans la ruche ciblée (AC4) --

func TestSessionApplyEnsureAbsentAndListReconciliationInTargetHive(t *testing.T) {
	ops := newFakeRegistryOps()
	// État réel de la ruche ciblée : flag présent (à supprimer), conteneur
	// avec une entrée canon divergente + une surnuméraire + une voisine non
	// numérique (INTOUCHABLE).
	flagID := keyID("HKU", applySidA+`\Software\P\Explorer`, "DisallowRun")
	container := applySidA + `\Software\P\Explorer\DisallowRun`
	ops.values[flagID] = RegistryValue{Kind: "REG_DWORD", Int: 1}
	ops.values[keyID("HKU", container, "1")] = RegistryValue{Kind: "REG_SZ", Str: "powershell.exe"} // divergente
	ops.values[keyID("HKU", container, "7")] = RegistryValue{Kind: "REG_SZ", Str: "rogue.exe"}      // surnuméraire
	ops.values[keyID("HKU", container, "Comment")] = RegistryValue{Kind: "REG_SZ", Str: "keep"}     // non numérique

	agent := newSessionApplyAgent(t, ops, map[string]string{
		applySidA: sessionEnvelope(itemFlagAbsentWriter, itemListWriter),
	})
	agent.convergeSessionSystem()

	// Flag supprimé (ensure:absent dans la ruche ciblée).
	if _, ok := ops.values[flagID]; ok {
		t.Fatalf("flag DisallowRun : suppression attendue (ensure:absent), values=%v", ops.values)
	}
	// Conteneur réconcilié (D3/35.2) : canon "1"=cmd.exe, surnuméraire
	// purgée, voisine non numérique INTOUCHÉE.
	if got := ops.values[keyID("HKU", container, "1")]; got.Str != "cmd.exe" {
		t.Fatalf("entrée canon 1 : cmd.exe attendu, got %+v", got)
	}
	if _, ok := ops.values[keyID("HKU", container, "7")]; ok {
		t.Fatal("entrée surnuméraire 7 : purge attendue")
	}
	if got := ops.values[keyID("HKU", container, "Comment")]; got.Str != "keep" {
		t.Fatalf("valeur non numérique voisine : JAMAIS touchée, got %+v", got)
	}
	// Deux verdicts (un par type), pour le rapport du cycle.
	if len(agent.machineReportItems) != 2 {
		t.Fatalf("2 verdicts (registry + registry_list) attendus, got %+v", agent.machineReportItems)
	}
}

// --- Race logoff : session déloguée = no-op sans orpheline (piège n°4) --------

func TestSessionApplyLoggedOffSessionMaterializesNothing(t *testing.T) {
	// La session est déloguée ENTRE l'énumération (activeSIDs) et l'écriture :
	// la ruche HKU\<SID> est démontée — la sonde race-logoff de Write
	// (héritée de l'impl Windows, review 35.3 #1) rend un no-op nil, JAMAIS
	// de clé orpheline matérialisée sous HKEY_USERS.
	ops := newFakeRegistryOps()
	ops.unmountedHku[strings.ToLower(applySidA)] = true
	agent := newSessionApplyAgent(t, ops, map[string]string{
		applySidA: sessionEnvelope(itemFlagWriter),
	})

	agent.convergeSessionSystem()

	if len(ops.values) != 0 {
		t.Fatalf("ruche démontée : RIEN ne doit être matérialisé, values=%v", ops.values)
	}
	if ops.writeCnt != 0 {
		t.Fatalf("ruche démontée : zéro écriture effective, got %d", ops.writeCnt)
	}
	// La passe n'est PAS une erreur (no-op silencieux — la session s'évapore
	// de l'énumération au cycle suivant) : le verdict reste celui du moteur
	// (drift constaté, apply no-op), jamais un crash.
	if len(agent.machineReportItems) != 1 || agent.machineReportItems[0].Status == "error" {
		t.Fatalf("no-op silencieux attendu (pas d'error), got %+v", agent.machineReportItems)
	}
}

// --- Isolation par session (AC4) ----------------------------------------------

func TestSessionApplyErrorInOneSessionDoesNotBlockTheOthers(t *testing.T) {
	ops := newFakeRegistryOps()
	// La ruche A est illisible (ACL hostile) → verdict error pour SA passe ;
	// la ruche B converge normalement.
	ops.readErr[keyID("HKU", applySidA+`\Software\P\Explorer`, "DisallowRun")] = fmt.Errorf("accès refusé")
	agent := newSessionApplyAgent(t, ops, map[string]string{
		applySidA: sessionEnvelope(itemFlagWriter),
		applySidB: sessionEnvelope(itemRegeditWriter),
	})

	agent.convergeSessionSystem()

	if _, ok := ops.values[keyID("HKU", applySidB+`\Software\P\System`, "DisableRegistryTools")]; !ok {
		t.Fatalf("la session B doit converger malgré l'échec de A, values=%v", ops.values)
	}
	statuses := map[string]int{}
	for _, item := range agent.machineReportItems {
		statuses[item.Status]++
	}
	if statuses["error"] != 1 || statuses["drift"] != 1 {
		t.Fatalf("1 error (A) + 1 drift (B) attendus, got %+v", agent.machineReportItems)
	}
}

// --- Quarantaine / ops nil / cache absent / aucun item marqué ------------------

func TestSessionApplyQuarantineSkipsThePass(t *testing.T) {
	ops := newFakeRegistryOps()
	agent := newSessionApplyAgent(t, ops, map[string]string{
		applySidA: sessionEnvelope(itemFlagWriter),
	})
	agent.quarantined = true

	agent.convergeSessionSystem()

	if ops.readCnt != 0 || ops.writeCnt != 0 || len(agent.machineReportItems) != 0 {
		t.Fatalf("quarantaine : AUCUN traitement d'état attendu (reads=%d writes=%d items=%d)",
			ops.readCnt, ops.writeCnt, len(agent.machineReportItems))
	}
}

func TestSessionApplyNilOpsIsInert(t *testing.T) {
	agent := newSessionApplyAgent(t, nil, map[string]string{
		applySidA: sessionEnvelope(itemFlagWriter),
	})

	agent.convergeSessionSystem() // ne doit pas paniquer

	if agent.machineReportItems != nil {
		t.Fatalf("SessionSystemOps nil = passe inerte, got %+v", agent.machineReportItems)
	}
}

func TestSessionApplyMissingCacheIsSkipped(t *testing.T) {
	ops := newFakeRegistryOps()
	agent := newSessionApplyAgent(t, ops, nil)
	agent.activeSIDs = map[string]bool{applySidA: true} // session vivante SANS cache

	agent.convergeSessionSystem()

	if ops.readCnt != 0 || len(agent.machineReportItems) != 0 {
		t.Fatalf("cache absent : skip silencieux attendu (reads=%d items=%d)", ops.readCnt, len(agent.machineReportItems))
	}
}

func TestSessionApplyWithoutMarkedItemsIsANoop(t *testing.T) {
	// Cache sans AUCUN item writer : la passe ne touche ni le registre ni
	// l'applied-state per-SID (contrat §8 — rien à gérer, pas de purge).
	ops := newFakeRegistryOps()
	agent := newSessionApplyAgent(t, ops, map[string]string{
		applySidA: sessionEnvelope(itemCompanionPlain, itemUnknownWriter),
	})

	agent.convergeSessionSystem()

	if ops.readCnt != 0 || ops.writeCnt != 0 || len(agent.machineReportItems) != 0 {
		t.Fatalf("aucun item writer=system : no-op attendu (reads=%d writes=%d items=%d)",
			ops.readCnt, ops.writeCnt, len(agent.machineReportItems))
	}
	if _, err := os.Stat(agent.Store.SessionAppliedStatePath(applySidA)); err == nil {
		t.Fatal("aucun applied-state per-SID ne doit être créé sans item marqué")
	}
}

// --- Fusion des verdicts par type au rapport (piège n°9) -----------------------

func TestSessionApplyVerdictsMergeByTypeWithMachineItems(t *testing.T) {
	// Le type `registry` peut arriver de TROIS chemins (machine HKLM, passe
	// par-session, drop compagnon) : MergeReportItemsByType garde le pire
	// statut — l'unicité des types §6 est préservée sans rien inventer.
	ops := newFakeRegistryOps()
	agent := newSessionApplyAgent(t, ops, map[string]string{
		applySidA: sessionEnvelope(itemFlagWriter), // → drift (1er passage)
	})
	// Verdict machine PRÉ-EXISTANT du cycle (convergeMachine) : compliant.
	agent.machineReportItems = []ReportItem{{Type: "registry", Status: "compliant", Hash: "machine-h"}}

	agent.convergeSessionSystem()
	merged := MergeReportItemsByType(agent.machineReportItems)

	if len(merged) != 1 || merged[0].Type != "registry" {
		t.Fatalf("UN item registry fusionné attendu, got %+v", merged)
	}
	if merged[0].Status != "drift" {
		t.Fatalf("pire statut attendu (drift > compliant), got %+v", merged[0])
	}
}

package shared

import (
	"os"
	"strconv"
	"strings"
	"testing"
)

// Tests de l'ORCHESTRATION de la portée MACHINE (Story 27.3, review M2) :
// convergeMachine + MachineEngine. Le handler registry et le moteur (engine.go)
// sont testés ailleurs ; ici on couvre le câblage neuf — lecture du cache d'état
// SYSTEM, extraction de la portée machine, persistance de l'applied-state
// machine, drain des items de rapport, et les no-op (moteur nil / cache absent).

// envelopeWithMachineRegistry : enveloppe d'état v1 avec UN item registry HKLM
// en portée MACHINE (passe par le vrai parsing JSON : value en float64, comme
// en production).
func envelopeWithMachineRegistry(value int) string {
	return `{"schema":"se5.desired-state/v1","generated_at":"2026-06-12T10:00:00Z","ttl_seconds":3600,` +
		`"machine":[{"type":"registry","semantics":"exclusive",` +
		`"payload":{"hive":"HKLM","path":"SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System",` +
		`"name":"EnableLUA","type":"REG_DWORD","value":` + strconv.Itoa(value) + `},` +
		`"hash":"enablelua-h"}],"session":[],"machine_user":[]}`
}

func newMachineAgent(t *testing.T, ops RegistryOps) (*Agent, *Store) {
	t.Helper()
	store := newTestStore(t)
	log := &Logger{}
	agent := &Agent{
		Store:         store,
		Log:           log,
		MachineEngine: &Engine{Handlers: map[string]Handler{"registry": &RegistryHandler{Ops: ops, Log: log}}},
	}

	return agent, store
}

// AC5 (portée machine) : le service SYSTEM applique le registre HKLM du cache,
// draine un item de rapport, et persiste l'applied-state machine — puis reste
// idempotent (2e passe = zéro écriture, compliant).
func TestConvergeMachineAppliesHklmDrainsReportThenIdempotent(t *testing.T) {
	ops := newFakeRegistryOps()
	agent, store := newMachineAgent(t, ops)
	if err := store.WriteStateCache([]byte(envelopeWithMachineRegistry(0)), `"etag-m1"`); err != nil {
		t.Fatal(err)
	}

	// 1er passage : clé absente → drift → écriture → persistance applied-state.
	agent.convergeMachine()
	if ops.writeCnt != 1 {
		t.Fatalf("écriture HKLM attendue au premier passage, got %d", ops.writeCnt)
	}
	if len(agent.machineReportItems) != 1 || agent.machineReportItems[0].Type != "registry" {
		t.Fatalf("un item de rapport registry attendu : %+v", agent.machineReportItems)
	}
	if agent.machineReportItems[0].Status != "drift" {
		t.Fatalf("status drift attendu au premier passage : %+v", agent.machineReportItems[0])
	}
	if raw, _ := os.ReadFile(store.AppliedStatePath()); !strings.Contains(string(raw), "registry") {
		t.Fatalf("applied-state machine doit mémoriser le type registry, got %q", raw)
	}

	// 2e passage : valeur désormais conforme (le fake l'a mémorisée à l'écriture)
	// → idempotence (zéro écriture) + compliant.
	ops.writeCnt = 0
	agent.convergeMachine()
	if ops.writeCnt != 0 {
		t.Fatalf("idempotence : zéro écriture au 2e passage, got %d", ops.writeCnt)
	}
	if len(agent.machineReportItems) != 1 || agent.machineReportItems[0].Status != "compliant" {
		t.Fatalf("compliant attendu au 2e passage : %+v", agent.machineReportItems)
	}
}

// AC6/AC5 : moteur machine nil (console de debug, plateforme sans registre) =
// no-op strict, jamais de panique, aucun item de rapport.
func TestConvergeMachineNilEngineNoop(t *testing.T) {
	store := newTestStore(t)
	agent := &Agent{Store: store, Log: &Logger{}} // MachineEngine == nil
	if err := store.WriteStateCache([]byte(envelopeWithMachineRegistry(0)), `"etag"`); err != nil {
		t.Fatal(err)
	}

	agent.convergeMachine() // ne doit pas paniquer
	if agent.machineReportItems != nil {
		t.Fatalf("nil MachineEngine = no-op, got %+v", agent.machineReportItems)
	}
}

// Best-effort : cache d'état absent → convergence sautée sans panique ni
// écriture (le poste n'a jamais reçu d'état).
func TestConvergeMachineNoCacheNoop(t *testing.T) {
	ops := newFakeRegistryOps()
	agent, _ := newMachineAgent(t, ops) // aucun WriteStateCache

	agent.convergeMachine()
	if agent.machineReportItems != nil {
		t.Fatalf("cache absent = no-op, got %+v", agent.machineReportItems)
	}
	if ops.writeCnt != 0 {
		t.Fatalf("aucune écriture sans cache, got %d", ops.writeCnt)
	}
}

// Portée machine vide (aucune règle HKLM) = type absent (contrat §8) : rien à
// appliquer, aucun item de rapport, aucune écriture — même si le moteur existe.
func TestConvergeMachineEmptyScopeNoop(t *testing.T) {
	ops := newFakeRegistryOps()
	agent, store := newMachineAgent(t, ops)
	if err := store.WriteStateCache([]byte(minimalEnvelope(3600)), `"etag"`); err != nil {
		t.Fatal(err)
	}

	agent.convergeMachine()
	if agent.machineReportItems != nil {
		t.Fatalf("portée machine vide = aucun item, got %+v", agent.machineReportItems)
	}
	if ops.writeCnt != 0 {
		t.Fatalf("aucune écriture sur portée vide, got %d", ops.writeCnt)
	}
}

package shared

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Tests du handler `fs_acl` (Story 36.1, contrat §7.7) — fake FsAclOps en
// mémoire (NOUVEAU fake : le fakeRegistryOps est registre, jamais détourné).

// --- Fake FsAclOps ------------------------------------------------------------

type fakeFsAclOps struct {
	sids  map[string]string        // nom (minuscule) → SID ; absent ⇒ erreur LSA
	aces  map[string][]ExplicitAce // path (minuscule) → ACE explicites
	paths map[string]bool          // paths existants (sinon ErrFsPathNotExist)

	lookupCnt int // nombre d'appels LookupSid EFFECTIFS (mémo par passe)
	addCnt    int
	removeCnt int

	listErr map[string]error // path minuscule → erreur d'énumération forcée
}

func newFakeFsAclOps() *fakeFsAclOps {
	return &fakeFsAclOps{
		sids:    map[string]string{},
		aces:    map[string][]ExplicitAce{},
		paths:   map[string]bool{},
		listErr: map[string]error{},
	}
}

func fsPathKey(p string) string { return strings.ToLower(p) }

func (f *fakeFsAclOps) existPath(p string)       { f.paths[fsPathKey(p)] = true }
func (f *fakeFsAclOps) setSid(name, sid string)  { f.sids[strings.ToLower(name)] = sid }
func (f *fakeFsAclOps) seedAce(p string, a ExplicitAce) {
	f.existPath(p)
	f.aces[fsPathKey(p)] = append(f.aces[fsPathKey(p)], a)
}

func (f *fakeFsAclOps) LookupSid(name string) (string, error) {
	f.lookupCnt++
	sid, ok := f.sids[strings.ToLower(name)]
	if !ok {
		return "", errFake("trustee irrésoluble : " + name)
	}

	return sid, nil
}

func (f *fakeFsAclOps) ListExplicitAces(path string) ([]ExplicitAce, error) {
	key := fsPathKey(path)
	if err, ok := f.listErr[key]; ok {
		return nil, err
	}
	if !f.paths[key] {
		return nil, ErrFsPathNotExist
	}
	out := make([]ExplicitAce, len(f.aces[key]))
	copy(out, f.aces[key])

	return out, nil
}

func (f *fakeFsAclOps) AddAce(path string, ace ExplicitAce) error {
	key := fsPathKey(path)
	if !f.paths[key] {
		return ErrFsPathNotExist
	}
	f.aces[key] = append(f.aces[key], ace)
	f.addCnt++

	return nil
}

func (f *fakeFsAclOps) RemoveAce(path string, ace ExplicitAce) error {
	key := fsPathKey(path)
	if !f.paths[key] {
		return ErrFsPathNotExist
	}
	kept := f.aces[key][:0:0]
	removed := false
	for _, a := range f.aces[key] {
		if !removed && a.Equal(ace) {
			removed = true

			continue
		}
		kept = append(kept, a)
	}
	f.aces[key] = kept
	if removed {
		f.removeCnt++
	}

	return nil
}

func (f *fakeFsAclOps) hasAce(path string, ace ExplicitAce) bool {
	for _, a := range f.aces[fsPathKey(path)] {
		if a.Equal(ace) {
			return true
		}
	}

	return false
}

type fakeErr string

func (e fakeErr) Error() string { return string(e) }
func errFake(s string) error    { return fakeErr(s) }

// --- Helpers ------------------------------------------------------------------

const pfPath = `C:\Program Files`

func fsAclItem(path, trustee, aceType, rights, appliesTo, ensure string) StateItem {
	return StateItem{
		Type:      "fs_acl",
		Semantics: "exclusive",
		Hash:      path + trustee + "-h",
		Payload: map[string]any{
			"path":       path,
			"trustee":    trustee,
			"ace_type":   aceType,
			"rights":     rights,
			"applies_to": appliesTo,
			"ensure":     ensure,
		},
	}
}

func newFsAclHandler(t *testing.T, ops FsAclOps) *FsAclHandler {
	t.Helper()

	return &FsAclHandler{Ops: ops, StatePath: filepath.Join(t.TempDir(), "fsacl-state.json")}
}

// L'ACE cible d'un deny list_folder folder_only pour un SID donné.
func denyListFolder(sid string) ExplicitAce {
	return ExplicitAce{SID: sid, AceType: "deny", Mask: fileListDirectory, Flags: 0}
}

// --- (a) pose + relecture conforme + 2e Apply zéro op -------------------------

func TestFsAclApplyThenIdempotent(t *testing.T) {
	ops := newFakeFsAclOps()
	ops.existPath(pfPath)
	ops.setSid("Eleves", "S-1-5-21-1-2-3-1001")
	h := newFsAclHandler(t, ops)
	items := []StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "present")}

	ok, err := h.Test(items)
	if err != nil || ok {
		t.Fatalf("ACE absente → non conforme attendu (ok=%v err=%v)", ok, err)
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if !ops.hasAce(pfPath, denyListFolder("S-1-5-21-1-2-3-1001")) {
		t.Fatalf("l'ACE deny list_folder aurait dû être posée")
	}
	if ops.addCnt != 1 {
		t.Fatalf("1 pose attendue, obtenu %d", ops.addCnt)
	}

	ok, err = h.Test(items)
	if err != nil || !ok {
		t.Fatalf("après apply : conforme attendu (ok=%v err=%v)", ok, err)
	}
	// 2e Apply sur état stable = ZÉRO op.
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.addCnt != 1 || ops.removeCnt != 0 {
		t.Fatalf("apply idempotent attendu : addCnt=%d removeCnt=%d", ops.addCnt, ops.removeCnt)
	}
}

// --- (b) ACE supprimée à la main ⇒ re-drift STRICT à travers le moteur --------

func TestFsAclThroughEngineStrictRedrift(t *testing.T) {
	ops := newFakeFsAclOps()
	ops.existPath(pfPath)
	sid := "S-1-5-21-1-2-3-1001"
	ops.setSid("Eleves", sid)
	h := newFsAclHandler(t, ops)
	engine := &Engine{Handlers: map[string]Handler{"fs_acl": h}}
	target := []StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "present")}

	// Cycle 1 : ACE manquante → drift + pose.
	report := engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Type != "fs_acl" || report[0].Status != "drift" {
		t.Fatalf("cycle 1 : drift attendu, obtenu %+v", report)
	}
	if ops.addCnt != 1 {
		t.Fatalf("cycle 1 : 1 pose attendue, obtenu %d", ops.addCnt)
	}

	// Suppression MANUELLE (tampering) → re-drift + re-pose (STRICT).
	_ = ops.RemoveAce(pfPath, denyListFolder(sid))
	ops.removeCnt = 0 // on ne compte que les retraits du handler après ce point
	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "drift" {
		t.Fatalf("cycle 2 (tampering) : re-drift attendu, obtenu %+v", report)
	}
	if ops.addCnt != 2 {
		t.Fatalf("cycle 2 : 2 poses cumulées attendues, obtenu %d", ops.addCnt)
	}

	// Cycle 3 : stable → compliant, zéro op.
	report = engine.RunPass(target, AppliedState{})
	if len(report) != 1 || report[0].Status != "compliant" {
		t.Fatalf("cycle 3 : compliant attendu, obtenu %+v", report)
	}
	if ops.addCnt != 2 {
		t.Fatalf("cycle 3 : aucune pose supplémentaire (addCnt=%d)", ops.addCnt)
	}
}

// --- (c) changement de trustee : ancienne ACE retirée PUIS nouvelle posée -----

func TestFsAclTrusteeChangeRemovesOldAceViaStore(t *testing.T) {
	ops := newFakeFsAclOps()
	ops.existPath(pfPath)
	ops.setSid("Eleves", "S-1-5-21-1-2-3-1001")
	ops.setSid("Domain Users", "S-1-5-21-1-2-3-513")
	h := newFsAclHandler(t, ops)

	// Armé : deny Eleves.
	if err := h.Apply([]StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "present")}); err != nil {
		t.Fatalf("apply eleves: %v", err)
	}
	// Change de valeur : deny Domain Users (identité DIFFÉRENTE — le trustee est
	// dans l'identité) : l'ancienne (Eleves) devient orpheline du store.
	if err := h.Apply([]StateItem{fsAclItem(pfPath, "Domain Users", "deny", "list_folder", "folder_only", "present")}); err != nil {
		t.Fatalf("apply domain users: %v", err)
	}

	if ops.hasAce(pfPath, denyListFolder("S-1-5-21-1-2-3-1001")) {
		t.Fatalf("l'ancienne ACE Eleves aurait dû être retirée (orpheline)")
	}
	if !ops.hasAce(pfPath, denyListFolder("S-1-5-21-1-2-3-513")) {
		t.Fatalf("la nouvelle ACE Domain Users aurait dû être posée")
	}
	if len(ops.aces[fsPathKey(pfPath)]) != 1 {
		t.Fatalf("exactement UNE ACE gérée attendue (aucune orpheline), obtenu %d", len(ops.aces[fsPathKey(pfPath)]))
	}
}

// --- (d) changement de rights, MÊME identité : remplacement propre ------------

func TestFsAclRightsChangeSameIdentityCleanReplacement(t *testing.T) {
	ops := newFakeFsAclOps()
	ops.existPath(pfPath)
	sid := "S-1-5-21-1-2-3-1001"
	ops.setSid("Eleves", sid)
	h := newFsAclHandler(t, ops)

	if err := h.Apply([]StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "present")}); err != nil {
		t.Fatalf("apply list_folder: %v", err)
	}
	// Même identité (path|trustee|ace_type inchangés), rights → modify : l'ACE
	// change de masque → l'ancienne est retirée, la nouvelle posée.
	if err := h.Apply([]StateItem{fsAclItem(pfPath, "Eleves", "deny", "modify", "folder_only", "present")}); err != nil {
		t.Fatalf("apply modify: %v", err)
	}

	if ops.hasAce(pfPath, denyListFolder(sid)) {
		t.Fatalf("l'ancienne ACE list_folder aurait dû être remplacée")
	}
	if !ops.hasAce(pfPath, ExplicitAce{SID: sid, AceType: "deny", Mask: fsAclModifyMask, Flags: 0}) {
		t.Fatalf("la nouvelle ACE modify aurait dû être posée")
	}
	if len(ops.aces[fsPathKey(pfPath)]) != 1 {
		t.Fatalf("exactement UNE ACE gérée attendue, obtenu %d", len(ops.aces[fsPathKey(pfPath)]))
	}
}

// --- (e) ensure:absent retire ; déjà absente = compliant idempotent ----------

func TestFsAclEnsureAbsentRemovesAndIsIdempotent(t *testing.T) {
	ops := newFakeFsAclOps()
	sid := "S-1-5-21-1-2-3-1001"
	ops.setSid("Eleves", sid)
	ops.seedAce(pfPath, denyListFolder(sid)) // ACE déjà posée (hors store)
	h := newFsAclHandler(t, ops)
	items := []StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "absent")}

	ok, _ := h.Test(items)
	if ok {
		t.Fatalf("ACE présente + ensure:absent → non conforme attendu")
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if ops.hasAce(pfPath, denyListFolder(sid)) {
		t.Fatalf("l'ACE aurait dû être retirée (ensure:absent)")
	}
	if ops.removeCnt != 1 {
		t.Fatalf("1 retrait attendu, obtenu %d", ops.removeCnt)
	}

	// Déjà absente : compliant + idempotent (zéro op).
	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("ACE absente + ensure:absent → conforme attendu (ok=%v err=%v)", ok, err)
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply 2: %v", err)
	}
	if ops.removeCnt != 1 {
		t.Fatalf("aucun retrait supplémentaire attendu, obtenu %d", ops.removeCnt)
	}
}

// --- (f) orphelin de store réconcilié -----------------------------------------

func TestFsAclOrphanStoreEntryReconciled(t *testing.T) {
	ops := newFakeFsAclOps()
	ops.existPath(pfPath)
	sidEleves := "S-1-5-21-1-2-3-1001"
	sidProfs := "S-1-5-21-1-2-3-1002"
	ops.setSid("Eleves", sidEleves)
	ops.setSid("Profs", sidProfs)
	h := newFsAclHandler(t, ops)

	// Armé Eleves.
	if err := h.Apply([]StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "present")}); err != nil {
		t.Fatalf("apply eleves: %v", err)
	}
	// Nouvel état : SEULEMENT Profs (identité distincte). Eleves = orphelin du
	// store → son ACE doit être retirée à ce cycle.
	if err := h.Apply([]StateItem{fsAclItem(pfPath, "Profs", "deny", "list_folder", "folder_only", "present")}); err != nil {
		t.Fatalf("apply profs: %v", err)
	}
	if ops.hasAce(pfPath, denyListFolder(sidEleves)) {
		t.Fatalf("l'ACE orpheline Eleves aurait dû être réconciliée (retirée)")
	}
	if !ops.hasAce(pfPath, denyListFolder(sidProfs)) {
		t.Fatalf("la nouvelle ACE Profs aurait dû être posée")
	}
}

// --- (g) store corrompu ⇒ warning + repart vide, sans crash -------------------

func TestFsAclCorruptStoreRestartsEmpty(t *testing.T) {
	ops := newFakeFsAclOps()
	ops.existPath(pfPath)
	sid := "S-1-5-21-1-2-3-1001"
	ops.setSid("Eleves", sid)
	h := newFsAclHandler(t, ops)
	if err := os.WriteFile(h.StatePath, []byte("{ this is not json"), 0o600); err != nil {
		t.Fatal(err)
	}
	items := []StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "present")}

	// Ne crash pas ; converge l'ACE désirée.
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply avec store corrompu: %v", err)
	}
	if !ops.hasAce(pfPath, denyListFolder(sid)) {
		t.Fatalf("l'ACE désirée aurait dû être posée malgré le store corrompu")
	}
}

// --- (h) deny SID système ⇒ erreur d'item ISOLÉE (les autres convergent) ------

func TestFsAclDenySystemSidIsIsolatedError(t *testing.T) {
	ops := newFakeFsAclOps()
	ops.existPath(pfPath)
	ops.setSid("Everyone", "S-1-1-0")           // well-known système
	ops.setSid("Eleves", "S-1-5-21-1-2-3-1001") // légitime
	h := newFsAclHandler(t, ops)
	items := []StateItem{
		fsAclItem(pfPath, "Everyone", "deny", "list_folder", "folder_only", "present"),
		fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "present"),
	}

	err := h.Apply(items)
	if err == nil {
		t.Fatalf("un deny sur SID système doit remonter une erreur d'item")
	}
	// L'item légitime a convergé malgré l'erreur isolée (effort maximal).
	if !ops.hasAce(pfPath, denyListFolder("S-1-5-21-1-2-3-1001")) {
		t.Fatalf("l'ACE Eleves aurait dû converger malgré le refus du deny système")
	}
	if ops.hasAce(pfPath, denyListFolder("S-1-1-0")) {
		t.Fatalf("le deny sur Everyone (S-1-1-0) n'aurait JAMAIS dû être posé")
	}

	// À travers le moteur : verdict `error` pour le type (mais Eleves posée).
	engine := &Engine{Handlers: map[string]Handler{"fs_acl": h}}
	report := engine.RunPass(items, AppliedState{})
	if len(report) != 1 || report[0].Status != "error" {
		t.Fatalf("verdict error attendu pour le type fs_acl, obtenu %+v", report)
	}
}

// --- (i) chemin inexistant ⇒ erreur d'item (jamais de création) ---------------

func TestFsAclNonExistentPathIsItemError(t *testing.T) {
	ops := newFakeFsAclOps() // pfPath NON déclaré existant
	ops.setSid("Eleves", "S-1-5-21-1-2-3-1001")
	h := newFsAclHandler(t, ops)
	items := []StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "present")}

	if err := h.Apply(items); err == nil {
		t.Fatalf("chemin inexistant (present) : erreur d'item attendue")
	}
	if ops.addCnt != 0 {
		t.Fatalf("jamais de pose sur un chemin inexistant, obtenu %d", ops.addCnt)
	}
	if ops.paths[fsPathKey(pfPath)] {
		t.Fatalf("le chemin n'aurait JAMAIS dû être créé")
	}
}

func TestFsAclAbsentOnNonExistentPathIsSatisfied(t *testing.T) {
	ops := newFakeFsAclOps() // chemin inexistant
	ops.setSid("Eleves", "S-1-5-21-1-2-3-1001")
	h := newFsAclHandler(t, ops)
	items := []StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "absent")}

	ok, err := h.Test(items)
	if err != nil || !ok {
		t.Fatalf("absent + chemin inexistant = déjà satisfait (ok=%v err=%v)", ok, err)
	}
	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
}

// --- (j) trustee irrésoluble ⇒ erreur d'item ----------------------------------

func TestFsAclUnresolvableTrusteeIsItemError(t *testing.T) {
	ops := newFakeFsAclOps()
	ops.existPath(pfPath)
	ops.setSid("Eleves", "S-1-5-21-1-2-3-1001")
	// "Inconnu" n'a pas de SID → LookupSid échoue.
	h := newFsAclHandler(t, ops)
	items := []StateItem{
		fsAclItem(pfPath, "Inconnu", "deny", "list_folder", "folder_only", "present"),
		fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "present"),
	}

	if err := h.Apply(items); err == nil {
		t.Fatalf("trustee irrésoluble : erreur d'item attendue")
	}
	// L'item résoluble a convergé (effort maximal).
	if !ops.hasAce(pfPath, denyListFolder("S-1-5-21-1-2-3-1001")) {
		t.Fatalf("l'ACE Eleves aurait dû converger malgré le trustee irrésoluble")
	}
}

// --- (k) payload invalide ⇒ error pour le type --------------------------------

func TestFsAclInvalidPayloadIsError(t *testing.T) {
	h := newFsAclHandler(t, newFakeFsAclOps())
	cases := []struct {
		name    string
		payload any
	}{
		{"non objet", "texte"},
		{"path absent", map[string]any{"trustee": "Eleves", "ace_type": "deny", "rights": "list_folder", "applies_to": "folder_only", "ensure": "present"}},
		{"trustee vide", map[string]any{"path": pfPath, "trustee": "", "ace_type": "deny", "rights": "list_folder", "applies_to": "folder_only", "ensure": "present"}},
		{"ace_type inconnu", map[string]any{"path": pfPath, "trustee": "Eleves", "ace_type": "audit", "rights": "list_folder", "applies_to": "folder_only", "ensure": "present"}},
		{"rights inconnu", map[string]any{"path": pfPath, "trustee": "Eleves", "ace_type": "deny", "rights": "full", "applies_to": "folder_only", "ensure": "present"}},
		{"applies_to inconnu", map[string]any{"path": pfPath, "trustee": "Eleves", "ace_type": "deny", "rights": "list_folder", "applies_to": "everywhere", "ensure": "present"}},
		{"ensure inconnu", map[string]any{"path": pfPath, "trustee": "Eleves", "ace_type": "deny", "rights": "list_folder", "applies_to": "folder_only", "ensure": "maybe"}},
		{"ensure absent (clé manquante)", map[string]any{"path": pfPath, "trustee": "Eleves", "ace_type": "deny", "rights": "list_folder", "applies_to": "folder_only"}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			items := []StateItem{{Type: "fs_acl", Semantics: "exclusive", Hash: "h", Payload: tc.payload}}
			if _, err := h.Test(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur de Test")
			}
			if err := h.Apply(items); err == nil {
				t.Fatalf("payload invalide attendu en erreur d'Apply")
			}
		})
	}
}

// --- (l) ACE tierces jamais touchées ------------------------------------------

func TestFsAclNeverTouchesThirdPartyAces(t *testing.T) {
	ops := newFakeFsAclOps()
	sid := "S-1-5-21-1-2-3-1001"
	ops.setSid("Eleves", sid)
	// ACE TIERCE (autre principal, allow full) présente sur le chemin — jamais
	// gérée par nous.
	thirdParty := ExplicitAce{SID: "S-1-5-21-9-9-9-500", AceType: "allow", Mask: fsAclModifyMask, Flags: fsAclContainerInherit | fsAclObjectInherit}
	ops.seedAce(pfPath, thirdParty)
	h := newFsAclHandler(t, ops)

	// Poser puis retirer NOTRE ACE : la tierce survit aux deux.
	if err := h.Apply([]StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "present")}); err != nil {
		t.Fatalf("apply present: %v", err)
	}
	if !ops.hasAce(pfPath, thirdParty) {
		t.Fatalf("l'ACE tierce doit survivre à la pose de la nôtre")
	}
	if err := h.Apply([]StateItem{fsAclItem(pfPath, "Eleves", "deny", "list_folder", "folder_only", "absent")}); err != nil {
		t.Fatalf("apply absent: %v", err)
	}
	if !ops.hasAce(pfPath, thirdParty) {
		t.Fatalf("l'ACE tierce doit survivre au retrait de la nôtre")
	}
	if ops.hasAce(pfPath, denyListFolder(sid)) {
		t.Fatalf("notre ACE aurait dû être retirée")
	}
}

// --- (m) mémo SID par passe (compteur du fake) --------------------------------

func TestFsAclSidMemoizedPerPass(t *testing.T) {
	ops := newFakeFsAclOps()
	ops.existPath(`C:\Program Files`)
	ops.existPath(`C:\Program Files (x86)`)
	sid := "S-1-5-21-1-2-3-1001"
	ops.setSid("Eleves", sid)
	h := newFsAclHandler(t, ops)
	// Deux items MÊME trustee sur deux chemins distincts.
	items := []StateItem{
		fsAclItem(`C:\Program Files`, "Eleves", "deny", "list_folder", "folder_only", "present"),
		fsAclItem(`C:\Program Files (x86)`, "Eleves", "deny", "list_folder", "folder_only", "present"),
	}

	if err := h.Apply(items); err != nil {
		t.Fatalf("apply: %v", err)
	}
	// Le même trustee n'est résolu qu'UNE fois par passe (mémo).
	if ops.lookupCnt != 1 {
		t.Fatalf("mémo par passe : 1 seule résolution LSA attendue pour un trustee unique, obtenu %d", ops.lookupCnt)
	}
	if ops.addCnt != 2 {
		t.Fatalf("2 poses attendues (2 chemins), obtenu %d", ops.addCnt)
	}
}

package shared

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"sort"
	"strings"
)

// Handler `fs_acl` (exclusive PAR ACE / scope MACHINE uniquement) — Story 36.1,
// contrat §7.7. Premier mécanisme HORS-REGISTRE. Logique PURE, OS-agnostique
// (les accès NTFS réels sont injectés via FsAclOps) → testée sur l'hôte ;
// agent/windows n'apporte que l'impl Win32 (LSA + chirurgie DACL).
//
// D4 — PROPRIÉTÉ CHIRURGICALE. Le handler possède SES ACE explicites
// IDENTIFIÉES PAR LE STORE (« dernier appliqué »), JAMAIS la DACL entière : une
// ACE NTFS ne porte aucun marqueur de propriété, alors le handler persiste, par
// identité d'item, l'ACE exactement posée {path, trustee, sid, ace_type, mask,
// flags} dans un fichier JSON dédié (Store.FsAclStatePath(), WriteFileAtomic).
// Quand une valeur change (trustee A → B), l'ancien item disparaît de l'état
// (identité différente) : le store est la SEULE mémoire qui dit « l'ACE A est à
// nous » — la réconciliation d'orphelins la retire PUIS pose la nouvelle
// (aucune ACE orpheline). owner/SACL/ACE héritées/ACE tierces ne sont JAMAIS
// touchés (SetNamedSecurityInfo DACL-only, merge — jamais de réécriture).
//
// CONVERGENCE level-triggered (§5, STRICT inconditionnel 27.8) :
//   - Test  : pour chaque item `present`, une ACE EXPLICITE exactement égale
//     (SID résolu, type, masque traduit, flags traduits — piège #6) existe dans
//     la DACL ; pour chaque `absent`, aucune ; ET aucune entrée du store hors
//     état désiré n'a d'ACE encore présente (orphelin) ⇒ conforme ssi TOUT vrai ;
//   - Apply : effort MAXIMAL par item (première erreur remontée à la FIN,
//     idempotent — 2 passes stables = zéro op). (1) retire les ACE des
//     orphelins du store puis les purge ; (2) `present` manquante : si le store
//     porte une ACE DIFFÉRENTE pour la même identité (rights/applies_to
//     changés), la retire d'abord, puis pose l'ACE par merge, enregistre au
//     store ; (3) `absent` : retire l'ACE exactement égale si présente (déjà
//     absente = idempotent) et purge l'entrée du store.
//
// REFUS AGENT = DÉFENSE EN PROFONDEUR (piège #8), INDÉPENDANT du serveur :
//   - `deny` dont le SID résolu est well-known système (S-1-1-0 Everyone,
//     S-1-5-11 Authenticated Users, S-1-5-18/19/20, préfixe S-1-5-32- BUILTIN
//     dont Administrators, préfixe S-1-5-80- comptes de service dont
//     TrustedInstaller) ⇒ erreur d'ITEM (jamais posée) ;
//   - chemin INEXISTANT ⇒ erreur d'item (JAMAIS de création de dossier) ;
//   - trustee irrésoluble via LSA ⇒ erreur d'item.
// Les AUTRES items convergent ; l'erreur remonte TOUJOURS (verdict `error` du
// type au moteur — jamais d'application partielle silencieuse). Résolution SID
// mémoïsée PAR PASSE seulement (piège #7).
//
// D3 — masques/flags SPÉCIFIQUES uniquement (piège #6) : les droits génériques
// (GENERIC_*) seraient remappés par le noyau à l'écriture → relecture non
// byte-égale → Test en dérive perpétuelle. Table de traduction ci-dessous.

// ErrFsPathNotExist : sentinelle « le chemin n'existe pas » (les ops OS
// l'enveloppent via %w). Le handler la distingue d'une erreur d'accès : un
// `present` sur chemin inexistant est une erreur d'item (jamais de mkdir), un
// `absent` sur chemin inexistant est déjà satisfait.
var ErrFsPathNotExist = errors.New("fs_acl : chemin inexistant")

// Table de traduction rights → masque d'accès (bits SPÉCIFIQUES, piège #6).
const (
	fileListDirectory  = 0x00000001 // FILE_LIST_DIRECTORY SEUL — masquer sans casser (traverse/execute/read intacts)
	fileGenericRead    = 0x00120089 // FILE_GENERIC_READ (composite de bits spécifiques)
	fileGenericWrite   = 0x00120116 // FILE_GENERIC_WRITE
	fileGenericExecute = 0x001200A0 // FILE_GENERIC_EXECUTE
	deleteAccess       = 0x00010000 // DELETE
	// fsAclModifyMask = « Modification » de l'onglet Sécurité = READ|WRITE|
	// EXECUTE|DELETE (= 0x001301BF).
	fsAclModifyMask = fileGenericRead | fileGenericWrite | fileGenericExecute | deleteAccess
)

// Table de traduction applies_to → flags d'héritage (mêmes bits que
// EXPLICIT_ACCESS.Inheritance / ACE AceFlags côté Win32).
const (
	fsAclObjectInherit    = 0x1 // OBJECT_INHERIT_ACE (fichiers)
	fsAclContainerInherit = 0x2 // CONTAINER_INHERIT_ACE (sous-dossiers)
	fsAclInheritOnly      = 0x8 // INHERIT_ONLY_ACE
)

// rightsToMask traduit un `rights` du contrat en masque d'accès spécifique.
func rightsToMask(rights string) (uint32, bool) {
	switch rights {
	case "list_folder":
		return fileListDirectory, true
	case "read":
		return fileGenericRead, true
	case "write":
		return fileGenericWrite, true
	case "modify":
		return fsAclModifyMask, true
	default:
		return 0, false
	}
}

// appliesToFlags traduit un `applies_to` du contrat en flags d'héritage.
func appliesToFlags(appliesTo string) (uint32, bool) {
	switch appliesTo {
	case "folder_only":
		return 0, true
	case "folder_subfolders_files":
		return fsAclContainerInherit | fsAclObjectInherit, true
	case "subfolders_files_only":
		return fsAclContainerInherit | fsAclObjectInherit | fsAclInheritOnly, true
	default:
		return 0, false
	}
}

// ExplicitAce : une ACE EXPLICITE (non héritée) vue/posée sur un chemin NTFS.
// SID résolu (string), type allow/deny, masque et flags traduits.
type ExplicitAce struct {
	SID     string
	AceType string // "allow" | "deny"
	Mask    uint32
	Flags   uint32
}

// Equal : deux ACE explicites sont-elles EXACTEMENT égales ? SID insensible à
// la casse (les SID string sont canoniques mais on ne parie pas dessus), type
// insensible à la casse, masque et flags stricts.
func (a ExplicitAce) Equal(b ExplicitAce) bool {
	return strings.EqualFold(a.SID, b.SID) &&
		strings.EqualFold(a.AceType, b.AceType) &&
		a.Mask == b.Mask &&
		a.Flags == b.Flags
}

// FsAclOps : accès NTFS spécifiques à l'OS, injectés (testable hôte). L'impl
// Windows vit dans agent/windows/handler_fs_acl_windows.go (LSA + chirurgie
// DACL) ; un fake en mémoire couvre les tests.
type FsAclOps interface {
	// LookupSid résout un NOM (DOMAIN\name, well-known, groupe local) en SID
	// string via la LSA du poste joint (LookupAccountName). Irrésoluble ⇒ err
	// (erreur d'item).
	LookupSid(name string) (sid string, err error)

	// ListExplicitAces retourne les ACE EXPLICITES (NON héritées) de la DACL du
	// chemin — allow ET deny. Chemin inexistant ⇒ (nil, ErrFsPathNotExist)
	// (enveloppée) : JAMAIS de création. err = accès refusé / lecture SD
	// impossible.
	ListExplicitAces(path string) ([]ExplicitAce, error)

	// AddAce pose l'ACE par merge CHIRURGICAL (SetEntriesInAcl + SetNamedSecurityInfo
	// DACL-only, ordre canonique deny-first géré par Windows). Idempotent du point
	// de vue du résultat. La DACL n'est JAMAIS réécrite en bloc.
	AddAce(path string, ace ExplicitAce) error

	// RemoveAce reconstruit la DACL MOINS l'ACE exactement égale (itération
	// GetAce + DeleteAce à l'index). Une ACE déjà absente ⇒ nil (idempotent).
	RemoveAce(path string, ace ExplicitAce) error
}

// FsAclSpec : une ACE cible (un item du payload `fs_acl`, EXACTEMENT 6 clés).
type FsAclSpec struct {
	Path      string
	Trustee   string // NOM (résolu en SID par l'agent, D5) — jamais un SID
	AceType   string // "allow" | "deny"
	Rights    string // list_folder | read | write | modify
	AppliesTo string // folder_only | folder_subfolders_files | subfolders_files_only
	Ensure    string // "present" | "absent" (TOUJOURS présent, piège #13)
}

// identity : identité exclusive {path|trustee|ace_type} insensible à la casse
// (iso exclusiveKey serveur, 3 segments) — clé du store et unicité interne.
func (s FsAclSpec) identity() string {
	return strings.ToLower(s.Path) + "|" + strings.ToLower(s.Trustee) + "|" + strings.ToLower(s.AceType)
}

func (s FsAclSpec) absent() bool { return s.Ensure == "absent" }

// targetAce : l'ACE explicite cible pour un SID résolu (masque/flags traduits).
func (s FsAclSpec) targetAce(sid string) ExplicitAce {
	mask, _ := rightsToMask(s.Rights)
	flags, _ := appliesToFlags(s.AppliesTo)

	return ExplicitAce{SID: sid, AceType: s.AceType, Mask: mask, Flags: flags}
}

// parseFsAclSpec : extrait un FsAclSpec d'un payload §7.7 brut. Enveloppe
// invalide (false → {status: error} pour le type) si : une clé manque, un champ
// n'est pas string, un enum est hors domaine. `path`/`trustee` non vides
// requis. Forme UNIQUE (piège #13 : `ensure` TOUJOURS présent) ⇒ parse trivial.
func parseFsAclSpec(raw any) (FsAclSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return FsAclSpec{}, false
	}

	path, _ := payload["path"].(string)
	trustee, _ := payload["trustee"].(string)
	if path == "" || trustee == "" {
		return FsAclSpec{}, false
	}

	aceType, _ := payload["ace_type"].(string)
	aceType = strings.ToLower(aceType)
	if aceType != "allow" && aceType != "deny" {
		return FsAclSpec{}, false
	}

	rights, _ := payload["rights"].(string)
	rights = strings.ToLower(rights)
	if _, ok := rightsToMask(rights); !ok {
		return FsAclSpec{}, false
	}

	appliesTo, _ := payload["applies_to"].(string)
	appliesTo = strings.ToLower(appliesTo)
	if _, ok := appliesToFlags(appliesTo); !ok {
		return FsAclSpec{}, false
	}

	ensure, _ := payload["ensure"].(string)
	ensure = strings.ToLower(ensure)
	if ensure != "present" && ensure != "absent" {
		return FsAclSpec{}, false
	}

	return FsAclSpec{
		Path:      path,
		Trustee:   trustee,
		AceType:   aceType,
		Rights:    rights,
		AppliesTo: appliesTo,
		Ensure:    ensure,
	}, true
}

// isWellKnownSystemSID : le SID résolu est-il un principal SYSTÈME sur lequel un
// `deny` briserait le poste (piège #8) ? Défense en profondeur agent,
// INDÉPENDANTE de la validation serveur (FsAclAuthoringGuard).
func isWellKnownSystemSID(sid string) bool {
	s := strings.ToUpper(strings.TrimSpace(sid))
	switch s {
	case "S-1-1-0", // Everyone
		"S-1-5-11", // Authenticated Users
		"S-1-5-18", // Local System
		"S-1-5-19", // Local Service
		"S-1-5-20": // Network Service
		return true
	}
	// BUILTIN (S-1-5-32-*, dont 544 Administrators) et comptes de service
	// (S-1-5-80-*, dont TrustedInstaller).
	return strings.HasPrefix(s, "S-1-5-32-") || strings.HasPrefix(s, "S-1-5-80-")
}

// --- Store « dernier appliqué » (piège #4) -----------------------------------

// appliedFsAce : l'ACE exactement posée, persistée par identité d'item. Porte
// le trustee (nom) et le SID résolu à l'instant de la pose — pour retirer
// l'ancienne ACE même si le trustee n'est plus dans l'état désiré.
type appliedFsAce struct {
	Path    string `json:"path"`
	Trustee string `json:"trustee"`
	SID     string `json:"sid"`
	AceType string `json:"ace_type"`
	Mask    uint32 `json:"mask"`
	Flags   uint32 `json:"flags"`
}

// ace : la vue ExplicitAce de l'enregistrement (pour Add/Remove/comparaison).
func (r appliedFsAce) ace() ExplicitAce {
	return ExplicitAce{SID: r.SID, AceType: r.AceType, Mask: r.Mask, Flags: r.Flags}
}

// fsAclAppliedState : map identité (path|trustee|ace_type minuscules) → ACE posée.
type fsAclAppliedState map[string]appliedFsAce

// readFsAclState charge le store. Fichier absent ⇒ map vide (corrupted=false) ;
// JSON illisible ⇒ map vide + corrupted=true (l'appelant warn puis repart vide,
// iso ReadAppliedState).
func readFsAclState(path string) (state fsAclAppliedState, corrupted bool) {
	state = fsAclAppliedState{}
	raw, err := os.ReadFile(path)
	if err != nil {
		return state, false
	}
	if err := json.Unmarshal(raw, &state); err != nil {
		return fsAclAppliedState{}, true
	}
	if state == nil {
		state = fsAclAppliedState{}
	}

	return state, false
}

// writeFsAclState persiste le store (écriture atomique, iso WriteAppliedState).
func writeFsAclState(path string, state fsAclAppliedState) error {
	raw, err := json.Marshal(state)
	if err != nil {
		return err
	}

	return WriteFileAtomic(path, raw)
}

// --- Résolution SID mémoïsée PAR PASSE (piège #7) -----------------------------

type sidMemo struct {
	ops   FsAclOps
	cache map[string]string
	errs  map[string]error
}

func newSidMemo(ops FsAclOps) *sidMemo {
	return &sidMemo{ops: ops, cache: map[string]string{}, errs: map[string]error{}}
}

func (m *sidMemo) resolve(name string) (string, error) {
	key := strings.ToLower(name)
	if sid, ok := m.cache[key]; ok {
		return sid, nil
	}
	if err, ok := m.errs[key]; ok {
		return "", err
	}
	sid, err := m.ops.LookupSid(name)
	if err != nil {
		m.errs[key] = err

		return "", err
	}
	m.cache[key] = sid

	return sid, nil
}

// --- Handler ------------------------------------------------------------------

// FsAclHandler : handler exclusive-par-ACE branché dans le moteur (engine.go
// INTOUCHÉ — la machine d'états §5 reste au moteur). SERVICE SYSTEM seul.
type FsAclHandler struct {
	Ops FsAclOps
	// StatePath : Store.FsAclStatePath() — le store « dernier appliqué ».
	StatePath string
	Log       *Logger
}

// desiredSpecs : parse + dédoublonne par identité les items cible. Le serveur
// garantit déjà l'unicité (exclusive par clé au compilateur) ; défense : la
// DERNIÈRE occurrence fait foi, ordre de sortie TRIÉ (logs/erreurs stables, iso
// desiredSpecs de registry).
func (h *FsAclHandler) desiredSpecs(items []StateItem) ([]FsAclSpec, error) {
	byIdentity := map[string]FsAclSpec{}
	order := []string{}
	for _, item := range items {
		spec, ok := parseFsAclSpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload fs_acl inattendu : enveloppe invalide")
		}
		id := spec.identity()
		if _, seen := byIdentity[id]; !seen {
			order = append(order, id)
		}
		byIdentity[id] = spec
	}

	sort.Strings(order)
	specs := make([]FsAclSpec, 0, len(order))
	for _, id := range order {
		specs = append(specs, byIdentity[id])
	}

	return specs, nil
}

// acePresent : la DACL courante contient-elle une ACE exactement égale ?
func acePresent(current []ExplicitAce, target ExplicitAce) bool {
	for _, ace := range current {
		if ace.Equal(target) {
			return true
		}
	}

	return false
}

// Test : conforme ssi (a) chaque item `present` a son ACE explicite exactement
// égale, (b) chaque `absent` n'a AUCUNE ACE égale, (c) aucun orphelin du store
// (identité hors état désiré) n'a d'ACE encore présente. Un item irrésoluble /
// refusé / à chemin inexistant `present` rend NON conforme (l'Apply surfacera
// l'erreur — effort maximal) ; un payload invalide est une erreur franche.
func (h *FsAclHandler) Test(items []StateItem) (bool, error) {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return false, err
	}

	store, corrupted := readFsAclState(h.StatePath)
	if corrupted {
		logWarning(h.Log, "Store fs_acl corrompu (%s) : ignoré pour ce Test (repart vide).", h.StatePath)
	}

	desiredIDs := map[string]bool{}
	for _, s := range specs {
		desiredIDs[s.identity()] = true
	}

	// (c) Orphelins du store dont l'ACE est encore présente ⇒ non conforme.
	for id, rec := range store {
		if desiredIDs[id] {
			continue
		}
		current, err := h.Ops.ListExplicitAces(rec.Path)
		if errors.Is(err, ErrFsPathNotExist) {
			continue // chemin parti → orphelin déjà résolu
		}
		if err != nil {
			return false, nil // illisible → Apply (effort maximal) tranchera
		}
		if acePresent(current, rec.ace()) {
			return false, nil
		}
	}

	// (a)/(b) Items désirés.
	memo := newSidMemo(h.Ops)
	for _, spec := range specs {
		sid, err := memo.resolve(spec.Trustee)
		if err != nil {
			return false, nil // irrésoluble → Apply surfacera l'erreur d'item
		}
		if spec.AceType == "deny" && isWellKnownSystemSID(sid) {
			return false, nil // deny système refusé → Apply erreur d'item
		}

		current, err := h.Ops.ListExplicitAces(spec.Path)
		if errors.Is(err, ErrFsPathNotExist) {
			if spec.absent() {
				continue // absent + chemin inexistant = déjà satisfait
			}

			return false, nil // present + chemin inexistant → Apply erreur d'item
		}
		if err != nil {
			return false, nil
		}

		present := acePresent(current, spec.targetAce(sid))
		if spec.absent() {
			if present {
				return false, nil
			}

			continue
		}
		if !present {
			return false, nil
		}
	}

	return true, nil
}

// Apply : converge en effort MAXIMAL par item (première erreur remontée à la
// fin, idempotent). Chirurgie DACL uniquement (jamais de réécriture, D4). Le
// store est relu au début et réécrit atomiquement à la fin s'il a changé.
func (h *FsAclHandler) Apply(items []StateItem) error {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return err
	}

	store, corrupted := readFsAclState(h.StatePath)
	if corrupted {
		logWarning(h.Log, "Store fs_acl corrompu (%s) : repart vide (les ACE orphelines d'avant-corruption sont perdues, assumé).", h.StatePath)
		store = fsAclAppliedState{}
	}

	var firstErr error
	record := func(e error) {
		if firstErr == nil {
			firstErr = e
		}
	}
	dirty := false

	desiredIDs := map[string]bool{}
	for _, s := range specs {
		desiredIDs[s.identity()] = true
	}

	// (1) Réconcilier les orphelins du store : retire l'ACE enregistrée PUIS
	// purge l'entrée (zéro ACE orpheline). Ordre trié (logs déterministes).
	orphanIDs := []string{}
	for id := range store {
		if !desiredIDs[id] {
			orphanIDs = append(orphanIDs, id)
		}
	}
	sort.Strings(orphanIDs)
	for _, id := range orphanIDs {
		rec := store[id]
		if err := h.Ops.RemoveAce(rec.Path, rec.ace()); err != nil {
			if errors.Is(err, ErrFsPathNotExist) {
				delete(store, id) // chemin parti → l'ACE l'est aussi
				dirty = true

				continue
			}
			record(fmt.Errorf("retrait de l'ACE orpheline %s : %w", id, err))

			continue // garder l'entrée (retentée au cycle suivant)
		}
		delete(store, id)
		dirty = true
		logInfo(h.Log, "ACE fs_acl orpheline retirée : %s", id)
	}

	// (2) Converger les items désirés.
	memo := newSidMemo(h.Ops)
	for _, spec := range specs {
		id := spec.identity()

		sid, err := memo.resolve(spec.Trustee)
		if err != nil {
			record(fmt.Errorf("résolution LSA du trustee %q (%s) : %w", spec.Trustee, id, err))

			continue
		}

		// Défense en profondeur (piège #8) : deny sur SID well-known système.
		if spec.AceType == "deny" && isWellKnownSystemSID(sid) {
			record(fmt.Errorf("deny refusé sur le principal système %q (SID %s, %s)", spec.Trustee, sid, id))

			continue
		}

		target := spec.targetAce(sid)
		current, err := h.Ops.ListExplicitAces(spec.Path)
		if err != nil {
			if errors.Is(err, ErrFsPathNotExist) {
				if spec.absent() {
					// ACE définitionnellement absente → purge l'entrée du store.
					if _, ok := store[id]; ok {
						delete(store, id)
						dirty = true
					}

					continue
				}
				record(fmt.Errorf("chemin inexistant pour %s (jamais de création de dossier)", id))

				continue
			}
			record(fmt.Errorf("lecture de la DACL de %s : %w", id, err))

			continue
		}

		if spec.absent() {
			if acePresent(current, target) {
				if err := h.Ops.RemoveAce(spec.Path, target); err != nil {
					record(fmt.Errorf("retrait de l'ACE %s : %w", id, err))

					continue
				}
				logInfo(h.Log, "ACE fs_acl retirée (ensure: absent) : %s", id)
			}
			if _, ok := store[id]; ok {
				delete(store, id)
				dirty = true
			}

			continue
		}

		// present : si le store porte une ACE DIFFÉRENTE pour la MÊME identité
		// (rights/applies_to changés), la retirer d'abord — zéro ACE orpheline.
		if rec, ok := store[id]; ok {
			old := rec.ace()
			if !old.Equal(target) && acePresent(current, old) {
				if err := h.Ops.RemoveAce(spec.Path, old); err != nil {
					record(fmt.Errorf("retrait de l'ancienne ACE %s : %w", id, err))

					continue
				}
				current, err = h.Ops.ListExplicitAces(spec.Path)
				if err != nil {
					record(fmt.Errorf("relecture de la DACL de %s : %w", id, err))

					continue
				}
			}
		}

		wantRec := appliedFsAce{
			Path: spec.Path, Trustee: spec.Trustee, SID: sid,
			AceType: spec.AceType, Mask: target.Mask, Flags: target.Flags,
		}

		if acePresent(current, target) {
			// Déjà conforme : aucune op DACL ; on aligne le store si besoin
			// (idempotent — 2 passes stables = zéro op car le store matche déjà).
			if store[id] != wantRec {
				store[id] = wantRec
				dirty = true
			}

			continue
		}

		if err := h.Ops.AddAce(spec.Path, target); err != nil {
			record(fmt.Errorf("pose de l'ACE %s : %w", id, err))

			continue
		}
		store[id] = wantRec
		dirty = true
		logInfo(h.Log, "ACE fs_acl posée : %s (mask=0x%X flags=0x%X)", id, target.Mask, target.Flags)
	}

	if dirty {
		if err := writeFsAclState(h.StatePath, store); err != nil {
			logError(h.Log, "Écriture du store fs_acl en échec : %v", err)
			record(fmt.Errorf("écriture du store fs_acl : %w", err))
		}
	}

	return firstErr
}

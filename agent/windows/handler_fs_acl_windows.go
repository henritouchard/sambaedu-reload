package main

import (
	"errors"
	"fmt"
	"strings"
	"unsafe"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `fs_acl` (Story 36.1) — chirurgie DACL EN GO NATIF
// via golang.org/x/sys/windows (déjà dans go.mod). SERVICE SYSTEM seul (le
// compagnon n'a pas les droits, et le type n'existe pas côté session).
//
// La logique de convergence (Test/Apply, store dernier-appliqué, réconciliation
// d'orphelins, refus défense en profondeur, idempotence) vit dans
// shared.FsAclHandler (testée hôte avec un fake) ; ce fichier n'apporte que
// l'impl des 4 ops FsAclOps :
//   - LookupSid   : windows.LookupSID("", name) → sid.String() (LSA locale du
//     poste joint — noms de domaine ET well-known résolus, D5) ;
//   - ListExplicitAces : GetNamedSecurityInfo(DACL) + itération GetAce (lazy
//     proc advapi32), en NE gardant que les ACE NON héritées (flag INHERITED_ACE
//     exclu), allow + deny ;
//   - AddAce      : ACLFromEntries([1 EXPLICIT_ACCESS], oldDacl) = SetEntriesInAcl
//     (merge + ordre canonique deny-first géré par Windows) →
//     SetNamedSecurityInfo(DACL_SECURITY_INFORMATION SEUL, SANS PROTECTED_*) —
//     owner/SACL/héritage JAMAIS touchés (D4, piège #5) ;
//   - RemoveAce   : reconstruit la DACL MOINS l'ACE exactement égale (itération
//     GetAce + DeleteAce à l'index, lazy proc — DeleteAce n'est pas wrappé par
//     x/sys) ; ACE déjà absente ⇒ nil (idempotent).
//
// Chemin INEXISTANT ⇒ shared.ErrFsPathNotExist (enveloppée) — JAMAIS de création
// de dossier (le handler tranche : erreur d'item pour `present`, satisfait pour
// `absent`).

// Constantes d'ACE (miroir des valeurs winnt.h — x/sys expose les types mais
// pas GetAce/DeleteAce).
const (
	accessAllowedAceType = 0    // ACCESS_ALLOWED_ACE_TYPE
	accessDeniedAceType  = 1    // ACCESS_DENIED_ACE_TYPE
	inheritedAce         = 0x10 // INHERITED_ACE — ACE héritée (jamais possédée)
	// explicitInheritFlagsMask : bits d'héritage conservés pour la comparaison
	// byte-égale avec les flags traduits par le handler (OBJECT_INHERIT|
	// CONTAINER_INHERIT|NO_PROPAGATE|INHERIT_ONLY) — exclut INHERITED_ACE et
	// les bits d'audit.
	explicitInheritFlagsMask = 0x0F
)

var (
	modAdvapi32   = windows.NewLazySystemDLL("advapi32.dll")
	procGetAce    = modAdvapi32.NewProc("GetAce")
	procDeleteAce = modAdvapi32.NewProc("DeleteAce")
)

// fsAclOps : impl shared.FsAclOps de production (Windows NTFS / LSA).
type fsAclOps struct {
	log *shared.Logger
}

// LookupSid résout un NOM en SID string via la LSA du poste joint
// (LookupAccountName, relayée par windows.LookupSID avec un système vide =
// local). Résout les groupes de domaine ET les well-known. Irrésoluble ⇒ err.
func (o *fsAclOps) LookupSid(name string) (string, error) {
	sid, _, _, err := windows.LookupSID("", name)
	if err != nil {
		return "", fmt.Errorf("résolution LSA de %q : %w", name, err)
	}

	return sid.String(), nil
}

// ListExplicitAces retourne les ACE EXPLICITES (non héritées) de la DACL.
// Chemin inexistant ⇒ shared.ErrFsPathNotExist. DACL NULL (accès total) ⇒
// aucune ACE explicite.
func (o *fsAclOps) ListExplicitAces(path string) ([]shared.ExplicitAce, error) {
	sd, err := windows.GetNamedSecurityInfo(path, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		if isNotExist(err) {
			return nil, fmt.Errorf("%s : %w", path, shared.ErrFsPathNotExist)
		}

		return nil, fmt.Errorf("lecture de la DACL de %s : %w", path, err)
	}
	dacl, _, err := sd.DACL()
	if err != nil {
		return nil, fmt.Errorf("extraction de la DACL de %s : %w", path, err)
	}
	if dacl == nil {
		return nil, nil // DACL NULL = tout le monde a accès, aucune ACE explicite
	}

	aces := make([]shared.ExplicitAce, 0, int(dacl.AceCount))
	for i := 0; i < int(dacl.AceCount); i++ {
		hdr, err := getAce(dacl, uint32(i))
		if err != nil {
			return nil, fmt.Errorf("lecture de l'ACE %d de %s : %w", i, path, err)
		}
		if hdr.Header.AceFlags&inheritedAce != 0 {
			continue // ACE héritée : jamais possédée par l'agent
		}
		var aceType string
		switch hdr.Header.AceType {
		case accessAllowedAceType:
			aceType = "allow"
		case accessDeniedAceType:
			aceType = "deny"
		default:
			continue // ACE d'audit/objet : hors périmètre fs_acl
		}
		sid := (*windows.SID)(unsafe.Pointer(&hdr.SidStart))
		aces = append(aces, shared.ExplicitAce{
			SID:     sid.String(),
			AceType: aceType,
			Mask:    uint32(hdr.Mask),
			Flags:   uint32(hdr.Header.AceFlags) & explicitInheritFlagsMask,
		})
	}

	return aces, nil
}

// AddAce pose l'ACE par MERGE chirurgical (jamais de réécriture de DACL).
func (o *fsAclOps) AddAce(path string, ace shared.ExplicitAce) error {
	sid, err := windows.StringToSid(ace.SID)
	if err != nil {
		return fmt.Errorf("SID invalide %q : %w", ace.SID, err)
	}

	mode := windows.ACCESS_MODE(windows.GRANT_ACCESS)
	if strings.EqualFold(ace.AceType, "deny") {
		mode = windows.ACCESS_MODE(windows.DENY_ACCESS)
	}

	entries := []windows.EXPLICIT_ACCESS{{
		AccessPermissions: windows.ACCESS_MASK(ace.Mask),
		AccessMode:        mode,
		Inheritance:       ace.Flags,
		Trustee: windows.TRUSTEE{
			TrusteeForm:  windows.TRUSTEE_IS_SID,
			TrusteeValue: windows.TrusteeValueFromSID(sid),
		},
	}}

	sd, err := windows.GetNamedSecurityInfo(path, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		if isNotExist(err) {
			return fmt.Errorf("%s : %w", path, shared.ErrFsPathNotExist)
		}

		return fmt.Errorf("lecture de la DACL de %s : %w", path, err)
	}
	oldDacl, _, err := sd.DACL()
	if err != nil {
		return fmt.Errorf("extraction de la DACL de %s : %w", path, err)
	}

	// SetEntriesInAcl : merge la nouvelle ACE dans la DACL existante (ordre
	// canonique deny-first géré par Windows).
	newDacl, err := windows.ACLFromEntries(entries, oldDacl)
	if err != nil {
		return fmt.Errorf("construction de la DACL de %s : %w", path, err)
	}

	// DACL_SECURITY_INFORMATION SEUL (SANS PROTECTED_DACL_SECURITY_INFORMATION) :
	// owner, group, SACL et héritage INTACTS (D4).
	if err := windows.SetNamedSecurityInfo(path, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION, nil, nil, newDacl, nil); err != nil {
		return fmt.Errorf("écriture de la DACL de %s : %w", path, err)
	}

	return nil
}

// RemoveAce reconstruit la DACL MOINS l'ACE exactement égale (première
// occurrence explicite). ACE déjà absente ⇒ nil (idempotent).
func (o *fsAclOps) RemoveAce(path string, ace shared.ExplicitAce) error {
	target, err := windows.StringToSid(ace.SID)
	if err != nil {
		return fmt.Errorf("SID invalide %q : %w", ace.SID, err)
	}

	sd, err := windows.GetNamedSecurityInfo(path, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		if isNotExist(err) {
			return fmt.Errorf("%s : %w", path, shared.ErrFsPathNotExist)
		}

		return fmt.Errorf("lecture de la DACL de %s : %w", path, err)
	}
	dacl, _, err := sd.DACL()
	if err != nil {
		return fmt.Errorf("extraction de la DACL de %s : %w", path, err)
	}
	if dacl == nil {
		return nil // pas de DACL → rien à retirer (idempotent)
	}

	wantType := uint8(accessAllowedAceType)
	if strings.EqualFold(ace.AceType, "deny") {
		wantType = accessDeniedAceType
	}

	for i := 0; i < int(dacl.AceCount); i++ {
		hdr, err := getAce(dacl, uint32(i))
		if err != nil {
			return fmt.Errorf("lecture de l'ACE %d de %s : %w", i, path, err)
		}
		if hdr.Header.AceFlags&inheritedAce != 0 || hdr.Header.AceType != wantType {
			continue
		}
		if uint32(hdr.Mask) != ace.Mask {
			continue
		}
		if uint32(hdr.Header.AceFlags)&explicitInheritFlagsMask != ace.Flags {
			continue
		}
		sid := (*windows.SID)(unsafe.Pointer(&hdr.SidStart))
		if !windows.EqualSid(sid, target) {
			continue
		}

		// Match EXACT → DeleteAce à l'index (mutation en place de la DACL) puis
		// réécriture DACL-only.
		if err := deleteAce(dacl, uint32(i)); err != nil {
			return fmt.Errorf("suppression de l'ACE %d de %s : %w", i, path, err)
		}
		if err := windows.SetNamedSecurityInfo(path, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION, nil, nil, dacl, nil); err != nil {
			return fmt.Errorf("écriture de la DACL de %s : %w", path, err)
		}

		return nil
	}

	return nil // ACE déjà absente → idempotent
}

// getAce : GetAce(pAcl, index, &pAce) (lazy proc — non wrappé par x/sys). Les
// ACE allow/denied partagent la layout {Header, Mask, SidStart} → on lit via
// ACCESS_ALLOWED_ACE dans les deux cas.
func getAce(acl *windows.ACL, index uint32) (*windows.ACCESS_ALLOWED_ACE, error) {
	var pAce *windows.ACCESS_ALLOWED_ACE
	ret, _, callErr := procGetAce.Call(
		uintptr(unsafe.Pointer(acl)),
		uintptr(index),
		uintptr(unsafe.Pointer(&pAce)),
	)
	if ret == 0 {
		return nil, fmt.Errorf("GetAce(%d) : %w", index, callErr)
	}

	return pAce, nil
}

// deleteAce : DeleteAce(pAcl, index) (lazy proc — non wrappé par x/sys).
func deleteAce(acl *windows.ACL, index uint32) error {
	ret, _, callErr := procDeleteAce.Call(
		uintptr(unsafe.Pointer(acl)),
		uintptr(index),
	)
	if ret == 0 {
		return fmt.Errorf("DeleteAce(%d) : %w", index, callErr)
	}

	return nil
}

// isNotExist : l'erreur Win32 signale-t-elle un chemin absent (jamais de mkdir) ?
func isNotExist(err error) bool {
	return errors.Is(err, windows.ERROR_FILE_NOT_FOUND) || errors.Is(err, windows.ERROR_PATH_NOT_FOUND)
}

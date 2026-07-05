package main

import (
	"fmt"
	"unsafe"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `privilege` (Story 35.6) — droits de logon LSA
// `SeDeny*` EN GO NATIF via la policy LSA locale. SERVICE SYSTEM seul (le
// compagnon n'a pas les droits, et le type n'existe pas côté session).
//
// La logique de convergence (Test/Apply, réconciliation de CONTENEUR sans
// store, refus SeDeny*-only en défense en profondeur, pas d'application
// partielle sur compte irrésoluble, idempotence) vit dans
// shared.PrivilegeHandler (testée hôte avec un fake) ; ce fichier n'apporte
// que l'impl des 4 ops PrivilegeOps :
//   - LookupSid              : windows.LookupSID("", name) → sid.String()
//     (RÉUTILISE le pattern fsAclOps.LookupSid de 36.1 — LSA locale du poste
//     joint, noms de domaine ET well-known résolus, D5) ;
//   - AccountsWithPrivilege  : LsaOpenPolicy + LsaEnumerateAccountsWithUserRight
//     (lazy proc advapi32 — non wrappé par x/sys, iso pattern GetAce/DeleteAce
//     de 36.1). AUCUN titulaire (STATUS_NO_MORE_ENTRIES /
//     STATUS_OBJECT_NAME_NOT_FOUND) ⇒ liste vide, PAS une erreur (les SeDeny*
//     sont vides par défaut) ; STATUS_NO_SUCH_PRIVILEGE (nom inconnu de la
//     LSA — inatteignable derrière l'allowlist) ⇒ erreur franche nommée ;
//   - GrantPrivilege         : LsaAddAccountRights (déjà titulaire ⇒ succès,
//     idempotent côté LSA) ;
//   - RevokePrivilege        : LsaRemoveAccountRights (compte sans objet LSA /
//     droit déjà absent ⇒ STATUS_OBJECT_NAME_NOT_FOUND traité en succès,
//     idempotent).
//
// AUCUN store sur disque (D4 : le privilège EST le conteneur, la liste des
// titulaires est énumérable). La policy est ouverte/fermée PAR OP (pas de
// handle long-vécu : cycle de convergence espacé, simplicité > micro-perf).

// Constantes LSA (miroir winnt.h/ntsecapi.h — x/sys expose les types
// NTUnicodeString/OBJECT_ATTRIBUTES/NTStatus mais pas les procs Lsa*).
const (
	policyViewLocalInformation = 0x00000001 // POLICY_VIEW_LOCAL_INFORMATION (enumerate)
	policyCreateAccount        = 0x00000010 // POLICY_CREATE_ACCOUNT (add rights → objet compte)
	policyLookupNames          = 0x00000800 // POLICY_LOOKUP_NAMES (add/remove rights)

	// privilegePolicyAccess : le strict nécessaire aux 3 ops LSA (jamais
	// POLICY_ALL_ACCESS — moindre privilège, même en SYSTEM).
	privilegePolicyAccess = policyViewLocalInformation | policyCreateAccount | policyLookupNames
)

var (
	procLsaOpenPolicy                     = modAdvapi32.NewProc("LsaOpenPolicy")
	procLsaClose                          = modAdvapi32.NewProc("LsaClose")
	procLsaFreeMemory                     = modAdvapi32.NewProc("LsaFreeMemory")
	procLsaEnumerateAccountsWithUserRight = modAdvapi32.NewProc("LsaEnumerateAccountsWithUserRight")
	procLsaAddAccountRights               = modAdvapi32.NewProc("LsaAddAccountRights")
	procLsaRemoveAccountRights            = modAdvapi32.NewProc("LsaRemoveAccountRights")
)

// lsaEnumerationInformation : LSA_ENUMERATION_INFORMATION (un SID titulaire).
type lsaEnumerationInformation struct {
	Sid *windows.SID
}

// privilegeOps : impl shared.PrivilegeOps de production (LSA Windows).
type privilegeOps struct {
	log *shared.Logger
}

// LookupSid résout un NOM en SID string via la LSA du poste joint
// (LookupAccountName, relayée par windows.LookupSID avec un système vide =
// local — RÉUTILISE le pattern fsAclOps.LookupSid, 36.1). Irrésoluble ⇒ err
// (erreur d'item, jamais d'application partielle — piège #8).
func (o *privilegeOps) LookupSid(name string) (string, error) {
	sid, _, _, err := windows.LookupSID("", name)
	if err != nil {
		return "", fmt.Errorf("résolution LSA de %q : %w", name, err)
	}

	return sid.String(), nil
}

// AccountsWithPrivilege énumère les SID titulaires du privilège via
// LsaEnumerateAccountsWithUserRight. Liste vide ⇒ (nil, nil).
func (o *privilegeOps) AccountsWithPrivilege(privilege string) ([]string, error) {
	handle, err := openLsaPolicy()
	if err != nil {
		return nil, err
	}
	defer closeLsaPolicy(handle)

	right, err := windows.NewNTUnicodeString(privilege)
	if err != nil {
		return nil, fmt.Errorf("nom de privilège %q : %w", privilege, err)
	}

	// Buffer alloué par la LSA : reçu dans un pointeur TYPÉ (pattern getAce
	// de 36.1 — jamais un uintptr intermédiaire, exigence go vet/GC).
	var buffer *lsaEnumerationInformation
	var count uint32
	ret, _, _ := procLsaEnumerateAccountsWithUserRight.Call(
		handle,
		uintptr(unsafe.Pointer(right)),
		uintptr(unsafe.Pointer(&buffer)),
		uintptr(unsafe.Pointer(&count)),
	)
	status := windows.NTStatus(ret)
	switch status {
	case 0: // STATUS_SUCCESS
	case windows.STATUS_NO_MORE_ENTRIES, windows.STATUS_OBJECT_NAME_NOT_FOUND:
		// AUCUN titulaire : les SeDeny* sont vides par défaut — liste vide,
		// pas une erreur.
		return nil, nil
	case windows.STATUS_NO_SUCH_PRIVILEGE:
		// Inatteignable derrière l'allowlist SeDeny* (double rideau) — nommé
		// explicitement si un binaire recevait un nom hors vocabulaire LSA.
		return nil, fmt.Errorf("privilège %q inconnu de la LSA (STATUS_NO_SUCH_PRIVILEGE)", privilege)
	default:
		return nil, fmt.Errorf("énumération des titulaires de %q : %w", privilege, status.Errno())
	}
	// LsaFreeMemory n'est armé qu'après avoir écarté le buffer nul : une LSA
	// qui renvoie SUCCESS avec count==0/buffer==nil (au lieu de
	// STATUS_NO_MORE_ENTRIES) ne doit pas nous faire libérer un pointeur nul.
	if count == 0 || buffer == nil {
		return nil, nil
	}
	defer procLsaFreeMemory.Call(uintptr(unsafe.Pointer(buffer))) //nolint:errcheck // best-effort, buffer LSA

	// Copie des SID AVANT LsaFreeMemory (le buffer appartient à la LSA).
	entries := unsafe.Slice(buffer, int(count))
	sids := make([]string, 0, len(entries))
	for _, entry := range entries {
		if entry.Sid != nil {
			sids = append(sids, entry.Sid.String())
		}
	}

	return sids, nil
}

// GrantPrivilege accorde le privilège au SID (LsaAddAccountRights — crée
// l'objet compte LSA au besoin ; déjà titulaire ⇒ succès, idempotent).
func (o *privilegeOps) GrantPrivilege(sid, privilege string) error {
	return o.changeAccountRights(sid, privilege, true)
}

// RevokePrivilege retire le privilège au SID (LsaRemoveAccountRights — droit
// déjà absent / compte sans objet LSA ⇒ succès, idempotent).
func (o *privilegeOps) RevokePrivilege(sid, privilege string) error {
	return o.changeAccountRights(sid, privilege, false)
}

func (o *privilegeOps) changeAccountRights(sid, privilege string, grant bool) error {
	parsed, err := windows.StringToSid(sid)
	if err != nil {
		return fmt.Errorf("SID invalide %q : %w", sid, err)
	}

	handle, err := openLsaPolicy()
	if err != nil {
		return err
	}
	defer closeLsaPolicy(handle)

	right, err := windows.NewNTUnicodeString(privilege)
	if err != nil {
		return fmt.Errorf("nom de privilège %q : %w", privilege, err)
	}

	var ret uintptr
	if grant {
		ret, _, _ = procLsaAddAccountRights.Call(
			handle,
			uintptr(unsafe.Pointer(parsed)),
			uintptr(unsafe.Pointer(right)),
			1,
		)
	} else {
		ret, _, _ = procLsaRemoveAccountRights.Call(
			handle,
			uintptr(unsafe.Pointer(parsed)),
			0, // AllRights = FALSE : seulement le droit nommé
			uintptr(unsafe.Pointer(right)),
			1,
		)
	}

	status := windows.NTStatus(ret)
	if status == 0 {
		return nil
	}
	// Retrait d'un droit déjà absent (compte sans objet LSA) : idempotent.
	if !grant && status == windows.STATUS_OBJECT_NAME_NOT_FOUND {
		return nil
	}
	verb := "accord"
	if !grant {
		verb = "révocation"
	}

	return fmt.Errorf("%s de %q sur %s : %w", verb, privilege, sid, status.Errno())
}

// openLsaPolicy ouvre la policy LSA LOCALE avec le strict accès requis.
//
// LsaOpenPolicy attend un LSA_OBJECT_ATTRIBUTES ; on réutilise le
// windows.OBJECT_ATTRIBUTES NT, layout-identique (mêmes champs, même ordre),
// zéroé avec seul Length renseigné — idiome documenté (tous les champs sont
// ignorés/réservés pour LsaOpenPolicy).
func openLsaPolicy() (uintptr, error) {
	var attrs windows.OBJECT_ATTRIBUTES
	attrs.Length = uint32(unsafe.Sizeof(attrs))

	var handle uintptr
	ret, _, _ := procLsaOpenPolicy.Call(
		0, // SystemName NULL = poste local
		uintptr(unsafe.Pointer(&attrs)),
		privilegePolicyAccess,
		uintptr(unsafe.Pointer(&handle)),
	)
	if status := windows.NTStatus(ret); status != 0 {
		return 0, fmt.Errorf("LsaOpenPolicy : %w", status.Errno())
	}

	return handle, nil
}

func closeLsaPolicy(handle uintptr) {
	procLsaClose.Call(handle) //nolint:errcheck // best-effort au defer
}

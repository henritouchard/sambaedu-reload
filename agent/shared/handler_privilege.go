package shared

import (
	"fmt"
	"sort"
	"strings"
)

// Handler `privilege` (exclusive PAR nom de privilège / scope MACHINE
// uniquement) — Story 35.6, contrat §7.9. Troisième mécanisme HORS-REGISTRE.
// Logique PURE, OS-agnostique (les accès LSA réels sont injectés via
// PrivilegeOps) → testée sur l'hôte ; agent/windows n'apporte que l'impl LSA
// (LsaEnumerateAccountsWithUserRight / LsaAddAccountRights /
// LsaRemoveAccountRights + windows.LookupSID).
//
// D4 — PROPRIÉTÉ DU CONTENEUR SANS STORE (iso `firewall`, PAS `fs_acl`,
// écart assumé). Un privilège LSA porte une liste de titulaires ÉNUMÉRABLE
// (LsaEnumerateAccountsWithUserRight) — contrairement à une ACE NTFS, AUCUN
// store « dernier appliqué » n'est nécessaire, AUCUN marqueur : le privilège
// EST le conteneur. Le handler possède la liste EN ENTIER et la réconcilie à
// chaque cycle : accorde les SID désirés manquants, RÉVOQUE tout titulaire
// hors état désiré (y compris un compte accordé à la main). Un
// `accounts: []` VIDE le privilège (off réel).
//
// SÛRETÉ (piège #3) — « posséder la liste entière » n'est sûr QUE parce que
// les privilèges `SeDeny*` sont VIDES PAR DÉFAUT sous Windows (aucun
// titulaire légitime préexistant à écraser). La même convergence sur un droit
// *grant* (SeInteractiveLogonRight, SeRemoteInteractiveLogonRight) révoquerait
// le droit de session à tout le monde → machine VERROUILLÉE, injoignable.
// D'où le REFUS AGENT SeDeny*-only ci-dessous (défense en profondeur,
// INDÉPENDANT du serveur — le serveur peut avoir tort, l'agent ne verrouille
// jamais la machine).
//
// CONVERGENCE level-triggered (§5, STRICT inconditionnel 27.8) :
//   - Test  : pour chaque privilège désiré, l'ensemble des SID titulaires
//     (AccountsWithPrivilege) est EXACTEMENT égal à l'ensemble des SID désirés
//     (résolus par LookupSid) ⇒ conforme, sinon drift (titulaire manquant OU
//     surnuméraire) ;
//   - Apply : effort MAXIMAL par item (première erreur remontée à la fin,
//     idempotent — 2 passes stables = zéro op). Accorde chaque SID désiré
//     manquant, révoque chaque titulaire hors état désiré.
//
// REFUS AGENT = DÉFENSE EN PROFONDEUR (piège #9), dans Test ET Apply :
//   - `privilege` HORS de l'allowlist SeDeny* (privilegeAllowlist, MIROIR de
//     PrivilegeAuthoringGuard::ALLOWED_PRIVILEGES côté PHP) ⇒ erreur d'ITEM,
//     JAMAIS appliqué ;
//   - compte IRRÉSOLUBLE via LSA ⇒ erreur d'item avec détail (le compte
//     fautif), et la réconciliation de CE privilège n'est PAS appliquée
//     partiellement (piège #8 — un trou « un élève non refusé » serait
//     silencieux) ; les AUTRES privilèges convergent, l'erreur remonte
//     TOUJOURS (verdict `error` du type) ;
//   - compte résolvant un principal à LARGE PORTÉE (broadPrincipalSids : Everyone
//     S-1-1-0, Authenticated Users S-1-5-11, Users, Administrators, Interactive,
//     Domain Users/Admins par RID… — MIROIR de PrivilegeAuthoringGuard côté PHP)
//     ⇒ erreur d'item SANS application partielle : une SeDeny* LÉGITIME (donc
//     hors du filet allowlist) sur un principal trop large verrouillerait le
//     poste. L'agent tranche sur le SID RÉSOLU (robuste locale/alias) ;
//   - payload statiquement invalide (clé manquante, `accounts` non-liste) ⇒
//     enveloppe invalide ⇒ {status: error} pour le type (iso registry/fs_acl).
//
// EFFET AU LOGON SUIVANT (piège #5) : les droits de logon `SeDeny*` sont
// évalués par Windows à l'OUVERTURE de session — accorder le deny ne coupe
// pas une session en cours, la PROCHAINE tentative est refusée ; le retrait
// (`accounts: []`) rétablit le logon au logon suivant, sans reboot. Sémantique
// Windows, pas un bug.

// privilegeAllowlist : les 5 droits de logon `SeDeny*` — enum FERMÉ (D3),
// MIROIR EXACT de la constante PHP PrivilegeAuthoringGuard::ALLOWED_PRIVILEGES
// (le serveur refuse à l'authoring, l'agent refuse à l'application — double
// rideau). Clés en minuscules (comparaison insensible à la casse).
var privilegeAllowlist = map[string]bool{
	"sedenyinteractivelogonright":       true,
	"sedenynetworklogonright":           true,
	"sedenybatchlogonright":             true,
	"sedenyservicelogonright":           true,
	"sedenyremoteinteractivelogonright": true,
}

// broadPrincipalSids : SID well-known à LARGE PORTÉE refusés comme titulaires
// d'une SeDeny* (défense en profondeur « portée », MIROIR de
// PrivilegeAuthoringGuard::BROAD_SIDS/BROAD_PRINCIPALS côté PHP). Poser une
// SeDeny* sur l'un d'eux verrouille le poste (personne ne peut plus ouvrir de
// session du type refusé). L'agent tranche sur le SID RÉSOLU par la LSA — donc
// robuste à la locale et aux alias de nom (là où le serveur ne voit que le nom
// nommable). Clés en MAJUSCULES (les SID.String() le sont). Les groupes de
// domaine à large portée (Domain Users/Admins/Computers) sont attrapés par
// suffixe de RID (voir isBroadPrincipalSid).
var broadPrincipalSids = map[string]bool{
	"S-1-1-0":      true, // Everyone
	"S-1-5-11":     true, // Authenticated Users
	"S-1-5-4":      true, // Interactive
	"S-1-5-2":      true, // Network
	"S-1-5-13":     true, // Terminal Server User
	"S-1-5-14":     true, // Remote Interactive Logon
	"S-1-5-18":     true, // Local System
	"S-1-5-32-544": true, // Administrators
	"S-1-5-32-545": true, // Users
}

// isBroadPrincipalSid : le SID résolu est-il à large portée ? SID well-known
// exact OU RID de groupe de domaine à large portée (`…-513` Domain Users,
// `-512` Domain Admins, `-515` Domain Computers).
func isBroadPrincipalSid(sid string) bool {
	up := strings.ToUpper(strings.TrimSpace(sid))
	if broadPrincipalSids[up] {
		return true
	}
	for _, rid := range []string{"-513", "-512", "-515"} {
		if strings.HasSuffix(up, rid) {
			return true
		}
	}

	return false
}

// PrivilegeOps : accès LSA spécifiques à l'OS, injectés (testable hôte).
// L'impl Windows vit dans agent/windows/handler_privilege_windows.go (policy
// LSA + LookupSID) ; un fake en mémoire couvre les tests. L'interface
// n'expose AUCUNE op de fichier : le mécanisme est SANS store (D4, structurel).
type PrivilegeOps interface {
	// LookupSid résout un NOM (DOMAIN\name, nom nu, well-known) en SID string
	// via la LSA du poste joint (windows.LookupSID — RÉUTILISE le pattern
	// fsAclOps.LookupSid de 36.1). Irrésoluble ⇒ err (erreur d'item).
	LookupSid(name string) (sid string, err error)

	// AccountsWithPrivilege énumère les SID titulaires du privilège
	// (LsaEnumerateAccountsWithUserRight). AUCUN titulaire ⇒ (nil, nil) — une
	// liste vide n'est PAS une erreur (les SeDeny* sont vides par défaut).
	AccountsWithPrivilege(privilege string) ([]string, error)

	// GrantPrivilege accorde le privilège au SID (LsaAddAccountRights).
	// Déjà titulaire ⇒ nil (idempotent côté LSA).
	GrantPrivilege(sid, privilege string) error

	// RevokePrivilege retire le privilège au SID (LsaRemoveAccountRights).
	// Déjà absent ⇒ nil (idempotent).
	RevokePrivilege(sid, privilege string) error
}

// PrivilegeSpec : un privilège cible (un item du payload §7.9, EXACTEMENT
// 2 clés).
type PrivilegeSpec struct {
	Privilege string   // un des 5 SeDeny* (allowlist)
	Accounts  []string // NOMS Windows (jamais des SID) ; vide = privilège vidé
}

// identity : identité exclusive = le nom du privilège en minuscules (iso
// exclusiveKey serveur, 1 segment — la maille gagnante prend la liste ENTIÈRE).
func (s PrivilegeSpec) identity() string { return strings.ToLower(s.Privilege) }

// parsePrivilegeSpec : extrait un PrivilegeSpec d'un payload §7.9 brut.
// Enveloppe invalide (false → {status: error} pour le type) si : le payload
// n'est pas un objet, `privilege` est absent/vide, `accounts` est absent ou
// n'est pas une liste de strings. Une liste VIDE est VALIDE (off réel). Le
// contrôle d'allowlist n'est PAS ici : un SeDeny inconnu / un grant est une
// erreur d'ITEM (piège #9), pas une enveloppe invalide.
func parsePrivilegeSpec(raw any) (PrivilegeSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return PrivilegeSpec{}, false
	}

	privilege, _ := payload["privilege"].(string)
	if strings.TrimSpace(privilege) == "" {
		return PrivilegeSpec{}, false
	}

	rawAccounts, present := payload["accounts"]
	if !present {
		return PrivilegeSpec{}, false
	}
	accounts, ok := stringSlice(rawAccounts)
	if !ok {
		return PrivilegeSpec{}, false
	}

	return PrivilegeSpec{Privilege: privilege, Accounts: accounts}, true
}

// privilegeItemViolation : raison NON vide si l'item doit être REFUSÉ (défense
// en profondeur SeDeny*-only, piège #9), sinon "". MIROIR du guard PHP : tout
// droit hors allowlist — un *grant* ou un SeDeny inconnu — n'est JAMAIS
// appliqué (une convergence « possède la liste entière » sur un grant
// verrouillerait la machine).
func privilegeItemViolation(spec PrivilegeSpec) string {
	if !privilegeAllowlist[spec.identity()] {
		return fmt.Sprintf("privilège %q hors de l'allowlist SeDeny* — un droit grant possédé en liste entière verrouillerait la machine (piège #3), jamais appliqué", spec.Privilege)
	}

	return ""
}

// --- Résolution SID mémoïsée PAR PASSE (piège #7) -----------------------------

type privilegeSidMemo struct {
	ops   PrivilegeOps
	cache map[string]string
	errs  map[string]error
}

func newPrivilegeSidMemo(ops PrivilegeOps) *privilegeSidMemo {
	return &privilegeSidMemo{ops: ops, cache: map[string]string{}, errs: map[string]error{}}
}

func (m *privilegeSidMemo) resolve(name string) (string, error) {
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

// PrivilegeHandler : handler exclusive-par-privilège branché dans le moteur
// (engine.go INTOUCHÉ — la machine d'états §5 reste au moteur). SERVICE SYSTEM
// seul. AUCUN champ de store (D4 : le privilège est le conteneur, les
// titulaires sont énumérables — le test structurel l'atteste).
type PrivilegeHandler struct {
	Ops PrivilegeOps
	Log *Logger
}

// desiredSpecs : parse + dédoublonne par privilège les items cible. Le serveur
// garantit déjà l'unicité (exclusive par clé au compilateur) ; défense : la
// DERNIÈRE occurrence fait foi, ordre de sortie TRIÉ (logs/erreurs stables,
// iso desiredSpecs de firewall/registry_list).
func (h *PrivilegeHandler) desiredSpecs(items []StateItem) ([]PrivilegeSpec, error) {
	byID := map[string]PrivilegeSpec{}
	order := []string{}
	for _, item := range items {
		spec, ok := parsePrivilegeSpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload privilege inattendu : enveloppe invalide")
		}
		id := spec.identity()
		if _, seen := byID[id]; !seen {
			order = append(order, id)
		}
		byID[id] = spec
	}

	sort.Strings(order)
	specs := make([]PrivilegeSpec, 0, len(order))
	for _, id := range order {
		specs = append(specs, byID[id])
	}

	return specs, nil
}

// desiredSids : résout TOUS les comptes d'un item en un ensemble de SID
// (clé = SID majuscule, valeur = SID tel que résolu). UN compte irrésoluble ⇒
// erreur (piège #8 : l'item entier est en erreur, JAMAIS d'application
// partielle — les comptes déjà résolus ne sont pas accordés).
func desiredSids(memo *privilegeSidMemo, spec PrivilegeSpec) (map[string]string, error) {
	desired := map[string]string{}
	for _, account := range spec.Accounts {
		sid, err := memo.resolve(account)
		if err != nil {
			return nil, fmt.Errorf("résolution LSA du compte %q (%s) : %w", account, spec.identity(), err)
		}
		// Refus PORTÉE (défense en profondeur, MIROIR du guard PHP) : une
		// SeDeny* sur un principal à large portée verrouille le poste ⇒ erreur
		// d'item, PAS d'application partielle (iso compte irrésoluble, piège #8).
		if isBroadPrincipalSid(sid) {
			return nil, fmt.Errorf("compte %q (%s) résout un principal à large portée %s — une SeDeny* dessus verrouillerait le poste, jamais appliqué", account, spec.identity(), sid)
		}
		desired[strings.ToUpper(sid)] = sid
	}

	return desired, nil
}

// Test : conforme ssi, pour CHAQUE privilège désiré, l'ensemble des SID
// titulaires est EXACTEMENT égal à l'ensemble des SID désirés (manquant OU
// surnuméraire ⇒ drift). Un item refusé (allowlist) / un compte irrésoluble /
// une énumération illisible rend NON conforme (l'Apply surfacera l'erreur
// d'item — effort maximal) ; un payload invalide est une erreur franche.
func (h *PrivilegeHandler) Test(items []StateItem) (bool, error) {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return false, err
	}

	memo := newPrivilegeSidMemo(h.Ops)
	for _, spec := range specs {
		// Refus SeDeny*-only (piège #9) : un item refusé ne sera jamais
		// appliqué → non conforme (l'Apply surfacera l'erreur d'item).
		if privilegeItemViolation(spec) != "" {
			return false, nil
		}

		desired, err := desiredSids(memo, spec)
		if err != nil {
			return false, nil // irrésoluble → Apply surfacera l'erreur d'item
		}

		actual, err := h.Ops.AccountsWithPrivilege(spec.Privilege)
		if err != nil {
			return false, nil // illisible → Apply (effort maximal) tranchera
		}

		// Égalité d'ENSEMBLES (l'ordre n'est pas porteur de sens).
		seen := map[string]bool{}
		for _, sid := range actual {
			key := strings.ToUpper(sid)
			if _, ok := desired[key]; !ok {
				return false, nil // titulaire surnuméraire ⇒ drift
			}
			seen[key] = true
		}
		for key := range desired {
			if !seen[key] {
				return false, nil // titulaire manquant ⇒ drift
			}
		}
	}

	return true, nil
}

// Apply : converge chaque privilège en effort MAXIMAL par item (première
// erreur remontée à la fin, idempotent — 2 passes stables = zéro op).
// Réconciliation de CONTENEUR (D4) : accorde les SID désirés manquants,
// révoque tout titulaire hors état désiré ; `accounts: []` VIDE le privilège.
// AUCUN store (piège #2). SID mémoïsés PAR PASSE seulement (piège #7).
func (h *PrivilegeHandler) Apply(items []StateItem) error {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return err
	}

	var firstErr error
	record := func(e error) {
		if firstErr == nil {
			firstErr = e
		}
	}

	memo := newPrivilegeSidMemo(h.Ops)
	for _, spec := range specs {
		id := spec.identity()

		// Refus SeDeny*-only (piège #9, défense en profondeur, INDÉPENDANT du
		// serveur) : erreur d'item isolée, les autres items convergent.
		if reason := privilegeItemViolation(spec); reason != "" {
			record(fmt.Errorf("privilege refusé (%s) : %s", id, reason))

			continue
		}

		// Résolution de TOUS les comptes AVANT toute op (piège #8) : un compte
		// irrésoluble ⇒ erreur d'item, la réconciliation de CE privilège n'est
		// PAS appliquée partiellement.
		desired, err := desiredSids(memo, spec)
		if err != nil {
			record(err)

			continue
		}

		actual, err := h.Ops.AccountsWithPrivilege(spec.Privilege)
		if err != nil {
			record(fmt.Errorf("énumération des titulaires de %s : %w", id, err))

			continue
		}

		actualSet := map[string]string{}
		for _, sid := range actual {
			actualSet[strings.ToUpper(sid)] = sid
		}

		// (1) Révoquer les titulaires surnuméraires — ordre trié (déterminisme).
		strayKeys := []string{}
		for key := range actualSet {
			if _, keep := desired[key]; !keep {
				strayKeys = append(strayKeys, key)
			}
		}
		sort.Strings(strayKeys)
		for _, key := range strayKeys {
			if err := h.Ops.RevokePrivilege(actualSet[key], spec.Privilege); err != nil {
				record(fmt.Errorf("révocation de %s sur %s : %w", actualSet[key], id, err))

				continue
			}
			logInfo(h.Log, "Privilège %s révoqué pour %s (titulaire hors état désiré).", spec.Privilege, actualSet[key])
		}

		// (2) Accorder les SID désirés manquants — ordre trié.
		missingKeys := []string{}
		for key := range desired {
			if _, present := actualSet[key]; !present {
				missingKeys = append(missingKeys, key)
			}
		}
		sort.Strings(missingKeys)
		for _, key := range missingKeys {
			if err := h.Ops.GrantPrivilege(desired[key], spec.Privilege); err != nil {
				record(fmt.Errorf("accord de %s sur %s : %w", desired[key], id, err))

				continue
			}
			logInfo(h.Log, "Privilège %s accordé à %s.", spec.Privilege, desired[key])
		}
	}

	return firstErr
}

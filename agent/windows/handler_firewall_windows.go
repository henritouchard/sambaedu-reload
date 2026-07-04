package main

import (
	"fmt"
	"strings"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `firewall` (Story 36.2, piège #6) — pilotage du
// pare-feu Windows via COM INetFwPolicy2 EN GO NATIF (vtables syscall pures,
// ZÉRO dépendance ajoutée : ni netsh, ni go-ole). Deux raisons excluent netsh :
// (1) `netsh advfirewall firewall add rule` ne sait PAS poser `Grouping` (le
// paramètre `group` n'active que des groupes prédéfinis) — or le Grouping EST
// notre marqueur de propriété (D4) ; (2) le shell-out est fragile. go-ole serait
// une nouvelle dépendance (go.mod = x/sys + x/text seuls) : refusée. Le
// précédent maison est `handler_shortcuts_windows.go` (COM IShellLink en vtables
// syscall). Ici : CoCreateInstance(HNetCfg.FwPolicy2) → INetFwPolicy2 →
// get_Rules → INetFwRules (Add / Remove(name) / get__NewEnum → IEnumVARIANT) →
// INetFwRule (put/get Name, Grouping, Direction, Action, Protocol, LocalPorts,
// RemotePorts, RemoteAddresses, Enabled, Profiles).
//
// GUIDs et ORDRE des vtables tirés des en-têtes SDK (netfw.h / icftypes.h) — pas
// de recopie de mémoire. Toutes les interfaces INetFw* sont DUAL (IDispatch) :
// le préfixe de vtable est IUnknown(0..2) + IDispatch(3..6), les méthodes de
// l'interface commencent à l'index 7.
//
// Quirk API : `put_Protocol` DOIT précéder `put_LocalPorts`/`put_RemotePorts`
// (l'ordre inverse échoue). `Profiles = ALL`, `Enabled = TRUE` sur toute règle
// posée. Une mutation = Remove + Add (recréation atomique, jamais in-place) —
// gérée par le handler shared. SERVICE SYSTEM seul (câblé main_windows.go).

// --- GUIDs COM (netfw.h) -----------------------------------------------------

var (
	clsidNetFwPolicy2 = windows.GUID{Data1: 0xE2B3C97F, Data2: 0x6AE1, Data3: 0x41AC, Data4: [8]byte{0x81, 0x7A, 0xF6, 0xF9, 0x21, 0x66, 0xD7, 0xDD}}
	iidINetFwPolicy2  = windows.GUID{Data1: 0x98325047, Data2: 0xC671, Data3: 0x4174, Data4: [8]byte{0x8D, 0x81, 0xDE, 0xFC, 0xD3, 0xF0, 0x31, 0x86}}
	clsidNetFwRule    = windows.GUID{Data1: 0x2C5BC43E, Data2: 0x3369, Data3: 0x4C33, Data4: [8]byte{0xAB, 0x0C, 0xBE, 0x94, 0x69, 0x67, 0x7A, 0xF4}}
	iidINetFwRule     = windows.GUID{Data1: 0xAF230D27, Data2: 0xBABA, Data3: 0x4E42, Data4: [8]byte{0xAC, 0xED, 0xF5, 0x24, 0xF2, 0x2C, 0xFC, 0xE2}}
	iidIEnumVariant   = windows.GUID{Data1: 0x00020404, Data2: 0x0000, Data3: 0x0000, Data4: [8]byte{0xC0, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x46}}
)

// Constantes NET_FW_* (icftypes.h) + VARIANT.
const (
	netFwRuleDirIn  = 1
	netFwRuleDirOut = 2

	netFwActionBlock = 0
	netFwActionAllow = 1

	netFwIPProtocolTCP = 6
	netFwIPProtocolUDP = 17
	netFwIPProtocolAny = 256

	netFwProfile2All = 0x7FFFFFFF

	variantBoolTrue = 0xFFFF // VARIANT_TRUE (VARIANT_BOOL = -1)
	vtDispatch      = 9      // VT_DISPATCH
)

var (
	modOleaut32        = windows.NewLazySystemDLL("oleaut32.dll")
	procSysAllocString = modOleaut32.NewProc("SysAllocString")
	procSysFreeString  = modOleaut32.NewProc("SysFreeString")
)

// --- Vtables (ordre ABI figé, netfw.h) ---------------------------------------

// iDispatchVtbl : préfixe IUnknown (index 0..2, déclaré dans
// handler_shortcuts_windows.go) + IDispatch (index 3..6). Partagé par toutes
// les interfaces INetFw* (dual).
type iDispatchVtbl struct {
	iUnknownVtbl
	GetTypeInfoCount uintptr
	GetTypeInfo      uintptr
	GetIDsOfNames    uintptr
	Invoke           uintptr
}

// INetFwPolicy2 — on ne cible que get_Rules (index 18).
type iNetFwPolicy2Vtbl struct {
	iDispatchVtbl
	getCurrentProfileTypes                          uintptr // 7
	getFirewallEnabled                              uintptr // 8
	putFirewallEnabled                              uintptr // 9
	getExcludedInterfaces                           uintptr // 10
	putExcludedInterfaces                           uintptr // 11
	getBlockAllInboundTraffic                       uintptr // 12
	putBlockAllInboundTraffic                       uintptr // 13
	getNotificationsDisabled                        uintptr // 14
	putNotificationsDisabled                        uintptr // 15
	getUnicastResponsesToMulticastBroadcastDisabled uintptr // 16
	putUnicastResponsesToMulticastBroadcastDisabled uintptr // 17
	getRules                                        uintptr // 18
	// (get_ServiceRestriction, EnableRuleGroup, DefaultInboundAction… non
	// utilisés — JAMAIS de mutation de politique par défaut/service, piège #11.)
}

type iNetFwPolicy2 struct{ vtbl *iNetFwPolicy2Vtbl }

// INetFwRules — get_Count(7) / Add(8) / Remove(9) / Item(10) / get__NewEnum(11).
type iNetFwRulesVtbl struct {
	iDispatchVtbl
	getCount   uintptr // 7
	add        uintptr // 8  Add(INetFwRule*)
	remove     uintptr // 9  Remove(BSTR)
	item       uintptr // 10 Item(BSTR, INetFwRule**)
	getNewEnum uintptr // 11 get__NewEnum(IUnknown**)
}

type iNetFwRules struct{ vtbl *iNetFwRulesVtbl }

// INetFwRule — table COMPLÈTE dans l'ordre ABI (get/put par propriété).
type iNetFwRuleVtbl struct {
	iDispatchVtbl
	getName             uintptr // 7
	putName             uintptr // 8
	getDescription      uintptr // 9
	putDescription      uintptr // 10
	getApplicationName  uintptr // 11
	putApplicationName  uintptr // 12
	getServiceName      uintptr // 13
	putServiceName      uintptr // 14
	getProtocol         uintptr // 15
	putProtocol         uintptr // 16
	getLocalPorts       uintptr // 17
	putLocalPorts       uintptr // 18
	getRemotePorts      uintptr // 19
	putRemotePorts      uintptr // 20
	getLocalAddresses   uintptr // 21
	putLocalAddresses   uintptr // 22
	getRemoteAddresses  uintptr // 23
	putRemoteAddresses  uintptr // 24
	getIcmpTypesAndCodes uintptr // 25
	putIcmpTypesAndCodes uintptr // 26
	getDirection        uintptr // 27
	putDirection        uintptr // 28
	getInterfaces       uintptr // 29
	putInterfaces       uintptr // 30
	getInterfaceTypes   uintptr // 31
	putInterfaceTypes   uintptr // 32
	getEnabled          uintptr // 33
	putEnabled          uintptr // 34
	getGrouping         uintptr // 35
	putGrouping         uintptr // 36
	getProfiles         uintptr // 37
	putProfiles         uintptr // 38
	getEdgeTraversal    uintptr // 39
	putEdgeTraversal    uintptr // 40
	getAction           uintptr // 41
	putAction           uintptr // 42
}

type iNetFwRule struct{ vtbl *iNetFwRuleVtbl }

// IEnumVARIANT — Next(3) / Skip(4) / Reset(5) / Clone(6).
type iEnumVariantVtbl struct {
	iUnknownVtbl
	next  uintptr // 3
	skip  uintptr // 4
	reset uintptr // 5
	clone uintptr // 6
}

type iEnumVariant struct{ vtbl *iEnumVariantVtbl }

// variant : VARIANT x64 (24 octets). On ne lit que VT_DISPATCH → `val` =
// pointeur (unsafe.Pointer pour rester visible du GC, jamais un uintptr nu).
type variant struct {
	vt         uint16
	wReserved1 uint16
	wReserved2 uint16
	wReserved3 uint16
	val        unsafe.Pointer
	_          uintptr
}

// --- firewallOps : impl FirewallOps de production (Windows COM) --------------

type firewallOps struct {
	log *shared.Logger
}

func (o *firewallOps) logf(format string, args ...any) {
	if o.log != nil {
		o.log.Debugf(format, args...)
	}
}

// withFwRules initialise COM, crée INetFwPolicy2, résout get_Rules → INetFwRules,
// exécute fn, libère tout (jamais de fuite de référence).
func withFwRules(fn func(rules *iNetFwRules) error) (retErr error) {
	hr, _, _ := procCoInitializeEx.Call(0, coinitApartmentThreaded)
	if int32(hr) < 0 {
		return fmt.Errorf("CoInitializeEx en échec (hr=0x%x)", uint32(hr))
	}
	defer procCoUninitialize.Call()

	var policyPtr unsafe.Pointer
	hr, _, _ = procCoCreateInst.Call(
		uintptr(unsafe.Pointer(&clsidNetFwPolicy2)),
		0,
		clsctxInprocServer,
		uintptr(unsafe.Pointer(&iidINetFwPolicy2)),
		uintptr(unsafe.Pointer(&policyPtr)),
	)
	if int32(hr) < 0 || policyPtr == nil {
		return fmt.Errorf("CoCreateInstance(NetFwPolicy2) en échec (hr=0x%x)", uint32(hr))
	}
	policy := (*iNetFwPolicy2)(policyPtr)
	defer release(policy.vtbl.Release, policyPtr)

	var rulesPtr unsafe.Pointer
	hr, _, _ = syscall.SyscallN(policy.vtbl.getRules, uintptr(policyPtr), uintptr(unsafe.Pointer(&rulesPtr)))
	if int32(hr) < 0 || rulesPtr == nil {
		return fmt.Errorf("INetFwPolicy2::get_Rules en échec (hr=0x%x)", uint32(hr))
	}
	rules := (*iNetFwRules)(rulesPtr)
	defer release(rules.vtbl.Release, rulesPtr)

	return fn(rules)
}

// ListGroupRules énumère les règles du GROUPE `group` (via get__NewEnum →
// IEnumVARIANT), filtrées sur leur `Grouping`. Les règles hors groupe ne sont
// JAMAIS retournées (D4).
func (o *firewallOps) ListGroupRules(group string) ([]shared.FwRule, error) {
	var out []shared.FwRule
	err := withFwRules(func(rules *iNetFwRules) error {
		var enumPtr unsafe.Pointer
		hr, _, _ := syscall.SyscallN(rules.vtbl.getNewEnum, uintptr(unsafe.Pointer(rules)), uintptr(unsafe.Pointer(&enumPtr)))
		if int32(hr) < 0 || enumPtr == nil {
			return fmt.Errorf("INetFwRules::get__NewEnum en échec (hr=0x%x)", uint32(hr))
		}
		unk := (*iUnknownOnly)(enumPtr)
		defer release(unk.vtbl.Release, enumPtr)

		var enumVarPtr unsafe.Pointer
		hr, _, _ = syscall.SyscallN(unk.vtbl.QueryInterface,
			uintptr(enumPtr),
			uintptr(unsafe.Pointer(&iidIEnumVariant)),
			uintptr(unsafe.Pointer(&enumVarPtr)),
		)
		if int32(hr) < 0 || enumVarPtr == nil {
			return fmt.Errorf("QueryInterface(IEnumVARIANT) en échec (hr=0x%x)", uint32(hr))
		}
		enum := (*iEnumVariant)(enumVarPtr)
		defer release(enum.vtbl.Release, enumVarPtr)

		for {
			var v variant
			var fetched uint32
			hr, _, _ = syscall.SyscallN(enum.vtbl.next,
				uintptr(enumVarPtr),
				1,
				uintptr(unsafe.Pointer(&v)),
				uintptr(unsafe.Pointer(&fetched)),
			)
			if int32(hr) < 0 || fetched == 0 {
				break
			}
			if v.vt != vtDispatch || v.val == nil {
				continue
			}
			dispPtr := v.val
			disp := (*iUnknownOnly)(dispPtr)

			var rulePtr unsafe.Pointer
			hr, _, _ = syscall.SyscallN(disp.vtbl.QueryInterface,
				uintptr(dispPtr),
				uintptr(unsafe.Pointer(&iidINetFwRule)),
				uintptr(unsafe.Pointer(&rulePtr)),
			)
			release(disp.vtbl.Release, dispPtr) // libère le VARIANT dispatch
			if int32(hr) < 0 || rulePtr == nil {
				continue
			}
			rule := (*iNetFwRule)(rulePtr)
			grouping := ruleGetStr(rule, rule.vtbl.getGrouping)
			if strings.EqualFold(grouping, group) {
				out = append(out, o.readRule(rule, grouping))
			}
			release(rule.vtbl.Release, rulePtr)
		}

		return nil
	})

	return out, err
}

// readRule lit une INetFwRule (déjà résolue au bon groupe) en shared.FwRule.
func (o *firewallOps) readRule(rule *iNetFwRule, grouping string) shared.FwRule {
	name := ruleGetStr(rule, rule.vtbl.getName)
	dir := ruleGetLong(rule, rule.vtbl.getDirection)
	action := ruleGetLong(rule, rule.vtbl.getAction)
	protocol := ruleGetLong(rule, rule.vtbl.getProtocol)
	enabled := ruleGetBool(rule, rule.vtbl.getEnabled)
	remoteAddr := ruleGetStr(rule, rule.vtbl.getRemoteAddresses)
	localPorts := ruleGetStr(rule, rule.vtbl.getLocalPorts)
	remotePorts := ruleGetStr(rule, rule.vtbl.getRemotePorts)

	return shared.FwRule{
		Name:            name,
		Grouping:        grouping,
		Direction:       directionFromLong(dir),
		Action:          actionFromLong(action),
		Protocol:        protocolFromLong(protocol),
		Enabled:         enabled,
		RemoteAddresses: splitCsv(remoteAddr),
		LocalPorts:      splitCsv(localPorts),
		RemotePorts:     splitCsv(remotePorts),
	}
}

// AddRule crée une règle et l'ajoute au conteneur (ordre put_Protocol AVANT les
// ports, piège #6 ; Profiles=ALL, Enabled=TRUE, Grouping posé).
func (o *firewallOps) AddRule(r shared.FwRule) error {
	return withFwRules(func(rules *iNetFwRules) error {
		var rulePtr unsafe.Pointer
		hr, _, _ := procCoCreateInst.Call(
			uintptr(unsafe.Pointer(&clsidNetFwRule)),
			0,
			clsctxInprocServer,
			uintptr(unsafe.Pointer(&iidINetFwRule)),
			uintptr(unsafe.Pointer(&rulePtr)),
		)
		if int32(hr) < 0 || rulePtr == nil {
			return fmt.Errorf("CoCreateInstance(NetFwRule) en échec (hr=0x%x)", uint32(hr))
		}
		rule := (*iNetFwRule)(rulePtr)
		defer release(rule.vtbl.Release, rulePtr)

		if err := rulePutStr(rule, rule.vtbl.putName, r.Name); err != nil {
			return fmt.Errorf("put_Name : %w", err)
		}
		if err := rulePutStr(rule, rule.vtbl.putGrouping, r.Grouping); err != nil {
			return fmt.Errorf("put_Grouping : %w", err)
		}
		if err := rulePutLong(rule, rule.vtbl.putDirection, directionToLong(r.Direction)); err != nil {
			return fmt.Errorf("put_Direction : %w", err)
		}
		if err := rulePutLong(rule, rule.vtbl.putAction, actionToLong(r.Action)); err != nil {
			return fmt.Errorf("put_Action : %w", err)
		}
		// put_Protocol AVANT les ports (quirk API).
		if err := rulePutLong(rule, rule.vtbl.putProtocol, protocolToLong(r.Protocol)); err != nil {
			return fmt.Errorf("put_Protocol : %w", err)
		}
		if lp := joinCsv(r.LocalPorts); lp != "" {
			if err := rulePutStr(rule, rule.vtbl.putLocalPorts, lp); err != nil {
				return fmt.Errorf("put_LocalPorts : %w", err)
			}
		}
		if rp := joinCsv(r.RemotePorts); rp != "" {
			if err := rulePutStr(rule, rule.vtbl.putRemotePorts, rp); err != nil {
				return fmt.Errorf("put_RemotePorts : %w", err)
			}
		}
		if ra := joinCsv(r.RemoteAddresses); ra != "" {
			if err := rulePutStr(rule, rule.vtbl.putRemoteAddresses, ra); err != nil {
				return fmt.Errorf("put_RemoteAddresses : %w", err)
			}
		}
		if err := rulePutLong(rule, rule.vtbl.putProfiles, netFwProfile2All); err != nil {
			return fmt.Errorf("put_Profiles : %w", err)
		}
		if err := rulePutBool(rule, rule.vtbl.putEnabled, true); err != nil {
			return fmt.Errorf("put_Enabled : %w", err)
		}

		hr, _, _ = syscall.SyscallN(rules.vtbl.add, uintptr(unsafe.Pointer(rules)), uintptr(rulePtr))
		if int32(hr) < 0 {
			return fmt.Errorf("INetFwRules::Add(%s) en échec (hr=0x%x)", r.Name, uint32(hr))
		}

		return nil
	})
}

// RemoveRule supprime la règle nommée (déjà absente ⇒ succès idempotent : le hr
// est ignoré — Remove d'un nom inconnu ne doit jamais faire échouer la passe).
func (o *firewallOps) RemoveRule(name string) error {
	return withFwRules(func(rules *iNetFwRules) error {
		bstr, err := sysAllocString(name)
		if err != nil {
			return err
		}
		defer sysFreeString(bstr)
		syscall.SyscallN(rules.vtbl.remove, uintptr(unsafe.Pointer(rules)), bstr)

		return nil
	})
}

// --- Helpers get/put ---------------------------------------------------------

// iUnknownOnly : vue IUnknown seule (pour QueryInterface/Release sur un pointeur
// d'interface dont on ne connaît que le préfixe IUnknown).
type iUnknownOnly struct{ vtbl *iUnknownVtbl }

func rulePutStr(rule *iNetFwRule, method uintptr, value string) error {
	bstr, err := sysAllocString(value)
	if err != nil {
		return err
	}
	defer sysFreeString(bstr)
	hr, _, _ := syscall.SyscallN(method, uintptr(unsafe.Pointer(rule)), bstr)
	if int32(hr) < 0 {
		return fmt.Errorf("hr=0x%x", uint32(hr))
	}

	return nil
}

func rulePutLong(rule *iNetFwRule, method uintptr, value int32) error {
	hr, _, _ := syscall.SyscallN(method, uintptr(unsafe.Pointer(rule)), uintptr(uint32(value)))
	if int32(hr) < 0 {
		return fmt.Errorf("hr=0x%x", uint32(hr))
	}

	return nil
}

func rulePutBool(rule *iNetFwRule, method uintptr, value bool) error {
	v := uintptr(0)
	if value {
		v = variantBoolTrue
	}
	hr, _, _ := syscall.SyscallN(method, uintptr(unsafe.Pointer(rule)), v)
	if int32(hr) < 0 {
		return fmt.Errorf("hr=0x%x", uint32(hr))
	}

	return nil
}

func ruleGetStr(rule *iNetFwRule, method uintptr) string {
	// La méthode écrit un BSTR (pointeur *uint16) dans notre variable : la
	// déclarer *uint16 évite toute conversion uintptr→unsafe.Pointer (vet).
	var bstr *uint16
	syscall.SyscallN(method, uintptr(unsafe.Pointer(rule)), uintptr(unsafe.Pointer(&bstr)))
	if bstr == nil {
		return ""
	}
	s := windows.UTF16PtrToString(bstr)
	sysFreeString(uintptr(unsafe.Pointer(bstr)))

	return s
}

func ruleGetLong(rule *iNetFwRule, method uintptr) int32 {
	var v int32
	syscall.SyscallN(method, uintptr(unsafe.Pointer(rule)), uintptr(unsafe.Pointer(&v)))

	return v
}

func ruleGetBool(rule *iNetFwRule, method uintptr) bool {
	var v int16
	syscall.SyscallN(method, uintptr(unsafe.Pointer(rule)), uintptr(unsafe.Pointer(&v)))

	return v != 0
}

// --- BSTR (oleaut32) ---------------------------------------------------------

func sysAllocString(s string) (uintptr, error) {
	p, err := windows.UTF16PtrFromString(s)
	if err != nil {
		return 0, err
	}
	bstr, _, _ := procSysAllocString.Call(uintptr(unsafe.Pointer(p)))
	if bstr == 0 {
		return 0, fmt.Errorf("SysAllocString a retourné NULL")
	}

	return bstr, nil
}

func sysFreeString(bstr uintptr) {
	if bstr != 0 {
		procSysFreeString.Call(bstr)
	}
}

// --- Traductions enum ↔ NET_FW_* / CSV ---------------------------------------

func directionToLong(d string) int32 {
	if d == "in" {
		return netFwRuleDirIn
	}

	return netFwRuleDirOut
}

func directionFromLong(v int32) string {
	if v == netFwRuleDirIn {
		return "in"
	}

	return "out"
}

func actionToLong(a string) int32 {
	if a == "allow" {
		return netFwActionAllow
	}

	return netFwActionBlock
}

func actionFromLong(v int32) string {
	if v == netFwActionAllow {
		return "allow"
	}

	return "block"
}

func protocolToLong(p string) int32 {
	switch p {
	case "tcp":
		return netFwIPProtocolTCP
	case "udp":
		return netFwIPProtocolUDP
	default:
		return netFwIPProtocolAny
	}
}

func protocolFromLong(v int32) string {
	switch v {
	case netFwIPProtocolTCP:
		return "tcp"
	case netFwIPProtocolUDP:
		return "udp"
	case netFwIPProtocolAny:
		return "any"
	default:
		return "" // protocole hors domaine (règle étrangère au groupe) : non
		// comparée — la règle sera de toute façon purgée comme stray.
	}
}

// splitCsv : découpe une liste CSV Windows (RemoteAddresses/ports). "*" (any) et
// "" ⇒ liste vide (nos règles portent toujours des valeurs explicites).
func splitCsv(s string) []string {
	s = strings.TrimSpace(s)
	if s == "" || s == "*" {
		return nil
	}
	parts := strings.Split(s, ",")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		if t := strings.TrimSpace(p); t != "" {
			out = append(out, t)
		}
	}

	return out
}

func joinCsv(items []string) string {
	return strings.Join(items, ",")
}

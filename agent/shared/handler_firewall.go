package shared

import (
	"fmt"
	"net"
	"net/netip"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Handler `firewall` (exclusive PAR rule_id / scope MACHINE uniquement) — Story
// 36.2, contrat §7.8. Deuxième mécanisme HORS-REGISTRE. Logique PURE,
// OS-agnostique (les accès pare-feu réels sont injectés via FirewallOps) →
// testée sur l'hôte ; agent/windows n'apporte que l'impl COM (INetFwPolicy2).
//
// D4 — PROPRIÉTÉ PAR CONTENEUR (anti-36.1, inversé). Contrairement à une ACE
// NTFS, une règle pare-feu PORTE son marqueur de propriété : son champ
// `Grouping = SambaEdu-Agent`. Le handler possède le GROUPE en entier et le
// réconcilie (iso registry_list) — AUCUN store n'est nécessaire (pas de
// firewall-state.json). Les règles HORS groupe, la politique par défaut, les
// profils et le service MpsSvc ne sont JAMAIS touchés (FirewallOps n'expose
// AUCUNE op dessus — l'interdit est inexprimable, structurel).
//
// CONVERGENCE level-triggered (§5, STRICT inconditionnel 27.8) :
//   - Test  : énumère les règles DU GROUPE seulement ; conforme ssi chaque item
//     `present` (passant le refus Q3) a sa règle `SambaEdu-Agent: <rule_id>`
//     ÉQUIVALENTE (direction/action/protocol/ports/adresses par normalisation
//     canonique — piège #4, jamais de match de chaîne brute — Enabled/Grouping
//     exacts) ET aucune règle du groupe hors état désiré présent (couvre les
//     items `absent` ET les règles étrangères au state) ;
//   - Apply : effort MAXIMAL par règle (première erreur remontée à la fin,
//     idempotent — 2 passes stables = zéro op). (1) supprime toute règle du
//     groupe hors désir ; (2) `present` absente ⇒ Add ; non conforme ⇒ Remove
//     PUIS Add (recréation atomique). Un état désiré effectif VIDE (que des
//     `absent`) VIDE le groupe.
//
// REFUS AGENT = DÉFENSE EN PROFONDEUR (Q3, piège #7), INDÉPENDANT du serveur,
// dans Test ET Apply (leçon review 36.1 #2a) : un `present` `action: block`
// dont la portée `explicit` chevauche une plage protégée (RFC1918/loopback/
// link-local/ULA ou /0) ⇒ erreur d'ITEM (jamais posée) ; adresse non parsable
// ⇒ erreur d'item. `remote_scope: internet` est SÛRE par construction (les
// plages émises EXCLUENT tout ça — D6). Constantes MIROIR du guard PHP
// (FirewallAuthoringGuard::PROTECTED_RANGES). Les AUTRES items convergent ;
// l'erreur remonte TOUJOURS (verdict `error` du type).

// FirewallRuleGroup : le conteneur POSSÉDÉ par l'agent (marqueur de propriété
// D4 — champ `Grouping` des règles). Constante partagée (agent/shared).
const FirewallRuleGroup = "SambaEdu-Agent"

// FirewallRuleName dérive le nom de règle unique et stable d'un rule_id (la
// suppression COM se fait par nom). Forme : `SambaEdu-Agent: <rule_id>`.
func FirewallRuleName(ruleID string) string {
	return FirewallRuleGroup + ": " + ruleID
}

// firewallRuleIDSlug : MIROIR EXACT de FirewallAuthoringGuard::RULE_ID côté
// serveur (corr. review #4 — défense en profondeur symétrique, iso les plages
// protégées). Un `rule_id` malformé qui atteindrait l'agent (serveur contourné/
// buggé) produit une ERREUR d'enveloppe, jamais un nom de règle Windows non
// slugifié.
var firewallRuleIDSlug = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,63}$`)

// FwRule : une règle pare-feu vue/posée sur le poste. Les enums sont en MOTS
// MÉTIER (l'impl COM traduit ↔ les constantes NET_FW_*) : la comparaison de
// convergence reste PURE et OS-agnostique.
type FwRule struct {
	Name     string
	Grouping string
	Direction string // "in" | "out"
	Action    string // "allow" | "block"
	Protocol  string // "any" | "tcp" | "udp"
	// Adresses/ports : listes de tokens tels que posés/relus (CIDR `addr/n`,
	// plages `a-b`, forme `adresse/masque` échoée par Windows — la comparaison
	// passe par une normalisation canonique en intervalles, piège #4).
	RemoteAddresses []string
	LocalPorts      []string
	RemotePorts     []string
	Enabled         bool
}

// FirewallOps : accès pare-feu spécifiques à l'OS, injectés (testable hôte).
// L'impl Windows vit dans agent/windows/handler_firewall_windows.go (COM natif
// INetFwPolicy2) ; un fake en mémoire couvre les tests. L'interface n'expose
// AUCUNE op de politique par défaut / profils / service (piège #11, structurel).
type FirewallOps interface {
	// ListGroupRules retourne les règles dont le `Grouping` est `group`
	// (SEULEMENT — jamais les voisines hors groupe). Groupe vide ⇒ (nil, nil).
	ListGroupRules(group string) ([]FwRule, error)
	// AddRule crée la règle (Grouping/Name/Direction/Action/Protocol/ports/
	// adresses, Profiles=ALL, Enabled=true). Idempotence gérée par l'appelant
	// (Remove avant Add pour une recréation).
	AddRule(rule FwRule) error
	// RemoveRule supprime la règle nommée (déjà absente ⇒ nil, idempotent).
	RemoveRule(name string) error
}

// FirewallSpec : une règle cible (un item du payload `firewall`).
type FirewallSpec struct {
	RuleID          string
	Direction       string // "in" | "out"
	Action          string // "allow" | "block"
	RemoteScope     string // "internet" | "explicit"
	Protocol        string // "any" | "tcp" | "udp"
	RemoteAddresses []string
	Ports           []string
	Ensure          string // "present" | "absent" (TOUJOURS présent, piège #2)
}

func (s FirewallSpec) absent() bool { return s.Ensure == "absent" }

// name : le nom de règle Windows dérivé (unique par rule_id, insensible à la
// casse pour l'identité interne).
func (s FirewallSpec) name() string { return FirewallRuleName(s.RuleID) }

// internetRemoteAddresses est la traduction FIGÉE de `remote_scope: internet`
// (D6, piège #8) : le complément des plages non routables/privées. IPv4 en
// plages `a-b` (formes STABLES à l'écho Windows), IPv6 `2000::/3` (unicast
// global — fe80::/10, fc00::/7, ::1 restent joignables). SÛRE par construction
// (Q3) : ces plages EXCLUENT RFC1918/loopback/link-local/ULA. Toute évolution
// future = décision écrite (test golden-style verrouille la chaîne EXACTE).
func internetRemoteAddresses() []string {
	return []string{
		// IPv4 : complément de {0/8, 10/8, 127/8, 169.254/16, 172.16/12,
		// 192.168/16, ≥ 224.0.0.0 (multicast/réservé)}.
		"1.0.0.0-9.255.255.255",
		"11.0.0.0-126.255.255.255",
		"128.0.0.0-169.253.255.255",
		"169.255.0.0-172.15.255.255",
		"172.32.0.0-192.167.255.255",
		"192.169.0.0-223.255.255.255",
		// IPv6 unicast global.
		"2000::/3",
	}
}

// firewallProtectedRanges : plages PROTÉGÉES (Q3, piège #7) — MIROIR EXACT de
// FirewallAuthoringGuard::PROTECTED_RANGES (RFC1918 + loopback + link-local +
// ULA, IPv4 ET IPv6). Un préfixe `/0` recouvre n'importe laquelle → refusé par
// l'intersection sans cas spécial.
var firewallProtectedRanges = mustPrefixes([]string{
	"10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", // RFC1918
	"127.0.0.0/8",    // loopback v4
	"169.254.0.0/16", // link-local v4
	"::1/128",        // loopback v6
	"fe80::/10",      // link-local v6
	"fc00::/7",       // ULA v6
})

func mustPrefixes(cidrs []string) []netip.Prefix {
	out := make([]netip.Prefix, 0, len(cidrs))
	for _, c := range cidrs {
		out = append(out, netip.MustParsePrefix(c))
	}

	return out
}

// parseAuthoringPrefix parse une entrée `remote_addresses` d'authoring : une IP
// littérale (⇒ /32 ou /128) OU un CIDR `addr/n`. AUCUNE plage `a-b`, AUCUN
// mot-clé Windows, AUCUNE forme `adresse/masque` (l'authoring est propre — la
// forme échoée par Windows n'apparaît QUE dans la comparaison de convergence).
func parseAuthoringPrefix(s string) (netip.Prefix, bool) {
	s = strings.TrimSpace(s)
	if strings.Contains(s, "/") {
		p, err := netip.ParsePrefix(s)
		if err != nil {
			return netip.Prefix{}, false
		}

		return p.Masked(), true
	}
	a, err := netip.ParseAddr(s)
	if err != nil {
		return netip.Prefix{}, false
	}

	return netip.PrefixFrom(a, a.BitLen()), true
}

// firewallItemViolation : raison NON vide si l'item `present` doit être REFUSÉ
// (défense en profondeur Q3, dans Test ET Apply), sinon "". Un `absent`
// (retrait) reste toujours autorisé (retirer une règle dangereuse est sûr).
// `internet` est SÛRE par construction (jamais refusée).
func firewallItemViolation(spec FirewallSpec) string {
	if spec.absent() || spec.RemoteScope != "explicit" {
		return ""
	}
	for _, addr := range spec.RemoteAddresses {
		pfx, ok := parseAuthoringPrefix(addr)
		if !ok {
			return fmt.Sprintf("adresse %q non parsable (attendu : IP ou CIDR IPv4/IPv6)", addr)
		}
		if spec.Action == "block" {
			for _, prot := range firewallProtectedRanges {
				if prot.Overlaps(pfx) {
					return fmt.Sprintf("action 'block' sur %q chevauche une plage protégée (RFC1918/loopback/link-local/ULA ou /0) — couper le réseau local du serveur est INTERDIT (Q3)", addr)
				}
			}
		}
	}

	return ""
}

// desiredRule construit la FwRule cible d'un item `present`.
func desiredRule(spec FirewallSpec) FwRule {
	rule := FwRule{
		Name:      spec.name(),
		Grouping:  FirewallRuleGroup,
		Direction: spec.Direction,
		Action:    spec.Action,
		Protocol:  spec.Protocol,
		Enabled:   true,
	}
	if spec.RemoteScope == "internet" {
		rule.RemoteAddresses = internetRemoteAddresses()
	} else {
		rule.RemoteAddresses = append([]string(nil), spec.RemoteAddresses...)
	}
	// Ports DISTANTS pour `out`, LOCAUX pour `in` (§7.8) — seulement si tcp|udp
	// et des ports ciblés (le guard/parse garantissent la cohérence).
	if (spec.Protocol == "tcp" || spec.Protocol == "udp") && len(spec.Ports) > 0 {
		if spec.Direction == "out" {
			rule.RemotePorts = append([]string(nil), spec.Ports...)
		} else {
			rule.LocalPorts = append([]string(nil), spec.Ports...)
		}
	}

	return rule
}

// ruleEquivalent : la règle relue est-elle conforme à la cible ? Direction/
// action/protocol/grouping insensibles à la casse ; Enabled strict ; adresses
// et ports comparés par NORMALISATION CANONIQUE (intervalles fusionnés —
// piège #4, jamais de match de chaîne brute).
func ruleEquivalent(actual, target FwRule) bool {
	if !strings.EqualFold(actual.Grouping, target.Grouping) ||
		!strings.EqualFold(actual.Direction, target.Direction) ||
		!strings.EqualFold(actual.Action, target.Action) ||
		!strings.EqualFold(actual.Protocol, target.Protocol) ||
		actual.Enabled != target.Enabled {
		return false
	}

	return sameAddressSet(actual.RemoteAddresses, target.RemoteAddresses) &&
		samePortSet(actual.LocalPorts, target.LocalPorts) &&
		samePortSet(actual.RemotePorts, target.RemotePorts)
}

// --- Normalisation canonique des adresses (anti drift-loop, piège #4) --------

// ipInterval : un intervalle d'adresses [lo, hi] d'une famille (4 | 6), bornes
// en octets big-endian (largeur 4 ou 16 — comparaison lexicographique non
// signée sur des chaînes de MÊME longueur).
type ipInterval struct {
	fam byte
	lo  string
	hi  string
}

func ipToKey(ip net.IP) (byte, string, bool) {
	if v4 := ip.To4(); v4 != nil {
		return 4, string(v4), true
	}
	if v16 := ip.To16(); v16 != nil {
		return 6, string(v16), true
	}

	return 0, "", false
}

// parseAddrInterval : parse un token d'adresse en intervalle. Gère la forme
// d'AUTHORING (IP, CIDR `addr/n`) ET les formes ÉCHOÉES par Windows (plage
// `a-b`, forme `adresse/masque` pointé). Token non parsable ⇒ ok=false.
func parseAddrInterval(tok string) (ipInterval, bool) {
	tok = strings.TrimSpace(tok)
	if tok == "" {
		return ipInterval{}, false
	}

	// Plage `a-b` (forme d'écho Windows des ranges internet).
	if i := strings.IndexByte(tok, '-'); i >= 0 {
		loIP := net.ParseIP(strings.TrimSpace(tok[:i]))
		hiIP := net.ParseIP(strings.TrimSpace(tok[i+1:]))
		if loIP == nil || hiIP == nil {
			return ipInterval{}, false
		}
		fam1, lo, ok1 := ipToKey(loIP)
		fam2, hi, ok2 := ipToKey(hiIP)
		if !ok1 || !ok2 || fam1 != fam2 || lo > hi {
			return ipInterval{}, false
		}

		return ipInterval{fam1, lo, hi}, true
	}

	// CIDR `addr/n` OU forme `adresse/masque` pointé (écho Windows d'un CIDR v4).
	if i := strings.IndexByte(tok, '/'); i >= 0 {
		addr := net.ParseIP(strings.TrimSpace(tok[:i]))
		if addr == nil {
			return ipInterval{}, false
		}
		fam, base, ok := ipToKey(addr)
		if !ok {
			return ipInterval{}, false
		}
		width := len(base)
		suf := strings.TrimSpace(tok[i+1:])
		var ones int
		if n, err := strconv.Atoi(suf); err == nil {
			if n < 0 || n > width*8 {
				return ipInterval{}, false
			}
			ones = n
		} else if fam == 4 {
			// Masque pointé (255.255.255.0) → nombre de bits (forme d'écho).
			maskIP := net.ParseIP(suf)
			if maskIP == nil {
				return ipInterval{}, false
			}
			m4 := maskIP.To4()
			if m4 == nil {
				return ipInterval{}, false
			}
			o, bits := net.IPMask(m4).Size()
			if bits == 0 { // masque non contigu
				return ipInterval{}, false
			}
			ones = o
		} else {
			return ipInterval{}, false
		}
		lo, hi := maskRange([]byte(base), ones)

		return ipInterval{fam, string(lo), string(hi)}, true
	}

	// IP littérale seule ⇒ intervalle ponctuel.
	ip := net.ParseIP(tok)
	if ip == nil {
		return ipInterval{}, false
	}
	fam, key, ok := ipToKey(ip)
	if !ok {
		return ipInterval{}, false
	}

	return ipInterval{fam, key, key}, true
}

// maskRange applique un préfixe de `ones` bits à une adresse : bornes basse
// (bits hôte à 0) et haute (bits hôte à 1).
func maskRange(base []byte, ones int) (lo, hi []byte) {
	lo = make([]byte, len(base))
	hi = make([]byte, len(base))
	for i := range base {
		var mask byte
		for b := 0; b < 8; b++ {
			if i*8+b < ones {
				mask |= 1 << (7 - b)
			}
		}
		lo[i] = base[i] & mask
		hi[i] = (base[i] & mask) | (^mask)
	}

	return lo, hi
}

// incKey : successeur immédiat d'une borne (pour la fusion d'intervalles
// adjacents). Retourne ok=false en cas de débordement (borne = max de la
// famille — pas de successeur).
func incKey(key string) (string, bool) {
	b := []byte(key)
	for i := len(b) - 1; i >= 0; i-- {
		if b[i] != 0xFF {
			b[i]++

			return string(b), true
		}
		b[i] = 0
	}

	return "", false
}

// normalizeAddresses : ensemble canonique d'un jeu de tokens (parse + fusion des
// intervalles chevauchants/adjacents + tri). Un token non parsable est IGNORÉ
// (une règle étrangère au format n'empêche pas la comparaison de converger — au
// pire elle diverge et est recréée). Deux jeux logiquement identiques (CIDR vs
// masque pointé, `a-b` vs union) produisent le MÊME canon.
func normalizeAddresses(tokens []string) []ipInterval {
	ivs := make([]ipInterval, 0, len(tokens))
	for _, t := range tokens {
		if iv, ok := parseAddrInterval(t); ok {
			ivs = append(ivs, iv)
		}
	}

	return mergeIntervals(ivs)
}

func mergeIntervals(ivs []ipInterval) []ipInterval {
	if len(ivs) == 0 {
		return nil
	}
	sort.Slice(ivs, func(i, j int) bool {
		if ivs[i].fam != ivs[j].fam {
			return ivs[i].fam < ivs[j].fam
		}
		if ivs[i].lo != ivs[j].lo {
			return ivs[i].lo < ivs[j].lo
		}

		return ivs[i].hi < ivs[j].hi
	})

	merged := []ipInterval{ivs[0]}
	for _, iv := range ivs[1:] {
		last := &merged[len(merged)-1]
		if iv.fam == last.fam {
			// Chevauchement OU adjacence (iv.lo <= succ(last.hi)).
			nextAfterLast, ok := incKey(last.hi)
			if iv.lo <= last.hi || (ok && iv.lo <= nextAfterLast) {
				if iv.hi > last.hi {
					last.hi = iv.hi
				}

				continue
			}
		}
		merged = append(merged, iv)
	}

	return merged
}

func sameAddressSet(a, b []string) bool {
	na := normalizeAddresses(a)
	nb := normalizeAddresses(b)
	if len(na) != len(nb) {
		return false
	}
	for i := range na {
		if na[i] != nb[i] {
			return false
		}
	}

	return true
}

// --- Normalisation canonique des ports ---------------------------------------

type portInterval struct{ lo, hi int }

func parsePortInterval(tok string) (portInterval, bool) {
	tok = strings.TrimSpace(tok)
	if i := strings.IndexByte(tok, '-'); i >= 0 {
		lo, err1 := strconv.Atoi(strings.TrimSpace(tok[:i]))
		hi, err2 := strconv.Atoi(strings.TrimSpace(tok[i+1:]))
		if err1 != nil || err2 != nil || lo < 1 || hi > 65535 || lo > hi {
			return portInterval{}, false
		}

		return portInterval{lo, hi}, true
	}
	n, err := strconv.Atoi(tok)
	if err != nil || n < 1 || n > 65535 {
		return portInterval{}, false
	}

	return portInterval{n, n}, true
}

func samePortSet(a, b []string) bool {
	na := normalizePorts(a)
	nb := normalizePorts(b)
	if len(na) != len(nb) {
		return false
	}
	for i := range na {
		if na[i] != nb[i] {
			return false
		}
	}

	return true
}

func normalizePorts(tokens []string) []portInterval {
	ivs := make([]portInterval, 0, len(tokens))
	for _, t := range tokens {
		if iv, ok := parsePortInterval(t); ok {
			ivs = append(ivs, iv)
		}
	}
	sort.Slice(ivs, func(i, j int) bool {
		if ivs[i].lo != ivs[j].lo {
			return ivs[i].lo < ivs[j].lo
		}

		return ivs[i].hi < ivs[j].hi
	})
	if len(ivs) == 0 {
		return nil
	}
	merged := []portInterval{ivs[0]}
	for _, iv := range ivs[1:] {
		last := &merged[len(merged)-1]
		if iv.lo <= last.hi+1 {
			if iv.hi > last.hi {
				last.hi = iv.hi
			}

			continue
		}
		merged = append(merged, iv)
	}

	return merged
}

// --- Parse strict du payload -------------------------------------------------

func stringSlice(raw any) ([]string, bool) {
	arr, ok := raw.([]any)
	if !ok {
		return nil, false
	}
	out := make([]string, 0, len(arr))
	for _, v := range arr {
		s, ok := v.(string)
		if !ok {
			return nil, false
		}
		out = append(out, s)
	}

	return out, true
}

// parseFirewallSpec : extrait un FirewallSpec d'un payload §7.8 brut. Enveloppe
// invalide (false → {status: error} pour le type) si : une clé requise manque,
// un enum est hors domaine, la cohérence conditionnelle est violée (`explicit`
// sans adresses, `internet` avec adresses, `ports` avec `any`).
func parseFirewallSpec(raw any) (FirewallSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return FirewallSpec{}, false
	}

	ruleID, _ := payload["rule_id"].(string)
	if ruleID == "" || !firewallRuleIDSlug.MatchString(ruleID) {
		return FirewallSpec{}, false
	}

	direction, _ := payload["direction"].(string)
	direction = strings.ToLower(direction)
	if direction != "in" && direction != "out" {
		return FirewallSpec{}, false
	}

	action, _ := payload["action"].(string)
	action = strings.ToLower(action)
	if action != "allow" && action != "block" {
		return FirewallSpec{}, false
	}

	remoteScope, _ := payload["remote_scope"].(string)
	remoteScope = strings.ToLower(remoteScope)
	if remoteScope != "internet" && remoteScope != "explicit" {
		return FirewallSpec{}, false
	}

	protocol, _ := payload["protocol"].(string)
	protocol = strings.ToLower(protocol)
	if protocol != "any" && protocol != "tcp" && protocol != "udp" {
		return FirewallSpec{}, false
	}

	ensure, _ := payload["ensure"].(string)
	ensure = strings.ToLower(ensure)
	if ensure != "present" && ensure != "absent" {
		return FirewallSpec{}, false
	}

	spec := FirewallSpec{
		RuleID:      ruleID,
		Direction:   direction,
		Action:      action,
		RemoteScope: remoteScope,
		Protocol:    protocol,
		Ensure:      ensure,
	}

	// `remote_addresses` : requis (non vide) ssi `explicit` ; interdit ssi
	// `internet`.
	if rawAddr, present := payload["remote_addresses"]; present {
		addrs, ok := stringSlice(rawAddr)
		if !ok {
			return FirewallSpec{}, false
		}
		spec.RemoteAddresses = addrs
	}
	if remoteScope == "explicit" {
		if len(spec.RemoteAddresses) == 0 {
			return FirewallSpec{}, false
		}
	} else if len(spec.RemoteAddresses) > 0 {
		return FirewallSpec{}, false
	}

	// `ports` : admis ssi `protocol ∈ tcp|udp` ; interdit ssi `any`.
	if rawPorts, present := payload["ports"]; present {
		ports, ok := stringSlice(rawPorts)
		if !ok {
			return FirewallSpec{}, false
		}
		spec.Ports = ports
	}
	if len(spec.Ports) > 0 && protocol == "any" {
		return FirewallSpec{}, false
	}

	return spec, true
}

// --- Handler ------------------------------------------------------------------

// FirewallHandler : handler exclusive-par-rule_id branché dans le moteur
// (engine.go INTOUCHÉ). SERVICE SYSTEM seul.
type FirewallHandler struct {
	Ops FirewallOps
	Log *Logger
}

// desiredSpecs : parse + dédoublonne par rule_id les items cible. Le serveur
// garantit déjà l'unicité (exclusive par clé au compilateur) ; défense : la
// DERNIÈRE occurrence fait foi, ordre de sortie TRIÉ (logs/erreurs stables, iso
// desiredSpecs de registry_list).
func (h *FirewallHandler) desiredSpecs(items []StateItem) ([]FirewallSpec, error) {
	byID := map[string]FirewallSpec{}
	order := []string{}
	for _, item := range items {
		spec, ok := parseFirewallSpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload firewall inattendu : enveloppe invalide")
		}
		id := strings.ToLower(spec.RuleID)
		if _, seen := byID[id]; !seen {
			order = append(order, id)
		}
		byID[id] = spec
	}

	sort.Strings(order)
	specs := make([]FirewallSpec, 0, len(order))
	for _, id := range order {
		specs = append(specs, byID[id])
	}

	return specs, nil
}

// keptTargets : les règles cibles à GARDER (items `present` passant le refus
// Q3), indexées par nom (insensible à la casse). Les items `absent` et les
// items refusés n'y figurent PAS (leur règle du groupe sera supprimée).
func keptTargets(specs []FirewallSpec) map[string]FwRule {
	kept := map[string]FwRule{}
	for _, spec := range specs {
		if spec.absent() {
			continue
		}
		if firewallItemViolation(spec) != "" {
			continue // refusé Q3 → non gardé (sa règle du groupe sera purgée).
		}
		kept[strings.ToLower(spec.name())] = desiredRule(spec)
	}

	return kept
}

// Test : conforme ssi (a) chaque règle du groupe est une cible gardée ET
// équivalente ; (b) aucune règle du groupe hors cibles gardées (couvre les
// items `absent`, les règles refusées Q3 et les règles étrangères) ; (c) aucun
// item `present` refusé Q3 (l'Apply surfacera l'erreur). Un payload invalide /
// une énumération illisible = erreur franche.
func (h *FirewallHandler) Test(items []StateItem) (bool, error) {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return false, err
	}

	// (c) Refus Q3 sur un item `present` ⇒ non conforme (Apply erreur d'item).
	for _, spec := range specs {
		if !spec.absent() && firewallItemViolation(spec) != "" {
			return false, nil
		}
	}

	kept := keptTargets(specs)

	rules, err := h.Ops.ListGroupRules(FirewallRuleGroup)
	if err != nil {
		return false, err
	}

	seen := map[string]bool{}
	for _, rule := range rules {
		key := strings.ToLower(rule.Name)
		target, ok := kept[key]
		if !ok {
			return false, nil // règle du groupe hors désir (absent / étrangère).
		}
		if !ruleEquivalent(rule, target) {
			return false, nil // règle non conforme.
		}
		seen[key] = true
	}
	// Chaque cible gardée doit avoir sa règle.
	for key := range kept {
		if !seen[key] {
			return false, nil
		}
	}

	return true, nil
}

// Apply : converge le groupe en effort MAXIMAL par règle (première erreur
// remontée à la fin, idempotent). Réconciliation par CONTENEUR (D4) : jamais de
// règle hors groupe, jamais la politique par défaut / le service.
func (h *FirewallHandler) Apply(items []StateItem) error {
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

	// Refus Q3 (défense en profondeur, INDÉPENDANT du serveur) : un item
	// `present` refusé n'est JAMAIS posé — erreur d'item isolée, les autres
	// convergent.
	for _, spec := range specs {
		if spec.absent() {
			continue
		}
		if reason := firewallItemViolation(spec); reason != "" {
			record(fmt.Errorf("firewall refusé (%s) : %s", spec.RuleID, reason))
		}
	}

	kept := keptTargets(specs)

	rules, err := h.Ops.ListGroupRules(FirewallRuleGroup)
	if err != nil {
		return fmt.Errorf("énumération du groupe %s : %w", FirewallRuleGroup, err)
	}

	// (1) Supprimer toute règle du groupe hors cibles gardées (strays, items
	// `absent`, items refusés Q3). Ordre trié (logs déterministes).
	existing := map[string]FwRule{}
	strayNames := []string{}
	for _, rule := range rules {
		key := strings.ToLower(rule.Name)
		existing[key] = rule
		if _, keep := kept[key]; !keep {
			strayNames = append(strayNames, rule.Name)
		}
	}
	sort.Strings(strayNames)
	for _, name := range strayNames {
		if err := h.Ops.RemoveRule(name); err != nil {
			record(fmt.Errorf("suppression de la règle hors désir %q : %w", name, err))

			continue
		}
		logInfo(h.Log, "Règle pare-feu hors désir supprimée : %s", name)
	}

	// (2) Converger les cibles gardées — ordre trié.
	keptNames := make([]string, 0, len(kept))
	for key := range kept {
		keptNames = append(keptNames, key)
	}
	sort.Strings(keptNames)
	for _, key := range keptNames {
		target := kept[key]
		cur, present := existing[key]
		if present && ruleEquivalent(cur, target) {
			continue // déjà conforme → zéro op (idempotence).
		}
		if present {
			// Non conforme : recréation atomique (Remove PUIS Add, piège #6).
			if err := h.Ops.RemoveRule(target.Name); err != nil {
				record(fmt.Errorf("suppression de la règle non conforme %q : %w", target.Name, err))

				continue
			}
		}
		if err := h.Ops.AddRule(target); err != nil {
			record(fmt.Errorf("pose de la règle %q : %w", target.Name, err))

			continue
		}
		logInfo(h.Log, "Règle pare-feu posée : %s (%s %s %s)", target.Name, target.Direction, target.Action, target.Protocol)
	}

	return firstErr
}

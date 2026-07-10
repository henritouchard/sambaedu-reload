package shared

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"regexp"
	"strconv"
	"time"
)

// Constantes du contrat se5.desired-state/v1 — FIGÉES, iso
// App\Services\Agent\StateContract (jamais une variable d'environnement,
// NFR12) et agent/shared/ContractV1.ps1 (lignée spike 24.2).
const (
	// ContractSchema est le nom de schéma complet, présent dans l'enveloppe
	// état ET dans le rapport.
	ContractSchema = "se5.desired-state/v1"

	// ContractMajor est la version majeure acceptée : l'agent REFUSE un
	// major inconnu (contrat §9) ; une version mineure ajoutée (v1.1) reste
	// acceptée (forward-compat).
	ContractMajor = 1
)

// ContractScopes : les trois portées de l'enveloppe état (aussi les clés JSON).
var ContractScopes = []string{"machine", "session", "machine_user"}

// ResourceTypes : identifiants de type de ressource publiés (§7 — figés : on
// ne renomme JAMAIS, on déprécie + ajoute).
var ResourceTypes = []string{
	"wallpaper", "lockscreen", "overlay", "shortcuts", "printers", "drives",
	"associations", "registry", "app_config", "applications",
	// Story 35.2 (D1) — listes registre à sous-valeurs indexées `\1..\N`
	// (réconciliation de clé-conteneur D3, contrat §7.6). Ajout ADDITIF.
	"registry_list",
	// Story 36.1 (D1) — mécanisme HORS-REGISTRE `fs_acl` : ACE NTFS gérées
	// (chirurgie DACL, service SYSTEM, portée Machine, contrat §7.7). Ajout
	// ADDITIF. Un agent ≤ 2.5.0 IGNORE ce type EN SILENCE (contrat §8).
	"fs_acl",
	// Story 36.2 (D1) — mécanisme HORS-REGISTRE `firewall` : règles pare-feu
	// Windows possédées par groupe (`SambaEdu-Agent`, service SYSTEM, portée
	// Machine, contrat §7.8). Ajout ADDITIF. Un agent ≤ 2.6.0 IGNORE ce type
	// EN SILENCE (contrat §8).
	"firewall",
	// Story 35.6 (D1) — mécanisme HORS-REGISTRE `privilege` : droits de logon
	// LSA `SeDeny*` gérés (réconciliation de CONTENEUR sans store, service
	// SYSTEM, portée Machine, contrat §7.9). Ajout ADDITIF. Un agent ≤ 2.7.0
	// IGNORE ce type EN SILENCE (contrat §8).
	"privilege",
	// Story 38.3 (D1) — nettoyage des crochets legacy SE4 du poste
	// (`legacy_cleanup`) : suppression idempotente du catalogue d'artefacts
	// legacy LOCAUX (blobs applications-*, tâches WPKG, scripts GPO locale,
	// helpers, autologon se4install, paires Mozilla `sambaedu.default` —
	// catalogue versionné DANS l'agent, D3). Réconciliation par SCAN sans
	// store (iso firewall/privilege), service SYSTEM, portée Machine, contrat
	// §7.10, payload `{mozilla: "vanilla"}` (enum fermé 1 valeur, Q5-a). Ajout
	// ADDITIF. Un agent ≤ 2.8.0 IGNORE ce type EN SILENCE (contrat §8).
	"legacy_cleanup",
}

// ResourceStatuses : statuts de conformité du rapport (§6 — iso
// App\Enums\AgentResourceStatus). Story 27.8 : `drifted_allowed` retiré
// (convergence STRICT inconditionnelle).
var ResourceStatuses = []string{"compliant", "drift", "error"}

var schemaPattern = regexp.MustCompile(`^se5\.desired-state/v(\d+)`)

// ValidSchema valide le champ `schema` d'une enveloppe ou d'un rapport.
// Forward-compat §9 : seule la version MAJEURE est discriminante
// (`se5.desired-state/v1.1` accepté, `v2` refusé).
func ValidSchema(schema string) bool {
	m := schemaPattern.FindStringSubmatch(schema)
	if m == nil {
		return false
	}
	major, err := strconv.Atoi(m[1])

	return err == nil && major == ContractMajor
}

// State est l'enveloppe `GET /state` décodée : les trois portées, jamais nil
// (portée absente = liste vide). Les champs inconnus de l'enveloppe sont
// ignorés au décodage (forward-compat §9).
type State struct {
	Schema      string
	GeneratedAt string
	TtlSeconds  int64
	// Debug : mode debug du poste (champ d'enveloppe `debug`). En debug, le
	// compagnon de session GARDE sa console ouverte et y recopie ses logs.
	// Absent/non bool → false (forward-compat : un serveur antérieur ne
	// l'émet pas).
	Debug       bool
	Machine     []any
	Session     []any
	MachineUser []any
}

// ParseState décode et valide l'enveloppe `GET /state` (200).
//
// Refuse un major inconnu (erreur → l'appelant loggue, PRÉSERVE son cache et
// poursuit les check-ins — piège n° 10). L'ETag n'est PAS géré ici (transport,
// pas contrat) : il reste opaque et stocké verbatim par la couche HTTP.
func ParseState(raw []byte) (*State, error) {
	v, err := DecodeJSON(raw)
	if err != nil {
		return nil, err
	}
	envelope, ok := v.(map[string]any)
	if !ok {
		return nil, fmt.Errorf("enveloppe état : objet JSON attendu, obtenu %T", v)
	}

	schema, _ := envelope["schema"].(string)
	if !ValidSchema(schema) {
		return nil, fmt.Errorf("schema inconnu ou major non supporté : %q (attendu : %s)", schema, ContractSchema)
	}

	state := &State{Schema: schema}
	state.GeneratedAt, _ = envelope["generated_at"].(string)
	if n, ok := envelope["ttl_seconds"].(json.Number); ok {
		if ttl, err := n.Int64(); err == nil {
			state.TtlSeconds = ttl
		}
	}
	// Champ d'enveloppe `debug` (bool). Absent/non bool → false.
	state.Debug, _ = envelope["debug"].(bool)

	// Portée absente (enveloppe tronquée) → liste vide, jamais nil.
	state.Machine = scopeItems(envelope, "machine")
	state.Session = scopeItems(envelope, "session")
	state.MachineUser = scopeItems(envelope, "machine_user")

	return state, nil
}

// DebugFromStateCacheFile lit le cache d'état (state.json brut) au chemin
// donné et retourne le drapeau `debug` de l'enveloppe. BEST-EFFORT : toute
// erreur (fichier absent, JSON invalide, major inconnu) → false, jamais de
// panique. Sert le câblage console du compagnon AVANT toute boucle : le cache
// per-SID reflète le dernier état tiré par session-fetch (SYSTEM).
func DebugFromStateCacheFile(path string) bool {
	raw, err := os.ReadFile(path)
	if err != nil {
		return false
	}
	state, err := ParseState(raw)
	if err != nil {
		return false
	}

	return state.Debug
}

func scopeItems(envelope map[string]any, scope string) []any {
	if items, ok := envelope[scope].([]any); ok {
		return items
	}

	return []any{}
}

// ReportItem est une entrée `items[]` du rapport §6. Le hash est OPAQUE :
// échoué tel quel depuis l'état serveur, jamais recalculé.
//
// Story 27.5 (§6, évolution MINEURE) : champ ADDITIF optionnel `inventory`
// (résultat PAR APP de l'item `applications` — un vieux serveur l'ignore, reste
// v1). Le `status`/`hash` du type RESTENT le verdict PAR TYPE (worst-status) ;
// l'inventaire est une DONNÉE additive, jamais un verdict per-app (grain 27.8
// intact). `omitempty` : seul l'item `applications` le porte.
type ReportItem struct {
	Type      string                `json:"type"`
	Status    string                `json:"status"`
	Hash      string                `json:"hash"`
	Detail    string                `json:"detail,omitempty"`
	Inventory []ReportInventoryItem `json:"inventory,omitempty"`
}

// ReportInventoryItem : résultat PAR APP de l'inventaire `applications` (Story
// 27.5 §6). `status` ∈ {compliant, drift, error} (compliant/drift = installé,
// error = non installé) ; `detail` optionnel (omitempty).
type ReportInventoryItem struct {
	AppID  string `json:"app_id"`
	Status string `json:"status"`
	Detail string `json:"detail,omitempty"`
}

// report est le payload `POST /report` (§6). Struct (ordre de clés stable
// pour le debug) — le rapport est du transport, PAS une entrée du hasher.
type report struct {
	Schema       string            `json:"schema"`
	GeneratedAt  string            `json:"generated_at"`
	AgentVersion string            `json:"agent_version"`
	Workstation  reportWorkstation `json:"workstation"`
	Items        []ReportItem      `json:"items"`
}

type reportWorkstation struct {
	Hostname string `json:"hostname"`
	UUID     string `json:"uuid"`
}

// BuildReport construit le payload JSON de `POST /report`.
//
// RÈGLE HOSTNAME (defer review 24.1 #8, résolu 24.2 — CONSERVÉ en Go) :
// hostname DOIT être le nom COURT du poste (sans domaine) — le serveur le
// compare à workstations.name et loggue `agent.report.identity_mismatch` en
// cas de divergence. uuid = UUID SMBIOS envoyé VERBATIM (vide admis : champ
// déclaratif, l'identité réelle est le token) — la normalisation minuscules
// est côté serveur.
//
// Story 24.5 : items est TOUJOURS vide (handlers → 24.6) — `items: []` est
// valide côté serveur (AC9 24.1). Un slice nil est sérialisé `[]`, jamais
// `null`.
func BuildReport(hostname, uuid string, items []ReportItem, now time.Time) ([]byte, error) {
	if hostname == "" {
		return nil, fmt.Errorf("rapport : hostname vide")
	}
	if items == nil {
		items = []ReportItem{}
	}

	return marshalCompactJSON(report{
		Schema:       ContractSchema,
		GeneratedAt:  now.UTC().Format(time.RFC3339),
		AgentVersion: Version,
		Workstation:  reportWorkstation{Hostname: hostname, UUID: uuid},
		Items:        items,
	})
}

// marshalCompactJSON sérialise du JSON de TRANSPORT (rapport) : compact, sans
// échappement HTML, sans '\n' final. Rien à voir avec la forme canonique du
// hasher (Canonicalize) — ce JSON n'est jamais hashé.
func marshalCompactJSON(v any) ([]byte, error) {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(v); err != nil {
		return nil, err
	}

	return bytes.TrimRight(buf.Bytes(), "\n"), nil
}

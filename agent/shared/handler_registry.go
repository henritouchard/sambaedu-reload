package shared

import (
	"encoding/json"
	"fmt"
	"sort"
	"strings"

	"golang.org/x/text/unicode/norm"
)

// Handler `registry` (exclusive PAR IDENTITÉ DE CLÉ / scope machine|session) —
// Story 27.3. Logique PURE, OS-agnostique (les accès registre réels sont
// injectés via RegistryOps) → testée sur l'hôte ; agent/windows ne fait que
// câbler golang.org/x/sys/windows/registry.
//
// UN SEUL handler générique pour les DEUX portées (D-Q2) : le SERVICE SYSTEM
// l'instancie pour les items HKLM (portée machine), le COMPAGNON pour les items
// HKCU (portée session). Le handler ne connaît QUE l'item concret
// {hive, path, name, type, value} — JAMAIS la notion de catalogue serveur :
// ajouter un réglage = data Laravel, ZÉRO release agent (invariant central).
//
// CONVERGENCE level-triggered, JAMAIS accumulation :
//   - test  : chaque clé cible a-t-elle EXACTEMENT sa valeur/type cible ?
//     (item `ensure:"absent"` : conforme ssi la valeur N'EXISTE PAS) ;
//   - apply : (ré)écrire les clés divergentes / supprimer les valeurs des items
//     `absent`. IDEMPOTENT (2 passes sur état stable = aucune écriture ni
//     suppression).
//
// TROIS RÉGIMES (Story 35.1, contrat §7.1) :
//   1. ÉCRIRE — item 5 clés {hive, path, name, type, value} (champ `ensure`
//      absent = `present`) : (ré)imposer la valeur cible ;
//   2. SUPPRIMER — item 4 clés {hive, path, name, ensure:"absent"} : supprimer
//      la VALEUR NOMMÉE si elle existe (jamais la clé-conteneur : des valeurs
//      voisines non gérées y vivent — la réconciliation de clé entière est le
//      type `registry_list`, D3/35.2). Déjà absente = compliant (idempotent) ;
//   3. NE PAS GÉRER — la clé N'EST PAS dans la cible : le handler NE TOUCHE
//      PLUS à cette clé (la valeur reste celle qu'elle avait, contrat §8 —
//      type/clé absent = non géré). Le handler ne touche toujours JAMAIS une
//      clé hors cible.
//
// ISOLATION des erreurs (AC5) : une clé protégée / ruche absente → l'op renvoie
// une erreur → le moteur (engine.go RunPass) rend {status: error, detail} pour
// le SEUL type `registry` ; les autres types continuent. La convergence interne
// est en EFFORT MAXIMAL : une clé en échec ne doit pas empêcher les AUTRES clés
// du même type de converger — la première erreur est remontée APRÈS avoir tenté
// toutes les clés (le moteur n'a qu'un verdict par type).

// RegistryValue : la valeur cible TYPÉE d'une clé de registre.
//   - DWORD/QWORD : Int (Kind = "REG_DWORD" | "REG_QWORD") ;
//   - SZ/EXPAND_SZ: Str (Kind = "REG_SZ" | "REG_EXPAND_SZ") ;
//   - MULTI_SZ    : Multi (Kind = "REG_MULTI_SZ").
// Un seul champ est signifiant selon Kind.
type RegistryValue struct {
	Kind  string
	Int   int64
	Str   string
	Multi []string
}

// Equal compare deux valeurs typées (réel vs cible). La comparaison de chaînes
// normalise en NFC (contrat §4.1 : Windows peut produire du NFD pour des
// chemins/noms visuellement identiques → faux drift sinon).
func (v RegistryValue) Equal(other RegistryValue) bool {
	if !strings.EqualFold(v.Kind, other.Kind) {
		return false
	}
	switch strings.ToUpper(v.Kind) {
	case "REG_DWORD", "REG_QWORD":
		return v.Int == other.Int
	case "REG_MULTI_SZ":
		if len(v.Multi) != len(other.Multi) {
			return false
		}
		for i := range v.Multi {
			if norm.NFC.String(v.Multi[i]) != norm.NFC.String(other.Multi[i]) {
				return false
			}
		}

		return true
	default: // REG_SZ, REG_EXPAND_SZ, inconnu
		return norm.NFC.String(v.Str) == norm.NFC.String(other.Str)
	}
}

// Verbe `ensure` du contrat §7.1 (Story 35.1) : optionnel, absence = present.
const (
	ensurePresent = "present"
	ensureAbsent  = "absent"
)

// RegistrySpec : une clé de registre cible (un item du payload `registry`). Les
// champs hive/path/name sont des strings ; value est typée.
type RegistrySpec struct {
	Hive string // "HKLM" | "HKCU"
	Path string // chemin de clé sous la ruche
	Name string // nom de la valeur
	// Ensure : verbe de convergence (Story 35.1). "" ou "present" = écrire la
	// valeur cible ; "absent" = supprimer la valeur nommée (Value est vide).
	Ensure string
	Value  RegistryValue
}

// absent : l'item demande la SUPPRESSION de la valeur nommée.
func (s RegistrySpec) absent() bool {
	return s.Ensure == ensureAbsent
}

// identity : clé d'identité {hive,path,name} insensible à la casse (Windows
// l'est) — sert les logs et l'unicité interne.
func (s RegistrySpec) identity() string {
	return strings.ToLower(s.Hive) + `\` + strings.ToLower(s.Path) + `\` + strings.ToLower(s.Name)
}

// RegistryOps : accès registre spécifiques à l'OS, injectés (testable hôte).
// L'impl Windows vit dans agent/windows/handler_registry_windows.go
// (golang.org/x/sys/windows/registry) ; un fake en mémoire couvre les tests.
type RegistryOps interface {
	// Read lit la valeur réelle d'une clé. present=false ssi la clé/valeur
	// N'EXISTE PAS (pas une erreur : c'est une dérive à corriger) — la présence
	// est indépendante du type réel : une valeur d'un type hors contrat
	// (REG_BINARY, …) est present=true avec un Kind sentinelle non comparable
	// (l'item `absent` doit la voir pour la supprimer, review 35.1 #1). err =
	// accès refusé / ruche absente (devient {status: error} pour le type).
	Read(hive, path, name string) (value RegistryValue, present bool, err error)

	// Write (ré)écrit la valeur cible (crée la clé/valeur au besoin). Idempotent
	// du point de vue du résultat. err = accès refusé / ruche absente.
	Write(spec RegistrySpec) error

	// Delete supprime la VALEUR NOMMÉE d'une clé (Story 35.1) — JAMAIS la
	// clé-conteneur (des valeurs voisines non gérées y vivent). Une valeur (ou
	// une clé) déjà absente N'EST PAS une erreur (idempotence : nil). err =
	// accès refusé / ruche invalide.
	Delete(hive, path, name string) error
}

// registryNotifier : hook OPTIONNEL (assertion de type sur Ops) implémenté par
// l'impl OS pour signaler au shell qu'au moins une clé HKCU affectant l'UI a
// changé. L'impl Windows émet SHChangeNotify(SHCNE_ASSOCCHANGED) → l'Explorer
// DÉJÀ ouvert relit ses réglages de vue (Hidden, HideFileExt) sans relogon.
// Optionnel : RegistryOps ne l'exige PAS — un fake de test / un OS sans impl ne
// le fournit pas (l'assertion échoue → aucune notification, comportement
// inchangé). Best-effort : un shell non rafraîchi n'est JAMAIS une erreur de
// convergence (la clé EST écrite ; au pire l'effet apparaît au prochain relogon).
type registryNotifier interface {
	NotifyShellChanged()
}

// isUserHive : la ruche est-elle celle de l'utilisateur (HKCU) ? Gate le
// rafraîchissement shell sur les seules clés per-user — les écritures HKLM du
// service (session 0) ne rafraîchissent aucun bureau interactif.
func isUserHive(hive string) bool {
	switch strings.ToUpper(strings.TrimSpace(hive)) {
	case "HKCU", "HKEY_CURRENT_USER":
		return true
	default:
		return false
	}
}

// RegistryHandler : handler exclusive-par-clé branché dans le moteur
// (engine.go) — la machine d'états §5 reste au moteur, JAMAIS ici.
type RegistryHandler struct {
	Ops RegistryOps
	Log *Logger
}

// desiredSpecs : parse + dédoublonne par identité de clé les items cible. Le
// serveur garantit déjà une clé unique par identité (exclusive par clé au
// compilateur) ; défense : la DERNIÈRE occurrence fait foi.
func (h *RegistryHandler) desiredSpecs(items []StateItem) ([]RegistrySpec, error) {
	byIdentity := map[string]RegistrySpec{}
	order := []string{}
	for _, item := range items {
		spec, ok := parseRegistrySpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload registry inattendu : enveloppe invalide")
		}
		id := spec.identity()
		if _, seen := byIdentity[id]; !seen {
			order = append(order, id)
		}
		byIdentity[id] = spec
	}

	// Ordre déterministe par identité (les logs/erreurs sont stables).
	sort.Strings(order)
	specs := make([]RegistrySpec, 0, len(order))
	for _, id := range order {
		specs = append(specs, byIdentity[id])
	}

	return specs, nil
}

// Test : chaque clé cible a-t-elle EXACTEMENT sa valeur cible ? Une clé absente
// ou divergente = non conforme. Item `ensure:"absent"` : conforme ssi la valeur
// N'EXISTE PAS (peu importe son type/contenu). Une erreur d'accès (ruche
// absente / refusé) remonte (le moteur rend error pour le type).
func (h *RegistryHandler) Test(items []StateItem) (bool, error) {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return false, err
	}

	for _, spec := range specs {
		actual, present, err := h.Ops.Read(spec.Hive, spec.Path, spec.Name)
		if err != nil {
			return false, fmt.Errorf("lecture de %s : %w", spec.identity(), err)
		}
		if spec.absent() {
			if present {
				return false, nil // la valeur existe → dérive (à supprimer)
			}

			continue
		}
		if !present || !actual.Equal(spec.Value) {
			return false, nil
		}
	}

	return true, nil
}

// Apply : converge — (ré)écrit les clés divergentes, SUPPRIME les valeurs des
// items `ensure:"absent"` encore présentes. Idempotent (une clé déjà conforme
// n'est ni réécrite ni re-supprimée). EFFORT MAXIMAL : on tente TOUTES les
// clés ; la première erreur est remontée à la fin (les clés saines convergent
// quand même, isolation inter-items AC5). Ne supprime/efface JAMAIS une clé
// absente de la cible (piège n° 5 : « ne pas gérer » = ne pas toucher).
func (h *RegistryHandler) Apply(items []StateItem) error {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return err
	}

	var firstErr error
	shellRefresh := false // au moins une clé HKCU a changé → rafraîchir le shell
	for _, spec := range specs {
		actual, present, err := h.Ops.Read(spec.Hive, spec.Path, spec.Name)
		if err != nil {
			logError(h.Log, "Lecture du réglage registre %s en échec : %v", spec.identity(), err)
			if firstErr == nil {
				firstErr = fmt.Errorf("lecture de %s : %w", spec.identity(), err)
			}

			continue
		}
		if spec.absent() {
			if !present {
				continue // déjà absente → idempotence (aucune suppression)
			}
			if err := h.Ops.Delete(spec.Hive, spec.Path, spec.Name); err != nil {
				logError(h.Log, "Suppression du réglage registre %s en échec : %v", spec.identity(), err)
				if firstErr == nil {
					firstErr = fmt.Errorf("suppression de %s : %w", spec.identity(), err)
				}

				continue
			}
			logInfo(h.Log, "Réglage registre supprimé (ensure: absent) : %s", spec.identity())
			// Une suppression EFFECTIVE d'une valeur HKCU compte comme un
			// changement (même gate que l'écriture — décision de design n° 6).
			if isUserHive(spec.Hive) {
				shellRefresh = true
			}

			continue
		}
		if present && actual.Equal(spec.Value) {
			continue // déjà conforme → idempotence (aucune écriture)
		}
		if err := h.Ops.Write(spec); err != nil {
			logError(h.Log, "Écriture du réglage registre %s en échec : %v", spec.identity(), err)
			if firstErr == nil {
				firstErr = fmt.Errorf("écriture de %s : %w", spec.identity(), err)
			}

			continue
		}
		logInfo(h.Log, "Réglage registre appliqué : %s = %s", spec.identity(), formatValue(spec.Value))
		if isUserHive(spec.Hive) {
			shellRefresh = true
		}
	}

	// Une clé HKCU affectant l'UI vient de changer (ex. Explorer\Advanced :
	// Hidden, HideFileExt) : sans signal au shell, l'Explorer DÉJÀ ouvert garde
	// ses anciens réglages de vue jusqu'au prochain relogon. On émet un
	// rafraîchissement best-effort (impl OS optionnelle — hôte/tests : no-op).
	// Gate sur HKCU + changement EFFECTIF : 0 écriture = 0 notification (au régime
	// stable, l'idempotence est préservée et l'Explorer ne « flicke » pas).
	if shellRefresh {
		if notifier, ok := h.Ops.(registryNotifier); ok {
			notifier.NotifyShellChanged()
		}
	}

	return firstErr
}

// parseRegistrySpec : extrait un RegistrySpec d'un payload §3 brut. Champs
// hive/path/name manquants = enveloppe invalide (false) → le moteur rapporte
// error. Verbe `ensure` (Story 35.1, optionnel) : `"absent"` ⇒ item de
// SUPPRESSION — seuls hive/path/name sont exigés (type/value sont ABSENTS du
// payload 4 clés) ; champ absent ou `"present"` ⇒ item d'écriture (parcours
// historique : type exigé, valeur typée selon `type`) ; toute autre valeur ⇒
// enveloppe invalide.
func parseRegistrySpec(raw any) (RegistrySpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return RegistrySpec{}, false
	}

	hive, _ := payload["hive"].(string)
	path, _ := payload["path"].(string)
	name, _ := payload["name"].(string)
	if hive == "" || path == "" || name == "" {
		return RegistrySpec{}, false
	}

	// Branche `ensure` AVANT l'exigence type/value (piège n° 5 de la story) :
	// un item `absent` n'a ni `type` ni `value` à parser.
	if rawEnsure, exists := payload["ensure"]; exists {
		ensure, ok := rawEnsure.(string)
		if !ok {
			return RegistrySpec{}, false // ensure non-string = enveloppe invalide
		}
		switch ensure {
		case ensureAbsent:
			return RegistrySpec{Hive: hive, Path: path, Name: name, Ensure: ensureAbsent}, true
		case ensurePresent:
			// Explicite ≡ item d'écriture classique (le serveur ne l'émet
			// jamais — byte-identité — mais le contrat l'admet).
		default:
			return RegistrySpec{}, false // valeur inconnue = enveloppe invalide
		}
	}

	regType, _ := payload["type"].(string)
	if regType == "" {
		return RegistrySpec{}, false
	}

	value, ok := parseRegistryValue(regType, payload["value"])
	if !ok {
		return RegistrySpec{}, false
	}

	return RegistrySpec{Hive: hive, Path: path, Name: name, Value: value}, true
}

// parseRegistryValue : typage de la valeur selon REG_*. Le JSON est décodé par
// DecodeJSON (UseNumber) → les entiers arrivent en json.Number ; on les borne
// en int64. Type inconnu = false.
func parseRegistryValue(regType string, raw any) (RegistryValue, bool) {
	switch strings.ToUpper(regType) {
	case "REG_DWORD", "REG_QWORD":
		n, ok := asInt64(raw)
		if !ok {
			return RegistryValue{}, false
		}

		return RegistryValue{Kind: strings.ToUpper(regType), Int: n}, true
	case "REG_SZ", "REG_EXPAND_SZ":
		s, ok := raw.(string)
		if !ok {
			return RegistryValue{}, false
		}

		return RegistryValue{Kind: strings.ToUpper(regType), Str: s}, true
	case "REG_MULTI_SZ":
		list, ok := raw.([]any)
		if !ok {
			return RegistryValue{}, false
		}
		multi := make([]string, 0, len(list))
		for _, v := range list {
			s, ok := v.(string)
			if !ok {
				return RegistryValue{}, false
			}
			multi = append(multi, s)
		}

		return RegistryValue{Kind: "REG_MULTI_SZ", Multi: multi}, true
	default:
		return RegistryValue{}, false
	}
}

// asInt64 : entier d'un payload JSON décodé. DecodeJSON (UseNumber) émet des
// json.Number ; les fakes de test peuvent passer des int/int64 natifs. Un float
// est REFUSÉ (contrat §4.1 zéro float) sauf s'il est entier (tolérance de test).
func asInt64(raw any) (int64, bool) {
	switch v := raw.(type) {
	case json.Number:
		n, err := v.Int64()

		return n, err == nil
	case int:
		return int64(v), true
	case int64:
		return v, true
	case float64:
		if v != float64(int64(v)) {
			return 0, false
		}

		return int64(v), true
	default:
		return 0, false
	}
}

// formatValue : représentation lisible pour les logs (jamais hashée).
func formatValue(v RegistryValue) string {
	switch strings.ToUpper(v.Kind) {
	case "REG_DWORD", "REG_QWORD":
		return fmt.Sprintf("%d", v.Int)
	case "REG_MULTI_SZ":
		return strings.Join(v.Multi, "|")
	default:
		return v.Str
	}
}

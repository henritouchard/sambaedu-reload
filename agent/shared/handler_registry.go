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
//
// RUCHE `HKU` (Story 35.3, contrat §7.1) — FAN-OUT interne au handler : un item
// `hive: "HKU"` (portée MACHINE, service SYSTEM — seul à pouvoir écrire les
// ruches des autres utilisateurs) désigne UNE cible logique que le handler
// applique à `HKU\.DEFAULT` (le « profil » lu par l'écran de logon) ET à chaque
// ruche utilisateur CHARGÉE (`HKU\<SID>`, sessions ouvertes). Les cibles sont
// énumérées À CHAQUE appel Test/Apply via RegistryOps.UserHives (jamais de
// cache : une session ouverte après coup est couverte au cycle suivant,
// level-triggered). L'IDENTITÉ LOGIQUE prime : l'item reste UN item du state —
// payload/hash INCHANGÉS par le nombre de sessions (desiredSpecs/identity
// intouchés) ; seul l'op physique voit les paths préfixés (`.DEFAULT\<path>`,
// `<SID>\<path>`). Drift AGRÉGÉ : Test conforme ssi TOUTES les cibles sont
// conformes ; Apply ne converge QUE les cibles divergentes (idempotence par
// cible) ; `ensure:"absent"` supprime la valeur dans TOUTES les cibles. Une
// ruche en échec (accès refusé / déchargée en course logoff) est ISOLÉE (effort
// maximal, re-résolue au cycle suivant) ; une erreur d'ÉNUMÉRATION rend l'item
// inapplicable (erreur franche → {status: error} pour le type). Aucun
// rafraîchissement shell pour HKU (isUserHive rend false : le service écrit
// depuis la session 0 — l'effet dans les sessions ouvertes vient au prochain
// re-read de l'app, et l'écran de logon relit .DEFAULT à chaque affichage).

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
	Hive string // "HKLM" | "HKCU" | "HKU" (fan-out multi-ruches, Story 35.3)
	Path string // chemin de clé sous la ruche
	Name string // nom de la valeur
	// Ensure : verbe de convergence (Story 35.1). "" ou "present" = écrire la
	// valeur cible ; "absent" = supprimer la valeur nommée (Value est vide).
	Ensure string
	Value  RegistryValue
	// Refresh : hint OPTIONNEL du payload (Story 43.1, champ `refresh`) — le
	// geste de rafraîchissement requis si CET item change effectivement.
	// Lecture indulgente (D3) : absent/vide/inconnu = RefreshNone, jamais une
	// enveloppe invalide. Un hint ne peut qu'ESCALADER le plancher shell_notify
	// des changements HKCU (D2), jamais l'affaiblir.
	Refresh RefreshLevel
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
	// Cible HKU dont la ruche de fan-out (`.DEFAULT`/`<SID>`) a été DÉMONTÉE
	// depuis l'énumération (race logoff, review 35.3 #1) : no-op nil — JAMAIS
	// de clé orpheline matérialisée sous HKEY_USERS ; la cible disparaît de
	// l'énumération au cycle suivant.
	Write(spec RegistrySpec) error

	// Delete supprime la VALEUR NOMMÉE d'une clé (Story 35.1) — JAMAIS la
	// clé-conteneur (des valeurs voisines non gérées y vivent). Une valeur (ou
	// une clé) déjà absente N'EST PAS une erreur (idempotence : nil). err =
	// accès refusé / ruche invalide.
	Delete(hive, path, name string) error

	// ValueNames énumère les NOMS des valeurs d'une clé (Story 35.2 — la
	// réconciliation de clé-conteneur du type `registry_list` doit VOIR les
	// entrées surnuméraires). Clé ABSENTE ⇒ (nil, nil) — pas une erreur
	// (idempotence, iso Delete : la cible « aucune entrée » est déjà
	// atteinte). err = accès refusé / ruche invalide.
	ValueNames(hive, path string) ([]string, error)

	// UserHives énumère les CIBLES du fan-out HKU (Story 35.3) : les sous-clés
	// de HKEY_USERS à converger — ".DEFAULT" (le profil lu par l'écran de
	// logon) + chaque ruche utilisateur CHARGÉE (SID `S-1-5-21-*`, hors
	// jumelles `_Classes` — HKCR per-user, y écrire créerait des débris ; les
	// SID de service S-1-5-18/19/20 ne matchent pas le préfixe et sont exclus
	// naturellement ; comptes AAD S-1-12-1-* hors périmètre, parc AD). Ordre
	// TRIÉ (logs déterministes). Op REQUIS (pas une assertion optionnelle :
	// sans lui un item HKU est INAPPLICABLE — l'échec doit
	// être franc). Énuméré par APPEL, jamais mis en cache : une session
	// ouverte/fermée entre deux cycles est couverte/évaporée au cycle suivant.
	// err = accès refusé / énumération impossible (l'item HKU devient
	// inapplicable → {status: error} pour le type).
	UserHives() ([]string, error)
}

// NB (Story 43.1) : UserHives est un op REQUIS de l'interface — contrairement
// au rafraîchissement shell, qui n'est PLUS un hook sur Ops : l'ancien
// `registryNotifier` (SHChangeNotify inline en fin d'Apply) est REMPLACÉ par
// l'échelle de rafraîchissement — les handlers ACCUMULENT le besoin
// (RefreshRequester, refresh.go), le COMPAGNON exécute UN geste en fin de
// passe. Une seule voie d'émission (piège n° 5 : plus jamais deux
// SHChangeNotify pour un même changement).

// isUserHive : la ruche est-elle celle de l'utilisateur (HKCU) ? Gate
// l'accumulation du besoin de rafraîchissement (échelle 43.1 — plancher
// shell_notify) sur les seules clés per-user : les écritures HKLM du service
// (session 0) ne rafraîchissent aucun bureau interactif. HKU rend false
// (branche default, Story 35.3 piège n° 9) : le service écrit depuis la
// session 0, aucun geste n'y rafraîchirait un bureau interactif — NE PAS
// l'étendre.
func isUserHive(hive string) bool {
	switch strings.ToUpper(strings.TrimSpace(hive)) {
	case "HKCU", "HKEY_CURRENT_USER":
		return true
	default:
		return false
	}
}

// isUsersHive : la ruche est-elle HKEY_USERS (HKU, Story 35.3) ? Gate le
// FAN-OUT multi-ruches du handler (cibles = RegistryOps.UserHives) — distincte
// de isUserHive (HKCU, gate du rafraîchissement shell).
func isUsersHive(hive string) bool {
	switch strings.ToUpper(strings.TrimSpace(hive)) {
	case "HKU", "HKEY_USERS":
		return true
	default:
		return false
	}
}

// hkuEnumeration : mémo PAR APPEL (un Test ou un Apply) des cibles du fan-out
// HKU — UNE énumération par passe (vue cohérente pour toutes les clés HKU de la
// passe), JAMAIS retenue entre deux cycles (piège n° 7 : une session ouverte
// après coup est couverte au cycle suivant, une session fermée s'évapore).
type hkuEnumeration struct {
	ops     RegistryOps
	fetched bool
	hives   []string
	err     error
}

func (e *hkuEnumeration) targets() ([]string, error) {
	if !e.fetched {
		e.hives, e.err = e.ops.UserHives()
		e.fetched = true
	}

	return e.hives, e.err
}

// fanOutUserHives : expanse un spec HKU logique vers ses cibles PHYSIQUES —
// même Hive ("HKU", résolue par rootKey), Path préfixé par la ruche cible
// (`.DEFAULT\<path>`, `<SID>\<path>`). Le path du payload ne porte JAMAIS le
// préfixe `.DEFAULT\` lui-même (piège n° 6 : un path de seed commençant par
// `.DEFAULT\` produirait un double-préfixe silencieux).
func fanOutUserHives(spec RegistrySpec, hives []string) []RegistrySpec {
	targets := make([]RegistrySpec, 0, len(hives))
	for _, hive := range hives {
		target := spec
		target.Path = hive + `\` + spec.Path
		targets = append(targets, target)
	}

	return targets
}

// RegistryHandler : handler exclusive-par-clé branché dans le moteur
// (engine.go) — la machine d'états §5 reste au moteur, JAMAIS ici.
type RegistryHandler struct {
	Ops RegistryOps
	Log *Logger

	// refreshWanted : besoin de rafraîchissement accumulé pendant l'Apply de
	// la passe courante (Story 43.1) — max(plancher shell_notify, hint) de
	// chaque item HKCU EFFECTIVEMENT changé. État PAR INSTANCE, mono-thread
	// (patron acquis) : l'instance du MachineEngine SYSTEM (mêmes types Go,
	// piège n° 2) n'accumule jamais (gate isUserHive — HKLM/HKU rendent false)
	// et n'est jamais consommée. Consommé + remis à zéro par
	// TakeRefreshRequest (compagnon, fin de RunPass).
	refreshWanted RefreshLevel
}

// TakeRefreshRequest : implémente RefreshRequester (refresh.go) — retourne le
// niveau max accumulé pendant la passe et remet l'accumulation à zéro
// (consommation PAR PASSE : pas de geste fantôme au tick suivant).
func (h *RegistryHandler) TakeRefreshRequest() RefreshLevel {
	level := h.refreshWanted
	h.refreshWanted = RefreshNone

	return level
}

// desiredSpecs : parse + dédoublonne par identité de clé les items cible. Le
// serveur garantit déjà une clé unique par identité (exclusive par clé au
// compilateur) ; défense : la DERNIÈRE occurrence fait foi.
// logHints (review 43.1 #3) : la trace du hint `refresh` inconnu n'est émise
// que depuis le chemin Test (Apply re-parse les MÊMES items dans la même
// passe — sans le gate, la ligne partirait deux fois par passe et par item).
func (h *RegistryHandler) desiredSpecs(items []StateItem, logHints bool) ([]RegistrySpec, error) {
	byIdentity := map[string]RegistrySpec{}
	order := []string{}
	for _, item := range items {
		spec, ok := parseRegistrySpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload registry inattendu : enveloppe invalide")
		}
		if logHints {
			logUnknownRefreshHint(h.Log, item.Payload, spec.identity())
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
// Item HKU (Story 35.3) : drift AGRÉGÉ — conforme ssi TOUTES les cibles du
// fan-out (`.DEFAULT` + ruches chargées, énumérées pour CETTE passe) sont
// conformes ; une erreur d'énumération est franche (l'item est inapplicable).
// DESIGN ASSUMÉ (review 35.3 #2) : les erreurs de LECTURE restent franches en
// Test — y compris par-cible HKU. HKU multiplie donc la surface de lecture :
// une ruche chargée à ACL hostile (ACCESS_DENIED) met le type `registry` de
// la portée en {status: error} pour LE cycle, Apply non atteint (les HKLM ne
// convergent pas ce cycle-là). L'isolation par-cible ne vaut qu'en Apply
// (effort maximal). Contrepartie acceptée : un Test menteur (erreur avalée en
// drift) masquerait des pannes réelles à la policy STRICT.
func (h *RegistryHandler) Test(items []StateItem) (bool, error) {
	specs, err := h.desiredSpecs(items, true)
	if err != nil {
		return false, err
	}

	enum := &hkuEnumeration{ops: h.Ops}
	for _, spec := range specs {
		targets := []RegistrySpec{spec}
		if isUsersHive(spec.Hive) {
			hives, err := enum.targets()
			if err != nil {
				return false, fmt.Errorf("énumération des ruches utilisateur pour %s : %w", spec.identity(), err)
			}
			targets = fanOutUserHives(spec, hives)
		}

		for _, target := range targets {
			actual, present, err := h.Ops.Read(target.Hive, target.Path, target.Name)
			if err != nil {
				return false, fmt.Errorf("lecture de %s : %w", target.identity(), err)
			}
			if target.absent() {
				if present {
					return false, nil // la valeur existe → dérive (à supprimer)
				}

				continue
			}
			if !present || !actual.Equal(target.Value) {
				return false, nil
			}
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
// Item HKU (Story 35.3) : converge CHAQUE cible du fan-out en effort maximal
// (idempotence PAR CIBLE — seules les ruches divergentes sont réécrites/
// purgées) ; une erreur d'énumération rend les items HKU inapplicables (erreur
// remontée) SANS empêcher les autres clés de la passe de converger.
func (h *RegistryHandler) Apply(items []StateItem) error {
	specs, err := h.desiredSpecs(items, false)
	if err != nil {
		return err
	}

	var firstErr error
	enum := &hkuEnumeration{ops: h.Ops}
	for _, spec := range specs {
		targets := []RegistrySpec{spec}
		if isUsersHive(spec.Hive) {
			hives, err := enum.targets()
			if err != nil {
				logError(h.Log, "Énumération des ruches utilisateur (HKU) en échec : %v", err)
				if firstErr == nil {
					firstErr = fmt.Errorf("énumération des ruches utilisateur pour %s : %w", spec.identity(), err)
				}

				continue // item HKU inapplicable — les autres clés convergent
			}
			targets = fanOutUserHives(spec, hives)
		}

		for _, target := range targets {
			changed, err := h.applyTarget(target)
			if err != nil {
				if firstErr == nil {
					firstErr = err
				}

				continue // effort maximal : cible suivante / clé suivante
			}
			// Un changement EFFECTIF d'une valeur HKCU (écriture OU suppression)
			// ACCUMULE le besoin de rafraîchissement (Story 43.1) : plancher
			// shell_notify (D2, iso-comportement du SHChangeNotify historique),
			// escaladé par le hint `refresh` de l'item. Gate sur HKCU +
			// changement EFFECTIF : 0 écriture = 0 geste (au régime stable,
			// l'idempotence est préservée et l'Explorer ne « flicke » pas).
			// HKU : jamais (isUserHive rend false — piège n° 9). Le geste
			// lui-même est exécuté par le COMPAGNON en fin de RunPass
			// (RefreshRequester) — plus d'émission inline ici (piège n° 5 :
			// une seule voie d'émission).
			if changed && isUserHive(target.Hive) {
				h.refreshWanted = maxRefreshLevel(h.refreshWanted,
					maxRefreshLevel(RefreshShellNotify, spec.Refresh))
			}
		}
	}

	return firstErr
}

// applyTarget : converge UNE cible physique (une clé simple, ou une cible du
// fan-out HKU au path préfixé). changed=true ssi une écriture/suppression
// EFFECTIVE a eu lieu (gate du rafraîchissement shell). Erreur loggée puis
// remontée à l'appelant (qui l'isole — effort maximal).
func (h *RegistryHandler) applyTarget(target RegistrySpec) (bool, error) {
	actual, present, err := h.Ops.Read(target.Hive, target.Path, target.Name)
	if err != nil {
		logError(h.Log, "Lecture du réglage registre %s en échec : %v", target.identity(), err)

		return false, fmt.Errorf("lecture de %s : %w", target.identity(), err)
	}
	if target.absent() {
		if !present {
			return false, nil // déjà absente → idempotence (aucune suppression)
		}
		if err := h.Ops.Delete(target.Hive, target.Path, target.Name); err != nil {
			logError(h.Log, "Suppression du réglage registre %s en échec : %v", target.identity(), err)

			return false, fmt.Errorf("suppression de %s : %w", target.identity(), err)
		}
		logInfo(h.Log, "Réglage registre supprimé (ensure: absent) : %s", target.identity())

		return true, nil
	}
	if present && actual.Equal(target.Value) {
		return false, nil // déjà conforme → idempotence (aucune écriture)
	}
	if err := h.Ops.Write(target); err != nil {
		logError(h.Log, "Écriture du réglage registre %s en échec : %v", target.identity(), err)

		return false, fmt.Errorf("écriture de %s : %w", target.identity(), err)
	}
	logInfo(h.Log, "Réglage registre appliqué : %s = %s", target.identity(), formatValue(target.Value))

	return true, nil
}

// parseRegistrySpec : extrait un RegistrySpec d'un payload §3 brut. Champs
// hive/path manquants = enveloppe invalide (false) → le moteur rapporte
// error. `name` (Story 35.2, scope 35.5) : la clé DOIT être PRÉSENTE dans le
// payload (absence = enveloppe invalide), mais `""` est un nom LÉGITIME — la
// valeur PAR DÉFAUT de la clé (`(Default)` dans regedit, contrat §7.1). Les
// API registre (RegQueryValueEx/RegSetValueEx/RegDeleteValue, relayées par
// golang.org/x/sys/windows/registry) traitent un nom vide comme la valeur par
// défaut : GetValue("", …) / SetStringValue("", …) / DeleteValue("") ciblent
// bien `(Default)` — aucun cas particulier côté handler.
// Verbe `ensure` (Story 35.1, optionnel) : `"absent"` ⇒ item de
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
	rawName, hasName := payload["name"]
	name, nameIsString := rawName.(string)
	if hive == "" || path == "" || !hasName || !nameIsString {
		return RegistrySpec{}, false
	}

	// Hint `refresh` OPTIONNEL (Story 43.1) — lecture INDULGENTE (D3, piège
	// n° 1) : absent, vide, non-string ou vocabulaire inconnu ⇒ RefreshNone,
	// JAMAIS une enveloppe invalide (aucune validation de « clés exactes » —
	// le champ est additif sûr pour un agent antérieur).
	refreshHint, _ := payload["refresh"].(string)
	refresh := ParseRefreshLevel(refreshHint)

	// Branche `ensure` AVANT l'exigence type/value (piège n° 5 de la story) :
	// un item `absent` n'a ni `type` ni `value` à parser.
	if rawEnsure, exists := payload["ensure"]; exists {
		ensure, ok := rawEnsure.(string)
		if !ok {
			return RegistrySpec{}, false // ensure non-string = enveloppe invalide
		}
		switch ensure {
		case ensureAbsent:
			return RegistrySpec{Hive: hive, Path: path, Name: name, Ensure: ensureAbsent, Refresh: refresh}, true
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

	return RegistrySpec{Hive: hive, Path: path, Name: name, Value: value, Refresh: refresh}, true
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

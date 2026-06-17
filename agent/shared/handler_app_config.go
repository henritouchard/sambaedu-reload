package shared

import (
	"encoding/json"
	"fmt"
	"sort"
	"strings"
)

// Handler `app_config` (aggregate PAR app_kind / scope session) — Story 27.4.
// Logique PURE, OS-agnostique (les chemins natifs des apps + l'écriture fichier
// réelle sont injectés via AppConfigOps) → testée sur l'hôte ; agent/windows ne
// fait que câbler le chemin natif d'install + l'écriture atomique de fichier.
//
// UN SEUL MÉCANISME : écrire un `policies.json` au chemin natif de l'app
// (Firefox `…\Mozilla Firefox\distribution\policies.json`, Thunderbird au chemin
// équivalent). PAS de Chrome/Edge, PAS de mécanisme « registre policies », PAS
// de redirection de profil (recadrage 2026-06-17 — le legacy ne gère que
// FF/TB). Le serveur calcule le QUOI (policies résolues, 6 niveaux fusionnés) ;
// l'agent fait le OÙ/COMMENT (chemin natif par app, écriture atomique,
// idempotence, drift STRICT).
//
// CONVERGENCE level-triggered (piège n° 5), JAMAIS accumulation :
//   - test  : pour chaque app cible, le `policies.json` GÉRÉ présent == la
//     config cible (à l'octet près, forme canonique) ? ET tout `policies.json`
//     GÉRÉ d'une app sortie des règles est-il absent ?
//   - apply : (ré)écrire les divergents + retirer les `policies.json` GÉRÉS des
//     apps sorties des règles. IDEMPOTENT (2 passes sur état stable = aucune
//     écriture).
//
// MARQUEUR de périmètre (piège n° 7, iso 27.1 n° 5) : seuls les `policies.json`
// posés par l'agent (marqueur) sont gérés. L'app butée — qui écrirait localement
// un réglage SANS mécanisme enterprise — n'est JAMAIS bricolée : on ne touche
// que le `policies.json` (mécanisme enterprise documenté).
//
// `policies.json` HORS PÉRIMÈTRE (review #7, décision Henri 2026-06-17) : un
// fichier posé hors SambaEdu (autre outil, admin) occupe le chemin natif d'une
// app CIBLE. La non-ingérence est PRÉSERVÉE (on ne l'écrase ni ne le supprime
// JAMAIS), mais le statut N'EST PLUS `compliant` (trompeur : nos policies ne
// sont PAS actives, c'est le fichier étranger qui pilote l'app). On rapporte
// `error` avec un détail explicite (« policies.json hors-périmètre présent,
// policy agent non appliquée ») → le défaut « signaler sans écraser » SURFACE le
// conflit à l'admin. (Une future « prise de possession » SYSTEM pourra être
// décidée par Henri ; par défaut on signale.) L'isolation par item (engine.go
// RunPass) garantit que les autres types continuent.
//
// ISOLATION des erreurs (AC4) : un chemin verrouillé / une app absente → l'op
// renvoie une erreur → le moteur (engine.go RunPass) rend {status: error,
// detail} pour le SEUL type `app_config` ; les autres types continuent. La
// convergence interne est EFFORT MAXIMAL : une app en échec n'empêche pas les
// autres apps de converger — la première erreur est remontée APRÈS avoir tenté
// toutes les apps (le moteur n'a qu'un verdict par type).

// AppConfigManagedMarker : sentinel écrit dans le `policies.json` posé par
// l'agent (piège n° 7). Distingue un `policies.json` GÉRÉ d'un fichier posé par
// un autre outil / un admin — seuls les gérés sont comparés/supprimés. Inscrit
// comme une clé d'extension dédiée du document (l'impl OS l'ajoute à l'écriture
// et la cherche à la lecture) — invisible pour Firefox/Thunderbird (clé inconnue
// ignorée par le moteur de policies).
const AppConfigManagedMarker = "_sambaedu_managed"

// AppConfigSpec : la config résolue cible d'une app (un item du payload
// `app_config`). `Policies` est le contenu fusionné 6 niveaux côté serveur ;
// `Canonical` en est la sérialisation déterministe (clés triées récursivement)
// que l'op compare/écrit dans `policies.json`.
type AppConfigSpec struct {
	AppKind   string // "firefox" | "thunderbird"
	Policies  map[string]any
	Canonical []byte // forme canonique du `policies.json` (octets à écrire/comparer)
}

// AppConfigOps : opérations spécifiques à l'OS (chemins natifs + I/O fichier),
// injectées (testable hôte). L'impl Windows vit dans
// agent/windows/handler_app_config_windows.go (chemin natif d'install +
// écriture atomique) ; un fake en mémoire couvre les tests.
type AppConfigOps interface {
	// PolicyPath résout le chemin ABSOLU du `policies.json` natif de l'app.
	// Erreur = app non gérée (app_kind inconnu) → l'item devient error, les
	// autres apps continuent. App NON installée n'est PAS une erreur ici (le
	// chemin se résout même sans install) : c'est Write qui échouera si le
	// dossier parent est absent et non créable (isolation AC4).
	PolicyPath(appKind string) (string, error)

	// Inspect renvoie l'état du chemin `policies.json` pour le périmètre agent :
	//   - exists  : un fichier est présent à `path` ;
	//   - managed : ce fichier porte le marqueur de gestion SambaEdu (posé par
	//     l'agent) — comparable/supprimable. Un fichier présent mais NON géré
	//     (autre outil, admin) est HORS périmètre : JAMAIS écrasé ni supprimé.
	//   - err     : fichier illisible/corrompu OU chemin non inspectable → le
	//     moteur rend error pour le type `app_config`.
	// Absent → (false, false, nil) : libre, apply écrira.
	Inspect(path string) (exists bool, managed bool, err error)

	// Matches : le `policies.json` GÉRÉ à `path` correspond-il EXACTEMENT à la
	// config cible (octets canoniques, marqueur exclu de la comparaison) ?
	// N'est appelée QUE sur un chemin déjà inspecté géré. absent/divergent →
	// (false, nil) ; conforme → (true, nil) ; illisible → (false, err).
	Matches(path string, spec AppConfigSpec) (bool, error)

	// Write écrit (ou réécrit) le `policies.json` à `path` avec le marqueur de
	// gestion (écriture ATOMIQUE). err = chemin verrouillé / app absente (dossier
	// natif introuvable). Idempotent : réécrire le même contenu = même fichier.
	Write(path string, spec AppConfigSpec) error

	// Remove supprime un `policies.json` GÉRÉ (app sortie des règles). Absent =
	// pas d'erreur (idempotent). N'est appelée QUE sur un chemin déjà inspecté
	// géré (jamais un fichier hors périmètre).
	Remove(path string) error
}

// AppConfigHandler : handler aggregate branché dans le moteur (engine.go) — la
// machine d'états §5 et le hash d'agrégat restent au moteur, JAMAIS ici.
type AppConfigHandler struct {
	Ops AppConfigOps
	Log *Logger
}

// desiredSpecs : parse + dédoublonne par app_kind les items cible. Le serveur
// garantit déjà un item par app_kind (aggregate dédoublonné par contenu) ;
// défense : la DERNIÈRE occurrence d'un app_kind fait foi. Ordre stable par
// app_kind (logs/erreurs déterministes).
func (h *AppConfigHandler) desiredSpecs(items []StateItem) ([]AppConfigSpec, error) {
	byKind := map[string]AppConfigSpec{}
	for _, item := range items {
		spec, ok := parseAppConfigSpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload app_config inattendu : enveloppe invalide")
		}
		byKind[spec.AppKind] = spec
	}

	kinds := make([]string, 0, len(byKind))
	for k := range byKind {
		kinds = append(kinds, k)
	}
	sort.Strings(kinds)

	specs := make([]AppConfigSpec, 0, len(kinds))
	for _, k := range kinds {
		specs = append(specs, byKind[k])
	}

	return specs, nil
}

// Test : chaque app cible a-t-elle EXACTEMENT son `policies.json` géré conforme,
// ET aucune app sortie des règles ne garde-t-elle un `policies.json` géré ?
//   - chemin LIBRE (absent) pour une app cible → non conforme (apply écrira) ;
//   - chemin GÉRÉ divergent → non conforme ;
//   - chemin occupé HORS périmètre pour une app CIBLE → ERREUR de conflit
//     (review #7) : jamais touché, mais surfacé en `error` (nos policies ne sont
//     pas actives) ;
//   - `policies.json` géré ORPHELIN (app hors cible) présent → non conforme.
//
// Une erreur d'accès (chemin verrouillé, app_kind inconnu, fichier illisible,
// conflit hors-périmètre) remonte → le moteur rend error pour le type.
func (h *AppConfigHandler) Test(items []StateItem) (bool, error) {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return false, err
	}

	desiredKinds := map[string]bool{}
	for _, spec := range specs {
		desiredKinds[spec.AppKind] = true

		path, err := h.Ops.PolicyPath(spec.AppKind)
		if err != nil {
			return false, fmt.Errorf("chemin policies de %q : %w", spec.AppKind, err)
		}

		exists, managed, err := h.Ops.Inspect(path)
		if err != nil {
			return false, fmt.Errorf("inspection de %q : %w", path, err)
		}
		if exists && !managed {
			// Conflit hors-périmètre (review #7) : fichier étranger sur une app
			// CIBLE → JAMAIS écrasé, mais rapporté error (policy agent inactive).
			return false, foreignPolicyConflictError(spec.AppKind, path)
		}
		if !exists {
			return false, nil // libre : la cible n'est pas posée → apply écrira.
		}
		ok, err := h.Ops.Matches(path, spec)
		if err != nil {
			return false, fmt.Errorf("comparaison de %q : %w", path, err)
		}
		if !ok {
			return false, nil // géré mais divergent.
		}
	}

	// Aucun `policies.json` GÉRÉ d'une app SORTIE des règles ne doit subsister
	// (level-triggered) : on balaye les apps connues hors cible.
	for _, kind := range knownAppKinds() {
		if desiredKinds[kind] {
			continue
		}
		path, err := h.Ops.PolicyPath(kind)
		if err != nil {
			continue // app_kind connu mais chemin non résoluble : rien à balayer.
		}
		_, managed, err := h.Ops.Inspect(path)
		if err != nil {
			return false, fmt.Errorf("inspection de %q (orphelin) : %w", path, err)
		}
		if managed {
			return false, nil // un `policies.json` géré orphelin subsiste.
		}
	}

	return true, nil
}

// Apply : converge — (ré)écrit les `policies.json` divergents + retire les
// gérés des apps sorties des règles. Idempotent + level-triggered. EFFORT
// MAXIMAL : on tente TOUTES les apps ; la première erreur est remontée à la fin
// (les apps saines convergent quand même, isolation inter-items AC4). Ne touche
// JAMAIS un `policies.json` hors périmètre (app butée / fichier d'un autre
// outil) : il est sauté, jamais écrasé ni supprimé (piège n° 7).
func (h *AppConfigHandler) Apply(items []StateItem) error {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return err
	}

	var firstErr error
	desiredKinds := map[string]bool{}

	for _, spec := range specs {
		desiredKinds[spec.AppKind] = true

		path, err := h.Ops.PolicyPath(spec.AppKind)
		if err != nil {
			logError(h.Log, "Chemin policies de %q non résoluble : %v", spec.AppKind, err)
			if firstErr == nil {
				firstErr = fmt.Errorf("chemin policies de %q : %w", spec.AppKind, err)
			}

			continue
		}

		exists, managed, err := h.Ops.Inspect(path)
		if err != nil {
			logError(h.Log, "Inspection de %q en échec : %v", path, err)
			if firstErr == nil {
				firstErr = fmt.Errorf("inspection de %q : %w", path, err)
			}

			continue
		}

		// Fichier posé HORS SambaEdu au chemin natif d'une app CIBLE : laissé tel
		// quel (JAMAIS écrasé — non-ingérence préservée, review #7) MAIS rapporté
		// error de conflit (policy agent inactive). Effort maximal : non fatal pour
		// les AUTRES apps (qui convergent quand même), mais le type rend error.
		if exists && !managed {
			logError(h.Log, "policies.json hors périmètre (%s) — policy agent NON appliquée (jamais écrasé) : %s", spec.AppKind, path)
			if firstErr == nil {
				firstErr = foreignPolicyConflictError(spec.AppKind, path)
			}

			continue
		}

		if exists && managed {
			ok, err := h.Ops.Matches(path, spec)
			if err != nil {
				logError(h.Log, "Comparaison de %q en échec : %v", path, err)
				if firstErr == nil {
					firstErr = fmt.Errorf("comparaison de %q : %w", path, err)
				}

				continue
			}
			if ok {
				continue // déjà conforme → idempotence (aucune écriture).
			}
		}

		if err := h.Ops.Write(path, spec); err != nil {
			logError(h.Log, "Écriture de %q en échec : %v", path, err)
			if firstErr == nil {
				firstErr = fmt.Errorf("écriture de %q : %w", path, err)
			}

			continue
		}
		logInfo(h.Log, "policies.json appliqué (%s) : %s", spec.AppKind, path)
	}

	// Retrait des `policies.json` GÉRÉS des apps sorties des règles
	// (level-triggered). Jamais un fichier non géré.
	for _, kind := range knownAppKinds() {
		if desiredKinds[kind] {
			continue
		}
		path, err := h.Ops.PolicyPath(kind)
		if err != nil {
			continue
		}
		_, managed, err := h.Ops.Inspect(path)
		if err != nil {
			logError(h.Log, "Inspection de %q (orphelin) en échec : %v", path, err)
			if firstErr == nil {
				firstErr = fmt.Errorf("inspection orphelin de %q : %w", path, err)
			}

			continue
		}
		if !managed {
			continue // absent OU hors périmètre : on ne touche pas.
		}
		if err := h.Ops.Remove(path); err != nil {
			logError(h.Log, "Retrait du policies.json géré %q en échec : %v", path, err)
			if firstErr == nil {
				firstErr = fmt.Errorf("retrait de %q : %w", path, err)
			}

			continue
		}
		logInfo(h.Log, "policies.json géré retiré (%s, sorti des règles) : %s", kind, path)
	}

	return firstErr
}

// foreignPolicyConflictError : conflit hors-périmètre (review #7). Un
// `policies.json` posé hors SambaEdu occupe le chemin natif d'une app cible :
// jamais écrasé (non-ingérence), mais surfacé en `error` (la policy agent n'est
// PAS active — c'est le fichier étranger qui pilote l'app). Détail exploitable
// pour l'admin (contrat §6).
func foreignPolicyConflictError(appKind, path string) error {
	return fmt.Errorf("policies.json hors-périmètre présent (%s), policy agent non appliquée (fichier étranger jamais écrasé) : %s", appKind, path)
}

// knownAppKinds : les apps `policies.json` gérées par CE handler (iso
// AppKind::cases() serveur — Firefox, Thunderbird). Balayées pour le nettoyage
// level-triggered des orphelins. Ajouter une app `policies.json` future = data
// serveur + une entrée ici (chemin natif côté OS), ZÉRO branche métier.
func knownAppKinds() []string {
	return []string{"firefox", "thunderbird"}
}

// parseAppConfigSpec : extrait un AppConfigSpec d'un payload §3 brut. app_kind
// vide/inconnu ou policies non-objet = enveloppe invalide (false) → le moteur
// rapporte error. La forme canonique du `policies.json` est calculée ICI (clés
// triées récursivement) pour une comparaison/écriture déterministe.
func parseAppConfigSpec(raw any) (AppConfigSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return AppConfigSpec{}, false
	}

	appKind, _ := payload["app_kind"].(string)
	appKind = strings.ToLower(strings.TrimSpace(appKind))
	if appKind == "" {
		return AppConfigSpec{}, false
	}

	policies, ok := payload["policies"].(map[string]any)
	if !ok {
		// `policies` peut être absent (rare) → objet vide accepté (la config se
		// réduit au socle template/auto déjà résolu serveur). Mais un `policies`
		// présent d'un type non objet (string/list mal placé) est invalide.
		if _, present := payload["policies"]; present {
			return AppConfigSpec{}, false
		}
		policies = map[string]any{}
	}

	canonical, err := canonicalPolicies(policies)
	if err != nil {
		return AppConfigSpec{}, false
	}

	return AppConfigSpec{
		AppKind:   appKind,
		Policies:  policies,
		Canonical: canonical,
	}, true
}

// canonicalPolicies : sérialisation JSON déterministe (clés triées
// récursivement, indentée 2 espaces comme l'export legacy `JSON_PRETTY_PRINT`)
// du `policies.json` à écrire. Déterministe → idempotence : deux compilations du
// même état produisent les MÊMES octets, donc aucune réécriture en boucle. Le
// marqueur de gestion n'est PAS inclus ici (l'impl OS l'ajoute à l'écriture et
// l'exclut de la comparaison).
//
// Délègue à CanonicalJSON — la SEULE fonction de canonicalisation (review #3) :
// la forme CIBLE (calculée ici) et la forme RELUE du fichier (côté windows)
// passent par le MÊME code → idempotence garantie, y compris sur des nombres
// décodés `json.Number` (payload réseau décodé `UseNumber`).
func canonicalPolicies(policies map[string]any) ([]byte, error) {
	return CanonicalJSON(policies)
}

// CanonicalJSON : sérialisation JSON déterministe et UNIQUE du projet pour le
// `policies.json` (clés triées récursivement, indentation 2 espaces,
// slashes/unicode non échappés — iso adapters PHP
// JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE ; '\n' final
// retiré). UNIQUE fonction appelée DES DEUX côtés (forme cible `shared` +
// forme relue `agent/windows`) pour garantir l'idempotence à l'octet près, y
// compris sur des `json.Number` (review #3).
func CanonicalJSON(v any) ([]byte, error) {
	sorted := sortMaps(v)
	buf := &jsonBuffer{}
	enc := json.NewEncoder(buf)
	enc.SetEscapeHTML(false)
	enc.SetIndent("", "  ")
	if err := enc.Encode(sorted); err != nil {
		return nil, err
	}

	return buf.bytesTrimmed(), nil
}

// sortMaps trie récursivement les clés des objets (les listes restent dans leur
// ordre — ordre signifiant). Garantit une sérialisation lisible et stable.
func sortMaps(v any) any {
	switch t := v.(type) {
	case map[string]any:
		keys := make([]string, 0, len(t))
		for k := range t {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		out := make(map[string]any, len(t))
		for _, k := range keys {
			out[k] = sortMaps(t[k])
		}

		return out
	case []any:
		out := make([]any, len(t))
		for i := range t {
			out[i] = sortMaps(t[i])
		}

		return out
	default:
		return v
	}
}

// jsonBuffer : petit tampon pour l'encodeur (json.NewEncoder ajoute un '\n'
// final qu'on retire pour un fichier propre).
type jsonBuffer struct {
	b []byte
}

func (j *jsonBuffer) Write(p []byte) (int, error) {
	j.b = append(j.b, p...)

	return len(p), nil
}

func (j *jsonBuffer) bytesTrimmed() []byte {
	return []byte(strings.TrimRight(string(j.b), "\n"))
}

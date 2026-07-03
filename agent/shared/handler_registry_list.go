package shared

import (
	"fmt"
	"sort"
	"strconv"
	"strings"

	"golang.org/x/text/unicode/norm"
)

// Handler `registry_list` (exclusive PAR CLÉ-CONTENEUR / scope machine|session)
// — Story 35.2, contrat §7.6. Logique PURE, OS-agnostique (accès registre
// injectés via RegistryOps) → testée sur l'hôte ; agent/windows n'apporte que
// l'op ValueNames.
//
// D3 — l'agent POSSÈDE la clé-conteneur : dans la clé `{hive, path}`, les
// valeurs dont le nom est composé UNIQUEMENT de chiffres (`^[0-9]+$`) sont à
// lui. Le canon est `"1".."N"` (strconv.Itoa, comparaison de chaînes STRICTE :
// `"01"`, `"007"` sont HORS canon → supprimées). Réconciliation :
//   - écrire les valeurs nommées `1..N` dans l'ORDRE de `values`
//     (Kind = entry_type, création de clé au besoin) ;
//   - supprimer toute AUTRE valeur au nom numérique (socle Delete 35.1) ;
//   - ne JAMAIS toucher une valeur à nom NON numérique de la même clé ;
//   - ne JAMAIS supprimer la clé-conteneur elle-même (même à liste vide — des
//     valeurs voisines non gérées peuvent y vivre).
// `values: []` = purge des entrées numérotées (le « off » honnête d'une
// liste) ; clé absente = conforme.
//
// UN SEUL handler générique pour les DEUX portées (iso registry) : le SERVICE
// SYSTEM l'instancie pour les conteneurs HKLM (portée machine), le COMPAGNON
// pour les conteneurs HKCU (portée session). Le handler ne connaît QUE l'item
// concret {hive, path, entry_type, values} — jamais le catalogue serveur.
//
// EFFORT MAXIMAL par conteneur ET entre conteneurs (iso RegistryHandler.Apply) :
// une clé/valeur en échec n'empêche pas les autres de converger — la première
// erreur est remontée à la FIN (le moteur n'a qu'un verdict par type, grain
// 27.8 : UN statut pour le type `registry_list`, dual-scope fusionné par
// MergeReportItemsByType).
//
// Un changement EFFECTIF (écriture OU suppression) sur un conteneur HKCU
// déclenche le rafraîchissement shell (même gate que `registry` : zéro
// changement = zéro notification). NB : `DisallowRun` est lu par l'Explorer au
// LOGON SUIVANT (mémoire projet) — ce n'est pas un bug de convergence.

// RegistryListSpec : une clé-conteneur cible (un item du payload
// `registry_list`, contrat §7.6 — EXACTEMENT 4 clés).
type RegistryListSpec struct {
	Hive      string   // "HKLM" | "HKCU"
	Path      string   // clé-conteneur sous la ruche
	EntryType string   // "REG_SZ" | "REG_EXPAND_SZ" (borné par le contrat)
	Values    []string // liste ORDONNÉE ; vide = purge des entrées numérotées
}

// identity : identité du conteneur {hive\path} insensible à la casse (Windows
// l'est) — logs + unicité interne (iso exclusiveKey serveur, 2 segments).
func (s RegistryListSpec) identity() string {
	return strings.ToLower(s.Hive) + `\` + strings.ToLower(s.Path)
}

// isNumericName : le nom appartient-il au domaine POSSÉDÉ par l'agent
// (digits-only, non vide) ? La valeur par défaut (`""`) et tout nom
// alphanumérique ne sont JAMAIS touchés.
func isNumericName(name string) bool {
	if name == "" {
		return false
	}
	for _, r := range name {
		if r < '0' || r > '9' {
			return false
		}
	}

	return true
}

// RegistryListHandler : handler exclusive-par-conteneur branché dans le
// moteur (engine.go INTOUCHÉ — la machine d'états §5 reste au moteur).
type RegistryListHandler struct {
	Ops RegistryOps
	Log *Logger
}

// desiredListSpecs : parse + dédoublonne par identité de conteneur les items
// cible. Le serveur garantit déjà un conteneur unique par identité (exclusive
// par clé au compilateur) ; défense : la DERNIÈRE occurrence fait foi, ordre
// de sortie trié (logs/erreurs stables — iso desiredSpecs de registry).
func (h *RegistryListHandler) desiredListSpecs(items []StateItem) ([]RegistryListSpec, error) {
	byIdentity := map[string]RegistryListSpec{}
	order := []string{}
	for _, item := range items {
		spec, ok := parseRegistryListSpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload registry_list inattendu : enveloppe invalide")
		}
		id := spec.identity()
		if _, seen := byIdentity[id]; !seen {
			order = append(order, id)
		}
		byIdentity[id] = spec
	}

	sort.Strings(order)
	specs := make([]RegistryListSpec, 0, len(order))
	for _, id := range order {
		specs = append(specs, byIdentity[id])
	}

	return specs, nil
}

// canonName : nom canonique de la i-ème entrée (1-based) — strconv.Itoa, la
// SEULE forme au canon ("01"/"007" ne matchent jamais, comparaison stricte).
func canonName(i int) string {
	return strconv.Itoa(i + 1)
}

// Test : chaque conteneur est-il EXACTEMENT à sa cible ? Conforme ssi :
//   - les valeurs nommées "1".."N" existent avec le Kind entry_type et les
//     contenus values[i-1] (comparaison NFC, iso RegistryValue.Equal) ;
//   - AUCUNE autre valeur au nom numérique n'existe dans la clé (les noms non
//     numériques sont ignorés — jamais gérés).
//
// values vide ⇒ conforme ssi aucune valeur numérique (clé absente = conforme :
// ValueNames rend nil,nil). Une erreur d'accès remonte (verdict error du type).
func (h *RegistryListHandler) Test(items []StateItem) (bool, error) {
	specs, err := h.desiredListSpecs(items)
	if err != nil {
		return false, err
	}

	for _, spec := range specs {
		// 1. Le canon 1..N est-il exact ?
		for i, want := range spec.Values {
			actual, present, err := h.Ops.Read(spec.Hive, spec.Path, canonName(i))
			if err != nil {
				return false, fmt.Errorf("lecture de %s!%s : %w", spec.identity(), canonName(i), err)
			}
			target := RegistryValue{Kind: spec.EntryType, Str: want}
			if !present || !actual.Equal(target) {
				return false, nil
			}
		}

		// 2. Aucune entrée numérique surnuméraire ? (Kind indifférent : une
		// valeur numérotée de type exotique — sentinelle REG_UNSUPPORTED —
		// est énumérée comme les autres et compte comme dérive si hors canon ;
		// dans le canon, l'étape 1 l'a déjà vue divergente via Equal.)
		names, err := h.Ops.ValueNames(spec.Hive, spec.Path)
		if err != nil {
			return false, fmt.Errorf("énumération de %s : %w", spec.identity(), err)
		}
		canon := make(map[string]bool, len(spec.Values))
		for i := range spec.Values {
			canon[canonName(i)] = true
		}
		for _, name := range names {
			if isNumericName(name) && !canon[name] {
				return false, nil // surnuméraire possédée → dérive (à supprimer)
			}
		}
	}

	return true, nil
}

// Apply : converge chaque conteneur — écrit les 1..N divergents/manquants
// dans l'ordre, supprime les noms numériques hors canon. IDEMPOTENT (2 passes
// stables = zéro op). EFFORT MAXIMAL : toutes les entrées de tous les
// conteneurs sont tentées, la première erreur est remontée à la fin.
func (h *RegistryListHandler) Apply(items []StateItem) error {
	specs, err := h.desiredListSpecs(items)
	if err != nil {
		return err
	}

	var firstErr error
	shellRefresh := false // au moins un changement effectif HKCU → notifier le shell
	recordErr := func(err error) {
		if firstErr == nil {
			firstErr = err
		}
	}

	for _, spec := range specs {
		// Énumération AVANT écritures : les surnuméraires sont détectées sur
		// l'état réel courant (les écritures canon n'y figurent pas encore).
		names, err := h.Ops.ValueNames(spec.Hive, spec.Path)
		if err != nil {
			logError(h.Log, "Énumération du conteneur registre %s en échec : %v", spec.identity(), err)
			recordErr(fmt.Errorf("énumération de %s : %w", spec.identity(), err))

			continue // conteneur illisible : on n'y touche pas, les autres continuent.
		}

		// 1. Écrire les entrées canon divergentes/manquantes, DANS L'ORDRE.
		for i, want := range spec.Values {
			name := canonName(i)
			target := RegistryValue{Kind: spec.EntryType, Str: want}
			actual, present, err := h.Ops.Read(spec.Hive, spec.Path, name)
			if err != nil {
				logError(h.Log, "Lecture de %s!%s en échec : %v", spec.identity(), name, err)
				recordErr(fmt.Errorf("lecture de %s!%s : %w", spec.identity(), name, err))

				continue
			}
			if present && actual.Equal(target) {
				continue // déjà conforme → idempotence (aucune écriture)
			}
			// Réutilise RegistryOps.Write via un RegistrySpec (création de clé
			// au besoin) — une valeur canon de Kind exotique (REG_UNSUPPORTED)
			// échoue Equal → réécrite au entry_type cible (review 35.1 #1).
			if err := h.Ops.Write(RegistrySpec{Hive: spec.Hive, Path: spec.Path, Name: name, Value: target}); err != nil {
				logError(h.Log, "Écriture de %s!%s en échec : %v", spec.identity(), name, err)
				recordErr(fmt.Errorf("écriture de %s!%s : %w", spec.identity(), name, err))

				continue
			}
			logInfo(h.Log, "Entrée de liste registre appliquée : %s!%s = %s", spec.identity(), name, norm.NFC.String(want))
			if isUserHive(spec.Hive) {
				shellRefresh = true
			}
		}

		// 2. Supprimer les noms NUMÉRIQUES hors canon — JAMAIS un nom non
		// numérique, JAMAIS la clé-conteneur (même à liste vide).
		canon := make(map[string]bool, len(spec.Values))
		for i := range spec.Values {
			canon[canonName(i)] = true
		}
		for _, name := range names {
			if !isNumericName(name) || canon[name] {
				continue
			}
			if err := h.Ops.Delete(spec.Hive, spec.Path, name); err != nil {
				logError(h.Log, "Suppression de l'entrée surnuméraire %s!%s en échec : %v", spec.identity(), name, err)
				recordErr(fmt.Errorf("suppression de %s!%s : %w", spec.identity(), name, err))

				continue
			}
			logInfo(h.Log, "Entrée de liste registre surnuméraire supprimée : %s!%s", spec.identity(), name)
			// Le nom était ÉNUMÉRÉ (présent) → suppression EFFECTIVE.
			if isUserHive(spec.Hive) {
				shellRefresh = true
			}
		}
	}

	// Même gate que RegistryHandler : changement HKCU EFFECTIF seulement —
	// au régime stable, zéro op = zéro notification (pas de « flicker »).
	if shellRefresh {
		if notifier, ok := h.Ops.(registryNotifier); ok {
			notifier.NotifyShellChanged()
		}
	}

	return firstErr
}

// parseRegistryListSpec : extrait un RegistryListSpec d'un payload §7.6 brut.
// Enveloppe invalide (false → {status: error} pour le type) si : hive/path
// vides ou absents, entry_type absent ou hors {REG_SZ, REG_EXPAND_SZ}
// (piège n°14), values absent ou non liste-de-chaînes. `values: []` est VALIDE
// (purge). Jamais de champ `name` dans ce payload (4 clés exactement — les
// noms 1..N sont DÉRIVÉS de l'ordre de values, pas transportés).
func parseRegistryListSpec(raw any) (RegistryListSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return RegistryListSpec{}, false
	}

	hive, _ := payload["hive"].(string)
	path, _ := payload["path"].(string)
	if hive == "" || path == "" {
		return RegistryListSpec{}, false
	}

	entryType, _ := payload["entry_type"].(string)
	entryType = strings.ToUpper(entryType)
	switch entryType {
	case "REG_SZ", "REG_EXPAND_SZ":
		// borné par le contrat §7.6 (les listes indexées Windows sont des chaînes)
	default:
		return RegistryListSpec{}, false
	}

	rawValues, ok := payload["values"].([]any)
	if !ok {
		return RegistryListSpec{}, false // absent ou non-liste = enveloppe invalide
	}
	values := make([]string, 0, len(rawValues))
	for _, v := range rawValues {
		s, ok := v.(string)
		if !ok {
			return RegistryListSpec{}, false // entrée non-string = enveloppe invalide
		}
		values = append(values, s)
	}

	return RegistryListSpec{Hive: hive, Path: path, EntryType: entryType, Values: values}, true
}

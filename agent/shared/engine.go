package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"time"
)

// Moteur de convergence générique (Story 24.6 — portage fidèle de
// ConvergenceEngine.ps1, design validé en review 24.4).
//
// Cœur PORTABLE (contrainte n° 5 du cahier des charges) : AUCUNE dépendance
// Windows ici — uniquement la machine d'états du contrat §5 (convergence
// STRICT inconditionnelle — Story 27.8), l'isolation par item et les
// conventions de hash du rapport. Les handlers spécifiques OS (registre,
// SystemParametersInfo, chemins %LOCALAPPDATA%) vivent dans agent/windows/
// derrière l'interface Handler.
//
// Ce que fait Engine.RunPass :
//   - itère les items DANS L'ORDRE du payload serveur (AC epic / FR18 —
//     jamais d'ordre inventé, jamais de parallélisme), groupés par type (un
//     type = un verdict, le rapport exige des types UNIQUES — contrat §6) ;
//   - dispatch vers le handler enregistré par type ; type sans handler =
//     ignoré + log DEBUG (contrat §8 : « ne touche pas ») ;
//   - isolation PAR type (recover au point de dispatch) : un échec/panic
//     produit {status: error, detail} et la passe CONTINUE (AC epic) ;
//   - applique la machine d'états §5 (cf. ResolveItemStatus — STRICT
//     inconditionnel depuis 27.8) ; le store applied-state injecté par
//     l'appelant (per-user pour le compagnon) trace le dernier-appliqué ;
//   - produit les items de rapport {type, status, hash[, detail]}.
//
// Conventions de hash du rapport (24.4, conservées À L'IDENTIQUE — en
// changer fausserait les transitions agent_report_events côté serveur au
// premier rapport Go) :
//   - type `exclusive` : le hash d'item opaque du serveur, VERBATIM ;
//   - type `aggregate` : le serveur ne fournit PAS de hash d'ensemble —
//     l'agent construit une EMPREINTE déterministe : SHA-256 hex de la
//     concaténation des hashes opaques des items du type, dans l'ordre du
//     payload serveur. Ce n'est PAS un recalcul de hash d'item (interdit —
//     le hash serveur reste opaque, et l'agent ne hashe JAMAIS depuis sa
//     propre sérialisation) : c'est une empreinte d'agrégat sur des chaînes
//     opaques, que le serveur ne compare qu'au rapport PRÉCÉDENT.

// detailMaxLength : borne du contrat §6 — `detail` ≤ 2000 caractères.
const detailMaxLength = 2000

// StateItem est un item du contrat (§3) vu par le moteur : champs extraits
// de l'enveloppe parsée, payload laissé brut pour les handlers. Le Hash est
// OPAQUE (fourni serveur) — jamais recalculé. Story 27.8 : plus de champ
// `Mode` (le mécanisme strict/default est supprimé — convergence STRICT
// inconditionnelle) ; un éventuel champ `mode` du payload serveur est ignoré.
type StateItem struct {
	Type      string
	Semantics string // "exclusive" | "aggregate" (défaut exclusive)
	Hash      string
	Payload   any // map[string]any ou nil — parsing, jamais hashé
}

// ItemsFromScope convertit les items bruts d'une portée parsée (ParseState)
// en StateItem, dans l'ordre du payload serveur. Item sans type ou sans hash
// (enveloppe inattendue) = ignoré + warning, jamais une erreur de passe.
func ItemsFromScope(raw []any, log *Logger) []StateItem {
	items := make([]StateItem, 0, len(raw))
	for _, entry := range raw {
		obj, ok := entry.(map[string]any)
		if !ok {
			logWarning(log, "Item non-objet dans le payload : ignoré (enveloppe inattendue).")

			continue
		}
		typ, _ := obj["type"].(string)
		hash, _ := obj["hash"].(string)
		if typ == "" || hash == "" {
			logWarning(log, "Item sans type ou sans hash dans le payload : ignoré (enveloppe inattendue).")

			continue
		}
		semantics, _ := obj["semantics"].(string)
		items = append(items, StateItem{
			Type:      typ,
			Semantics: semantics,
			Hash:      hash,
			Payload:   obj["payload"],
		})
	}

	return items
}

// Handler est le contrat d'un handler de type de ressource (interface TYPÉE
// — le dispatch durci de la review 24.4 #4 est réglé structurellement en Go).
//
// Test répond « le réel correspond-il à la cible ? » ; Apply converge
// (IDEMPOTENT — rejouable sans effet cumulatif). L'un comme l'autre peuvent
// échouer (error ou panic) : l'échec est capturé PAR type par le moteur et
// devient {status: error, detail}.
type Handler interface {
	Test(items []StateItem) (bool, error)
	Apply(items []StateItem) error
}

// InventoryReporter : interface OPTIONNELLE qu'un handler peut implémenter pour
// joindre un inventaire PAR SOUS-ENTITÉ à son item de rapport (Story 27.5, AC4 —
// le handler `applications` rapporte le résultat par app). Le moteur l'appelle
// APRÈS le dispatch (Test/Apply) et n'attache l'inventaire que si non vide. Cela
// NE change PAS le verdict du type (status/hash restent worst-status par type —
// grain 27.8 intact) : l'inventaire est une DONNÉE additive.
type InventoryReporter interface {
	// ReportInventory : inventaire par sous-entité du DERNIER Test/Apply (vidé
	// par le moteur dans le ReportItem du cycle). Vide → champ omis.
	ReportInventory() []ReportInventoryItem
}

// AppliedEntry : dernier-appliqué d'un type (contrat §5) — hash OPAQUE
// (hash d'item exclusive ou empreinte d'agrégat) + horodatage informatif.
type AppliedEntry struct {
	Hash      string `json:"hash"`
	AppliedAt string `json:"applied_at"`
}

// AppliedState : map type → dernier-appliqué. Mutée en place par RunPass —
// la persister (écriture atomique per-user) est la responsabilité de
// l'appelant.
type AppliedState map[string]AppliedEntry

// Verdict de la machine d'états §5 pour un type.
type Verdict struct {
	Status        string
	ShouldApply   bool
	ShouldPersist bool
}

// ResolveItemStatus implémente la machine d'états du contrat §5 — STRICT
// inconditionnel (Story 27.8 : le mécanisme strict/default est SUPPRIMÉ, la
// cible fait TOUJOURS loi) :
//
//	réel = cible → compliant ; réel ≠ cible → APPLIQUE → drift.
//
// La dérive humaine n'est plus tolérée : `réel ≠ cible` produit toujours
// `drift` + apply (plus de `drifted_allowed`, plus de paramètre `mode` ni
// `lastAppliedHash` au verdict).
//
// ShouldPersist : la cible devient le dernier-appliqué après compliant ou
// apply réussi (empreinte persistée pour la traçabilité, décision 24.4 n° 9,
// conservée même sans incidence de verdict — Story 27.8 D-B).
func ResolveItemStatus(isCompliant bool) Verdict {
	if isCompliant {
		return Verdict{Status: "compliant", ShouldPersist: true}
	}

	return Verdict{Status: "drift", ShouldApply: true, ShouldPersist: true}
}

// AggregateHash : empreinte d'agrégat d'un type `aggregate` — SHA-256 hex
// (minuscules) de la concaténation des hashes opaques des items, dans
// l'ordre serveur. Déterministe par construction : mêmes items dans le même
// ordre = même empreinte (« rapport identique = zéro événement » préservé).
func AggregateHash(items []StateItem) string {
	concatenated := ""
	for _, item := range items {
		concatenated += item.Hash
	}
	sum := sha256.Sum256([]byte(concatenated))

	return hex.EncodeToString(sum[:])
}

// Engine : une passe de convergence = items (ordre serveur) × handlers →
// items de rapport.
type Engine struct {
	// Handlers : type → Handler. Tout type absent de la map est ignoré
	// (contrat §8) — les types suivants arrivent avec l'Epic 27.
	Handlers map[string]Handler
	Log      *Logger

	// Now : horloge injectable (tests d'applied_at). nil = time.Now.
	Now func() time.Time
}

func (e *Engine) now() time.Time {
	if e.Now != nil {
		return e.Now()
	}

	return time.Now()
}

// RunPass exécute une passe de convergence. Mute applied (la persistance
// atomique est la responsabilité de l'appelant — per-user côté compagnon).
func (e *Engine) RunPass(items []StateItem, applied AppliedState) []ReportItem {
	// Groupement par type en PRÉSERVANT l'ordre de première occurrence
	// (ordre serveur). Un type = un groupe = un verdict (types uniques §6).
	order := []string{}
	groups := map[string][]StateItem{}
	for _, item := range items {
		if _, seen := groups[item.Type]; !seen {
			order = append(order, item.Type)
		}
		groups[item.Type] = append(groups[item.Type], item)
	}

	reportItems := []ReportItem{}

	for _, typ := range order {
		typeItems := groups[typ]

		handler, ok := e.Handlers[typ]
		if !ok {
			// Contrat §8 : type sans handler = l'agent NE TOUCHE PAS à la
			// ressource et n'émet AUCUN statut pour elle.
			logDebug(e.Log, "Type '%s' sans handler enregistré : ignoré (aucun statut émis).", typ)

			continue
		}

		first := typeItems[0]
		semantics := first.Semantics
		if semantics == "" {
			semantics = "exclusive"
		}

		// Hash rapporté (conventions 24.4) : exclusive = hash d'item
		// verbatim ; aggregate = empreinte déterministe, ordre serveur.
		var targetHash string
		if semantics == "aggregate" {
			targetHash = AggregateHash(typeItems)
		} else {
			if len(typeItems) > 1 {
				// Le compilateur serveur garantit UN item par type exclusive
				// — défense : le DERNIER fait foi (§3.1 « l'unique / le
				// dernier »).
				logWarning(e.Log, "Type exclusif '%s' avec %d items : seul le dernier fait foi (contrat §3.1).", typ, len(typeItems))
			}
			targetHash = typeItems[len(typeItems)-1].Hash
		}

		reportItem, persist := e.dispatch(handler, typ, typeItems, targetHash)
		if persist {
			// Persistance du dernier-appliqué (traçabilité, décision 24.4 n° 9 ;
			// conservée Story 27.8 D-B même sans incidence de verdict).
			applied[typ] = AppliedEntry{
				Hash:      targetHash,
				AppliedAt: e.now().UTC().Format(time.RFC3339),
			}
		}
		reportItems = append(reportItems, reportItem)
	}

	return reportItems
}

// dispatch : Test → machine d'états §5 → Apply éventuel, ISOLÉ par type —
// erreur OU panic d'un handler devient {status: error, detail} et la passe
// continue (AC epic isolation). persist n'est vrai qu'après un Test/Apply
// réussi (un Apply en échec ne persiste jamais la cible).
func (e *Engine) dispatch(handler Handler, typ string, typeItems []StateItem, targetHash string) (item ReportItem, persist bool) {
	// recover au POINT DE DISPATCH (décision n° 4) : aucun handler ne peut
	// tuer la passe.
	defer func() {
		if r := recover(); r != nil {
			item = errorReportItem(typ, targetHash, fmt.Sprintf("panique du handler '%s' : %v", typ, r))
			persist = false
			logError(e.Log, "Convergence '%s' en échec (panique rattrapée) : %v", typ, r)
		}
	}()

	isCompliant, err := handler.Test(typeItems)
	if err != nil {
		logError(e.Log, "Convergence '%s' en échec : %v", typ, err)

		return e.withInventory(handler, errorReportItem(typ, targetHash, err.Error())), false
	}

	verdict := ResolveItemStatus(isCompliant)
	if verdict.ShouldApply {
		if err := handler.Apply(typeItems); err != nil {
			logError(e.Log, "Convergence '%s' en échec : %v", typ, err)

			return e.withInventory(handler, errorReportItem(typ, targetHash, err.Error())), false
		}
	}

	logInfo(e.Log, "Convergence '%s' : %s.", typ, verdict.Status)

	report := ReportItem{Type: typ, Status: verdict.Status, Hash: targetHash}

	return e.withInventory(handler, report), verdict.ShouldPersist
}

// withInventory joint l'inventaire PAR SOUS-ENTITÉ d'un handler (s'il implémente
// InventoryReporter, ex. `applications`) à son item de rapport — sur TOUS les
// chemins de sortie, y compris `error` (Story 27.5, AC4 : un verdict de type
// `error`/`drift` porte quand même les résultats par app — fondation des
// licences à pool). N'altère PAS le verdict par type (grain 27.8 intact) : donnée
// additive. Vide → champ omis (omitempty).
func (e *Engine) withInventory(handler Handler, item ReportItem) ReportItem {
	if reporter, ok := handler.(InventoryReporter); ok {
		if inv := reporter.ReportInventory(); len(inv) > 0 {
			item.Inventory = inv
		}
	}

	return item
}

// errorReportItem : item {status: error} — `detail` obligatoire non vide
// (contrat §6), borné à 2000 caractères.
func errorReportItem(typ, hash, detail string) ReportItem {
	if detail == "" {
		detail = fmt.Sprintf("échec du handler '%s' sans message", typ)
	}
	detail = truncateRunes(detail, detailMaxLength)

	return ReportItem{Type: typ, Status: "error", Hash: hash, Detail: detail}
}

// truncateRunes borne une chaîne en RUNES (jamais au milieu d'un caractère
// UTF-8 — le détail part dans un rapport JSON).
func truncateRunes(s string, max int) string {
	if max <= 0 {
		return ""
	}
	runes := []rune(s)
	if len(runes) <= max {
		return s
	}

	return string(runes[:max])
}

// Helpers de log nil-safe : le moteur ne possède aucun logger concret
// (portabilité) — l'appelant peut ne pas en fournir (tests).
func logDebug(l *Logger, format string, args ...any) {
	if l != nil {
		l.Debugf(format, args...)
	}
}

func logInfo(l *Logger, format string, args ...any) {
	if l != nil {
		l.Infof(format, args...)
	}
}

func logWarning(l *Logger, format string, args ...any) {
	if l != nil {
		l.Warningf(format, args...)
	}
}

func logError(l *Logger, format string, args ...any) {
	if l != nil {
		l.Errorf(format, args...)
	}
}

package shared

import (
	"encoding/json"
	"os"
	"slices"
	"time"
)

// Collecte des drops session au cycle du service (Story 24.6 — portage de
// Read-SessionReports 24.4). Le rapport v1 n'a PAS de dimension user (§6
// FIGÉ) : le compagnon ne poste jamais — il dépose son session-report.json
// per-SID, le service collecte, VALIDE STRICTEMENT, fusionne et rapporte.
//
// FRONTIÈRE DE CONFIANCE (piège n° 8) : le user peut forger SON
// session-report.json (et SON applied-state local) — chaque entrée est
// validée AVANT fusion (type publié §7, status enum, hash hex-64, detail
// borné, taille de fichier plafonnée, JSON invalide = drop ignoré + log).
// Le serveur répondrait 422 sur TOUT le rapport : une entrée forgée ne doit
// jamais couler le rapport machine entier. Impact borné par construction :
// le user ne peut fausser que les statuts session de SON poste — documenté,
// pas sur-ingénié.
//
// Fusion : un item PAR type (le rapport §6 exige des types UNIQUES) — en
// multi-session, le drop au generated_at le plus récent gagne (postes
// d'école = 1 session interactive ; limitation documentée). Ordre des types
// ASCENDANT dans le rapport (déterminisme — acquis dev 24.4 n° 5 : le
// serveur n'impose pas d'ordre, mais un ordre stable facilite le debug).

type mergedDropItem struct {
	generatedAt time.Time
	item        ReportItem
}

// CollectSessionReports lit tous les drops per-SID, valide strictement et
// fusionne en items de rapport (types asc). Erreur de collecte individuelle
// = drop ignoré + log, jamais une erreur globale.
func CollectSessionReports(store *Store, log *Logger) []ReportItem {
	entries, err := os.ReadDir(store.SessionReportsRoot())
	if err != nil {
		return []ReportItem{}
	}

	merged := map[string]mergedDropItem{}

	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}
		sid := entry.Name()
		path := store.SessionReportPath(sid)
		info, err := os.Stat(path)
		if err != nil {
			continue // pas de drop pour ce SID
		}
		// Plafond AVANT parse : une entrée non fiable ne sature jamais le
		// service.
		if info.Size() > SessionReportMaxBytes {
			logWarning(log, "Drop session %s au-delà du plafond (%d octets) : ignoré.", sid, SessionReportMaxBytes)

			continue
		}

		raw, err := os.ReadFile(path)
		if err != nil {
			logWarning(log, "Drop session %s illisible : %v — ignoré.", sid, err)

			continue
		}
		var drop struct {
			GeneratedAt string       `json:"generated_at"`
			Items       []ReportItem `json:"items"`
		}
		if err := json.Unmarshal(raw, &drop); err != nil {
			logWarning(log, "Drop session %s JSON invalide : ignoré (%v).", sid, err)

			continue
		}

		// generated_at non parsable = époque zéro (le drop perd la fusion
		// face à tout drop daté — jamais une erreur).
		generatedAt, _ := time.Parse(time.RFC3339, drop.GeneratedAt)

		// generated_at FUTUR = forgeable par le code user (frontière de
		// confiance) : époque zéro aussi — un drop forgé ne peut pas voler
		// la fusion à une session légitime du même poste.
		if generatedAt.After(time.Now()) {
			generatedAt = time.Time{}
		}

		for _, item := range drop.Items {
			// Validation STRICTE entrée par entrée (frontière de confiance).
			invalidReason := ""
			switch {
			case !slices.Contains(ResourceTypes, item.Type):
				invalidReason = "type hors liste publiée"
			case !slices.Contains(ResourceStatuses, item.Status):
				invalidReason = "status hors enum"
			case !ValidChecksum(item.Hash):
				invalidReason = "hash non hex-64"
			case item.Status == "error" && isBlank(item.Detail):
				invalidReason = "error sans detail"
			}
			if invalidReason != "" {
				logWarning(log, "Drop session %s : entrée invalide ignorée (%s, type=%q).", sid, invalidReason, item.Type)

				continue
			}
			item.Detail = truncateRunes(item.Detail, detailMaxLength)

			if existing, ok := merged[item.Type]; ok && !existing.generatedAt.Before(generatedAt) {
				continue // un drop plus récent (ou aussi récent) porte déjà ce type
			}
			merged[item.Type] = mergedDropItem{generatedAt: generatedAt, item: item}
		}
	}

	types := make([]string, 0, len(merged))
	for typ := range merged {
		types = append(types, typ)
	}
	slices.Sort(types)

	items := make([]ReportItem, 0, len(types))
	for _, typ := range types {
		items = append(items, merged[typ].item)
	}

	return items
}

// isBlank : vide ou espaces seulement (iso IsNullOrWhiteSpace — un detail
// " " ne satisfait pas « non vide »).
func isBlank(s string) bool {
	for _, r := range s {
		if r != ' ' && r != '\t' && r != '\n' && r != '\r' {
			return false
		}
	}

	return true
}

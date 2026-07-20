package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"slices"
	"strings"
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

// PurgeOrphanDrops supprime les répertoires de drop per-SID des sessions qui ne
// sont PLUS interactives (logoff / arrêt / reboot). Sans ça, un drop mort vit
// indéfiniment sous ProgramData (persiste aux reboots) et est re-collecté +
// re-rapporté à CHAQUE cycle SYSTEM — le serveur réécrit `reported_at = now()`
// → fantôme « il y a 6 min » indélébile, montrant l'état d'un utilisateur parti.
//
// `activeSIDs` = ensemble des SID des sessions interactives VIVANTES (Active OU
// Disconnected), résolu côté SYSTEM par la MÊME énumération WTS que le fetch.
// Un SID absent de cet ensemble = session terminée → son drop est purgé.
//
// FAIL-OPEN : `activeSIDs == nil` (énumération indisponible/échouée, console de
// debug, tests hôte) → AUCUNE purge. On ne supprime JAMAIS le drop d'une session
// qu'on n'a pas pu confirmer terminée — au pire un fantôme survit un cycle de
// plus, jamais la perte du rapport d'une session vivante. Un ensemble VIDE non
// nil (zéro session interactive confirmée) purge légitimement tous les drops.
func PurgeOrphanDrops(store *Store, activeSIDs map[string]bool, log *Logger) {
	if activeSIDs == nil {
		return
	}
	entries, err := os.ReadDir(store.SessionReportsRoot())
	if err != nil {
		return // pas de répertoire de drops → rien à purger
	}
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}
		sid := entry.Name()
		if activeSIDs[sid] {
			continue // session vivante (Active/Disconnected) : drop légitime, conservé
		}
		if err := os.RemoveAll(store.SessionReportDir(sid)); err != nil {
			logWarning(log, "Purge du drop de session orpheline %s en échec : %v.", sid, err)

			continue
		}
		logInfo(log, "Drop de session orpheline purgé (session terminée) : %s.", sid)
	}
}

// statusSeverity : ordre de gravité pour la fusion par type (error > drift >
// compliant). Un statut inconnu est traité comme le moins grave (jamais une
// panique).
func statusSeverity(status string) int {
	switch status {
	case "error":
		return 2
	case "drift":
		return 1
	default: // compliant / inconnu
		return 0
	}
}

// MergeReportItemsByType fusionne une liste d'items de rapport pour garantir des
// types UNIQUES (contrat §6) — Story 27.3. Un même type peut arriver de DEUX
// portées convergées séparément : `registry` HKLM (service SYSTEM, portée
// machine) ET `registry` HKCU (compagnon, portée session). Sans fusion, le
// rapport porterait deux items `registry` → l'ingestion serveur
// (updateOrCreate sur (workstation_id, type)) en écraserait un silencieusement.
//
// Règle : par type, on garde le statut le PLUS GRAVE (error > drift > compliant)
// — la conformité globale d'un type est sa pire portée. Le hash retenu est celui
// de l'item gagnant (premier au statut le plus grave dans l'ordre d'entrée) ;
// son `detail` est préservé. Ordre de sortie : types ASCENDANT (déterminisme).
// La liste d'entrée est laissée intacte (copie).
func MergeReportItemsByType(items []ReportItem) []ReportItem {
	if len(items) == 0 {
		return []ReportItem{}
	}

	byType := map[string]ReportItem{}
	order := []string{}
	for _, item := range items {
		existing, seen := byType[item.Type]
		if !seen {
			order = append(order, item.Type)
			byType[item.Type] = item

			continue
		}
		// Conserve le plus grave ; à gravité égale, le PREMIER vu (stable).
		if statusSeverity(item.Status) > statusSeverity(existing.Status) {
			byType[item.Type] = item
		}
	}

	slices.Sort(order)
	merged := make([]ReportItem, 0, len(order))
	for _, typ := range order {
		merged = append(merged, byType[typ])
	}

	return merged
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

// CompanionReportType : canal de signalement de la santé du compagnon de
// session. N'est PAS un type desired-state (aucun provider serveur, absent de
// ResourceTypes) : le serveur l'accepte au RAPPORT seulement
// (StateContract::REPORT_ONLY_TYPES).
//
// Volontairement HORS ResourceTypes côté Go : le répertoire de drop est
// inscriptible par le user (<SID>:M), donc un type accepté à la collecte est un
// type FORGEABLE. `companion` n'est émis que par le service SYSTEM, après la
// collecte — un drop qui le revendiquerait est rejeté par CollectSessionReports
// comme n'importe quel type inconnu.
const CompanionReportType = "companion"

// DetectCompanionHealth : une session interactive est ouverte, le compagnon
// est-il vivant ?
//
// POURQUOI : le compagnon ne peut pas signaler sa propre mort. Une tâche
// planifiée qui échoue au lancement (DACL du binaire, droit de logon, crash
// immédiat) est TOTALEMENT muette — le service SYSTEM continue de rapporter la
// convergence machine, donc le poste paraît sain côté serveur alors que
// l'overlay, le wallpaper et toute la portée session sont morts. Constaté à la
// main le 2026-07-20 (agent.exe sans Users:RX → 0x80070005 à chaque logon,
// diagnostic uniquement par Get-ScheduledTaskInfo sur le poste).
//
// SIGNAL : le drop per-SID que le compagnon dépose à chaque passe
// (SessionReportPath). Pour chaque SID vivant, il doit exister et être FRAIS.
// On se fie au mtime plutôt qu'au `generated_at` du corps : les deux sont
// falsifiables par le user (il a M sur le répertoire), mais le mtime ne demande
// aucun parse et l'os.Stat est de toute façon déjà fait à la collecte. La
// falsification n'est pas un enjeu ici — le user ne peut que se déclarer sain
// sur SON poste, jamais dégrader celui d'un autre (frontière de confiance
// n° 8, déjà assumée pour les statuts session).
//
// activeSIDs nil (énumération indisponible, quarantaine) → aucun verdict :
// FAIL-OPEN, iso PurgeOrphanDrops. Zéro session → `compliant` : il n'y a aucun
// compagnon à attendre, et c'est ce qui EFFACE une erreur précédente (le type
// n'ayant pas de provider, le serveur ne le prune jamais — il faut rapporter
// explicitement le retour à la normale, pas omettre l'item).
//
// Retourne 0 ou 1 item (le rapport §6 exige des types uniques).
func DetectCompanionHealth(store *Store, activeSIDs map[string]bool, logins map[string]string, now time.Time, grace time.Duration, log *Logger) []ReportItem {
	if activeSIDs == nil {
		return nil
	}

	silent := make([]string, 0, len(activeSIDs))
	for sid := range activeSIDs {
		info, err := os.Stat(store.SessionReportPath(sid))
		if err != nil || now.Sub(info.ModTime()) > grace {
			silent = append(silent, sid)
		}
	}
	// Ordre stable : le hash ne doit dépendre que de l'ENSEMBLE des sessions
	// muettes, jamais de l'ordre d'itération de la map (aléatoire en Go).
	slices.Sort(silent)

	if len(silent) == 0 {
		return []ReportItem{{
			Type:   CompanionReportType,
			Status: "compliant",
			Hash:   companionHash(nil),
		}}
	}

	labels := make([]string, 0, len(silent))
	for _, sid := range silent {
		if login := logins[sid]; login != "" {
			labels = append(labels, login+" ("+sid+")")

			continue
		}
		labels = append(labels, sid)
	}

	detail := "compagnon de session sans signe de vie depuis plus de " +
		grace.Round(time.Second).String() + " pour : " + strings.Join(labels, ", ") +
		" — tâche SambaEduAgent-SessionCompanion en échec ? (overlay, wallpaper et portée session inertes)"
	logWarning(log, "%s", detail)

	return []ReportItem{{
		Type:   CompanionReportType,
		Status: "error",
		Hash:   companionHash(silent),
		Detail: truncateDetail(detail, 480),
	}}
}

// companionHash : SHA-256 de l'ENSEMBLE des SID muets (triés, séparés par \n),
// vide quand tout va bien. Le serveur exige un hex-64 sur tout item de rapport,
// et cette forme lui donne du SENS : le hash ne bouge que si la population des
// sessions muettes change — donc un événement de drift est émis à la bascule,
// et pas à chaque cycle d'une panne qui dure.
func companionHash(silent []string) string {
	sum := sha256.Sum256([]byte(strings.Join(silent, "\n")))

	return hex.EncodeToString(sum[:])
}

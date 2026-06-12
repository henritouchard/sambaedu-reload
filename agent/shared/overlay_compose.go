package shared

import (
	"strings"
	"unicode"
)

// Composition du document overlay.json (Story 24.6 — portage byte-compatible
// du sérialiseur PS 24.4, handlers/Overlay.ps1).
//
// L'agent EST le fetch du POC overlay : il compose et écrit
// `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` — per-user par construction.
// Le render (Rainmeter/Conky, resources/overlay/ INTOUCHÉ) lit ce fichier.
//
// ⚠️ Sérialiseur à STRUCTURE FIXE, jamais encoding/json (piège n° 10) : la
// regex WebParser de la skin Rainmeter exige `": "` (un espace simple après
// les deux-points), un ordre de clés littéral stable et l'Unicode BRUT
// UTF-8 — encoding/json émet compact `":"` sans espace (et ConvertTo-Json
// PS 5.1 mettait DEUX espaces + \uXXXX : les deux cassent le render). Le
// format ci-dessous reproduit BYTE-À-BYTE celui du PS 24.4 (golden de
// non-régression : overlay_compose_test.go) — tout octet compte, le `test`
// du handler est une comparaison de contenu.
//
// AUCUN champ volatil (pas de generated_at) : un champ horodaté ferait
// dériver chaque passe (drift perpétuel).

// OverlaySchema : schéma de la facade locale — celui que le render POC
// valide/connaît (resources/overlay/README.md).
const OverlaySchema = "se5.wallpaper-overlay/v1"

// Bornes iso OverlayService::sanitizeText (title 255, text 2000).
const (
	overlaySeverityMaxLength = 16
	overlayTitleMaxLength    = 255
	overlayTextMaxLength     = 2000
	overlayIdentityMaxLength = 255
)

// sanitizeOverlayText : aplatissement iso `OverlayService::sanitizeText`
// (et Format-OverlayText PS 24.4) — retours ligne / espaces multiples → UN
// espace, trim, clamp en runes. Protège le parsing regex mono-ligne du
// render. NB : le `\s+` du PS était la classe .NET (espaces Unicode inclus)
// — unicode.IsSpace reproduit cette couverture.
func sanitizeOverlayText(value string, maxLength int) string {
	flat := strings.Join(strings.FieldsFunc(value, unicode.IsSpace), " ")

	return truncateRunes(flat, maxLength)
}

// escapeOverlayJSONString : échappement JSON minimal iso PS 24.4 —
// backslash, guillemet, contrôles résiduels → espace. L'Unicode reste BRUT
// (UTF-8) : lisible par le render, jamais de \uXXXX.
func escapeOverlayJSONString(value string) string {
	var b strings.Builder
	for _, r := range value {
		switch {
		case r == '\\':
			b.WriteString(`\\`)
		case r == '"':
			b.WriteString(`\"`)
		case r < 0x20:
			// Contrôles résiduels (le sanitize a déjà aplati \r\n\t).
			b.WriteByte(' ')
		default:
			b.WriteRune(r)
		}
	}

	return b.String()
}

// overlayAlert : une alerte composée (signal posté, déjà sanitizée).
type overlayAlert struct {
	Severity string
	Title    string
	Text     string
}

// ComposeOverlayDocument compose le document overlay.json cible depuis TOUS
// les items overlay de la passe (aggregate = union, ordre serveur) :
//
//   - item `kind: "identity"` (enrichissement serveur OverlayStateProvider)
//     → identity.fullname/login + machine.room — le compagnon ne connaît
//     localement ni le fullname ni la salle, et le critère Keycloak (NFR7)
//     interdit tout appel AD côté poste. Un seul bloc identité (le serveur
//     n'en émet qu'un — défense : le PREMIER gagne, ordre serveur) ;
//   - machine.name = COMPUTERNAME LOCAL (jamais demandé au serveur) ;
//   - les autres items (signaux postés) → alerts[], ordre serveur.
//
// Champs absents (machine-only sans identity) = chaînes vides, jamais omis :
// la regex du render exige la présence des clés.
func ComposeOverlayDocument(items []StateItem, computerName string) string {
	var identity map[string]any
	alerts := []overlayAlert{}

	for _, item := range items {
		payload, ok := item.Payload.(map[string]any)
		if !ok || payload == nil {
			continue
		}
		if kind, _ := payload["kind"].(string); kind == "identity" {
			if identity == nil {
				identity = payload
			}

			continue
		}

		severity, _ := payload["severity"].(string)
		if severity == "" {
			severity = "info"
		}
		title, _ := payload["title"].(string)
		text, _ := payload["text"].(string)
		alerts = append(alerts, overlayAlert{
			Severity: sanitizeOverlayText(severity, overlaySeverityMaxLength),
			Title:    sanitizeOverlayText(title, overlayTitleMaxLength),
			Text:     sanitizeOverlayText(text, overlayTextMaxLength),
		})
	}

	fullname, login, room := "", "", ""
	if identity != nil {
		if v, ok := identity["fullname"].(string); ok {
			fullname = sanitizeOverlayText(v, overlayIdentityMaxLength)
		}
		if v, ok := identity["login"].(string); ok {
			login = sanitizeOverlayText(v, overlayIdentityMaxLength)
		}
		if v, ok := identity["room"].(string); ok {
			room = sanitizeOverlayText(v, overlayIdentityMaxLength)
		}
	}

	// Structure LITTÉRALE fixe — byte-compatible PS 24.4 (lignes jointes par
	// \n, indentation 4 espaces, `": "` simple, pas de \n final).
	lines := []string{
		"{",
		`    "schema": "` + OverlaySchema + `",`,
		`    "identity": {`,
		`        "fullname": "` + escapeOverlayJSONString(fullname) + `",`,
		`        "login": "` + escapeOverlayJSONString(login) + `"`,
		"    },",
		`    "machine": {`,
		`        "name": "` + escapeOverlayJSONString(computerName) + `",`,
		`        "room": "` + escapeOverlayJSONString(room) + `"`,
		"    },",
	}
	if len(alerts) == 0 {
		lines = append(lines, `    "alerts": []`)
	} else {
		lines = append(lines, `    "alerts": [`)
		for i, alert := range alerts {
			suffix := ","
			if i == len(alerts)-1 {
				suffix = ""
			}
			lines = append(lines,
				"        {",
				`            "severity": "`+escapeOverlayJSONString(alert.Severity)+`",`,
				`            "title": "`+escapeOverlayJSONString(alert.Title)+`",`,
				`            "text": "`+escapeOverlayJSONString(alert.Text)+`"`,
				"        }"+suffix,
			)
		}
		lines = append(lines, "    ]")
	}
	lines = append(lines, "}")

	return strings.Join(lines, "\n")
}

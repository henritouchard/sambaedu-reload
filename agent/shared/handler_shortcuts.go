package shared

import (
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
)

// Handler `shortcuts` (aggregate / scope machine_user) — Story 27.1, fix
// définitif du Bug C. Logique PURE, OS-agnostique (les opérations `.lnk`
// réelles sont injectées via ShortcutOps) → testée sur l'hôte ; agent/windows
// ne fait que câbler la création COM IShellLink.
//
// CONVERGENCE level-triggered (décision n° 5), JAMAIS accumulation : le legacy
// téléchargeait un `.lnk` à chaque logon et tenait un `shortcuts.txt` pour
// nettoyer. Ici :
//   - test  : l'ensemble des `.lnk` GÉRÉS sur le poste correspond-il EXACTEMENT
//     à l'union cible (présence + cible/args/icône) ?
//   - apply : créer/réécrire les manquants ou divergents ET supprimer les
//     raccourcis gérés sortis des règles. IDEMPOTENT (deux passes sur état
//     stable = aucune écriture).
//
// MARQUEUR de périmètre (décision n° 5) : seuls les `.lnk` posés par l'agent
// sont gérés (l'impl Windows marque le champ Description du raccourci avec un
// sentinel — ShortcutManagedMarker). Un raccourci créé par l'utilisateur n'est
// JAMAIS listé donc JAMAIS supprimé. S'il occupe le chemin EXACT d'une cible
// (homonyme : un prof crée « Intranet » sur son bureau), `test`/`apply`
// IGNORENT ce chemin via Blocked() — ni écrasé, ni supprimé, ni erreur : les
// AUTRES raccourcis convergent quand même (sinon un seul homonyme annulait
// toute la convergence du type — bug review #1).
//
// Le `desktop_path` est résolu CÔTÉ SERVEUR (le provider l'a calculé depuis
// WorkstationEnvironment — fix Bug C) ; l'agent substitue seulement les tokens
// locaux (`<user>`, `<se4fs>`) et résout les emplacements startup/taskbar
// standard. L'agent reste bête : aucune branche métier shared/personal ici.

// ShortcutManagedMarker : sentinel écrit dans le champ Description d'un `.lnk`
// posé par l'agent (décision n° 5). Distingue un raccourci GÉRÉ d'un raccourci
// créé par l'utilisateur — seuls les gérés sont supprimables.
const ShortcutManagedMarker = "SambaEdu desired-state managed shortcut"

// Emplacements `place` du contrat (iso Shortcut::PLACE_* côté serveur).
const (
	shortcutPlaceDesktop = "desktop"
	shortcutPlaceStartup = "startup"
	shortcutPlaceTaskbar = "taskbar"
)

// shortcutSweepPathsKey : clé du payload `shortcuts` portant les emplacements
// Bureau à BALAYER (Story 27.21, arbitrage option A — champ additif §9,
// forward-compatible).
//
// POSE ≠ BALAYAGE — les deux notions sont DISTINCTES et ne se confondent pas :
//   - `desktop_path` (string) = l'emplacement UNIQUE où l'agent POSE. Seule
//     autorité de placement, inchangée depuis le fix du Bug C.
//   - `desktop_sweep_paths` (liste de strings) = les emplacements que l'agent
//     BALAIE pour y supprimer les `.lnk` GÉRÉS sortis des règles. Il contient
//     toujours l'emplacement de pose, plus le ou les emplacements DEVENUS
//     inactifs dont le serveur veut le nettoyage.
//
// Pourquoi le serveur et pas l'agent : le Bureau RÉSEAU
// `\\<se4fs>\users\<user>\Bureau\` est un emplacement PAR UTILISATEUR, PARTAGÉ
// entre TOUS ses postes, alors que le desired-state est compilé par couple
// (poste, user). Un agent qui déciderait seul de le balayer y supprimerait les
// `.lnk` d'un AUTRE poste du même utilisateur (finding 🔴 #1 de la review
// 27.21). Seul le serveur connaît l'environnement du parc, donc l'autorité :
// parc `shared_local` ⇒ [réseau, local] ; `personal_local`/`nomade` ⇒ [local].
const shortcutSweepPathsKey = "desktop_sweep_paths"

// ShortcutSpec : un raccourci cible résolu (un item du payload `shortcuts`).
// Tous les champs sont des strings (contrat §4.1, jamais de float).
type ShortcutSpec struct {
	Name        string // nom d'affichage (→ <name>.lnk)
	Target      string // cible : chemin exe ou URL
	Args        string // arguments de lancement
	Icon        string // chemin d'icône réel `chemin,index` (peut être vide)
	Place       string // desktop | startup | taskbar
	DesktopPath string // chemin du bureau résolu serveur (place=desktop only)

	// IconAsset : filename content-addressed `<sha256>.ico` d'une icône
	// UPLOADÉE (Story 27.7). Présent UNIQUEMENT pour une icône uploadée (nom
	// nu côté serveur) ; vide pour un chemin d'icône réel (`firefox.exe,0` →
	// Icon). Quand présent ET que le `.ico` local est disponible (pré-
	// téléchargé content-addressed par SyncShortcutIcons), l'impl OS pointe
	// l'IconLocation sur le fichier LOCAL `IconPath(<sha>.ico)` ; absent /
	// non téléchargé → pas d'IconLocation (icône défaut), JAMAIS un chemin
	// irrésoluble (régression « feuille blanche »). Le drift résultant est
	// rattrapé au cycle suivant (sous-décision F, piège n° 7).
	IconAsset    string
	IconChecksum string // SHA-256 attendu (validé à l'écriture locale)
}

// ParseIconLocation décompose une icône à la convention Windows historique
// `chemin,index` (ex. `C:\…\firefox.exe,0`) en (chemin, index) — c'est la forme
// native d'un `.lnk` (IShellLink::SetIconLocation(LPCWSTR path, int index)).
//
// Le serveur (`windows_icon`) stocke l'icône avec son index suffixé. Sans ce
// split, le handler passait `…\firefox.exe,0` comme CHEMIN de fichier → Windows
// cherchait un fichier nommé « firefox.exe,0 », introuvable → icône « feuille
// blanche ». Bug terrain 27.1.
//
// Règles : on coupe sur la DERNIÈRE virgule UNIQUEMENT si le suffixe est un
// entier (positif ou négatif — un index négatif = ressource par ID). Sinon, la
// virgule fait partie du chemin (rare mais légal) → index 0. Une icône vide
// reste ("", 0). Les espaces autour de l'index sont tolérés.
func ParseIconLocation(icon string) (path string, index int) {
	if icon == "" {
		return "", 0
	}

	pos := strings.LastIndex(icon, ",")
	if pos < 0 {
		return icon, 0
	}

	suffix := strings.TrimSpace(icon[pos+1:])
	n, err := strconv.Atoi(suffix)
	if err != nil {
		return icon, 0 // pas un index → la virgule appartient au chemin
	}

	return icon[:pos], n
}

// ResolveUploadedIconLocation décide l'IconLocation BRUTE à poser pour une
// icône UPLOADÉE content-addressed (Story 27.7), AVANT substitution de tokens.
// Logique PURE (stat + jointure de chemin), testée sur l'hôte ; l'impl Windows
// l'appelle puis la passe à SetIconLocation / ParseIconLocation.
//
//   - asset présent dans `iconsDir` (pré-téléchargé par SyncShortcutIcons) →
//     chemin LOCAL absolu (index 0 via la convention sans `,index`). Plus de
//     « feuille blanche ».
//   - asset NON encore disponible (pas téléchargé / checksum KO côté sync) →
//     "" : pas d'IconLocation (icône défaut Windows), JAMAIS un chemin
//     irrésoluble. Le drift est rattrapé au cycle suivant (sous-décision F,
//     piège n° 7).
//
// `iconAsset` est supposé DÉJÀ validé (ValidShortcutIconFilename) par
// parseShortcutSpec — un asset hors format n'arrive jamais ici (il a été remis
// à "" en amont, on retombe alors sur l'icône réelle, hors de cette fonction).
func ResolveUploadedIconLocation(iconAsset, iconsDir string) string {
	if iconAsset == "" || iconsDir == "" {
		return ""
	}
	local := filepath.Join(iconsDir, iconAsset)
	if _, err := os.Stat(local); err != nil {
		return "" // pas encore là : pas d'icône cassée
	}

	return local
}

// UsableShortcutDir : le répertoire résolu est-il exploitable pour un balayage
// ou une pose ? (Story 27.21, fail-soft.)
//
// Un chemin vide, ou un UNC dont le SERVEUR est vide (`\\\users\bob\Bureau\` —
// symptôme d'un `<se4fs>` non substituable : poste hors-domaine, ni SE4FS ni
// LOGONSERVER), n'est PAS exploitable. On préfère IGNORER la probe plutôt que de
// balayer un chemin bancal. Fonction PURE (aucun accès disque) : l'impl OS
// l'appelle après substitution des tokens.
func UsableShortcutDir(dir string) bool {
	dir = strings.TrimSpace(dir)
	if dir == "" {
		return false
	}
	if strings.HasPrefix(dir, `\\`) {
		// On retire EXACTEMENT le préfixe UNC (2 backslashes) — surtout pas un
		// TrimLeft, qui avalerait aussi le serveur vide de `\\\users\…` et ferait
		// passer `users` pour un nom de serveur.
		server, _, _ := strings.Cut(dir[2:], `\`)

		return strings.TrimSpace(server) != ""
	}

	return true
}

// ShortcutOps : opérations `.lnk` spécifiques à l'OS, injectées (testable
// hôte). L'impl Windows vit dans agent/windows/handler_shortcuts_windows.go
// (COM IShellLink) ; un stub no-op couvre les autres OS.
type ShortcutOps interface {
	// PlaceDir résout le répertoire ABSOLU d'un emplacement, tokens
	// (`<user>`/`<se4fs>`) substitués localement. Erreur = emplacement non
	// résoluble (l'item devient error, les autres types continuent).
	PlaceDir(spec ShortcutSpec) (string, error)

	// ListManaged liste les chemins ABSOLUS des `.lnk` GÉRÉS (marqueur présent)
	// trouvés dans les répertoires donnés. Un répertoire absent = aucun fichier
	// (jamais une erreur). N'inclut JAMAIS un raccourci utilisateur.
	ListManaged(dirs []string) ([]string, error)

	// Matches : le `.lnk` présent à `path` correspond-il EXACTEMENT à la cible
	// (target/args/icon) ET porte-t-il le marqueur de gestion ?
	//   - absent             → (false, nil)  : apply doit le créer.
	//   - géré mais divergent → (false, nil)  : apply doit le réécrire.
	//   - homonyme NON géré   → (false, nil)  : un `.lnk` utilisateur occupe le
	//     chemin. JAMAIS une erreur (sinon le moteur passe TOUT le type en
	//     `error`, décision n° 5) ; apply consulte Blocked() pour ne PAS écraser.
	//   - conforme            → (true, nil).
	Matches(path string, spec ShortcutSpec) (bool, error)

	// Blocked : un `.lnk` NON géré (sans marqueur) occupe-t-il déjà `path` ? Un
	// raccourci créé par l'utilisateur (homonyme d'une cible) → true : le chemin
	// est HORS périmètre SambaEdu, on ne l'écrase ni ne le supprime JAMAIS
	// (décision n° 5). Absent / géré = false. Illisible = false (prudence : on
	// ne touche pas ce qu'on ne comprend pas).
	Blocked(path string) (bool, error)

	// Create écrit (ou réécrit) le `.lnk` à `path` avec le marqueur de gestion.
	// Idempotent : réécrire le même contenu = même fichier.
	Create(path string, spec ShortcutSpec) error

	// Remove supprime un `.lnk` géré. Absent = pas d'erreur (idempotent).
	Remove(path string) error
}

// ShortcutsHandler : handler aggregate branché dans le moteur (engine.go) —
// la machine d'états §5 et le hash d'agrégat restent au moteur, JAMAIS ici.
type ShortcutsHandler struct {
	Ops ShortcutOps
	Log *Logger
}

// joinLnk : chemin complet du `.lnk` (dir + name.lnk). Le dir est déjà absolu
// et terminé ou non par un séparateur — on normalise un seul backslash.
func joinLnk(dir, name string) string {
	dir = strings.TrimRight(dir, `\/`)

	return dir + `\` + name + ".lnk"
}

// desiredSet : map chemin complet → spec, calculée depuis les items cible.
// Deux items qui résoudraient le même chemin (même name+place) mais des cibles
// (target/args) DIFFÉRENTES = mauvaise config admin (garbage-in) : le
// compilateur dédoublonne par CONTENU, donc des contenus distincts ne fusionnent
// pas et c'est le DERNIER de l'itération qui gagne (non déterministe par nature
// du cas). On ne pose pas de garde lourde (review #M2) : deux raccourcis de même
// nom au même emplacement avec deux cibles est une erreur de configuration, pas
// un état légitime à arbitrer. Cas nominal (contenus identiques) : déjà
// dédoublonné côté serveur, aucune collision ici.
func (h *ShortcutsHandler) desiredSet(items []StateItem) (map[string]ShortcutSpec, error) {
	desired := map[string]ShortcutSpec{}
	for _, item := range items {
		spec, ok := parseShortcutSpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload shortcuts inattendu : enveloppe invalide")
		}
		dir, err := h.Ops.PlaceDir(spec)
		if err != nil {
			return nil, fmt.Errorf("emplacement %q non résoluble : %w", spec.Place, err)
		}
		desired[joinLnk(dir, spec.Name)] = spec
	}

	return desired, nil
}

// managedDirs : répertoires DISTINCTS à balayer pour lister les `.lnk` gérés.
//
// On balaye l'UNION des emplacements gérables CONNUS (desktop/startup/taskbar),
// PAS seulement ceux présents dans le `desired` courant (review #2). Sans cela,
// si TOUTES les règles `place=desktop` disparaissent mais qu'une règle `startup`
// subsiste, le Bureau ne serait plus balayé → un `.lnk` Bureau géré resterait
// orphelin pour toujours (viole AC3 level-triggered). En balayant tous les
// emplacements, un emplacement vidé de ses règles voit ses `.lnk` GÉRÉS
// (marqueur) supprimés au passage suivant — jamais les fichiers utilisateur
// (ListManaged ne liste que les `.lnk` marqués).
//
// **Story 27.21 (arbitrage option A) — les emplacements Bureau à balayer sont
// NOMMÉS PAR LE SERVEUR** (`desktop_sweep_paths`, cf. shortcutSweepPathsKey).
// L'agent n'en invente aucun : il obéit. Parc `shared_local` ⇒ le serveur
// ordonne [Bureau réseau, Bureau local] (double-balayage anti-orphelins : une
// bascule de la politique home ne laisse jamais de `.lnk` géré à l'ancien
// emplacement) ; parc `personal_local`/`nomade` ⇒ [Bureau local] SEULEMENT —
// ces postes n'ont aucune autorité sur le Bureau réseau, partagé entre tous les
// postes de l'utilisateur (finding 🔴 #1).
//
// Deux emplacements sont ajoutés d'office, et c'est SÛR car ils sont PROPRES AU
// POSTE (jamais partagés entre postes, donc jamais de suppression d'un fichier
// dont un autre poste est l'autorité) :
//   - le Bureau LOCAL standard (`%USERPROFILE%\Desktop`, probe sans
//     desktop_path) — garde le nettoyage cross-placement de la review #2 de 27.1
//     même si le serveur ne nomme rien (payload d'un serveur antérieur) ;
//   - les `desktop_path` du desired courant — on POSE là, donc on doit y
//     nettoyer ce qui est sorti des règles.
//
// L'UNC réseau reste JOIGNABLE même quand K: n'est pas monté (le montage client
// ≠ l'accès UNC — même principe que la décorrélation 36.7). Si l'emplacement
// n'est PAS résoluble sur ce poste (hors-domaine, SE4FS absent), PlaceDir
// retourne une erreur et la probe est simplement IGNORÉE : fail-soft, jamais
// fatal — les autres emplacements convergent quand même.
//
// Jamais de suppression d'un fichier user : seuls les `.lnk` marqués sont listés
// (ListManaged / ShortcutManagedMarker).
func (h *ShortcutsHandler) managedDirs(desired map[string]ShortcutSpec, sweepPaths []string) ([]string, error) {
	// Probes : un spec par emplacement gérable CONNU (union, pas seulement le
	// desired courant). startup/taskbar se résolvent toujours (env).
	probes := []ShortcutSpec{
		// Bureau LOCAL standard (desktop_path vide → l'impl OS résout
		// `%USERPROFILE%\Desktop`). Propre au poste ⇒ toujours balayé.
		{Place: shortcutPlaceDesktop},
		{Place: shortcutPlaceStartup},
		{Place: shortcutPlaceTaskbar},
	}

	// Emplacements Bureau ORDONNÉS par le serveur, dans l'ordre reçu (déjà
	// déterministe côté provider), puis ceux du desired courant.
	seenDesktopPath := map[string]bool{}
	desktopPaths := []string{}
	for _, path := range sweepPaths {
		if path != "" && !seenDesktopPath[path] {
			seenDesktopPath[path] = true
			desktopPaths = append(desktopPaths, path)
		}
	}
	extra := []string{}
	for _, spec := range desired {
		if spec.Place == shortcutPlaceDesktop && spec.DesktopPath != "" && !seenDesktopPath[spec.DesktopPath] {
			seenDesktopPath[spec.DesktopPath] = true
			extra = append(extra, spec.DesktopPath)
		}
	}
	// Tri : l'itération d'une map Go est aléatoire — sans tri, la LISTE des
	// probes varierait d'une passe à l'autre (les dirs finaux sont triés, mais
	// autant garder la construction déterministe).
	sort.Strings(extra)
	desktopPaths = append(desktopPaths, extra...)
	for _, path := range desktopPaths {
		probes = append(probes, ShortcutSpec{Place: shortcutPlaceDesktop, DesktopPath: path})
	}

	seen := map[string]bool{}
	dirs := []string{}
	for _, spec := range probes {
		dir, err := h.Ops.PlaceDir(spec)
		if err != nil {
			// Emplacement non résoluble pour CE passage : on l'ignore — pas une
			// erreur fatale (les autres emplacements convergent quand même).
			//
			// TRACÉ (review 27.21 #4) : un balayage sauté en SILENCE rend le
			// level-triggered malhonnête — `Test` rapporterait `compliant` alors
			// que des `.lnk` gérés fantômes subsistent à l'emplacement non
			// balayé. On veut qu'un opérateur puisse le constater dans le log.
			logInfo(h.Log, "Emplacement de balayage ignoré (%s, desktop_path=%q non résoluble) : %v — les raccourcis gérés qui s'y trouveraient ne seront PAS nettoyés à cette passe", spec.Place, spec.DesktopPath, err)

			continue
		}
		if strings.TrimRight(dir, `\/`) == "" {
			// Idem : chemin vide (bureau non résoluble) = emplacement non balayé.
			logInfo(h.Log, "Emplacement de balayage ignoré (%s, desktop_path=%q) : chemin résolu vide — les raccourcis gérés qui s'y trouveraient ne seront PAS nettoyés à cette passe", spec.Place, spec.DesktopPath)

			continue
		}
		key := strings.TrimRight(dir, `\/`)
		if !seen[key] {
			seen[key] = true
			dirs = append(dirs, dir)
		}
	}
	sort.Strings(dirs) // déterminisme (les tests comparent l'ensemble)

	return dirs, nil
}

// sweepPathsFrom : emplacements Bureau à BALAYER, tels que NOMMÉS par le serveur
// (`desktop_sweep_paths`, Story 27.21 option A). Union dédoublonnée sur les
// items, dans l'ordre d'émission du serveur (déterministe : `items` est une
// slice, jamais une map).
//
// Ce champ est une donnée de CONTEXTE (poste), pas une propriété du raccourci :
// il est recopié à l'identique sur CHAQUE item du type, y compris les
// `place=startup`/`taskbar`. C'est délibéré — l'agent doit connaître les Bureaux
// à balayer MÊME quand plus aucune règle `place=desktop` n'existe (leçon de la
// review #2 de 27.1 : sinon un Bureau vidé de ses règles n'est plus jamais
// nettoyé et garde ses `.lnk` gérés orphelins à vie).
//
// LIMITE (préexistante, niveau moteur — pas propre à 27.21) : ceci ne tient que
// tant qu'il reste AU MOINS UN item `shortcuts` (n'importe quel `place`). Si la
// DERNIÈRE règle raccourci d'un couple (poste, user) disparaît, le type est
// absent de l'état, le moteur ne convoque JAMAIS ce handler, et un `.lnk` géré
// résiduel reste orphelin. Vaut aussi pour le Bureau local et pour les autres
// types agrégés (drives, etc.). Correction = story dédiée (sentinelle de type
// vidé, ou invocation des handlers sur types absents), hors périmètre 27.21.
//
// Absent (payload d'un serveur antérieur à 27.21, ou aucun item) ⇒ liste vide :
// repli CONSERVATEUR sur les seuls emplacements propres au poste (cf.
// managedDirs). On ne touche JAMAIS un emplacement partagé que le serveur n'a
// pas explicitement nommé.
func sweepPathsFrom(items []StateItem) []string {
	seen := map[string]bool{}
	paths := []string{}
	for _, item := range items {
		payload, ok := item.Payload.(map[string]any)
		if !ok || payload == nil {
			continue
		}
		for _, raw := range sweepPathValues(payload[shortcutSweepPathsKey]) {
			path := strings.TrimSpace(raw)
			if path == "" || seen[path] {
				continue
			}
			seen[path] = true
			paths = append(paths, path)
		}
	}

	return paths
}

// sweepPathValues normalise la valeur brute du champ : `[]any` (forme réelle
// après décodage JSON du contrat) ou `[]string` (construction directe en test).
// Toute autre forme (absente, scalaire, éléments non-string) est IGNORÉE — un
// payload malformé ne doit jamais faire échouer la passe ni élargir le périmètre
// de balayage.
func sweepPathValues(raw any) []string {
	switch v := raw.(type) {
	case []string:
		return v
	case []any:
		out := make([]string, 0, len(v))
		for _, elem := range v {
			if s, ok := elem.(string); ok {
				out = append(out, s)
			}
		}

		return out
	default:
		return nil
	}
}

// Test : l'ensemble des `.lnk` gérés == l'union cible (présence + contenu) ?
func (h *ShortcutsHandler) Test(items []StateItem) (bool, error) {
	desired, err := h.desiredSet(items)
	if err != nil {
		return false, err
	}

	dirs, err := h.managedDirs(desired, sweepPathsFrom(items))
	if err != nil {
		return false, err
	}
	managed, err := h.Ops.ListManaged(dirs)
	if err != nil {
		return false, err
	}

	// Un raccourci géré hors cible (sorti des règles) = non conforme.
	for _, path := range managed {
		if _, want := desired[path]; !want {
			return false, nil
		}
	}

	// Chaque cible doit être présente ET correspondre exactement — SAUF si un
	// raccourci utilisateur (homonyme non géré) occupe le chemin : ce chemin est
	// hors périmètre SambaEdu, on ne le compte ni en faveur ni en défaveur de la
	// convergence (décision n° 5 — « jamais toucher un raccourci hors
	// périmètre »). Les AUTRES cibles convergent quand même.
	for path, spec := range desired {
		blocked, err := h.Ops.Blocked(path)
		if err != nil {
			return false, err
		}
		if blocked {
			continue // homonyme utilisateur : ignoré (ni conforme ni dérive)
		}
		ok, err := h.Ops.Matches(path, spec)
		if err != nil {
			return false, err
		}
		if !ok {
			return false, nil
		}
	}

	return true, nil
}

// Apply : converge — crée/réécrit les manquants ou divergents, supprime les
// gérés sortis des règles. Idempotent + level-triggered.
func (h *ShortcutsHandler) Apply(items []StateItem) error {
	desired, err := h.desiredSet(items)
	if err != nil {
		return err
	}

	dirs, err := h.managedDirs(desired, sweepPathsFrom(items))
	if err != nil {
		return err
	}
	managed, err := h.Ops.ListManaged(dirs)
	if err != nil {
		return err
	}

	// 1) Supprimer les GÉRÉS sortis des règles (jamais un raccourci user).
	for _, path := range managed {
		if _, want := desired[path]; want {
			continue
		}
		if err := h.Ops.Remove(path); err != nil {
			return fmt.Errorf("suppression du raccourci géré %q : %w", path, err)
		}
		logInfo(h.Log, "Raccourci géré retiré (sorti des règles) : %s", path)
	}

	// 2) Créer / réécrire les cibles manquantes ou divergentes (déterminisme :
	// ordre des chemins trié, sans incidence fonctionnelle mais stable en log).
	paths := make([]string, 0, len(desired))
	for path := range desired {
		paths = append(paths, path)
	}
	sort.Strings(paths)
	for _, path := range paths {
		spec := desired[path]
		// Un raccourci utilisateur (homonyme non géré) occupe le chemin : on ne
		// l'écrase JAMAIS (décision n° 5). On saute ce chemin (les autres cibles
		// convergent quand même) — pas d'erreur, pas d'écriture, pas de delete.
		blocked, err := h.Ops.Blocked(path)
		if err != nil {
			return err
		}
		if blocked {
			logInfo(h.Log, "Raccourci utilisateur (hors périmètre) laissé tel quel : %s", path)

			continue
		}
		ok, err := h.Ops.Matches(path, spec)
		if err != nil {
			return err
		}
		if ok {
			continue // déjà conforme → idempotence (aucune écriture)
		}
		if err := h.Ops.Create(path, spec); err != nil {
			return fmt.Errorf("création du raccourci %q : %w", path, err)
		}
		logInfo(h.Log, "Raccourci posé : %s → %s", path, spec.Target)
	}

	return nil
}

// parseShortcutSpec : extrait un ShortcutSpec d'un payload §3 brut. Place
// inconnu / champs manquants = enveloppe invalide (false) → le moteur rapporte
// error. `desktop_path` n'est requis QUE pour place=desktop.
func parseShortcutSpec(raw any) (ShortcutSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return ShortcutSpec{}, false
	}

	name, _ := payload["name"].(string)
	place, _ := payload["place"].(string)
	if name == "" || place == "" {
		return ShortcutSpec{}, false
	}
	if place != shortcutPlaceDesktop && place != shortcutPlaceStartup && place != shortcutPlaceTaskbar {
		return ShortcutSpec{}, false
	}

	target, _ := payload["target"].(string)
	args, _ := payload["args"].(string)
	icon, _ := payload["icon"].(string)
	desktopPath, _ := payload["desktop_path"].(string)

	// Story 27.7 : champs ajoutés (forward-compatible) — une icône UPLOADÉE
	// porte `icon_asset`/`icon_checksum`. Validés STRICTEMENT : un asset hors
	// format est IGNORÉ (on retombe sur `icon` brut, jamais un asset cassé).
	iconAsset, _ := payload["icon_asset"].(string)
	iconChecksum, _ := payload["icon_checksum"].(string)
	if iconAsset != "" && (!ValidShortcutIconFilename(iconAsset) || !ValidChecksum(iconChecksum)) {
		iconAsset = ""
		iconChecksum = ""
	}

	if place == shortcutPlaceDesktop && desktopPath == "" {
		return ShortcutSpec{}, false // le serveur DOIT fournir le chemin (Bug C)
	}

	return ShortcutSpec{
		Name:         name,
		Target:       target,
		Args:         args,
		Icon:         icon,
		Place:        place,
		DesktopPath:  desktopPath,
		IconAsset:    iconAsset,
		IconChecksum: iconChecksum,
	}, true
}

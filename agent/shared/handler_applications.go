package shared

import (
	"fmt"
	"sort"
	"strings"

	"golang.org/x/text/unicode/norm"
)

// Handler `applications` (aggregate / scope MACHINE) — Story 27.5.
//
// « UN TUYAU, DEUX OUTILS » : l'agent unifie le TRANSPORT (le déclencheur), PAS
// le moteur de paquets. WPKG reste le moteur déclaratif (résolution de
// dépendances, `<check>/<install>/<upgrade>`, versions) — il N'EST PAS absorbé.
// Ce handler DÉCLENCHE le moteur WPKG local (via Ops.TriggerWpkg) à la place de
// la GPO `se4_wpkg`, et LIT l'état par paquet de WPKG (`wpkg.xml`, via
// Ops.ListInstalled) — il ne réimplémente JAMAIS l'installation, la détection de
// présence (`<check>`), la résolution de dépendances ou la gestion de version.
//
// Logique PURE, OS-agnostique (le dépôt du profil par-hôte, le shell-out
// `wpkg-client.vbs` et la lecture de `wpkg.xml` sont injectés via
// ApplicationsOps) → testée sur l'hôte ; agent/windows ne fait que câbler ces
// trois opérations. Le shell-out vers le moteur WPKG est la SEULE exception
// justifiée à « API native, zéro shell-out » : déclencher un moteur externe ne
// s'écrit pas en Win32.
//
// CONVERGENCE level-triggered (Vérité #5 « programmé aujourd'hui, effectif
// demain »), JAMAIS un événement ponctuel :
//   - Test  : l'ensemble cible (app_id) est-il DÉJÀ entièrement installé ?
//     « désiré ⊆ installé ? » lu dans `wpkg.xml` (l'état courant réel de WPKG).
//     Vrai → compliant SANS re-déclenchement. Faux (installation incomplète /
//     ensemble modifié) → Apply.
//   - Apply : DÉCLENCHE WPKG (Ops.TriggerWpkg) ; WPKG résout/installe ce qui
//     manque. Idempotent / level-triggered : re-déclencher sur un poste déjà
//     convergé est sans effet cumulatif (WPKG no-op de lui-même).
//
// MARQUEUR DE PÉRIMÈTRE (« désactiver = cesser de gérer », iso 27.1/27.4) : une
// app retirée des affectations disparaît de l'ensemble cible → elle n'est plus
// exigée installée (l'inventaire la libère) ; l'agent NE la désinstalle PAS de
// lui-même (c'est WPKG qui le ferait via `<remove>` dans son profil — le handler
// ne touche jamais au poste hors du déclenchement WPKG).
//
// ISOLATION (AC4) : un échec de déclenchement WPKG → l'op renvoie une erreur →
// le moteur (engine.go RunPass) rend {status: error, detail} pour le SEUL type
// `applications` ; les autres types convergent. Jamais de faux `compliant` (leçon
// 🟠 27.4 #7) : un échec d'install est `error` + `detail`.

// ApplicationsSpec : une application cible (un item du payload `applications`).
// `AppId` est l'identifiant de paquet WPKG ( = `package-id` de `profiles.xml`) ;
// `Name` est le libellé d'affichage (informe l'inventaire/les logs, jamais une
// recette d'install).
type ApplicationsSpec struct {
	AppId string
	Name  string
}

// WpkgPackageResult : résultat par paquet d'un run WPKG (lu dans `wpkg.xml`
// après le déclenchement). `Installed` = le paquet est présent dans la base
// d'état locale de WPKG. Sert l'inventaire par app (AC4) — fondation des
// licences à pool.
type WpkgPackageResult struct {
	AppId     string
	Installed bool
}

// WpkgResult : résultat agrégé d'un run WPKG déclenché. `Triggered` = le moteur
// a bien été lancé (code de sortie capturé) ; `Installed` = l'ensemble des
// package-id présents dans `wpkg.xml` APRÈS le run (relu — level-triggered).
type WpkgResult struct {
	Triggered bool
	Installed []string
}

// ApplicationsOps : opérations spécifiques à l'OS (dépôt du profil + shell-out
// WPKG + lecture de `wpkg.xml`), injectées (testable hôte). L'impl Windows vit
// dans agent/windows/handler_applications_windows.go ; un fake en mémoire couvre
// les tests.
type ApplicationsOps interface {
	// ListInstalled lit `wpkg.xml` (la base d'état locale de WPKG, source de
	// vérité PAR PAQUET) et renvoie l'ensemble des `package-id` installés. err =
	// `wpkg.xml` illisible/corrompu → le moteur rend error pour le type. ABSENT
	// (jamais de run WPKG) → ([], nil) : rien d'installé encore (apply
	// déclenchera). Le handler ne réimplémente PAS `<check>` : il LIT l'état que
	// WPKG a écrit.
	ListInstalled() ([]string, error)

	// TriggerWpkg dépose le profil par-hôte (depuis l'ensemble cible) puis
	// DÉCLENCHE le moteur WPKG local (`wpkg-client.vbs /NOTempo`) ; c'est le
	// CLIENT qui télécharge le bundle (l'agent ne télécharge pas). Renvoie le
	// résultat agrégé (run lancé + état par paquet relu dans `wpkg.xml`). err =
	// déclenchement impossible (profil non déposable, cscript absent, code de
	// sortie d'échec global) → {status: error}.
	//
	// `specs` = l'ensemble cible (utilisé pour générer le profil par-hôte
	// `profiles.xml`/`hosts.xml` localement — D9 : zéro charge Laravel).
	TriggerWpkg(specs []ApplicationsSpec) (WpkgResult, error)

	// DeployedProfileAppIds lit l'ensemble des `package-id` du profil par-hôte
	// DÉJÀ DÉPOSÉ localement (`profiles.xml`, écrit par le dernier `Apply` via
	// `TriggerWpkg`/`dropHostProfile`) — c'est « ce que l'agent a demandé à WPKG
	// de gérer la dernière fois ». ABSENT (jamais déposé) → ([], nil) : rien géré
	// encore. Illisible/corrompu → err (le moteur rend error pour le type).
	//
	// Sert la DÉTECTION DE RETRAIT (2026-06-19). WPKG `/synchronize` ne
	// désinstalle un paquet que lorsqu'il QUITTE le profil déposé sur le poste
	// (`getPackagesRemoved` compare l'installé à `profiles.xml`). Or l'agent ne
	// redépose ce profil (et ne relance WPKG) QUE dans `Apply`, et `Apply` n'est
	// déclenché que si `Test` échoue. « Désiré ⊆ installé » seul reste vert après
	// un retrait (les apps RESTANTES sont toujours installées) → l'app retirée
	// survivrait, le profil sur le poste n'étant jamais réécrit. En comparant le
	// desired set courant au profil déposé, `Test` détecte le retrait (desired ⊊
	// déposé) et déclenche `Apply`.
	DeployedProfileAppIds() ([]string, error)
}

// ApplicationsHandler : handler aggregate branché dans le moteur (engine.go) —
// la machine d'états §5 et le hash d'agrégat restent au moteur, JAMAIS ici.
//
// `lastInventory` : inventaire PAR APP du dernier Test/Apply (PROCESS-LOCAL),
// exposé via Inventory() pour le BuildReport du cycle (AC4 — champ additif
// `inventory` sur l'item de rapport `applications`). Le verdict de conformité du
// TYPE reste UN statut (worst-status) géré par le moteur ; l'inventaire est une
// DONNÉE additive sous la ligne d'état, jamais un verdict per-app (grain 27.8
// intact).
type ApplicationsHandler struct {
	Ops ApplicationsOps
	Log *Logger

	lastInventory []WpkgPackageResult
}

// desiredSpecs : parse + dédoublonne par app_id les items cible. Dernier gagne
// (défense — le serveur garantit déjà l'union dédupliquée). Ordre stable par
// app_id (logs/inventaire déterministes).
func (h *ApplicationsHandler) desiredSpecs(items []StateItem) ([]ApplicationsSpec, error) {
	byAppID := map[string]ApplicationsSpec{}
	for _, item := range items {
		spec, ok := parseApplicationsSpec(item.Payload)
		if !ok {
			return nil, fmt.Errorf("payload applications inattendu : enveloppe invalide")
		}
		byAppID[spec.AppId] = spec
	}

	appIDs := make([]string, 0, len(byAppID))
	for id := range byAppID {
		appIDs = append(appIDs, id)
	}
	sort.Strings(appIDs)

	specs := make([]ApplicationsSpec, 0, len(appIDs))
	for _, id := range appIDs {
		specs = append(specs, byAppID[id])
	}

	return specs, nil
}

// Test : l'ensemble cible est-il DÉJÀ entièrement appliqué ? Deux conditions —
// au NIVEAU (pas à l'événement) :
//
//  1. « désiré ⊆ installé » : on lit `wpkg.xml` (l'état courant réel de WPKG) et
//     on vérifie que chaque app cible y figure. Toute app cible manquante → non
//     conforme (apply déclenchera WPKG pour l'installer).
//  2. « périmètre géré inchangé » : le desired set ÉGALE-t-il le profil par-hôte
//     DÉJÀ DÉPOSÉ (`profiles.xml`) ? S'ils diffèrent — une app a été RETIRÉE (ou
//     ajoutée) du profil côté serveur — le périmètre géré a bougé → non conforme
//     → Apply réécrit le profil et relance WPKG `/synchronize`, qui désinstalle
//     nativement l'app retirée (recettes `<remove>`). Sans cette 2ᵉ condition,
//     un retrait laissait « désiré ⊆ installé » vert (les apps RESTANTES sont
//     toujours installées) → l'app retirée survivait sur le poste, le profil
//     n'étant jamais réécrit (régression terrain 2026-06-19).
//
// On compare au profil DÉPOSÉ, PAS au set installé complet : un logiciel installé
// hors-SE5 (jamais dans notre profil déposé) ne doit pas provoquer un
// re-déclenchement permanent — WPKG le préserve déjà (paquet non-zombie).
//
// On NE réimplémente PAS `<check>` : la présence est CELLE QUE WPKG A ÉCRITE dans
// `wpkg.xml`. L'inventaire par app est mémorisé pour le rapport (AC4). Une erreur
// de lecture (`wpkg.xml` ou `profiles.xml` illisible) remonte → le moteur rend
// error (jamais un faux compliant).
func (h *ApplicationsHandler) Test(items []StateItem) (bool, error) {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return false, err
	}

	installed, err := h.Ops.ListInstalled()
	if err != nil {
		return false, fmt.Errorf("lecture de wpkg.xml (état installé) : %w", err)
	}
	installedSet := normalizedSet(installed)

	missing := false
	inventory := make([]WpkgPackageResult, 0, len(specs))
	desiredSet := make(map[string]bool, len(specs))
	for _, spec := range specs {
		desiredSet[normalizeNFC(spec.AppId)] = true
		present := installedSet[normalizeNFC(spec.AppId)]
		inventory = append(inventory, WpkgPackageResult{AppId: spec.AppId, Installed: present})
		if !present {
			missing = true
		}
	}
	h.lastInventory = inventory

	// Condition 2 — périmètre géré : desired set == profil par-hôte déposé ?
	// Diff (app retirée OU ajoutée) → non conforme (Apply réécrit le profil +
	// relance `/synchronize`).
	deployed, err := h.Ops.DeployedProfileAppIds()
	if err != nil {
		return false, fmt.Errorf("lecture du profil par-hôte déposé (profiles.xml) : %w", err)
	}
	if !sameNormalizedSet(desiredSet, deployed) {
		return false, nil
	}

	// Condition 1 — désiré ⊆ installé → compliant (pas de re-déclenchement).
	return !missing, nil
}

// Apply : converge — DÉCLENCHE le moteur WPKG local (Ops.TriggerWpkg) sur
// l'ensemble cible. WPKG résout/installe ce qui manque (dépendances comprises).
// Idempotent / level-triggered (re-déclencher sur un poste convergé = no-op
// WPKG). Après le run, on relit l'état par paquet (`wpkg.xml`) pour l'inventaire
// (AC4) et on construit le verdict par app. EFFORT MAXIMAL : si une app cible
// reste manquante APRÈS le run, on remonte une erreur (jamais un faux
// `compliant`, leçon 🟠 27.4 #7) — mais l'inventaire reflète l'état réel.
func (h *ApplicationsHandler) Apply(items []StateItem) error {
	specs, err := h.desiredSpecs(items)
	if err != nil {
		return err
	}

	result, err := h.Ops.TriggerWpkg(specs)
	if err != nil {
		// Le déclenchement a échoué (profil non déposable, cscript absent, code
		// de sortie d'échec global) → error pour le type (les autres convergent).
		logError(h.Log, "Déclenchement WPKG en échec : %v", err)

		return fmt.Errorf("déclenchement WPKG : %w", err)
	}

	// État par paquet relu APRÈS le run (level-triggered) — source de vérité
	// `wpkg.xml`. Sert l'inventaire (AC4) et le verdict effort-maximal.
	installedSet := normalizedSet(result.Installed)

	var missing []string
	inventory := make([]WpkgPackageResult, 0, len(specs))
	for _, spec := range specs {
		present := installedSet[normalizeNFC(spec.AppId)]
		inventory = append(inventory, WpkgPackageResult{AppId: spec.AppId, Installed: present})
		if !present {
			missing = append(missing, spec.AppId)
		}
	}
	h.lastInventory = inventory

	if len(missing) > 0 {
		// WPKG a été déclenché mais certaines apps cibles ne sont toujours pas
		// installées (installeur en échec, dépendance manquante…). On NE ment
		// PAS : error + detail (jamais un compliant optimiste, leçon 🟠 27.4 #7).
		logError(h.Log, "WPKG déclenché mais %d app(s) cible(s) non installée(s) : %s", len(missing), strings.Join(missing, ", "))

		return fmt.Errorf("WPKG déclenché mais apps non installées après le run : %s", strings.Join(missing, ", "))
	}

	logInfo(h.Log, "WPKG déclenché : %d app(s) cible(s) installée(s).", len(specs))

	return nil
}

// Inventory expose l'inventaire PAR APP du dernier Test/Apply (AC4) — consommé
// par le BuildReport du cycle (champ additif `inventory` sur l'item de rapport
// `applications`). Vide tant qu'aucun Test/Apply n'a tourné dans ce cycle.
func (h *ApplicationsHandler) Inventory() []WpkgPackageResult {
	return h.lastInventory
}

// ReportInventory implémente InventoryReporter (engine.go) : projette
// l'inventaire par app du dernier Test/Apply en items de rapport (champ additif
// `inventory`, AC4). Statut PAR APP : `compliant` si installée, `error` sinon
// (compliant/drift comptent comme un siège ; le handler ne distingue pas drift
// d'install neuve au niveau de l'app — la présence/absence suffit à la
// comptabilité). Le verdict du TYPE reste worst-status (engine), inchangé.
func (h *ApplicationsHandler) ReportInventory() []ReportInventoryItem {
	out := make([]ReportInventoryItem, 0, len(h.lastInventory))
	for _, r := range h.lastInventory {
		status := "compliant"
		if !r.Installed {
			status = "error"
		}
		out = append(out, ReportInventoryItem{AppID: r.AppId, Status: status})
	}

	return out
}

// parseApplicationsSpec : extrait un ApplicationsSpec d'un payload §3 brut.
// app_id vide = enveloppe invalide (false) → le moteur rapporte error. `name`
// optionnel (informe l'inventaire/les logs).
func parseApplicationsSpec(raw any) (ApplicationsSpec, bool) {
	payload, ok := raw.(map[string]any)
	if !ok || payload == nil {
		return ApplicationsSpec{}, false
	}

	appID, _ := payload["app_id"].(string)
	appID = strings.TrimSpace(appID)
	if appID == "" {
		return ApplicationsSpec{}, false
	}

	name, _ := payload["name"].(string)

	return ApplicationsSpec{AppId: appID, Name: name}, true
}

// normalizeNFC : forme NFC d'une chaîne (leçon 🟠 27.4 #3 — Windows peut produire
// du NFD). Évite un faux « non installé » quand le package-id relu de `wpkg.xml`
// est en NFD alors que la cible est en NFC (ou inversement).
func normalizeNFC(s string) string {
	return norm.NFC.String(s)
}

// normalizedSet : ensemble (NFC) d'une liste de package-id pour l'appartenance.
func normalizedSet(ids []string) map[string]bool {
	set := make(map[string]bool, len(ids))
	for _, id := range ids {
		set[normalizeNFC(strings.TrimSpace(id))] = true
	}

	return set
}

// sameNormalizedSet : le set désiré (clés DÉJÀ en forme NFC) est-il égal, en tant
// qu'ENSEMBLE (ordre/doublons indifférents), à `ids` (normalisé NFC) ? Sert la
// comparaison desired ↔ profil déposé du `Test` (détection de retrait/ajout).
func sameNormalizedSet(desired map[string]bool, ids []string) bool {
	other := normalizedSet(ids)
	if len(other) != len(desired) {
		return false
	}
	for k := range desired {
		if !other[k] {
			return false
		}
	}

	return true
}

package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/url"
	"os"
	"path"
	"regexp"
)

// Auto-update de l'agent (Story 25.2) — LE chemin le plus testé (NFR8).
//
// L'agent consomme le manifest 25.1 (`GET /api/v1/agent/release`, figé) : il
// détecte au check-in qu'une version DIFFÉRENTE de `shared.Version` est
// annoncée pour ce poste (résolution par ring côté serveur), télécharge le
// binaire via l'`url` ABSOLUE du manifest (verbatim — jamais reconstruite,
// décision amont n° 2), VÉRIFIE le SHA-256 du corps AVANT d'écrire puis la
// SIGNATURE Authenticode du fichier stagé AVANT de swapper, et se remplace par
// un swap atomique anti-brique (copie-atomique→re-hash→rename→rollback, cœur
// dans shared/swap.go) PUIS provoque sa propre SORTIE NON-GRACIEUSE (os.Exit≠0,
// Option A) pour que la recovery SCM relance le binaire vN+1. Un échec ne
// brique JAMAIS le poste et ne casse jamais le
// cycle machine (iso `SyncWallpaperAssets`) : l'agent en place reste
// fonctionnel, l'échec est rapporté (item `agent_update`), retry au prochain
// check-in (cadence `ttl_seconds`).
//
// SÉMANTIQUE DE COMPARAISON (piège n° 11) : le manifest dit autoritairement
// « voici la version que ce poste DOIT avoir » (le serveur a déjà tranché par
// la résolution de ring). L'agent applique `manifest.version != shared.Version`
// — ÉGALITÉ STRICTE, PAS d'ordre semver : même un downgrade volontaire (rollback
// décidé serveur) est appliqué. Le serveur est l'autorité, l'agent obéit.

// manifestFilenamePattern : le filename servi par 25.1 (décision amont) — le
// download n'écrit JAMAIS sous un nom non validé (un staging dir reste un
// fichier disque, anti-traversal iso wallpaper). Le serveur impose ce pattern
// strict au download (25.1 AC4) ; l'agent re-valide ce qu'il extrait de l'url.
var manifestFilenamePattern = regexp.MustCompile(`^sambaedu-agent-[0-9A-Za-z.+~_-]+\.exe$`)

// releaseManifest : la réponse manifest décodée (wrapper SE5, décision amont
// n° 9 — golden tests/Fixtures/Agent/release-manifest.v1.json).
type releaseManifest struct {
	Version string
	Hash    string
	URL     string
}

// SelfUpdate : tentative d'auto-update en fin de cycle machine (décision n° 1).
//
// Appelée au même point que `SyncWallpaperAssets` (après portée machine, sous
// garde `!quarantined`, avant le `POST /report` du cycle pour que l'item
// `agent_update` d'un échec rejoigne le rapport). UN SEUL download par cycle
// (piège n° 9) : pas de retry intra-cycle. Un échec pose `pendingUpdateError`
// (rapporté) et rend la main — jamais de panique propagée (l'appelant a un
// recover, mais on est défensif iso le reste de l'agent).
func (a *Agent) SelfUpdate(cfg Config) {
	if a.quarantined {
		a.Log.Debugf("Auto-update : quarantaine active, sauté.")

		return
	}
	// Plateforme sans primitives d'update (Linux, tests sans stub) : l'agent
	// ne se remplace jamais (pas de SCM, pas d'Authenticode). No-op silencieux
	// — l'auto-update n'a de sens qu'en service Windows réel.
	if a.SwapAndRestart == nil {
		a.Log.Debugf("Auto-update : aucune primitive de swap (plateforme non-Windows ?), sauté.")

		return
	}

	token, err := a.Store.ReadToken()
	if err != nil {
		a.Log.Errorf("Auto-update impossible : %v", err)

		return
	}
	a.Client.SetToken(token)

	// 1. GET manifest. 404 = rien à faire (no-op), 401 = arrêt (re-enrôlement
	// MANUEL), 403 = quarantaine, réseau/5xx = warning + skip (retry au cycle).
	manifest, ok := a.fetchReleaseManifest(cfg)
	if !ok {
		return
	}

	// 2. Comparaison ÉGALITÉ STRICTE (piège n° 11) : version cible == courante
	// → rien à faire (no-op, zéro download). Couvre l'anti-boucle (piège n° 9 :
	// un update appliqué fait passer shared.Version à la version cible au
	// prochain démarrage → plus de divergence).
	if manifest.Version == Version {
		a.Log.Debugf("Auto-update : version cible %s == version courante, rien à faire.", manifest.Version)

		return
	}
	a.Log.Infof("Auto-update : version cible %s annoncée (courante %s) — téléchargement.", manifest.Version, Version)

	// 3. Filename extrait de l'url ABSOLUE (dernier segment, percent-décodé) —
	// jamais reconstruit (décision n° 6). Re-validé contre le pattern strict
	// (le staging écrit ce nom sur disque).
	filename, err := releaseFilenameFromURL(manifest.URL)
	if err != nil {
		a.pendingUpdateError = fmt.Sprintf("url manifest inexploitable : %v", err)
		a.Log.Warningf("Auto-update : %s — retry au prochain cycle.", a.pendingUpdateError)

		return
	}

	// 4. Staging sous ProgramData\…\update\ (ACL SYSTEM) — Program Files n'est
	// PAS encore touché (décision n° 5). Préparé AVANT le download : on a besoin
	// du stagedPath pour le court-circuit « déjà stagé ».
	if err := a.Store.EnsureUpdateDir(a.UpdateACL); err != nil {
		a.pendingUpdateError = fmt.Sprintf("préparation du répertoire de staging : %v", err)
		a.Log.Warningf("Auto-update : %s — retry au prochain cycle.", a.pendingUpdateError)

		return
	}
	stagedPath := a.Store.UpdateStagePath(filename)

	// 5. Court-circuit réseau (iso content-addressed SyncWallpaperAssets) : si le
	// binaire cible est DÉJÀ stagé et que son SHA-256 == hash manifest, on saute
	// le download. Un cycle précédent qui a stagé+vérifié-hash mais échoué après
	// (signature, swap) ne re-télécharge pas — robustesse réseau, retry idempotent.
	staged := false
	if existing, err := readStagedAndHash(stagedPath); err == nil && existing == manifest.Hash {
		a.Log.Debugf("Auto-update : binaire %s déjà stagé et valide (SHA-256 ok), download sauté.", manifest.Version)
		staged = true
	}

	if !staged {
		// 6. Download du binaire via l'url manifest VERBATIM (Client, bearer +
		// rotation D5). Corps borné 16 Mio (piège n° 4) : un binaire >16 Mio
		// serait tronqué → SHA-256 divergent → rejeté (fail-safe correct).
		body, ok := a.downloadReleaseBinary(manifest.URL, filename)
		if !ok {
			return
		}

		// 7. SHA-256 du corps == hash manifest AVANT toute écriture (pattern exact
		// assets.go:146-154) — divergent → jeté, RIEN écrit, retry au prochain
		// cycle. PORTE 1.
		sum := sha256.Sum256(body)
		actual := hex.EncodeToString(sum[:])
		if actual != manifest.Hash {
			a.pendingUpdateError = fmt.Sprintf("SHA-256 du binaire téléchargé (%s) != hash manifest (%s)", actual, manifest.Hash)
			a.Log.Warningf("Auto-update : %s — binaire JETÉ (rien écrit), retry au prochain cycle.", a.pendingUpdateError)

			return
		}

		if err := WriteFileAtomic(stagedPath, body); err != nil {
			a.pendingUpdateError = fmt.Sprintf("écriture du binaire stagé : %v", err)
			a.Log.Warningf("Auto-update : %s — retry au prochain cycle.", a.pendingUpdateError)

			return
		}
	}

	// 8. Signature Authenticode du fichier STAGÉ AVANT tout swap (décision
	// n° 3) — invalide/non-confiance → jeté, AUCUN swap. PORTE 2. La vérif se
	// fait sur le fichier stagé, JAMAIS sur le fichier déjà en place. FAIL-CLOSED :
	// arrivé ici, SwapAndRestart != nil (garanti en tête) → un swap EST possible.
	// Si VerifyAuthenticode n'est pas câblée, c'est une ERREUR DE CONFIGURATION,
	// PAS une plateforme sans Authenticode (cette dernière est déjà sortie en
	// no-op via la garde SwapAndRestart == nil). On REFUSE de swapper sans la
	// porte signature — jamais de skip de vérif menant à un swap. Les deux portes
	// (hash, signature) sont successives et OBLIGATOIRES dès qu'un swap est possible.
	if a.VerifyAuthenticode == nil {
		a.pendingUpdateError = "config: VerifyAuthenticode non câblée, swap refusé"
		a.Log.Errorf("Auto-update : %s — anomalie de configuration, AUCUN swap (fail-closed).", a.pendingUpdateError)

		return
	}
	if err := a.VerifyAuthenticode(stagedPath); err != nil {
		a.pendingUpdateError = fmt.Sprintf("signature Authenticode invalide sur le binaire stagé : %v", err)
		a.Log.Warningf("Auto-update : %s — binaire JETÉ, AUCUN swap, retry au prochain cycle.", a.pendingUpdateError)

		return
	}
	a.Log.Infof("Auto-update : signature Authenticode du binaire %s valide.", manifest.Version)

	// 9. Swap atomique anti-brique + sortie non-gracieuse (Option A, décision
	// review 25.2). SwapAndRestart (côté windows/) délègue à shared.PerformSwap :
	// copie-atomique→re-hash du .new (M2)→rename→rollback, PUIS — sur succès
	// UNIQUEMENT — os.Exit(≠0) pour que la recovery SCM relance le binaire vN+1.
	// On passe manifest.Hash : le binaire RÉELLEMENT mis en place est re-vérifié
	// à sa position finale, pas seulement au staging.
	//
	// Si SwapAndRestart RETOURNE (avec ou sans erreur), c'est que le swap a
	// ÉCHOUÉ sans briquer (rollback fait, ancien binaire en place — anti-brique
	// AC3) : sur succès, os.Exit a déjà tué le process et ce code n'est jamais
	// atteint. On rapporte donc toujours l'échec quand on revient ici.
	if err := a.SwapAndRestart(stagedPath, manifest.Version, manifest.Hash); err != nil {
		a.pendingUpdateError = fmt.Sprintf("swap de l'agent en échec (ancien binaire préservé) : %v", err)
		a.Log.Errorf("Auto-update : %s — l'agent courant continue, retry au prochain cycle.", a.pendingUpdateError)

		return
	}

	// Chemin THÉORIQUEMENT inatteignable en service Windows réel : un swap réussi
	// appelle os.Exit AVANT de rendre la main. Si on arrive ici sans erreur,
	// c'est un stub de test (triggerRestart no-op) ou une plateforme sans
	// os.Exit câblé. La PREUVE de succès reste la nouvelle `agent_version`
	// rapportée par l'image vN+1 (AC4) — aucun item de succès posé.
	a.Log.Infof("Auto-update : version %s installée, sortie pour relance par la recovery SCM.", manifest.Version)
}

// fetchReleaseManifest : GET manifest + dispatch des codes. Retourne ok=false
// (skip silencieux ou arrêt) pour 404/401/403/réseau/5xx ; ok=true + manifest
// parsé sur 200. 401 entraîne un OutcomeStop au cycle suivant (le service
// s'arrêtera sur le GET /state) — ici on se contente de logger et skipper :
// l'auto-update ne PILOTE pas l'arrêt du service (c'est la portée machine).
func (a *Agent) fetchReleaseManifest(cfg Config) (releaseManifest, bool) {
	resp, err := a.Client.Get(cfg.ServerURL+"/api/v1/agent/release", "")
	if err != nil {
		a.Log.Warningf("Auto-update : serveur injoignable sur GET /release : %v — skip (retry au prochain cycle).", err)

		return releaseManifest{}, false
	}

	switch resp.StatusCode {
	case 200:
		manifest, err := parseReleaseManifest(resp.Body)
		if err != nil {
			a.Log.Warningf("Auto-update : manifest illisible (%v) — skip, retry au prochain cycle.", err)

			return releaseManifest{}, false
		}

		return manifest, true
	case 404:
		// no_release : aucune release applicable (poste sans ring ET aucune
		// stable) = RIEN À FAIRE (décision amont n° 7, piège n° 10). Pas une
		// erreur, pas un log d'erreur.
		a.Log.Debugf("Auto-update : GET /release -> 404 no_release, aucune release applicable.")

		return releaseManifest{}, false
	case 401:
		// Token mort : la portée machine (GET /state) s'arrêtera proprement —
		// l'auto-update ne déclenche PAS le re-enrôlement (jamais automatique).
		a.Log.Errorf("Auto-update : 401 sur GET /release (token refusé) — la portée machine arrêtera le service, re-enrôlement MANUEL.")

		return releaseManifest{}, false
	case 403:
		// M4 (Option 1) : un 403 sur le canal RELEASE ne met PAS le poste en
		// quarantaine GLOBALE — il SAUTE seulement l'update. La quarantaine
		// globale (qui supprime aussi le POST /report) reste réservée au 403 du
		// canal principal /state (loop.go). Le poste continue son cycle normal et
		// rapporte sa conformité ; on signale juste l'update sauté.
		a.pendingUpdateError = "GET /release -> 403 (canal release refusé) : update sauté ce cycle"
		a.Log.Warningf("Auto-update : %s — PAS de quarantaine globale (le cycle et le report continuent), retry au prochain cycle.", a.pendingUpdateError)

		return releaseManifest{}, false
	default:
		a.Log.Warningf("Auto-update : GET /release -> %d inattendu — skip, retry au prochain cycle.", resp.StatusCode)

		return releaseManifest{}, false
	}
}

// downloadReleaseBinary : GET du binaire via l'url manifest VERBATIM. Retourne
// ok=false (skip/quarantaine) ou le corps brut sur 200.
func (a *Agent) downloadReleaseBinary(manifestURL, filename string) ([]byte, bool) {
	resp, err := a.Client.Get(manifestURL, "")
	if err != nil {
		a.Log.Warningf("Auto-update : serveur injoignable sur GET du binaire %s : %v — skip, retry au prochain cycle.", filename, err)

		return nil, false
	}

	switch resp.StatusCode {
	case 200:
		return resp.Body, true
	case 401:
		a.Log.Errorf("Auto-update : 401 sur le download du binaire %s — la portée machine arrêtera le service.", filename)

		return nil, false
	case 403:
		// M4 (Option 1) : 403 sur le download du binaire (canal release) = update
		// sauté, PAS de quarantaine globale (cf. fetchReleaseManifest). Le report
		// du cycle part normalement.
		a.pendingUpdateError = fmt.Sprintf("GET du binaire %s -> 403 (canal release refusé) : update sauté ce cycle", filename)
		a.Log.Warningf("Auto-update : %s — PAS de quarantaine globale, retry au prochain cycle.", a.pendingUpdateError)

		return nil, false
	case 404:
		// La release a disparu entre le manifest et le download (course rare).
		a.pendingUpdateError = fmt.Sprintf("binaire %s introuvable au download (404)", filename)
		a.Log.Warningf("Auto-update : %s — skip, retry au prochain cycle.", a.pendingUpdateError)

		return nil, false
	default:
		a.pendingUpdateError = fmt.Sprintf("GET du binaire %s -> %d inattendu", filename, resp.StatusCode)
		a.Log.Warningf("Auto-update : %s — skip, retry au prochain cycle.", a.pendingUpdateError)

		return nil, false
	}
}

// parseReleaseManifest décode le wrapper SE5 `{success, version, hash, url}`
// (décision amont n° 9). Champs vides = manifest inexploitable (on ne tente
// rien : un hash ou une url vide ne doit jamais mener à un download/swap).
func parseReleaseManifest(raw []byte) (releaseManifest, error) {
	v, err := DecodeJSON(raw)
	if err != nil {
		return releaseManifest{}, err
	}
	obj, ok := v.(map[string]any)
	if !ok {
		return releaseManifest{}, fmt.Errorf("manifest : objet JSON attendu, obtenu %T", v)
	}

	// Wrapper SE5 (contrat 25.1) : `success` AUTORITAIRE. Absent ou != true =
	// le serveur n'affirme PAS une release valide → on ne tente rien (un manifest
	// d'erreur a un corps `{success:false,…}` qu'on ne doit jamais traiter comme
	// une cible d'update).
	if success, _ := obj["success"].(bool); !success {
		return releaseManifest{}, fmt.Errorf("manifest : success=false ou absent")
	}

	version, _ := obj["version"].(string)
	hash, _ := obj["hash"].(string)
	manifestURL, _ := obj["url"].(string)
	if version == "" || hash == "" || manifestURL == "" {
		return releaseManifest{}, fmt.Errorf("manifest incomplet (version=%q hash=%q url vide=%t)", version, hash, manifestURL == "")
	}
	if !ValidChecksum(hash) {
		return releaseManifest{}, fmt.Errorf("hash manifest malformé (attendu : 64 hex)")
	}

	return releaseManifest{Version: version, Hash: hash, URL: manifestURL}, nil
}

// releaseFilenameFromURL extrait et VALIDE le filename depuis l'url ABSOLUE du
// manifest (dernier segment du path, percent-décodé). L'url est autoritaire
// (décision amont n° 2) — on ne reconstruit jamais le chemin, on le LIT. Le
// pattern strict garde contre tout nom hostile (un staging dir reste un
// fichier disque).
func releaseFilenameFromURL(rawURL string) (string, error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return "", fmt.Errorf("url invalide : %w", err)
	}
	// path.Base sur le path DÉJÀ déchiffré par url.Parse (u.Path est décodé).
	filename := path.Base(u.Path)
	if !manifestFilenamePattern.MatchString(filename) {
		return "", fmt.Errorf("filename %q hors format attendu (sambaedu-agent-<version>.exe)", filename)
	}

	return filename, nil
}

// drainUpdateReportItems : retourne l'item de rapport `agent_update` d'un échec
// d'auto-update du cycle (décision n° 7), puis vide l'état pending (un échec se
// rapporte UNE fois). Vide → aucun item. Appelé par RunCycle juste avant
// BuildReport. `agent_update` n'est PAS un type de ressource desired-state (pas
// de provider serveur) : c'est un CANAL DE SIGNALEMENT d'échec côté agent — à
// ne pas confondre avec un handler.
func (a *Agent) drainUpdateReportItems() []ReportItem {
	if a.pendingUpdateError == "" {
		return nil
	}
	detail := a.pendingUpdateError
	a.pendingUpdateError = ""

	// Hash OBLIGATOIRE côté serveur (`items.*.hash` required + hex-64) : sans
	// lui l'item était rejeté en 422 AVEC TOUT LE RAPPORT du cycle — le signal
	// d'échec détruisait son porteur, et le type n'étant pas non plus dans la
	// liste acceptée, ce chemin n'a jamais fonctionné bout en bout. Hash du
	// message : deux échecs identiques ne produisent pas d'événement de drift à
	// répétition, un échec DIFFÉRENT en produit un.
	sum := sha256.Sum256([]byte(detail))

	return []ReportItem{{
		Type:   "agent_update",
		Status: "error",
		Hash:   hex.EncodeToString(sum[:]),
		Detail: truncateDetail(detail, 480),
	}}
}

// truncateDetail borne la taille du detail rapporté (le serveur tronque déjà,
// mais on reste poli sur le wire — iso Str::limit côté serveur). La troncature
// se fait sur une frontière de RUNE, jamais sur un octet : couper au milieu
// d'une séquence UTF-8 (les messages d'erreur FR sont accentués) produirait une
// string invalide. `max` est donc une borne en runes.
func truncateDetail(s string, max int) string {
	runes := []rune(s)
	if len(runes) <= max {
		return s
	}

	return string(runes[:max]) + "…"
}

// readStagedAndHash lit le fichier stagé et renvoie son SHA-256 hex (même
// primitive que la porte 1 / assets.go : sha256.Sum256 sur le corps). Erreur
// (fichier absent, lecture KO) → l'appelant retombe sur le download nominal.
func readStagedAndHash(stagedPath string) (string, error) {
	body, err := os.ReadFile(stagedPath)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(body)

	return hex.EncodeToString(sum[:]), nil
}

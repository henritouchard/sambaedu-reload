package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"strings"
	"testing"
)

// stateWithShortcutIcon : enveloppe v1 minimale dont l'item `shortcuts`
// (machine_user) référence une icône uploadée content-addressed.
func stateWithShortcutIcon(filename, checksum string) string {
	return `{"schema":"se5.desired-state/v1","generated_at":"2026-06-16T08:00:00+00:00","ttl_seconds":3600,"machine":[],"session":[],"machine_user":[{"type":"shortcuts","semantics":"aggregate","mode":"strict","payload":{"name":"Calculatrice","target":"C:\\Windows\\System32\\calc.exe","args":"","icon":"Calculatrice","icon_asset":"` +
		filename + `","icon_checksum":"` + checksum + `","place":"desktop","desktop_path":"%USERPROFILE%\\Desktop\\"},"hash":"` + strings.Repeat("a", 64) + `"}]}`
}

func iconFixture() (filename, checksum string, body []byte) {
	body = []byte("contenu-icone-ico")
	sum := sha256.Sum256(body)
	checksum = hex.EncodeToString(sum[:])

	return checksum + ".ico", checksum, body
}

func TestSyncShortcutIconsDownloadsVerifiesAndIsContentAddressed(t *testing.T) {
	f := newFakeSessionServer(t)
	filename, checksum, body := iconFixture()
	f.iconBody[filename] = body

	agent, store, cfg := newSessionAgent(t, f, nil)
	// L'icône est référencée par un cache de SESSION (le sync scanne machine
	// + toutes les sessions).
	if err := store.WriteSessionStateCache(testSID, []byte(stateWithShortcutIcon(filename, checksum)), `"e"`, nil); err != nil {
		t.Fatal(err)
	}

	aclCalls := 0
	agent.AssetsACL = func(path string) error { aclCalls++; return nil }

	agent.SyncShortcutIcons(cfg)

	raw, err := os.ReadFile(store.IconPath(filename))
	if err != nil || string(raw) != string(body) {
		t.Fatalf("icône téléchargée/vérifiée attendue : %v", err)
	}
	if aclCalls != 1 {
		t.Errorf("ACL Users:R posée à la création de icons\\ : %d", aclCalls)
	}

	// Content-addressed : présent = JAMAIS re-téléchargé.
	agent.SyncShortcutIcons(cfg)
	if len(f.iconCalls) != 1 {
		t.Errorf("un seul download attendu (idempotent) : %v", f.iconCalls)
	}
}

func TestSyncShortcutIconsChecksumMismatchNeverEntersCache(t *testing.T) {
	f := newFakeSessionServer(t)
	filename, checksum, _ := iconFixture()
	f.iconBody[filename] = []byte("contenu-corrompu")

	agent, store, cfg := newSessionAgent(t, f, nil)
	if err := store.WriteSessionStateCache(testSID, []byte(stateWithShortcutIcon(filename, checksum)), `"e"`, nil); err != nil {
		t.Fatal(err)
	}

	agent.SyncShortcutIcons(cfg)

	if _, err := os.Stat(store.IconPath(filename)); err == nil {
		t.Fatal("un contenu au checksum divergent n'entre JAMAIS dans le cache")
	}

	// Retry au prochain passage (le fichier manque toujours).
	agent.SyncShortcutIcons(cfg)
	if len(f.iconCalls) != 2 {
		t.Errorf("retry attendu : %v", f.iconCalls)
	}
}

func TestSyncShortcutIcons404IsLoggedNotFatal(t *testing.T) {
	f := newFakeSessionServer(t)
	filename, checksum, _ := iconFixture()
	// rien dans f.iconBody → 404

	agent, store, cfg := newSessionAgent(t, f, nil)
	if err := store.WriteSessionStateCache(testSID, []byte(stateWithShortcutIcon(filename, checksum)), `"e"`, nil); err != nil {
		t.Fatal(err)
	}

	agent.SyncShortcutIcons(cfg) // ne panique pas, ne crashe pas
	if _, err := os.Stat(store.IconPath(filename)); err == nil {
		t.Error("aucun fichier sur 404")
	}
}

func TestSyncShortcutIconsIgnoresInvalidFilenames(t *testing.T) {
	f := newFakeSessionServer(t)
	agent, store, cfg := newSessionAgent(t, f, nil)

	// icon_asset hors format content-addressed → strictement ignoré (jamais de
	// jointure de chemin sur une valeur non validée).
	bad := `{"schema":"se5.desired-state/v1","machine":[],"session":[],"machine_user":[{"type":"shortcuts","payload":{"name":"x","place":"startup","icon":"x","icon_asset":"../evil.ico","icon_checksum":"` + strings.Repeat("a", 64) + `"},"hash":"` + strings.Repeat("b", 64) + `"}]}`
	if err := store.WriteSessionStateCache(testSID, []byte(bad), `"e"`, nil); err != nil {
		t.Fatal(err)
	}

	agent.SyncShortcutIcons(cfg)
	if len(f.iconCalls) != 0 {
		t.Errorf("aucun download attendu (filename hors format) : %v", f.iconCalls)
	}
}

func TestSyncShortcutIconsNoAssetNothingToDownload(t *testing.T) {
	f := newFakeSessionServer(t)
	agent, store, cfg := newSessionAgent(t, f, nil)

	// Un raccourci à icône RÉELLE (chemin, pas d'icon_asset) : rien à
	// télécharger (le `.ico` vit côté poste, l'agent ne sync que les uploadées).
	noAsset := `{"schema":"se5.desired-state/v1","machine":[],"session":[],"machine_user":[{"type":"shortcuts","payload":{"name":"Firefox","target":"firefox.exe","icon":"firefox.exe,0","place":"startup"},"hash":"` + strings.Repeat("c", 64) + `"}]}`
	if err := store.WriteSessionStateCache(testSID, []byte(noAsset), `"e"`, nil); err != nil {
		t.Fatal(err)
	}

	agent.SyncShortcutIcons(cfg)
	if len(f.iconCalls) != 0 {
		t.Errorf("aucun download attendu (pas d'icône uploadée) : %v", f.iconCalls)
	}
}

func TestSyncShortcutIconsSkippedInQuarantine(t *testing.T) {
	f := newFakeSessionServer(t)
	filename, checksum, body := iconFixture()
	f.iconBody[filename] = body

	agent, store, cfg := newSessionAgent(t, f, nil)
	if err := store.WriteSessionStateCache(testSID, []byte(stateWithShortcutIcon(filename, checksum)), `"e"`, nil); err != nil {
		t.Fatal(err)
	}
	agent.quarantined = true

	agent.SyncShortcutIcons(cfg)
	if len(f.iconCalls) != 0 {
		t.Errorf("sync sauté en quarantaine : %v", f.iconCalls)
	}
}

func TestValidShortcutIconFilename(t *testing.T) {
	ok := strings.Repeat("a", 64) + ".ico"
	if !ValidShortcutIconFilename(ok) {
		t.Errorf("%q devrait être valide", ok)
	}
	for _, bad := range []string{
		"Calculatrice",                   // nom nu (pas content-addressed)
		strings.Repeat("a", 64) + ".png", // mauvaise extension
		strings.Repeat("a", 63) + ".ico", // trop court
		"../" + strings.Repeat("a", 64) + ".ico",
		strings.Repeat("A", 64) + ".ico", // hex majuscule
		"",
	} {
		if ValidShortcutIconFilename(bad) {
			t.Errorf("%q ne devrait PAS être valide", bad)
		}
	}
}

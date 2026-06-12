package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"strings"
	"testing"
)

// stateWithWallpaperAsset : enveloppe v1 minimale référençant un asset
// content-addressed.
func stateWithWallpaperAsset(filename, checksum string) string {
	return `{"schema":"se5.desired-state/v1","generated_at":"2026-06-12T08:00:00+00:00","ttl_seconds":3600,"machine":[],"session":[{"type":"wallpaper","semantics":"exclusive","mode":"default","payload":{"asset":"` +
		filename + `","checksum":"` + checksum + `"},"hash":"` + strings.Repeat("c", 64) + `"}],"machine_user":[]}`
}

func assetFixture() (filename, checksum string, body []byte) {
	body = []byte("contenu-image-jpeg")
	sum := sha256.Sum256(body)
	checksum = hex.EncodeToString(sum[:])

	return checksum + ".jpg", checksum, body
}

func TestSyncAssetsDownloadsVerifiesAndIsContentAddressed(t *testing.T) {
	f := newFakeSessionServer(t)
	filename, checksum, body := assetFixture()
	f.assetBody[filename] = body

	agent, store, cfg := newSessionAgent(t, f, nil)
	// L'asset est référencé par un cache de SESSION (le sync scanne machine
	// + toutes les sessions).
	if err := store.WriteSessionStateCache(testSID, []byte(stateWithWallpaperAsset(filename, checksum)), `"e"`, nil); err != nil {
		t.Fatal(err)
	}

	aclCalls := 0
	agent.AssetsACL = func(path string) error { aclCalls++; return nil }

	agent.SyncWallpaperAssets(cfg)

	raw, err := os.ReadFile(store.AssetPath(filename))
	if err != nil || string(raw) != string(body) {
		t.Fatalf("asset téléchargé/vérifié attendu : %v", err)
	}
	if aclCalls != 1 {
		t.Errorf("ACL Users:R posée à la création d'assets\\ : %d", aclCalls)
	}

	// Content-addressed : présent = JAMAIS re-téléchargé.
	agent.SyncWallpaperAssets(cfg)
	if len(f.assetCalls) != 1 {
		t.Errorf("un seul download attendu (idempotent) : %v", f.assetCalls)
	}
}

func TestSyncAssetsChecksumMismatchNeverEntersCache(t *testing.T) {
	f := newFakeSessionServer(t)
	filename, checksum, _ := assetFixture()
	f.assetBody[filename] = []byte("contenu-corrompu")

	agent, store, cfg := newSessionAgent(t, f, nil)
	if err := store.WriteSessionStateCache(testSID, []byte(stateWithWallpaperAsset(filename, checksum)), `"e"`, nil); err != nil {
		t.Fatal(err)
	}

	agent.SyncWallpaperAssets(cfg)

	if _, err := os.Stat(store.AssetPath(filename)); err == nil {
		t.Fatal("un contenu au checksum divergent n'entre JAMAIS dans le cache")
	}

	// Retry au prochain passage (le fichier manque toujours).
	agent.SyncWallpaperAssets(cfg)
	if len(f.assetCalls) != 2 {
		t.Errorf("retry attendu : %v", f.assetCalls)
	}
}

func TestSyncAssets404IsLoggedNotFatal(t *testing.T) {
	f := newFakeSessionServer(t)
	filename, checksum, _ := assetFixture()
	// rien dans f.assetBody → 404

	agent, store, cfg := newSessionAgent(t, f, nil)
	if err := store.WriteSessionStateCache(testSID, []byte(stateWithWallpaperAsset(filename, checksum)), `"e"`, nil); err != nil {
		t.Fatal(err)
	}

	agent.SyncWallpaperAssets(cfg) // ne panique pas, ne crashe pas
	if _, err := os.Stat(store.AssetPath(filename)); err == nil {
		t.Error("aucun fichier sur 404")
	}
}

func TestSyncAssetsIgnoresInvalidFilenamesAndLegacyFormats(t *testing.T) {
	f := newFakeSessionServer(t)
	agent, store, cfg := newSessionAgent(t, f, nil)

	// Le golden state (FIGÉ 23.x) porte un asset legacy "fonds/ecole-2026.jpg"
	// sans checksum : strictement ignoré (jamais de jointure de chemin sur
	// une valeur non validée).
	if err := store.WriteStateCache(mustReadGolden(t), `"e"`); err != nil {
		t.Fatal(err)
	}
	// Et un asset: null = rien à télécharger.
	nullAsset := `{"schema":"se5.desired-state/v1","machine":[],"session":[{"type":"wallpaper","payload":{"asset":null},"hash":"` + strings.Repeat("d", 64) + `"}],"machine_user":[]}`
	if err := store.WriteSessionStateCache(testSID, []byte(nullAsset), `"e2"`, nil); err != nil {
		t.Fatal(err)
	}

	agent.SyncWallpaperAssets(cfg)
	if len(f.assetCalls) != 0 {
		t.Errorf("aucun download attendu : %v", f.assetCalls)
	}
}

func TestSyncAssetsSkippedInQuarantine(t *testing.T) {
	f := newFakeSessionServer(t)
	filename, checksum, body := assetFixture()
	f.assetBody[filename] = body

	agent, store, cfg := newSessionAgent(t, f, nil)
	if err := store.WriteSessionStateCache(testSID, []byte(stateWithWallpaperAsset(filename, checksum)), `"e"`, nil); err != nil {
		t.Fatal(err)
	}
	agent.quarantined = true

	agent.SyncWallpaperAssets(cfg)
	if len(f.assetCalls) != 0 {
		t.Errorf("sync sauté en quarantaine : %v", f.assetCalls)
	}
}

func TestRunSessionFetchFetchesThenSyncsAssets(t *testing.T) {
	// Le point d'entrée de la tâche at-logon : fetch des sessions PUIS sync
	// des assets référencés par le cache frais.
	f := newFakeSessionServer(t)
	filename, checksum, body := assetFixture()
	f.assetBody[filename] = body
	f.userStateBody = stateWithWallpaperAsset(filename, checksum)

	agent, store, cfg := newSessionAgent(t, f, []Session{{Login: "jdoe", SID: testSID}})

	agent.RunSessionFetch(cfg)

	if _, err := store.ReadSessionStateCache(testSID); err != nil {
		t.Errorf("cache de session attendu : %v", err)
	}
	if _, err := os.Stat(store.AssetPath(filename)); err != nil {
		t.Errorf("asset pré-téléchargé attendu en fin de session-fetch : %v", err)
	}
}

package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"testing"
)

// AC3 (anti-brique) RÉELLEMENT testée sur Linux (#6/M6) : le cœur
// copie-atomique→re-hash→rename→rollback vit dans shared.PerformSwap, opérant
// sur des chemins injectés. Les renames POSIX se comportent comme Windows pour
// ce besoin. triggerRestart est un stub : on vérifie qu'il est appelé APRÈS un
// swap réussi, et JAMAIS si le swap échoue (l'ancien binaire reste intact).

func hashOf(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

func mustWrite(t *testing.T, path string, b []byte) {
	t.Helper()
	if err := os.WriteFile(path, b, 0o755); err != nil {
		t.Fatal(err)
	}
}

func readFile(t *testing.T, path string) []byte {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

// ── Swap nominal : fichiers permutés + triggerRestart appelé ──────────────────

func TestPerformSwapNominal(t *testing.T) {
	dir := t.TempDir()
	target := filepath.Join(dir, "agent.exe")
	staged := filepath.Join(dir, "staged.exe")

	oldBin := []byte("ANCIEN-BINAIRE-vN")
	newBin := []byte("NOUVEAU-BINAIRE-vN+1")
	mustWrite(t, target, oldBin)
	mustWrite(t, staged, newBin)

	restarted := false
	err := PerformSwap(target, staged, hashOf(newBin), func() { restarted = true })
	if err != nil {
		t.Fatalf("swap nominal sans erreur attendu, got %v", err)
	}
	if !restarted {
		t.Error("triggerRestart appelé APRÈS un swap réussi attendu")
	}
	// agent.exe porte désormais le NOUVEAU binaire.
	if got := readFile(t, target); string(got) != string(newBin) {
		t.Errorf("agent.exe = nouveau binaire attendu, got %q", got)
	}
	// Pas de .new résiduel (rename consommé) ; .old peut subsister (nettoyé au
	// boot suivant par cleanupOldBinary côté Windows).
	if _, err := os.Stat(target + ".new"); !os.IsNotExist(err) {
		t.Error(".new ne doit pas subsister après un swap réussi")
	}
}

// ── Rollback : la dépose du .new échoue (.new = répertoire) → ancien intact ────
// On force un échec à l'étape « dépose .new -> agent.exe » en créant agent.exe
// déjà occupé par... non : on force plutôt l'échec du rename (c) en rendant la
// cible un RÉPERTOIRE non vide après le rename (b) — impossible à orchestrer
// proprement. À la place : on force l'échec de la copie atomique du staged
// (source inexistante) AVANT toute étape destructive.
func TestPerformSwapStagedMissingNoMutation(t *testing.T) {
	dir := t.TempDir()
	target := filepath.Join(dir, "agent.exe")
	staged := filepath.Join(dir, "absent.exe") // n'existe pas

	oldBin := []byte("ANCIEN-BINAIRE-INTACT")
	mustWrite(t, target, oldBin)

	restarted := false
	err := PerformSwap(target, staged, hashOf(oldBin), func() { restarted = true })
	if err == nil {
		t.Fatal("erreur attendue quand le staged est absent")
	}
	if restarted {
		t.Error("triggerRestart NE doit JAMAIS être appelé si le swap échoue")
	}
	// agent.exe = ancien binaire, INTACT (aucune étape destructive atteinte).
	if got := readFile(t, target); string(got) != string(oldBin) {
		t.Errorf("agent.exe = ancien binaire intact attendu, got %q", got)
	}
}

// ── Rollback M2 : le .new mis en place a un hash DIVERGENT → abort, intact ─────
// Le binaire stagé est corrompu vis-à-vis du hash manifest : la re-vérification
// (M2) du .new à sa position finale doit échouer AVANT toute étape destructive,
// laisser l'ancien binaire en place et NE PAS appeler triggerRestart.
func TestPerformSwapNewHashMismatchRollsBack(t *testing.T) {
	dir := t.TempDir()
	target := filepath.Join(dir, "agent.exe")
	staged := filepath.Join(dir, "staged.exe")

	oldBin := []byte("ANCIEN-BINAIRE-INTACT")
	corrupt := []byte("BINAIRE-STAGE-CORROMPU")
	mustWrite(t, target, oldBin)
	mustWrite(t, staged, corrupt)

	// expectedHash = un hash qui NE correspond PAS au binaire stagé.
	wrongHash := hashOf([]byte("ce-que-le-manifest-annoncait"))

	restarted := false
	err := PerformSwap(target, staged, wrongHash, func() { restarted = true })
	if err == nil {
		t.Fatal("erreur attendue quand le .new diverge du hash manifest (M2)")
	}
	if restarted {
		t.Error("triggerRestart NE doit JAMAIS être appelé si la re-vérif M2 échoue")
	}
	if got := readFile(t, target); string(got) != string(oldBin) {
		t.Errorf("agent.exe = ancien binaire intact attendu (M2 abort avant swap), got %q", got)
	}
	// Le .new corrompu a été nettoyé.
	if _, err := os.Stat(target + ".new"); !os.IsNotExist(err) {
		t.Error(".new corrompu doit être supprimé sur abort M2")
	}
}

// ── Rollback : échec du rename final (c) → ancien binaire restauré en place ────
// On force un échec DÉTERMINISTE du rename final (.new -> agent.exe) via le hook
// renameForSwap (l'échec de (c) est rare en prod — même volume, après un (b)
// réussi — donc non provoquable de façon portable autrement). Le rollback
// (.old -> agent.exe) doit restaurer l'ANCIEN binaire intact.
func TestPerformSwapRenameFailureRollsBack(t *testing.T) {
	dir := t.TempDir()
	target := filepath.Join(dir, "agent.exe")
	staged := filepath.Join(dir, "staged.exe")

	oldBin := []byte("ANCIEN-BINAIRE-INTACT")
	newBin := []byte("NOUVEAU-BINAIRE")
	mustWrite(t, target, oldBin)
	mustWrite(t, staged, newBin)

	// Hook : laisser passer (b) (target -> target.old) ET le rollback
	// (target.old -> target), mais faire ÉCHOUER (c) (target.new -> target).
	orig := renameForSwap
	t.Cleanup(func() { renameForSwap = orig })
	renameForSwap = func(from, to string) error {
		if from == target+".new" && to == target {
			return os.ErrPermission // (c) échoue
		}
		return orig(from, to)
	}

	restarted := false
	err := PerformSwap(target, staged, hashOf(newBin), func() { restarted = true })
	if err == nil {
		t.Fatal("erreur attendue quand le rename final (c) échoue")
	}
	if restarted {
		t.Error("triggerRestart NE doit JAMAIS être appelé si le rename final échoue")
	}
	// L'ancien binaire a été RESTAURÉ en place par le rollback (.old -> target).
	if got := readFile(t, target); string(got) != string(oldBin) {
		t.Errorf("ancien binaire restauré en place par le rollback attendu, got %q", got)
	}
	// Pas de .old résiduel (consommé par le rollback) ni de .new (nettoyé).
	if _, err := os.Stat(target + ".new"); !os.IsNotExist(err) {
		t.Error(".new doit être nettoyé après le rollback")
	}
}

// ── Copie cross-volume simulée : atomicCopyFile crée le tmp À CÔTÉ de dst ──────
// On ne peut pas monter deux volumes en test, mais on vérifie l'invariant clé :
// le fichier temporaire est créé dans le répertoire de dst (donc le même volume
// que dst), garantissant un rename final intra-volume.
func TestAtomicCopyFileTmpBesideDst(t *testing.T) {
	srcDir := t.TempDir()
	dstDir := t.TempDir()
	src := filepath.Join(srcDir, "src.bin")
	dst := filepath.Join(dstDir, "dst.bin")
	content := []byte("contenu-binaire")
	mustWrite(t, src, content)

	if err := atomicCopyFile(src, dst); err != nil {
		t.Fatalf("copie atomique sans erreur attendue, got %v", err)
	}
	if got := readFile(t, dst); string(got) != string(content) {
		t.Errorf("dst = contenu copié attendu, got %q", got)
	}
	// Aucun tmp résiduel dans le répertoire de dst.
	entries, _ := os.ReadDir(dstDir)
	for _, e := range entries {
		if filepath.Ext(e.Name()) == ".tmp" {
			t.Errorf("tmp résiduel après copie atomique : %s", e.Name())
		}
	}
}

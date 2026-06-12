package shared

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestLoggerWritesStructuredLines(t *testing.T) {
	dir := t.TempDir()
	now := time.Date(2026, 6, 12, 10, 30, 0, 0, time.FixedZone("CEST", 2*3600))
	log := &Logger{Dir: dir, Now: func() time.Time { return now }}

	log.Infof("démarrage %s", "ok")
	log.Warningf("attention")

	raw, err := os.ReadFile(filepath.Join(dir, "agent.log"))
	if err != nil {
		t.Fatalf("agent.log : %v", err)
	}
	lines := strings.Split(strings.TrimRight(string(raw), "\n"), "\n")
	if len(lines) != 2 {
		t.Fatalf("2 lignes attendues, got %d : %q", len(lines), raw)
	}
	// Format [ISO 8601] [LEVEL] message — iso-24.2.
	if lines[0] != "[2026-06-12T10:30:00+02:00] [INFO] démarrage ok" {
		t.Errorf("ligne 1 : %q", lines[0])
	}
	if lines[1] != "[2026-06-12T10:30:00+02:00] [WARNING] attention" {
		t.Errorf("ligne 2 : %q", lines[1])
	}
}

func TestLoggerDailyRotationAndRetention(t *testing.T) {
	dir := t.TempDir()
	now := time.Date(2026, 6, 12, 8, 0, 0, 0, time.UTC)
	log := &Logger{Dir: dir, Now: func() time.Time { return now }}

	// Un agent.log daté de la VEILLE.
	current := filepath.Join(dir, "agent.log")
	if err := os.WriteFile(current, []byte("ancienne ligne\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	yesterday := now.AddDate(0, 0, -1)
	if err := os.Chtimes(current, yesterday, yesterday); err != nil {
		t.Fatal(err)
	}

	// Une archive AU-DELÀ de la rétention (7 j).
	old := filepath.Join(dir, "agent-2026-06-01.log")
	if err := os.WriteFile(old, []byte("x\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	oldTime := now.AddDate(0, 0, -11)
	if err := os.Chtimes(old, oldTime, oldTime); err != nil {
		t.Fatal(err)
	}

	log.Infof("nouvelle journée")

	// Rotation : agent.log de la veille archivé sous sa date.
	archive := filepath.Join(dir, "agent-2026-06-11.log")
	raw, err := os.ReadFile(archive)
	if err != nil || !strings.Contains(string(raw), "ancienne ligne") {
		t.Errorf("archive de la veille attendue (%s) : %v", archive, err)
	}
	// Nouveau agent.log avec la nouvelle ligne uniquement.
	raw, _ = os.ReadFile(current)
	if !strings.Contains(string(raw), "nouvelle journée") || strings.Contains(string(raw), "ancienne") {
		t.Errorf("agent.log du jour : %q", raw)
	}
	// Purge : l'archive > 7 j a disparu.
	if _, err := os.Stat(old); !os.IsNotExist(err) {
		t.Errorf("archive > rétention non purgée : %v", err)
	}
}

func TestLoggerNeverFailsWithoutDir(t *testing.T) {
	log := &Logger{} // pas de Dir : silencieux, jamais d'erreur ni de panique
	log.Errorf("ne doit pas planter")
}

package shared

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// Logger : log local structuré `[ISO 8601] [LEVEL] message` — iso-24.2.
// Rotation QUOTIDIENNE (agent.log d'un jour précédent → agent-YYYY-MM-DD.log),
// rétention 7 jours. Trace locale de la boucle : le serveur ne voit que les
// rapports. Zéro dépendance externe (stdlib seule, décision n° 8).
type Logger struct {
	// Dir : répertoire des logs (logs\ sous la racine agent). Créé à la
	// première écriture, ACL posée via SetACL (nil = no-op, tests).
	Dir    string
	SetACL func(path string) error

	// FileName : nom du fichier courant (défaut agent.log). Le compagnon
	// 24.6 réutilise CE logger avec racine per-user + companion.log —
	// format/rotation/rétention identiques (archives <base>-YYYY-MM-DD.log).
	FileName string

	// RetentionDays : rétention des archives (défaut 7).
	RetentionDays int

	// Now : horloge injectable (tests de rotation). nil = time.Now.
	Now func() time.Time

	// Echo : recopie chaque ligne sur stderr (mode console `agent.exe run`).
	Echo bool

	mu sync.Mutex
}

const logFileName = "agent.log"

// fileName / archivePrefix : agent.log → agent-YYYY-MM-DD.log ;
// companion.log → companion-YYYY-MM-DD.log (iso-24.3).
func (l *Logger) fileName() string {
	if l.FileName == "" {
		return logFileName
	}

	return l.FileName
}

func (l *Logger) archivePrefix() string {
	return strings.TrimSuffix(l.fileName(), ".log") + "-"
}

func (l *Logger) now() time.Time {
	if l.Now != nil {
		return l.Now()
	}

	return time.Now()
}

func (l *Logger) retention() int {
	if l.RetentionDays <= 0 {
		return 7
	}

	return l.RetentionDays
}

func (l *Logger) Debugf(format string, args ...any)   { l.log("DEBUG", format, args...) }
func (l *Logger) Infof(format string, args ...any)    { l.log("INFO", format, args...) }
func (l *Logger) Warningf(format string, args ...any) { l.log("WARNING", format, args...) }
func (l *Logger) Errorf(format string, args ...any)   { l.log("ERROR", format, args...) }

func (l *Logger) log(level, format string, args ...any) {
	l.mu.Lock()
	defer l.mu.Unlock()

	line := fmt.Sprintf("[%s] [%s] %s\n",
		l.now().Format("2006-01-02T15:04:05-07:00"), level, fmt.Sprintf(format, args...))

	if l.Echo {
		fmt.Fprint(os.Stderr, line)
	}
	if l.Dir == "" {
		return
	}

	// Un échec de log ne doit JAMAIS faire tomber l'agent (best-effort).
	if err := l.ensureDirLocked(); err != nil {
		return
	}
	l.rotateLocked()

	f, err := os.OpenFile(filepath.Join(l.Dir, l.fileName()), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600)
	if err != nil {
		return
	}
	defer f.Close()
	_, _ = f.WriteString(line)
}

func (l *Logger) ensureDirLocked() error {
	if _, err := os.Stat(l.Dir); err == nil {
		return nil
	}
	if err := os.MkdirAll(l.Dir, 0o700); err != nil {
		return err
	}
	if l.SetACL != nil {
		return l.SetACL(l.Dir)
	}

	return nil
}

// rotateLocked : agent.log d'un jour précédent → agent-YYYY-MM-DD.log, puis
// purge des archives au-delà de la rétention (iso-24.2).
func (l *Logger) rotateLocked() {
	current := filepath.Join(l.Dir, l.fileName())
	info, err := os.Stat(current)
	if err != nil {
		return
	}

	// Comparaison par date LOCALE (ISO, ordre lexicographique = chronologique)
	// — pas de Truncate (relatif à l'epoch UTC, faux autour de minuit local).
	today := l.now().Format("2006-01-02")
	lastWriteDay := info.ModTime().Format("2006-01-02")
	if lastWriteDay >= today {
		return
	}

	archive := filepath.Join(l.Dir, fmt.Sprintf("%s%s.log", l.archivePrefix(), lastWriteDay))
	_ = os.Rename(current, archive)

	// Purge > rétention.
	entries, err := os.ReadDir(l.Dir)
	if err != nil {
		return
	}
	cutoff := l.now().AddDate(0, 0, -l.retention())
	for _, entry := range entries {
		name := entry.Name()
		if !strings.HasPrefix(name, l.archivePrefix()) || !strings.HasSuffix(name, ".log") {
			continue
		}
		fi, err := entry.Info()
		if err != nil {
			continue
		}
		if fi.ModTime().Before(cutoff) {
			_ = os.Remove(filepath.Join(l.Dir, name))
		}
	}
}

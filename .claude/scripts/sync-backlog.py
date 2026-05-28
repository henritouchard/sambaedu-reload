#!/usr/bin/env python3
"""
Hook PostToolUse : synchronise backlog.html depuis sprint-status.yaml.
Déclenché après chaque Edit|Write. Ne fait rien si le fichier modifié
n'est pas sprint-status.yaml.
"""
import sys, json, re, os
from datetime import date

YAML_PATH = '/home/htouchard/code/irundo/codebase/sambaedu-reload/_bmad-output/implementation-artifacts/sprint-status.yaml'
HTML_PATH = '/home/htouchard/code/irundo/codebase/sambaedu-reload/_bmad-output/backlog.html'

# --- Lire l'input du hook (stdin JSON) ---
try:
    hook_input = json.load(sys.stdin)
    file_path = hook_input.get('tool_input', {}).get('file_path', '')
    if 'sprint-status.yaml' not in file_path:
        sys.exit(0)
except Exception:
    sys.exit(0)

# --- Parser sprint-status.yaml (sans dépendance pyyaml) ---
statuses = {}
in_section = False
try:
    with open(YAML_PATH) as f:
        for line in f:
            line = line.rstrip()
            if line.startswith('development_status:'):
                in_section = True
                continue
            if in_section:
                # Fin de section si ligne non-indentée et non-commentaire
                if line and not line.startswith(' ') and not line.startswith('#'):
                    break
                m = re.match(r'\s+([^#:\s][^:]*?):\s+(\S+)', line)
                if m:
                    key = m.group(1).strip()
                    val = m.group(2).strip()
                    statuses[key] = val
except Exception as e:
    print(f"sync-backlog: erreur lecture YAML: {e}", file=sys.stderr)
    sys.exit(1)

# --- Lire backlog.html ---
try:
    with open(HTML_PATH) as f:
        html = f.read()
except Exception as e:
    print(f"sync-backlog: erreur lecture HTML: {e}", file=sys.stderr)
    sys.exit(1)

# --- Mettre à jour les statuts ---
for key, status in statuses.items():
    if re.match(r'^\d+\w*-\d+-.+', key):
        # Story: "1-2-catchall-..." or "1bis-2-bootstrap-..." → id "1-2" or "1bis-2"
        m_story = re.match(r'^(\d+\w*)-(\d+)-.+', key)
        story_id = f"{m_story.group(1)}-{m_story.group(2)}"
        html = re.sub(
            r'(\{ id: "' + re.escape(story_id) + r'",.*?status: ")[^"]*(")',
            r'\g<1>' + status + r'\2',
            html
        )
    elif re.match(r'^epic-(\d+\w*)$', key):
        # Epic: "epic-1" → num 1, "epic-1bis" → num "1bis"
        num = re.match(r'^epic-(.+)$', key).group(1)
        # Handle both num: 1 (unquoted) and num: "1bis" (quoted) in HTML
        html = re.sub(
            r'(num: "?' + re.escape(num) + r'"?, title: "[^"]*", status: ")[^"]*(")',
            r'\g<1>' + status + r'\2',
            html
        )

# --- Mettre à jour la date ---
today = date.today().strftime('%Y-%m-%d')
html = re.sub(r'Mis à jour : \d{4}-\d{2}-\d{2}', f'Mis à jour : {today}', html)

# --- Écrire backlog.html ---
try:
    with open(HTML_PATH, 'w') as f:
        f.write(html)
except Exception as e:
    print(f"sync-backlog: erreur écriture HTML: {e}", file=sys.stderr)
    sys.exit(1)

print(json.dumps({"suppressOutput": True}))

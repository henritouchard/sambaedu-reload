#!/bin/bash
# =============================================================================
# build-test-extension.sh — Story 56.2 (outil de DEV/QA interne)
#
# Fabrique, SANS AUCUN privilège root, tout ce qu'il faut pour jouer le runbook
# QA de l'installation d'extensions (docs/qa/domains/extensions.md, Section 18) :
#
#   1. un paquet Debian `sambaedu-ext-hello_1.0.0_all.deb` conforme au contrat
#      de paquet `sambaedu-ext-*` (unité systemd livrée mais NI enable NI start,
#      EnvironmentFile, écoute sur 127.0.0.1:$SE5_EXT_PORT, DynamicUser) ;
#   2. un dépôt de catalogue signé conforme au format v1 de la Story 56.1 :
#      index.json (manifest `hello` de type `app`, entry_url /ext/hello, bloc
#      `install` au sha256 RÉEL du .deb) + index.json.sig + source.pub, avec une
#      paire de clés Ed25519 JETABLE générée dans le répertoire de sortie.
#
# Le dépôt se sert tel quel : `cd <out>/repo && python3 -m http.server 8099`.
#
# ⚠️ CE N'EST PAS UN LIVRABLE ÉDITEUR. L'outillage de PUBLICATION destiné aux
# développeurs tiers (génération d'index, gestion des clés, starter kit) relève
# de la Story 58.2. Ce script est un prototype interne au dépôt SE5, taillé pour
# la validation manuelle sur la VM — il ne prétend ni à la généralité, ni à la
# stabilité d'interface.
#
# Usage : scripts/dev/build-test-extension.sh [répertoire de sortie]
#         (défaut : /tmp/sambaedu-ext-hello)
# =============================================================================

set -euo pipefail

OUT="${1:-/tmp/sambaedu-ext-hello}"
KEY="hello"
PKG="sambaedu-ext-${KEY}"
VERSION="1.0.0"

for tool in dpkg-deb php python3; do
    command -v "$tool" >/dev/null 2>&1 || { echo "Outil requis manquant : $tool" >&2; exit 1; }
done

php -r 'exit(extension_loaded("sodium") ? 0 : 1);' \
    || { echo "L'extension PHP sodium est requise (signature Ed25519)." >&2; exit 1; }

rm -rf "$OUT"
mkdir -p "$OUT/build/DEBIAN" \
         "$OUT/build/usr/share/${PKG}" \
         "$OUT/build/lib/systemd/system" \
         "$OUT/repo/packages"

# ── 1. Le paquet ─────────────────────────────────────────────────────────────

cat > "$OUT/build/DEBIAN/control" <<CONTROL
Package: ${PKG}
Version: ${VERSION}
Section: web
Priority: optional
Architecture: all
Depends: python3
Maintainer: SambaEdu QA <qa@example.invalid>
Description: Extension de test « hello » pour le runbook QA SE5
 Backend minimal conforme au contrat de paquet sambaedu-ext-* : il lit son
 environnement dans /etc/sambaedu/extensions/hello.env, écoute EXCLUSIVEMENT
 sur 127.0.0.1:\$SE5_EXT_PORT et se sert sous \$SE5_EXT_BASE_PATH.
CONTROL

cat > "$OUT/build/usr/share/${PKG}/serve.py" <<'SERVE'
#!/usr/bin/env python3
"""Backend « hello » : contrat de paquet sambaedu-ext-* minimal."""
import os
from http.server import BaseHTTPRequestHandler, HTTPServer

PORT = int(os.environ.get("SE5_EXT_PORT", "8600"))
BASE = os.environ.get("SE5_EXT_BASE_PATH", "/ext/hello")
ISSUER = os.environ.get("SE5_OIDC_ISSUER", "")
CLIENT_ID = os.environ.get("SE5_OIDC_CLIENT_ID", "")


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):  # noqa: N802
        body = (
            "hello depuis l'extension de test SE5\n"
            f"base_path={BASE}\nport={PORT}\nissuer={ISSUER}\nclient_id={CLIENT_ID}\n"
            # Le secret n'est JAMAIS servi : il reste dans l'environnement.
        ).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):  # silence
        pass


if __name__ == "__main__":
    # 127.0.0.1 EXCLUSIVEMENT : l'exposition, c'est Apache (contrat de paquet).
    HTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
SERVE
chmod 0755 "$OUT/build/usr/share/${PKG}/serve.py"

cat > "$OUT/build/lib/systemd/system/${PKG}.service" <<UNIT
[Unit]
Description=Extension SE5 de test « ${KEY} »
After=network.target

[Service]
Type=simple
EnvironmentFile=/etc/sambaedu/extensions/${KEY}.env
ExecStart=/usr/bin/python3 /usr/share/${PKG}/serve.py
DynamicUser=yes
NoNewPrivileges=yes
PrivateTmp=yes
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT

# ⚠️ Contrat de paquet : le paquet n'enable/start JAMAIS son unité — c'est
# l'orchestrateur SE5 qui le fait, APRÈS avoir posé le fichier d'environnement.
cat > "$OUT/build/DEBIAN/postinst" <<'POSTINST'
#!/bin/sh
set -e
# Volontairement VIDE de tout enable/start : voir le contrat de paquet 56.2.
exit 0
POSTINST
chmod 0755 "$OUT/build/DEBIAN/postinst"

DEB="$OUT/repo/packages/${PKG}_${VERSION}_all.deb"
dpkg-deb --build --root-owner-group "$OUT/build" "$DEB" >/dev/null

SHA256="$(sha256sum "$DEB" | cut -d' ' -f1)"

# ── 2. Le dépôt signé (format de catalogue v1 — Story 56.1) ──────────────────

cat > "$OUT/repo/index.json" <<INDEX
{
  "index_version": 1,
  "name": "Dépôt de test SE5",
  "publisher": "QA SambaEdu",
  "extensions": [
    {
      "manifest_version": 1,
      "id": "${KEY}",
      "type": "app",
      "name": "Hello (test)",
      "version": "${VERSION}",
      "entry_url": "/ext/${KEY}",
      "icon": "fa-solid fa-hand",
      "publisher": "QA SambaEdu",
      "description": "Extension de test du canal d'installation (Story 56.2).",
      "scopes": ["profile"],
      "dependencies": [],
      "visibility": { "roles": ["admin", "prof"] },
      "install": {
        "channel": "deb",
        "package": "packages/${PKG}_${VERSION}_all.deb",
        "sha256": "${SHA256}",
        "redirect_paths": ["/ext/${KEY}/oidc/callback"]
      }
    }
  ]
}
INDEX

# Clé JETABLE générée ici : le dépôt de test n'a rien à voir avec les clés de
# production, et une clé privée de test ne doit jamais quitter ce répertoire.
php -r '
    $out = $argv[1];
    $pair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($pair);
    $pk = sodium_crypto_sign_publickey($pair);
    $index = file_get_contents($out . "/repo/index.json");
    file_put_contents($out . "/repo/index.json.sig", base64_encode(sodium_crypto_sign_detached($index, $sk)));
    file_put_contents($out . "/repo/source.pub", base64_encode($pk));
    file_put_contents($out . "/source.key", base64_encode($sk));
    chmod($out . "/source.key", 0600);
' "$OUT"

cat <<SUMMARY

Dépôt de test prêt.

  paquet      : $DEB
  sha256      : $SHA256
  dépôt       : $OUT/repo
  clé publique: $(cat "$OUT/repo/source.pub")
  clé privée  : $OUT/source.key  (jetable — re-signer un index altéré avec elle)

Servir le dépôt :
  cd $OUT/repo && python3 -m http.server 8099

Puis dans SE5 : /admin/extensions/sources → « Ajouter une source »
  URL : http://<ip du serveur de test>:8099
  Clé publique : (collée depuis repo/source.pub — obligatoire en http://)

Enfin :
  php artisan ext:sources:sync
  php artisan ext:install ${KEY}

SUMMARY

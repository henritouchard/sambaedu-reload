#!/bin/bash
#
# setupXsendfile.sh — Service natif des assets d'installation OS iPXE.
#
# Sur SE5, les binaires d'install OS (kernel/initrd debian-installer, sysresccd,
# clonezilla, .wim Windows...) sont servis par la route Laravel
# `GET /ipxe/os/{path}` (App\Ipxe\Http\Controllers\IpxeOsAssetController) :
# chemins versionnes en config, plus d'Alias Apache par-emplacement.
#
# Probleme : streamer ces gros fichiers a travers PHP-FPM mobilise un worker
# tout le transfert et bloque pres de la fin (l'initrd ~355 Mo coincait a 99%
# cote iPXE). Solution : le controller pose un en-tete `X-Sendfile` et delegue
# l'envoi des octets a Apache via mod_xsendfile (syscall sendfile — rapide,
# zero PHP, supporte Range). C'est le comportement PAR DEFAUT de SE5
# (config `ipxe.actions.os_assets.xsendfile_enabled` = true).
#
# Ce script (idempotent, re-executable) :
#   1. installe + active mod_xsendfile,
#   2. pose `XSendFile On` + `XSendFilePath <racine assets OS>` dans le vhost
#      reload (sambaedu.conf) s'ils sont absents,
#   3. configtest + reload Apache.
#
# Appele par install.sh (et setupApache.sh) APRES (re)generation du vhost.
#
# Usage : sudo bash /var/www/sambaedu-reload/scripts/setupXsendfile.sh
#
set -euo pipefail

VHOST="/etc/apache2/sites-enabled/sambaedu.conf"
# DOIT correspondre a la racine config('ipxe.actions.os_assets.roots')
# (defaut config/ipxe.php). Override possible via IPXE_OS_ASSETS_ROOT.
OS_ASSETS_ROOT="${IPXE_OS_ASSETS_ROOT:-/var/sambaedu/unattended/install/os}"

echo "=== setupXsendfile.sh — service natif assets OS iPXE (X-Sendfile) ==="

if [ "$(id -u)" -ne 0 ]; then
  echo "ERREUR : ce script doit etre execute en root." >&2
  exit 1
fi

# ── 1. Module mod_xsendfile ─────────────────────────────────────────────────
if ! apache2ctl -M 2>/dev/null | grep -qi 'xsendfile_module'; then
  echo "[*] Installation de libapache2-mod-xsendfile..."
  DEBIAN_FRONTEND=noninteractive apt-get install -y libapache2-mod-xsendfile
fi
a2enmod xsendfile >/dev/null 2>&1 || true

# ── 2. Directives XSendFile dans le vhost reload (idempotent) ───────────────
if [ ! -f "$VHOST" ]; then
  echo "[!] $VHOST introuvable — vhost reload pas encore genere ? (skip)" >&2
  exit 0
fi

if grep -q 'XSendFile On' "$VHOST"; then
  echo "[=] XSendFile deja present dans $VHOST."
else
  echo "[*] Ajout XSendFile On / XSendFilePath ($OS_ASSETS_ROOT) dans $VHOST..."
  awk -v root="$OS_ASSETS_ROOT" '
    { print }
    /DocumentRoot \/var\/www\/sambaedu-reload\/public/ && !done {
      print ""
      print "    # Service natif des assets OS iPXE : la route Laravel"
      print "    # /ipxe/os/{path} pose un en-tete X-Sendfile -> Apache envoie le"
      print "    # fichier via sendfile (pas de streaming PHP-FPM). XSendFilePath"
      print "    # whiteliste les repertoires autorises (securite)."
      print "    XSendFile On"
      print "    XSendFilePath " root
      done = 1
    }
  ' "$VHOST" > "$VHOST.tmp" && mv "$VHOST.tmp" "$VHOST"

  if ! grep -q 'XSendFile On' "$VHOST"; then
    echo "[!] Echec injection (ligne DocumentRoot reload introuvable dans $VHOST)." >&2
    exit 1
  fi
fi

# ── 3. Validation + reload ──────────────────────────────────────────────────
apache2ctl configtest
systemctl reload apache2 2>/dev/null || apache2ctl graceful
echo "[OK] X-Sendfile actif — assets OS iPXE servis nativement par Apache."

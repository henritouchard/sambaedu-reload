#!/bin/bash
# Notifie SE5 d'un événement de bail DHCP pour maintenir les enregistrements
# DNS (A) de l'AD.
#
# Lancé par dhcpd (`on commit` / `on release` / `on expiry`, cf. dhcpd.conf
# généré par make_dhcpd_conf.sh), ou à la demande.
#
# usage :
#   /usr/share/sambaedu/sbin/dhcp-dyndns.sh <action> <ip> <mac> <host>
#
# Story 8.4 : la cible est l'endpoint NATIF `/dhcp/dnsupdate` (le legacy
# `dhcp/dnsupdate.php` répondait 500 depuis l'extinction du canal SE4). Le
# serveur est idempotent : un renouvellement de bail sur une IP inchangée
# n'écrit RIEN dans le DNS — inutile de filtrer côté script.
#
# Contraintes dhcpd (à préserver) :
#   - appel en arrière-plan : dhcpd ne doit jamais être bloqué par le réseau ;
#   - `exit 0` inconditionnel : une sortie non-zéro perturberait le traitement
#     du bail alors que le DNS est un effet de bord non critique.
#
. /usr/share/sambaedu/includes/config.inc.sh

# `--form-string` et JAMAIS `-F` : avec `-F`, curl interprète une valeur
# commençant par `@` ou `<` comme un chemin de fichier à lire. Or `$4` est le
# `client-hostname` annoncé librement par n'importe quel client DHCP du LAN, et
# ce script tourne en ROOT sous dhcpd : un client nommé `</etc/shadow` ferait
# poster le contenu du fichier. `--form-string` traite toujours la valeur comme
# une chaîne littérale.
#
# Cible LOOPBACK + en-tête `Host` : dhcpd est co-localisé avec SE5, donc
# l'appel n'a aucune raison de sortir sur le réseau. La garde serveur
# (`dhcp.server.request`) autorise le loopback sans aucun réglage préalable —
# viser le nom d'hôte ferait présenter l'IP LAN et exigerait une allowlist.
#
# `--max-time` : un backend injoignable ne doit pas laisser traîner un curl
# détaché à chaque bail. `se4_key` n'est plus envoyé (la garde d'origine
# serveur remplace le secret partagé du legacy).
/usr/bin/curl -s -f --max-time 10 \
    -H "Host: ${config_se4fs_name}" \
    --form-string "action=$1" \
    --form-string "ip=$2" \
    --form-string "mac=$3" \
    --form-string "name=$4" \
    "http://127.0.0.1/dhcp/dnsupdate" >/dev/null 2>&1 &

exit 0

#!/bin/bash
#startup

# script de configuration des applications Linux

id=8a118d6979c527bd242a1e3fc3cb09cb

SE4FS=se4fs

URL=http://se4fs.localdev.fr

# script[firefox]
echo "Mise en place config Firefox"
if [ ! -d "/etc/firefox" ]; then
    mkdir -p /etc/firefox/policies
fi
if [ -f "/etc/firefox/policies/policies.json" ]; then
    rm -f "/etc/firefox/policies/policies.json"
fi
/usr/bin/curl -o /etc/firefox/policies/policies.json -F "id=$id" -F "os=linux" http://se4fs.localdev.fr/gpo/firefox_out.php
# fin de la configuration de firefox

# script[folders]
# correction des montages pour bullseye #

sed -i "s/vers=3\.1\.1,sec=krb5/vers=3.1.1/g" /etc/security/pam_mount.conf.xml

# script[glpi]
echo "server = http://glpi.test.fr
tag = 0000000x
" > /etc/glpi-agent/conf.d/local.cfg

# script[reseau]
echo "mise en place de la conf reseau networkmanager"
scripts=$(/usr/bin/curl -s -F "id=${id}" -F "action=startup" -F "os=linux" http://se4fs.localdev.fr/gpo/network_out.php)
eval "$scripts"

# script[reseau]
# Script pour récupérer la vitesse réseau et les informations LLDP
# et les envoyer à un serveur distant
# Nécessite les droits root pour lldpctl

# Configuration
LOG_URL="http://se4fs.localdev.fr/logs.php"

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Fonction pour afficher un titre
print_header() {
    echo -e "\n${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}\n"
}

# Vérifier si lldpd est installé
check_lldp() {
    if ! command -v lldpctl &> /dev/null; then
        echo -e "${RED}Erreur: lldpctl n'est pas installé${NC}"
        echo "Installez-le avec: sudo apt install lldpd (Debian/Ubuntu) ou sudo yum install lldpd (RHEL/CentOS)"
        return 1
    fi
    return 0
}

# Récupérer l'interface par défaut si aucune n'est spécifiée
get_default_interface() {
    ip route | grep default | awk '{print $5}' | head -n1
}

# Récupérer la vitesse de l'interface (sans unité)
get_speed() {
    local interface=$1
    local speed=""
    
    if [ -f /sys/class/net/"$interface"/speed ]; then
        speed=$(cat /sys/class/net/"$interface"/speed 2>/dev/null)
        if [ "$speed" = "-1" ] || [ -z "$speed" ]; then
            speed=""
        fi
    fi
    
    echo "$speed"
}

# Extraire une valeur LLDP spécifique
extract_lldp_value() {
    local lldp_output=$1
    local key=$2
    local value=""
    
    case "$key" in
        "SysName")
            value=$(echo "$lldp_output" | grep -oP '(?<=SysName:\s{2}).*' | head -n1 | xargs)
            ;;
        "PortDescr")
            value=$(echo "$lldp_output" | grep -oP '(?<=PortDescr:\s{2}).*' | head -n1 | xargs)
            ;;
        "MgmtIP")
            value=$(echo "$lldp_output" | grep -oP '(?<=MgmtIP:\s{2}).*' | head -n1 | xargs)
            # Alternative si MgmtIP n'est pas trouvé
            if [ -z "$value" ]; then
                value=$(echo "$lldp_output" | grep -oP 'IPv4:\s+\K[0-9.]+' | head -n1)
            fi
            ;;
        "VLAN")
            # Extraire le VLAN sans le préfixe "pvid"
            value=$(echo "$lldp_output" | grep -i "VLAN" | grep -oP '(?<=VLAN:\s{2}|pvid:\s*)\d+' | head -n1)
            # Alternative: chercher juste un nombre après VLAN
            if [ -z "$value" ]; then
                value=$(echo "$lldp_output" | grep -i "VLAN" | grep -oP '\d+' | head -n1)
            fi
            ;;
    esac
    
    echo "$value"
}
# Collecter et envoyer les données
collect_and_send() {
    local interface=$1
    
    echo -e "${BLUE}Collecte des données pour $interface...${NC}\n"
    
    # Récupérer la vitesse
    local speed=$(get_speed "$interface")
    
    if [ -z "$speed" ]; then
        echo -e "${RED}Erreur: Impossible de récupérer la vitesse de l'interface${NC}"
        return 1
    fi
    
    echo -e "Vitesse: ${GREEN}${speed} Mbps${NC}"
    
    # Récupérer les informations LLDP
    if ! check_lldp; then
        return 1
    fi
    
    if [ "$EUID" -ne 0 ]; then 
        echo -e "${RED}Erreur: Ce script doit être exécuté avec sudo pour accéder aux informations LLDP${NC}"
        return 1
    fi
    
    local lldp_output=$(lldpctl "$interface" 2>/dev/null)
    
    if [ -z "$lldp_output" ]; then
        echo -e "${RED}Erreur: Aucune information LLDP disponible pour $interface${NC}"
        echo "Vérifiez que:"
        echo "  - Le service lldpd est démarré: sudo systemctl start lldpd"
        echo "  - Le switch supporte LLDP"
        echo "  - Le port du switch a LLDP activé"
        return 1
    fi
    
    # Extraire les informations
    local sysname=$(extract_lldp_value "$lldp_output" "SysName")
    local portdescr=$(extract_lldp_value "$lldp_output" "PortDescr")
    local mgmtip=$(extract_lldp_value "$lldp_output" "MgmtIP")
    local vlan=$(extract_lldp_value "$lldp_output" "VLAN")
    
    # Afficher les informations collectées
    echo -e "SysName: ${GREEN}${sysname}${NC}"
    echo -e "PortDescr: ${GREEN}${portdescr}${NC}"
    echo -e "MgmtIP: ${GREEN}${mgmtip}${NC}"
    echo -e "VLAN: ${GREEN}${vlan}${NC}"
    
    # Vérifier que toutes les données sont présentes
    if [ -z "$sysname" ] || [ -z "$portdescr" ]; then
        echo -e "\n${YELLOW}Attention: Certaines informations LLDP sont manquantes${NC}"
    fi
    
    # Envoyer les données en POST
    echo -e "\n${BLUE}Envoi des données à $LOG_URL...${NC}"
    
    local response=$(curl -s -w "\n%{http_code}" -X POST "$LOG_URL" \
        -d "action=lldp" \
        -d "os=linux" \
        -d "computername=$(hostname)" \
        -d "speed=$speed" \
        -d "switchName=$sysname" \
        -d "port=$portdescr" \
        -d "switchIP=$mgmtip" \
        -d "vlan=$vlan" 2>&1)
    
    local http_code=$(echo "$response" | tail -n1)
    local body=$(echo "$response" | sed '$d')
    
    if [ "$http_code" = "200" ]; then
        echo -e "${GREEN}✓ Données envoyées avec succès (HTTP $http_code)${NC}"
        if [ -n "$body" ]; then
            echo -e "Réponse du serveur: $body"
        fi
    else
        echo -e "${RED}✗ Erreur lors de l'envoi (HTTP $http_code)${NC}"
        if [ -n "$body" ]; then
            echo -e "Réponse du serveur: $body"
        fi
        return 1
    fi
}

# Fonction principale
main() {
    local interface=""
    interface=$(get_default_interface)
    if [ -z "$interface" ]; then
        echo -e "${RED}Erreur: Impossible de déterminer l'interface par défaut${NC}"
    fi
    # Vérifier que l'interface existe
    if ! ip link show "$interface" &> /dev/null; then
        echo -e "${RED}Erreur: Interface $interface non trouvée${NC}"
        echo "Interfaces disponibles:"
        ls /sys/class/net/ | grep -v lo
    fi
    
    # Collecter et envoyer les données
    print_header "Interface: $interface"
    collect_and_send "$interface"
}

# Exécution
main 

# script[thunderbird]
echo "Mise en place config thunderbird"
if [ ! -d "/etc/thunderbird" ]; then
   mkdir -p /etc/thunderbird/policies
fi
if [ -f "/etc/thunderbird/policies/policies.json" ]; then
   rm  -f "/etc/thunderbird/policies/policies.json"
fi
/usr/bin/curl -o /etc/thunderbird/policies/policies.json -F "id=$id" http://se4fs.localdev.fr/gpo/thunderbird_out.php
# fin de la configuration de thunderbird

# script[veyon]
echo "Mise en place config veyon"
if [ -f '/usr/bin/veyon-cli' ]; then
    /usr/bin/veyon-cli config clear
    curl -o /tmp/global.json -F "id=$id" http://se4fs.localdev.fr/gpo/veyon_out.php
    curl -o /tmp/licence.vlf -F "licence=1" http://se4fs.localdev.fr/gpo/veyon_out.php
    if [ -f /tmp/global.json ]; then
        /usr/bin/veyon-cli config import /tmp/global.json
    fi
# Gestion d'une eventuelle license de plugin
    if [ -f /tmp/licence.vlf ]; then
        /usr/bin/veyon-cli licensing add /tmp/licence.vlf
    fi
# Redemarrage du service
#   /usr/bin/veyon-cli service restart
fi
# fin de conf veyon

# script[wallpaper]
echo "Mise en place du splash"
if [ ! -d "/usr/share/plymouth/themes/sambaedu" ]; then
    mkdir -p /usr/share/plymouth/themes/sambaedu
fi
/usr/bin/curl -o /usr/share/plymouth/themes/sambaedu/lockscreen.png -F "action=lockscreen" -F "format=png" -F "id=$id" http://se4fs.localdev.fr/gpo/wallpaper_out.php || true
if [ -d /run/ltsp/client ]; then
    /usr/share/sambaedu/scripts/set-gdm-wallpaper.sh /usr/share/plymouth/themes/sambaedu/lockscreen.png || true
fi

h=$(identify -format "%#" /usr/share/plymouth/themes/sambaedu/lockscreen.png)
if [ "$(cat /etc/sambaedu/applications/lockscreen.hash)" != "$h" ] ; then
    echo $h > "/etc/sambaedu/applications/lockscreen.hash"
    # md5sum  /usr/share/plymouth/themes/sambaedu/lockscreen.png > "/etc/sambaedu/applications/lockscreen.md5"
    plymouth-set-default-theme -R sambaedu || true
    sed -i "s!^#background=.*$!background=/usr/share/plymouth/themes/sambaedu/lockscreen.png!" /etc/lightdm/lightdm-gtk-greeter.conf || true
    /usr/share/sambaedu/scripts/set-gdm-wallpaper.sh /usr/share/plymouth/themes/sambaedu/lockscreen.png || true
    # conf splashscreen
    grep -q "^GRUB_TIMEOUT=5" /etc/default/grub && sed -i "s/^GRUB_TIMEOUT=5/GRUB_TIMEOUT=0/" /etc/default/grub || true
    grep -q "quiet splash" /etc/default/grub || sed -i "s/quiet/quiet splash/" /etc/default/grub || true
    grep -q "GRUB_GFXMODE=1024x786" /etc/default/grub || echo "GRUB_GFXMODE=1024x786" >> /etc/default/grub || true
    grep -q "GRUB_BACKGROUND=" /etc/default/grub || echo "GRUB_BACKGROUND=\"/usr/share/plymouth/themes/sambaedu/lockscreen.png\"" >> /etc/default/grub || true
    update-grub
fi
# fin de la configuration du splash

# script[wine]
echo "Mise en place wine"
dpkg --add-architecture i386
# on fait un miroir sur deb.sambaedu.org, donc plus la peine de définir de dépôt tiers !
if [ -f "/etc/apt/sources.list.d/winehq.list" ]; then
	mv "/etc/apt/sources.list.d/winehq.list" "/etc/apt/sources.list.d/winehq.list.save"
fi
if [ -z "$config_se4install_name" ]; then
        set_config sambaedu se4install_name se4install
fi
# fin de conf wine

# script[wpkg]
echo "Installation des logiciels via wpkg"
#packages=$(dpkg-query -W --showformat '${binary:Package} ')
packages=$(curl -s -F "id=$id"  http://se4fs.localdev.fr/wpkg/linux_out.php)
packages+=" $local_packages"
OLDIFS=$IFS
IFS=" "
for p in $packages; do
    if apt-cache policy $p 2>/dev/null | grep -q "[^N:]"; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q $p || true
    fi    
done
#DEBIAN_FRONTEND=noninteractive apt-get full-upgrade -y -q
DEBIAN_FRONTEND=noninteractive apt-get autoremove -y -q
IFS=$OLDIFS
#

/usr/bin/curl -s -F "id=8a118d6979c527bd242a1e3fc3cb09cb" -F "action=startup" -F "os=linux" -F "ret=0" http://se4fs.localdev.fr/gpo/applications.php

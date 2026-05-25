# Script pour récupérer la vitesse réseau et les informations LLDP
# et les envoyer à un serveur distant
# Nécessite les droits root pour lldpctl

# Configuration
LOG_URL="http://###_SE4FS_NAME_###.###_DOMAIN_###/logs.php"

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

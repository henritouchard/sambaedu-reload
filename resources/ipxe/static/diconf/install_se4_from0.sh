#!/bin/bash
# Projet SambaEdu - distribué selon la licence GPL
# Script permettant l'installation automatisée depuis 0
# Auteurs : sambaedu.org
# Version 2024-04

#Couleurs
COLTITRE="\033[1;35m"       # Rose
COLDEFAUT="\033[0;33m"      # Brun-jaune
COLCMD="\033[1;37m\c"       # Blanc
COLERREUR="\033[1;31m"      # Rouge
COLTXT="\033[0;37m"         # Gris
COLINFO="\033[0;36m\c"      # Cyan
COLPARTIE="\033[1;34m\c"    # Bleu

function usage()
{
echo "Script permettant la configuration et l'installation de SE4"
}


if [ "$1" = "--help" -o "$1" = "-h" ]
then
    usage
    echo "Usage : pas d'option"
    exit
fi

# Test presence whiptail
function install_whiptail()
{
if [ -z "$dialog_box" ];then
    choose_proxy
    show_part "Installation de whiptail"
    apt -q update
    apt install whiptail -y
fi
}


# Erreur on quitte !
function erreur()
{
echo -e "$COLERREUR"
echo "ERREUR!"
echo -e "$1"
echo -e "$COLTXT"
exit 1
}


# Demande de quitter
function quit_on_choice()
{
echo -e "$COLERREUR"
echo "Arrêt du script !"
echo -e "$1"
echo -e "$COLTXT"
exit 1
}


# Fonction de test d'erreur et on quitte
function quit_on_error()
{
if [ "$?" != "0" ]; then
    echo -e "$COLERREUR"
    echo "$1"
    echo "Attention "
    echo -e "la dernière commande a envoyé une erreur critique pour la suite !\nImpossible de poursuivre"
    echo -e "$COLTXT"
    exit 1
fi
}


# Affichage de la partie actuelle
function show_part()
{
echo ""
echo -e "$COLPARTIE"
echo -e "--------"
echo "$1"
echo -e "-------- $COLTXT"
# sleep 1
}


# Affichage d'une info
function show_info()
{
echo -e "$COLINFO"
echo -e "$1 $COLTXT"
# sleep 1
}


# Fonction permettant de poser la question s'il faut poursuivre ou quitter
function poursuivre()
{
echo
reponse=""
while [ "$reponse" != "o" -a "$reponse" != "O" -a "$reponse" != "n" ]
do
    echo -e "$COLTXT"
    echo -e "Peut-on poursuivre? (${COLCHOIX}O/n${COLTXL}) $COLSAISIE"
    read -t 40 reponse
    echo -e "$COLTXT"
    if [ -z "$reponse" ]; then
            reponse="o"
    fi
done
if [ "$reponse" != "o" -a "$reponse" != "O" ]; then
        quit_on_choice "Abandon!"
fi
}


function dialog_error_style()
{
if [ -z "$4" ]; then
    large="13"
else
    large="$4"
fi

NEWT_COLORS='
 window=,red
 border=white,red
 textbox=white,red
 button=black,white' $dialog_box --backtitle "$1" --title "$2" --msgbox "$3" $large 70
}


# Fonction de verification d'erreur avec possibilité de continuer
function check_error()
{
if [ "$?" != "0" ]; then
    echo -e "$COLERREUR"
    echo "Attention "
    echo -e "la dernière commande a envoyé une erreur !"
    echo -e "$1"
    echo -e "$COLTXT"
    poursuivre
fi
}


# Mise en place du proxy
function set_proxy()
{
unset http_proxy https_proxy ftp_proxy
profile_file="/etc/profile"
wgetrc_file="/etc/wgetrc"
# nettoyage
sed -i '/^http_proxy=.*/d' $profile_file
sed -i '/^https_proxy=.*/d' $profile_file
sed -i '/^ftp_proxy=.*/d' $profile_file
sed -i '/.*http_proxy.*/d' $profile_file
sed -i '/^http_proxy = .*/d' $wgetrc_file
sed -i '/^https_proxy = .*/d' $wgetrc_file
# mise en place
if [ -n "$proxy_address" ]; then
    server_proxy="1"
    proxy_url="http://$proxy_address:$proxy_port"
    echo "http_proxy=\"http://$proxy_address:$proxy_port\"" >> $profile_file
    echo "https_proxy=\"http://$proxy_address:$proxy_port\"" >> $profile_file
    echo "ftp_proxy=\"http://$proxy_address:$proxy_port\"" >> $profile_file
    echo "export http_proxy https_proxy ftp_proxy" >> $profile_file
    echo "http_proxy = http://$proxy_address:$proxy_port" >> $wgetrc_file
    echo "https_proxy = http://$proxy_address:$proxy_port" >> $wgetrc_file
    # relecture
    http_proxy="http://$proxy_address:$proxy_port"
    https_proxy="http://$proxy_address:$proxy_port"
    ftp_proxy="http://$proxy_address:$proxy_port"
fi
export http_proxy https_proxy ftp_proxy
}


function set_bashrc()
{
cp $dir_preseed/bashrc /root/.bashrc
}


function show_title()
{
backtitle="Projet SambaÉdu - https://www.sambaedu.org"

welcome_title="Installation de SambaEdu 4"
welcome_text="Bienvenue dans l'outil d'installation de SambaEdu 4.

SambaEdu est un projet libre sous licence GPL vivant de la collaboration active des différents contributeurs issus de différentes académies.

Ce programme vous permettra de lancer les différents outils en vue d'une installation de Sambaedu 4 depuis 0."

$dialog_box  --backtitle "$backtitle" --title "$welcome_title" --msgbox "$welcome_text" 18 70
}


function show_conf_end()
{
conf_end_title="Préconfiguration terminée"
conf_end_text="Vous devez maintenant démarrer l'installation du SE4AD avant de poursuivre en procédant comme suit :

- Démarrez la machine en bootant sur le réseau et choisissez l'installation du SE4AD.
- L'ensemble de l'installation sera automatique.

Une fois cette machine redémarrée, mais pas avant, vous pourrez revenir sur ce serveur et valider ce formulaire afin de finaliser l'installation."
$dialog_box  --backtitle "$backtitle" --title "$conf_end_title" --msgbox "$conf_end_text" 18 70

while [ "$confirm" != "yes" ]
do
    conf_end_confirm_title="Installation du SE4AD terminée ?"
    conf_end_confirm_text="Veuillez confirmer que le SE4AD est désormais en état de fonctionnement pour finaliser l'installation du se4fs"
    if ($dialog_box --backtitle "$backtitle" --title "$conf_end_confirm_title" --yesno "$conf_end_confirm_text" 15 60) then
        confirm="yes"
    else
        confirm="no"
    fi
done
}


function show_end_reboot() {
end_confirm_title="Installation terminée"
end_confirm_text="Votre serveur doit rebooter afin de relancer les services nécessaires. Redémarrer maintenant ?"
if ($dialog_box --backtitle "$backtitle" --title "$end_confirm_title" --yesno "$end_confirm_text" 15 60) then
    show_info "Redémarrage !"
    reboot
    else
        show_info "Attention : Le serveur ne sera fonctionnel qu'après redémarrage."
fi
}


function choose_proxy()
{
choose_proxy_title="Utilisation d'un proxy ?"
choose_proxy_text="Un proxy est-il présent sur votre réseau"
if ($dialog_box --backtitle "$backtitle" --title "$choose_proxy_title" --yesno "$choose_proxy_text" 15 60) then
    reponse="yes"
else
    reponse="no"
fi

if [ "$reponse" == "yes" ]; then
    $dialog_box --backtitle "$backtitle" --title "configuration du proxy" --inputbox "Veuillez saisir l'adresse IP du proxy.\nExemple : 172.16.0.1" 15 70 $proxy_address 2>$tempfile || erreur "Annulation"
    proxy_address="$(cat $tempfile)"

    $dialog_box --backtitle "$backtitle" --title "configuration du proxy" --inputbox "Veuillez saisir le port découte du proxy.\nExemple : 3128" 15 70 "3128" 2>$tempfile || erreur "Annulation"
    proxy_port="$(cat $tempfile)"

fi
set_proxy
}


function check_proxy()
{
show_info "Mise à jour des dépots "
apt -qq update
apt install --quiet --assume-yes curl gnupg
if curl --connect-timeout 3 -s https://sambaedu.org >/dev/null
then
   cnx_https="ok"
else
    if [ -n "$proxy_address" ]; then
        dialog_error_style "$backtitle" "Proxy obsolète !!" "Le proxy du réseau semble incompatible avec les standards modernes exigés par https Contactez la DSI !! - Abandon "
        exit 1
    fi
fi
}


# Fonction génération du sources.list SE4
function gensourcese4()
{
show_part "Génération des sources SE4"
show_info "Ajout de la signature du dépot SE4"
wget -O /etc/apt/trusted.gpg.d/sambaedu.gpg http://deb.sambaedu.org/debian/sambaedu.gpg.key
if [ "$?" != "0" ]; then
    echo -e "$COLERREUR"
    echo "Attention "
    echo -e "Impossible d'ajouter la signature du dépot. Le dépot ne sera pas signé"
    trusted_yes=" [trusted=yes]"
    poursuivre
fi
#distrib=$(lsb_release -cs)
. /etc/os-release
cat >/etc/apt/sources.list.d/se4.list <<END
# sources pour SambaEdu4
deb$trusted_yes http://deb.sambaedu.org/debian $VERSION_CODENAME $branche
END
apt -q update
}


# Fonction permettant de se connecter ssh root sur se4-FS
function permit_ssh_root()
{
grep -q "^PermitRootLogin yes" /etc/ssh/sshd_config || echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

systemctl restart ssh
}


# Fonction installation des paquets de base
function install_config_base()
{
show_info "Mise à jour des dépots et upgrade si necessaire, quelques mn de patience..."
echo -e "$COLCMD"
# tput reset
apt -qq update
apt upgrade --quiet --assume-yes

show_info "installation des paquets prioritaires..."
echo -e "$COLCMD"
prim_packages="ssh wget gnupg makepasswd curl sambaedu-config"
apt install --quiet --assume-yes $prim_packages
quit_on_error
source /usr/share/sambaedu/includes/utils.inc.sh
permit_ssh_root
install_backports $VERSION_CODENAME
apt -qq update
echo -e "$COLTXT"
}


# Fonction génération des fichiers /etc/hosts et /etc/hostname
function write_hostconf()
{
cat >/etc/hosts <<END
127.0.0.1 localhost
::1 localhost ip6-localhost ip6-loopback
ff02::1 ip6-allnodes
ff02::2 ip6-allrouters
$se4fs_ip $se4fs_name.$domain $se4fs_name
END

cat >/etc/hostname <<END
$se4fs_name
END
}


function disable_ipv6()
{
if ! grep -q "#disable_ipv6" /etc/sysctl.conf; then
    echo "#disable_ipv6
    # désactivation de ipv6 pour toutes les interfaces
    net.ipv6.conf.all.disable_ipv6 = 1

    # désactivation de l’auto configuration pour toutes les interfaces
    net.ipv6.conf.all.autoconf = 0

    # désactivation de ipv6 pour les nouvelles interfaces (ex:si ajout de carte réseau)
    net.ipv6.conf.default.disable_ipv6 = 1

    # désactivation de l’auto configuration pour les nouvelles interfaces
    net.ipv6.conf.default.autoconf = 0
    " >> /etc/sysctl.conf
    sysctl -p
fi
}


# confirmation de la conf du lan
function conf_network()
{
my_network
# $my_gateway $my_interface $my_mask $my_cdr $my_broadcast $my_address $my_network $my_hostname $my_fqdn $my_dnsserver $my_domain $my_proxy
se4fs_ip="$my_address"
se4fs_network="$my_network"
se4fs_bcast="$my_broadcast"
se4fs_gw="$my_gateway"
se4fs_mask="$my_mask"

}


# Fonction de preconfig se4-AD
function preconf_se4ad()
{
se4ad_lan_title="Configuration du futur SE4-AD"
if [ ! -e "/bin/lsblk" ]; then
    apt-get install util-linux
fi
sd_detect="$(lsblk -n -o "NAME,TYPE" | grep -v fd0 | sort | grep disk | head -n1 | cut -d " " -f1)"

reponse=""
details="no"
se4ad_ip_cut="$(echo "$se4fs_ip"  | cut -d . -f1-3)."
se4ad_mask="$se4fs_mask"
se4ad_network="$se4fs_network"
se4ad_bcast="$se4fs_bcast"
se4ad_gw="$se4fs_gw"
while [ "$reponse" != "yes" ]
do
    se4ad_boot_disk_txt="** Nom du disque sur lequel le système sera installé **

Indiquer le disque sur lequel le système sera installé. Le plus souvent il s'agira de /dev/sda mais cela peut être différent notamment sur Xen"
    $dialog_box --backtitle "$backtitle" --title "$se4ad_lan_title" --inputbox "$se4ad_boot_disk_txt" 13 70 "/dev/$sd_detect" 2>$tempfile || erreur "Annulation"
    se4ad_boot_disk=$(cat $tempfile)

    $dialog_box --backtitle "$backtitle" --title "$se4ad_lan_title" --inputbox "Saisir l'IP du SE4-AD" 12 70 $se4ad_ip_cut 2>$tempfile || erreur "Annulation"
    se4ad_ip=$(cat $tempfile)

    if [ "$se4ad_ip" = "$se4ad_ip_cut" ]; then
        dialog_error_style "$backtitle" "$se4ad_lan_title" "$se4ad_ip_cut est une saisie invalide !!"
        continue
    fi

    if [ "$details" != "no" ]; then
        $dialog_box --backtitle "$backtitle" --title "$se4ad_lan_title" --inputbox "Saisir le Masque sous réseau" 12 70 $se4fs_mask 2>$tempfile || erreur "Annulation"
        se4ad_mask=$(cat $tempfile)

        $dialog_box --backtitle "$backtitle" --title "$se4ad_lan_title" --inputbox "Saisir l'Adresse de base du réseau" 12 70 $se4fs_network 2>$tempfile || erreur "Annulation"
        se4ad_network=$(cat $tempfile)

        $dialog_box --backtitle "$backtitle" --title "$se4ad_lan_title" --inputbox "Saisir l'Adresse de broadcast" 12 70 $se4fs_bcast 2>$tempfile || erreur "Annulation"
        se4ad_bcast=$(cat $tempfile)

        $dialog_box --backtitle "$backtitle" --title "$se4ad_lan_title" --inputbox "Saisir l'Adresse de la passerelle" 12 70 $se4fs_gw 2>$tempfile || erreur "Annulation"
        se4ad_gw=$(cat $tempfile)
    fi
    details="yes"
    samba_domain_check="no"

    mirror_name_title="Miroir Debian à utiliser pour l'installation"
    $dialog_box --backtitle "$backtitle" --title "$mirror_name_title" --inputbox "Confirmer le nom du miroir à utiliser ou bien saisir l'adresse de votre miroir local si vous en avez un" 12 70 deb.debian.org 2>$tempfile || erreur "Annulation"
    mirror_name=$(cat $tempfile)

# se4ad_name_title="Nom du SE4-AD"
# $dialog_box --backtitle "$backtitle" --title "$se4ad_name_title" --inputbox "Saisir le Nom de la machine SE4-AD" 12 70 se4ad 2>$tempfile || erreur "Annulation"
# se4ad_name=$(cat $tempfile)

    choice_domain_title="Important - Nom de domaine AD"
    choice_domain_text="Sur un domaine AD, le serveur de domaine gère le DNS. Le choix du nom de domaine est donc primordial
Il est composé de plusieurs parties : le nom de domaine samba suivi de son suffixe, séparés par un point.

Exemple de domaine AD : \"diderot.org\"
* le domaine samba serait \"diderot\" et le suffixe \".org\"

ATTENTION :
* le domaine samba ne doit en aucun cas dépasser 15 caractères.
* Les domaines AD du type .lan ou .local sont à proscrire"
    NEWT_COLORS='window=,red'
    domain="$(hostname -d)"
    while [ "$samba_domain_check" != "ok" ]
    do
        color="color15"
        NEWT_COLORS="window=,$color border=black,$color textbox=black,$color" $dialog_box --backtitle "$backtitle" --title "$choice_domain_title" --inputbox "$choice_domain_text" 23 80 $my_domain 2>$tempfile || erreur "Annulation"
        domain="$(cat $tempfile)"
        samba_domain="$(echo "$domain" | cut -d"." -f1)"
        domain_chk="$(echo $domain | grep -E ".lan|.local")"
        samba_domain_size="${#samba_domain}"
        if [ $samba_domain_size -gt 15 ] || [ -n "$domain_chk" ] ; then
        NEWT_COLORS='
window=,red
border=white,red
textbox=white,red
button=black,white' $dialog_box --backtitle "$backtitle" --title "$se4fs_partman_title" --msgbox "Erreur : $samba_domain dépasse 15 caractères ou $domain est incorrect, merci de modifier votre saisie" 13 70
        continue
        else
            samba_domain_check="ok"
        fi
    done
    confirm_title="Récapitulatif de la configuration prévue"
    confirm_txt="Disque à utiliser : $se4ad_boot_disk

IP :            $se4ad_ip
Masque :        $se4ad_mask
Réseau :        $se4ad_network
Broadcast :     $se4ad_bcast
Passerelle :    $se4ad_gw
Miroir debian : $mirror_name
Serveur Proxy : $proxy_address sur le port $proxy_port

Nom :           $se4ad_name

Nom de domaine AD saisi : $domain
Nom de domaine samba :    $samba_domain

Confirmer l'enregistrement de cette configuration ?"

    if ($dialog_box --backtitle "$backtitle" --title "$confirm_title" --yesno "$confirm_txt" 22 60) then
        reponse="yes"
    else
        reponse="no"
    fi
done
}


# Fonction écriture fichier de conf sambaedu
function write_sambaedu_config()
{
cat > $se4fs_config <<END
## Params du futur SE4-AD ##
## Adresse IP du futur SE4-AD ##
se4ad_ip="$se4ad_ip"
se4ad_mask="$se4ad_mask"
se4ad_gw="$se4ad_gw"
se4ad_name="se4ad"
## Miroir debian ##
mirror_name="$mirror_name"
## Params du futur SE4-FS et domaine##
se4fs_ip="$se4fs_ip"
se4fs_name="se4fs"
samba_domain="$samba_domain"
domain="$domain"
nameserver="$my_dnsserver"
## params annuaire AD##
ldap_port="636"
ldap_admin_name="Administrator"
admin_rdn="cn=Users"
people_rdn="ou=Utilisateurs"
groups_rdn="ou=Groups"
rights_rdn="ou=Rights"
parcs_rdn="ou=Parcs"
computers_rdn="ou=computers"
classes_rdn="ou=classes"
equipes_rdn="ou=equipes"
matieres_rdn="ou=matieres"
cours_rdn="ou=cours"
projets_rdn="ou=projets"
other_groups_rdn="ou=autres"
delegations_rdn="ou=delegations"
equipements_rdn="ou=Materiels"
trash_rdn="ou=Trash"
lang="fr"
ldap_url="ldaps://$domain"
cnPolicy="1"
pwdPolicy="1"
path2UserSkel="/etc/skel/user"
server_proxy="$server_proxy"
proxy_address="$proxy_address"
proxy_port="$proxy_port"
proxy_url="$proxy_url"
acl_init="0"
install_zero="true"
END

chmod +x $se4fs_config
}


function debconf_set()
{
. /usr/share/sambaedu/includes/config.inc.sh

echo "krb5-config krb5-config/default_realm text ${config_domain^^}
krb5-config krb5-config/admin_server text $config_se4ad_name
krb5-config krb5-config/kerberos_servers text $config_se4ad_name
krb5-config krb5-config/add_servers boolean false
msmtp msmtp/apparmor boolean true
samba-common samba-common/dhcp boolean false
" | debconf-set-selections
}


function active_quota()
{
LADATE=$(date +%D_%Hh%M | sed -e "s!/!_!g")
FSTAB_TMP="/tmp/fstab"
FSTAB_ORI="/etc/fstab"
echo "" > $FSTAB_TMP

show_part "Activation des quotas et du discard - Modification de fstab si nécessaire..."
while read LIGNE
do
    XFS_DETECT=$(echo $LIGNE | grep xfs)
    if [ "$XFS_DETECT" != "" ]; then
        QUOTAS_OK=$(echo "$LIGNE" | grep "defaults,quota,discard")
        if [ -z "$QUOTAS_OK" ]; then
                echo "$LIGNE" | sed -e "s/defaults/defaults,quota,discard/" >>  $FSTAB_TMP
        else
            echo "$LIGNE" >> $FSTAB_TMP
        fi
    else
        echo "$LIGNE" >> $FSTAB_TMP
    fi
done < $FSTAB_ORI
mv $FSTAB_ORI ${FSTAB_ORI}.sauve_$LADATE
mv $FSTAB_TMP $FSTAB_ORI
}


function dl_iso()
{
    wget https://gitlab.sambaedu.org/sambaedu/sambaedu-ipxe/-/raw/main/sources/usr/share/sambaedu/scripts/install-debian-64-iso.sh
    chmod +x install-debian-64-iso.sh
    ./install-debian-64-iso.sh -v
}


# Variables :

dir_config="/etc/sambaedu"
dir_export="/etc/sambaedu/export_se4ad"
mkdir -p "$dir_export"
dir_preseed="/var/www/sambaedu/ipxe/diconf"
mkdir -p "$dir_preseed"

se4ad_config="$dir_export/se4ad.config"
se4fs_config="$dir_config/sambaedu.conf"
se4fs_config_clients="$dir_config/clients.conf"


dialog_box="$(which whiptail)" || dialog_box="$(which dialog)"
tempfile=`tempfile 2>/dev/null` || tempfile=/tmp/inst$$
tempfile2=`tempfile 2>/dev/null` || tempfile=/tmp/inst2$$
# url_sambaedu_config="https://raw.githubusercontent.com/SambaEdu/se4/main/sources/sambaedu-config"
# url_sambaedu_bashrc="https://raw.githubusercontent.com/SambaEdu/sambaedu-config/main/sources"
interfaces_file="/etc/network/interfaces"
se4fs_name="se4fs"
se4ad_name="se4ad"

# ip et domaine récupérés dans config.inc.sh une fois le paquet installé

if [ "$1" == "xp" -o "$1" == "se4XP" ]; then
    branche="se4XP"
else
    branche="stable"
fi
dir_config="/etc/sambaedu"

# debut du script
if [ -z "$dialog_box" ];then
    show_part "Définition du proxy"
    show_info "Veuillez saisir adresse:port du proxy.\nExemple : 172.19.80.1:3128 ou laissez vide sans proxy"
    read proxy
    set_proxy $proxy
    check_proxy
    install_whiptail
    show_title
else
    show_title
    while [ "$cnx_https" != "ok" ]
    do
        choose_proxy
        check_proxy
    done
fi
clear

gensourcese4
install_config_base
# set_bashrc
conf_network
preconf_se4ad
disable_ipv6
write_hostconf
write_sambaedu_config
echo
show_part "Installation sambaedu-boot-server pour la suite de la configuration"
apt install -y sambaedu-boot-server
systemctl restart atftpd.service
debconf_set
dl_iso
show_conf_end
apt -qq update
apt install -y sambaedu
apt install -y sambaedu-client-windows
active_quota
show_end_reboot
exit 0

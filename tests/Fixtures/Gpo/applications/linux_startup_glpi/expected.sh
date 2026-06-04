#!/bin/bash
#startup

# script de configuration des applications Linux

id=8a118d6979c527bd242a1e3fc3cb09cb

SE4FS=se4fs

URL=http://se4fs.localdev.fr

# script[apt]
local_packages="ltsp nfs-kernel-server squashfs-tools dnsmasq"

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
echo "recup infos réseau"
/usr/share/sambaedu/scripts/networkInfo.sh&

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

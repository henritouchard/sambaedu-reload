#!/bin/bash
#logon

# script de configuration des applications Linux

id=0476f127a9adf19d905e33d50630efc5

SE4FS=se4fs

URL=http://se4fs.localdev.fr

# script[sudo]
sudo /usr/share/sambaedu/scripts/system_script logon-system

# script[firefox]
echo "Mise en place profil utilisateur firefox pour Linux"
if [ ! -d "/tmp/cacheFirefox/$USER" ]; then
   mkdir -p "/tmp/cacheFirefox/$USER"
fi

chmod 700 "/tmp/cacheFirefox/$USER"

if [ ! -d "~/.mozilla/firefox/sambaedu.default" ]; then
   mkdir -p ~/.mozilla/firefox/sambaedu.default
fi

# a faire avec une page php ?
build="3B6073811A6ABF12"

echo "[Profile0]
Name=sambaedu
IsRelative=1
Path=sambaedu.default

[General]
StartWithLastProfile=1
Version=2

[Install${build}]
Default=sambaedu.default
Locked=1
" > ~/.mozilla/firefox/profiles.ini

echo "[${build}]
Default=sambaedu.default
Locked=1
" > ~/.mozilla/firefox/installs.ini

# fin de la configuration de firefox

# script[folders]
# cache les .lnk
find ~/Bureau -maxdepth 1 \( -iname "*.lnk" -o -iname "*.ini" -o -iname "*.BIN" -o -iname "*.url" \) -exec basename {} \; > ~/Bureau/.hidden
# script[folders]
#echo "Configuration des dossier et du bureau"

# xdg-mime default nemo.desktop inode/directory

# effacement des bookmarks de nemo si besoin
#if grep -q "Images" ~/.config/gtk-3.0/bookmarks > /dev/null 2>&1; then
# rm -f ~/.config/gtk-3.0/bookmarks
#echo "file:///home/$USER/Docs
#file:///home/$USER/Telechargements
#file:///home/$USER/Musique
#file:///home/$USER/Photos
#file:///home/$USER/Videos
#">~/.config/gtk-3.0/bookmarks
#fi
# fin de la configuration du bureau

# script[gdm]
echo "configuration gdm"
if [ "$USER" == "Debian-gdm" ]; then
    dbus-launch gsettings set org.gnome.settings-daemon.peripherals.keyboard numlock-state 'on' || true
fi
# fin de la configuration gdm

# script[gnome]
gsettings set org.gnome.nautilus.preferences always-use-location-entry  true
# extensions gnome
gnome-extensions enable ding@rastersoft.com 2>&1
gnome-extensions enable dash-to-dock@micxgx.gmail.com 2>&1
gnome-extensions enable ubuntu-appindicators@ubuntu.com 2>&1
gnome-extensions enable apps-menu@gnome-shell-extensions.gcampax.github.com

# configurations spécifiques de gnome
gsettings set org.gnome.shell.extensions.dash-to-dock disable-overview-on-startup true

# theme yaru-dark

gsettings set org.gnome.desktop.interface color-scheme prefer-dark
gsettings set org.gnome.desktop.interface gtk-theme Yaru-dark
gsettings set org.gnome.desktop.sound theme-name Yaru
gsettings set org.gnome.desktop.interface icon-theme Yaru-dark


# script[rclone]
# montage auto des partages de l'utilisateur dans un répertoire du home
rc_config=$HOME/.config/rclone/rclone.conf
mkdir -p $HOME/.config/systemd/user
rm -f $HOME/.config/systemd/user/*.service
mkdir -p $HOME/nuages
if [ -f  $rc_config ]; then
    /usr/bin/curl -o /tmp/cloud_$USER.sh -s -F "id=${id}" -F "os=linux" http://se4fs.localdev.fr/partages/cloud_out.php
    #eval $(/usr/bin/curl -s -F "id=${id}" -F "os=linux" http://se4fs.localdev.fr/partages/cloud_out.php)
    chmod u+x /tmp/cloud_$USER.sh && /tmp/cloud_$USER.sh
fi

# script[reseau]
echo "conf reseau user gnome"
scripts=$(/usr/bin/curl -s -F "id=${id}" -F "action=logon" -F "os=linux" http://se4fs.localdev.fr/gpo/network_out.php)
eval "$scripts"

# script[shortcuts]
echo "mise en place des raccourcis de logon"
scripts=$(/usr/bin/curl -s -F "id=${id}" -F "action=logon" -F "os=linux" http://se4fs.localdev.fr/gpo/shortcuts_out.php)
eval "$scripts"

# script[thunderbird]
echo "Mise en place profil utilisateur thunderbird pour Linux"
if [ ! -d "~/.thunderbird/Profiles/sambaedu.default" ]; then
    mkdir -p ~/.thunderbird/Profiles/sambaedu.default
fi

# a faire avec une page php ?
build="FDC34C9F024745EB"

echo "[Profile0]
Name=sambaedu
IsRelative=1
Path=Profiles/sambaedu.default

[General]
StartWithLastProfile=1
Version=2

[Install${build}]
Default=Profiles/sambaedu.default
Locked=1
" > ~/.thunderbird/profiles.ini

echo "[${build}]
Default=Profiles/sambaedu.default
Locked=1
" > ~/.thunderbird/installs.ini

# fin de la configuration de thunderbird

# script[wallpaper]
echo "Mise en place du fond d'ecran"

#until systemctl --user is-active gnome-session.target; do
#    sleep 1
#done
echo "wallpaper gnome"
file=$(dbus-launch gsettings get org.gnome.desktop.background picture-uri-dark | sed "s|'file:///\(.*\)'|\1|")
machine=$(hostname)
curl -s -o /home/$USER/.config/wallpaper-$machine.jpg -F "action=wallpaper" -F "id=$id" http://se4fs.localdev.fr/gpo/wallpaper_out.php
curl -s -o /home/$USER/.config/wallpaper-veyon.jpg -F "action=veyon" -F "id=$id" http://se4fs.localdev.fr/gpo/wallpaper_out.php

dbus-launch gsettings set org.gnome.desktop.background picture-options 'stretched' 2>&1

if [ "$XDG_SESSION_DESKTOP" == "mate" ]; then
    curl -s -o /home/$USER/.config/wallpaper-$machine.jpg -F "action=wallpaper" -F "id=$id" http://se4fs.localdev.fr/gpo/wallpaper_out.php
    [ -f "/home/$USER/.config/wallpaper.jpg" ] && dbus-launch gsettings set org.mate.background picture-filename /home/$USER/.config/wallpaper.jpg
    gsettings set org.mate.background picture-options 'stretched'
fi
# fin de la configuration du fond d'écran

/usr/bin/curl -s -F "id=0476f127a9adf19d905e33d50630efc5" -F "action=logon" -F "os=linux" -F "ret=0" http://se4fs.localdev.fr/gpo/applications.php

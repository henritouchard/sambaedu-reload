echo "conf reseau user gnome"
scripts=$(/usr/bin/curl -s -F "id=${id}" -F "action=logon" -F "os=linux" http://###_SE4FS_NAME_###.###_DOMAIN_###/gpo/network_out.php)
eval "$scripts"

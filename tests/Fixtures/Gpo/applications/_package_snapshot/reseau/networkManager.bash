echo "mise en place de la conf reseau networkmanager"
scripts=$(/usr/bin/curl -s -F "id=${id}" -F "action=startup" -F "os=linux" http://###_SE4FS_NAME_###.###_DOMAIN_###/gpo/network_out.php)
eval "$scripts"

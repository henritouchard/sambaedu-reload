#!/bin/bash
# script appelant une page php
# lancé par dhcpd, ou à la demande
# usage : 
# /usr/share/sambaedu/sbin/dhcp-dyndns.sh action ip mac host
#
. /usr/share/sambaedu/includes/config.inc.sh
/usr/bin/curl -s -f -F "se4_key=${config_se4_key}" -F "action=$1"  -F "ip=$2" -F "mac=$3", -F "name=$4" http://${config_se4fs_name}/dhcp/dnsupdate.php &
exit 0
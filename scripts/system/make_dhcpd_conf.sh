#!/bin/bash

# Génération de la config dhcp de base à partir des fichiers de conf.
# N'écrit pas les réservations. Celles-ci sont inclues par le fichier
# reservations.conf généré à partir de l'AD


. /usr/share/sambaedu/includes/config.inc.sh

write_conf(){

    sed -i "s/INTERFACESv4=.*$/INTERFACESv4=\"$config_dhcp_iface\"/" /etc/default/isc-dhcp-server

    conf_dhcp_file=/etc/dhcp/dhcpd.conf

    echo "################################################################################">$conf_dhcp_file
    echo "# This File is automagically created by SambaEdu, do not edit">>$conf_dhcp_file
    echo "##       GENERAL OPTIONS          ##############################################">>$conf_dhcp_file
    echo "allow booting;">>$conf_dhcp_file
    echo "allow bootp;">>$conf_dhcp_file
    echo "authoritative;">>$conf_dhcp_file
    echo "ddns-update-style none;">>$conf_dhcp_file

    echo "option domain-name \"$config_dhcp_domain_name\";">>$conf_dhcp_file
    if [ -n "$config_dhcp_max_lease" ]; then
        echo "max-lease-time $config_dhcp_max_lease;">>$conf_dhcp_file
    else
        echo "max-lease-time 7200;">>$conf_dhcp_file
    fi
    if [ -n "$config_dhcp_default_lease" ]; then
        echo "default-lease-time $config_dhcp_default_lease;">>$conf_dhcp_file
    else
        echo "default-lease-time 1200;">>$conf_dhcp_file
    fi
    echo "option wpad-url code 252 = string;">>$conf_dhcp_file
    echo "option client-arch code 93 = unsigned integer 16;">>$conf_dhcp_file

    if [ "$config_proxy_type" == "automatique" ]; then
        if  [ -n "$config_proxy_url" ]; then
            echo "option wpad-url \"$config_proxy_url\";">>$conf_dhcp_file
        fi
    fi
    if [ -n  "$config_dhcp_dns_server_sec" ]; then
        echo "option domain-name-servers $config_dhcp_dns_server_prim, $config_dhcp_dns_server_sec;">>$conf_dhcp_file
    else
        echo "option domain-name-servers $config_dhcp_dns_server_prim;">>$conf_dhcp_file
    fi
    if [ -n "$config_se4ad_ip" ]; then
        if [ -n "$config_se4ad_etab_ip" ]; then
            echo "option netbios-name-servers $config_se4ad_etab_ip, $config_se4ad_ip;">>$conf_dhcp_file
            echo "option ntp-servers $config_se4ad_etab_ip, $config_se4ad_ip;">>$conf_dhcp_file
        else
            echo "option netbios-name-servers $config_se4ad_ip;">>$conf_dhcp_file
            echo "option ntp-servers $config_se4ad_ip;">>$conf_dhcp_file
        fi
        echo "use-host-decl-names on;">>$conf_dhcp_file
    fi
    # boot ipxe
    echo "###       BOOT OPTIONS          ##############################################">>$conf_dhcp_file
    echo "next-server  $config_dhcp_tftp_server;">>$conf_dhcp_file
    # booter en tftp undionly.kpxe, puis la conf ipxe :
    # script ipxe statique avant install de sambaedu-ipxe, puis page php
    echo "
    if exists user-class and option user-class = \"sambaedu\" {
      filename \"${config_ipxe_url}${config_ipxe_script}\";
    } else {
      if exists client-arch {
         if option client-arch = 00:00 {">>$conf_dhcp_file
    if [ -n "$config_ipxe_legacy_file" ]; then
        echo "filename \"${config_ipxe_legacy_file}\";">>$conf_dhcp_file
    else
        echo "filename \"undionly.kpxe\";">>$conf_dhcp_file
    fi
    echo "      } elsif option client-arch = 00:06 {">>$conf_dhcp_file
    if [ -n "$config_ipxe_efi32_file" ]; then
        echo "filename \"${config_ipxe_efi32_file}\";">>$conf_dhcp_file
    else
        echo "filename \"snponly_x32.efi\";">>$conf_dhcp_file
    fi
    echo "      } elsif option client-arch = 00:07 {">>$conf_dhcp_file
    if [ -n "$config_ipxe_efi64_file" ]; then
        echo "filename \"${config_ipxe_efi64_file}\";">>$conf_dhcp_file
    else
        echo "filename \"snponly_x64.efi\";">>$conf_dhcp_file
    fi
    echo  "     }
      }
    }">>$conf_dhcp_file
    # fichier option supplémentaire
    if [ -n "$config_dhcp_extra_option" ]; then
        echo "include \"$config_dhcp_extra_option\";">>$conf_dhcp_file
    fi

    if [ -z "$config_dhcp_reseau" ]; then
    set_config dhcp dhcp_reseau $config_network
    fi

    if [ -z "$config_dhcp_masque" ]; then
    set_config dhcp dhcp_masque $config_mask
    fi

    # reseaux
    echo "###       SUBNETS          ##############################################">>$conf_dhcp_file
    RESEAU=$config_dhcp_reseau
    MASQUE=$config_dhcp_masque
    BEGIN_RANGE=$config_dhcp_begin_range
    END_RANGE=$config_dhcp_end_range
    GATEWAY=$config_dhcp_gateway
    EXTRA_OPTION=$config_dhcp_extra_option

    AppArmor="capability dac_read_search,\n"

    echo "">>$conf_dhcp_file
    echo "#####  SUBNET DECLARATION Default 0 #########">>$conf_dhcp_file
    echo "subnet $RESEAU netmask $MASQUE {">>$conf_dhcp_file
    echo "    range $BEGIN_RANGE $END_RANGE;">>$conf_dhcp_file

    j="0"
    while [ $j -lt 1024 ]; do
        ((j++))
        BEGIN_EXTRA_RANGE=config_dhcp_begin_range__$j
        END_EXTRA_RANGE=config_dhcp_end_range__$j
        if [ -n "${!BEGIN_EXTRA_RANGE}" -a -n "${!END_EXTRA_RANGE}" ]; then
            echo "    range ${!BEGIN_EXTRA_RANGE} ${!END_EXTRA_RANGE};">>$conf_dhcp_file
        else
            j="1024"
        fi
    done

    echo "    option routers $GATEWAY;">>$conf_dhcp_file
    if [ -n "$EXTRA_OPTION" ]; then
        echo "    include \"$EXTRA_OPTION\";">>$conf_dhcp_file
        AppArmor="$AppArmor$EXTRA_OPTION r,\n"
    fi
    echo "}">>$conf_dhcp_file

    i="0"
    while [ $i -lt 1024 ]; do
        ((i++))
        RESEAU=config_dhcp_reseau_$i
        if [ -n "${!RESEAU}" ]; then
            MASQUE=config_dhcp_masque_$i
            BEGIN_RANGE=config_dhcp_begin_range_$i
            END_RANGE=config_dhcp_end_range_$i
            GATEWAY=config_dhcp_gateway_$i
            EXTRA_OPTION=config_dhcp_extra_option_$i
            echo "">>$conf_dhcp_file
            echo "#####  SUBNET DECLARATION $i #########">>$conf_dhcp_file
            echo "subnet ${!RESEAU} netmask ${!MASQUE} {">>$conf_dhcp_file
            echo "    range ${!BEGIN_RANGE} ${!END_RANGE};">>$conf_dhcp_file

            j="0"
            while [ $j -lt 1024 ]; do
                ((j++))
                BEGIN_EXTRA_RANGE="config_dhcp_begin_range_"$i"_"$j
                END_EXTRA_RANGE="config_dhcp_end_range_"$i"_"$j
                if [ -n "${!BEGIN_EXTRA_RANGE}" -a -n "${!END_EXTRA_RANGE}" ]; then
                    echo "    range ${!BEGIN_EXTRA_RANGE} ${!END_EXTRA_RANGE};">>$conf_dhcp_file
                else
                    j="1024"
                fi
            done

            echo "    option routers ${!GATEWAY};">>$conf_dhcp_file
            if [ -n "${!EXTRA_OPTION}" ]; then
                echo "    include \"${!EXTRA_OPTION}\";">>$conf_dhcp_file
                if [[ "$AppArmor" != *"${!EXTRA_OPTION}"* ]]; then
                    AppArmor="$AppArmor${!EXTRA_OPTION} r,\n"
                fi
            fi
            echo "}">>$conf_dhcp_file
        fi
    done

    echo '### dns updates via se4fs webservice
on commit {
set noname = concat("dhcp-", binary-to-ascii(10, 8, "-", leased-address));
set ClientIP = binary-to-ascii(10, 8, ".", leased-address);
set ClientDHCID = concat (
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,1,1))),2), ":",
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,2,1))),2), ":",
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,3,1))),2), ":",
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,4,1))),2), ":",
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,5,1))),2), ":",
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,6,1))),2)
);
set ClientName = pick-first-value(option host-name, config-option host-name, client-name, noname);
log(concat("Commit: IP: ", ClientIP, " DHCID: ", ClientDHCID, " Name: ", ClientName));
execute("/usr/share/sambaedu/sbin/dhcp-dyndns.sh", "add", ClientIP, ClientDHCID, ClientName);
}

on release {
set ClientIP = binary-to-ascii(10, 8, ".", leased-address);
set ClientDHCID = concat (
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,1,1))),2), ":",
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,2,1))),2), ":",
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,3,1))),2), ":",
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,4,1))),2), ":",
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,5,1))),2), ":",
suffix (concat ("0", binary-to-ascii (16, 8, "", substring(hardware,6,1))),2)
);
log(concat("Release: IP: ", ClientIP));
execute("/usr/share/sambaedu/sbin/dhcp-dyndns.sh", "delete", ClientIP, ClientDHCID, "");
}

on expiry {
set ClientIP = binary-to-ascii(10, 8, ".", leased-address);
# cannot get a ClientMac here, apparently this only works when actually receiving a packet
log(concat("Expired: IP: ", ClientIP));
# cannot get a ClientName here, for some reason that always fails
# however the dhcp update script will obtain the short hostname.
execute("/usr/share/sambaedu/sbin/dhcp-dyndns.sh", "delete", ClientIP, "", "", "");
}
' >> $conf_dhcp_file

    # reservations
    # Story 8.3 (SE5) : le fichier /etc/sambaedu/reservations.inc est désormais
    # écrit par DhcpService::exportReservationsFile() (source SQL dhcp_reservations),
    # plus par le cron legacy script_make_reservations.php. On garde uniquement
    # l'inclusion conditionnelle du fichier s'il existe.
    if [ -e /etc/sambaedu/reservations.inc ] ; then
        cp /etc/sambaedu/reservations.inc /etc/dhcp/reservations.inc
        echo "include \"/etc/dhcp/reservations.inc\";" >> $conf_dhcp_file
    fi

    # AppArmor
    if [ $AppArmor != "capability dac_read_search,\n" ] && systemctl is-enabled apparmor.service >/dev/null 2>&1; then
        if grep -q "#include <dhcpd.d>" /etc/apparmor.d/usr.sbin.dhcpd >/dev/null 2>&1; then
            sed -i "s|#include <dhcpd.d>|include <dhcpd.d>|" /etc/apparmor.d/usr.sbin.dhcpd
        fi
        if [ "$(echo -e $AppArmor)" != "$(cat /etc/apparmor.d/dhcpd.d/extra_option)" ]; then
            echo -e "$AppArmor" > /etc/apparmor.d/dhcpd.d/extra_option
            systemctl restart apparmor.service
        fi
    fi
}

( flock -w 10 -E 0 0
    write_conf $1
    systemctl restart isc-dhcp-server.service || true
) 0>/var/lock/make_dhcpd_conf.lock


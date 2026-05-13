<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Config\SambaEduConfig;
use App\Dto\AppCustomization\AppContext;

/**
 * Génère les scripts bash (startup/logon) consommés par `gpo/network_out.php`.
 *
 * Story 16.3b — porte la logique de `sambaedu/includes/network.inc.php`
 * (`network_create_script`, `system_proxy`, `gnome_proxy`) en service natif
 * injectable et testable.
 *
 * @legacy-port path="sambaedu/includes/network.inc.php"
 * @legacy-port path="sambaedu/gpo/network_out.php"
 * @todo Story 16.4 : remplacer le `ssh root@se4ad pdbedit -Lw …` par
 *       `samba-tool user getpassword --attributes=dBCSPwd` (cf. piège 1 story 16.3b).
 */
class NetworkScriptGenerator
{
    /**
     * Regex stricte d'échappement du `samaccountname` machine avant interpolation
     * dans la commande `ssh ... pdbedit -Lw <name>` (parité legacy mais sécurisée).
     *
     * Caractères AD valides pour un samAccountName machine : `A-Z 0-9 _ - . $`.
     * Toute valeur ne matchant pas → on n'invoque pas le ssh, le bloc 802.1x
     * tombe avec une clé vide (le bash ne crash pas, le poste retry au prochain
     * boot).
     */
    private const SAMACCOUNTNAME_REGEX = '/^[A-Za-z0-9_\-\.\$]+$/';

    public function __construct(
        private readonly SambaEduConfig $config,
    ) {}

    /**
     * Génère le script `startup` Linux (header + network_create_script + system_proxy).
     *
     * Iso-bytes : header `\n`-terminé, pas de `\r\n` (sortie consommée par bash).
     *
     * @legacy-port path="sambaedu/gpo/network_out.php:28-40"
     */
    public function buildStartup(AppContext $context, string $os): string
    {
        if ($os !== 'linux') {
            // Iso-legacy : os=windows non géré (bug legacy reproduit, cf. AC1.6).
            // @legacy-bug os=windows renvoie vide — non corrigé volontairement (Story 16.3b iso-fonctionnel).
            return '';
        }

        return $this->header('startup')
            . $this->networkCreateScript($context)
            . $this->systemProxy();
    }

    /**
     * Génère le script `logon` Linux (header + gnome_proxy).
     *
     * @legacy-port path="sambaedu/gpo/network_out.php:42-51"
     */
    public function buildLogon(AppContext $context, string $os): string
    {
        if ($os !== 'linux') {
            // @legacy-bug iso-legacy : os=windows body vide.
            return '';
        }

        return $this->header('logon') . $this->gnomeProxy();
    }

    /**
     * Header bash iso-legacy (`#!/bin/bash\n#$action\n# script de configuration du reseau Linux\n`).
     *
     * @legacy-port path="sambaedu/gpo/network_out.php:33,45"
     */
    private function header(string $action): string
    {
        return "#!/bin/bash\n#" . $action . "\n# script de configuration du reseau Linux\n";
    }

    /**
     * Génère le bloc nmcli (WPA-PSK + 802.1x filaire + 802.1x wifi).
     *
     * @legacy-port path="sambaedu/includes/network.inc.php::network_create_script"
     * @todo Story 16.4 : remplacer `ssh ... pdbedit` par appel natif samba-tool / LDAP.
     *
     * @since Story 16.3b (review fix #8) : passage `public` → `private`. Couvert
     * indirectement par les tests Feature `NetworkOutEndpointTest::it_generates_startup_linux_script_with_correct_headers`.
     */
    private function networkCreateScript(AppContext $context): string
    {
        $raw = $context->raw;
        $machineSam = is_array($raw['machine'] ?? null)
            ? (string) ($raw['machine']['samaccountname'] ?? '')
            : '';

        $wpaSsid = (string) $this->config->get('wpa_ssid', '');
        $wpaPassword = (string) $this->config->get('wpa_password', '');
        $wired8021x = (string) $this->config->get('802_1x_wired', '');
        $wifi8021xSsid = (string) $this->config->get('802_1x_ssid', '');

        $scriptWpa = '';
        $scriptWired = '';
        $scriptWifi8021x = '';

        if ($wpaSsid !== '' && $wpaPassword !== '') {
            $scriptWpa .= "        if [ \"\$ssid\" == \"" . $wpaSsid . "\" ]; then
            echo \"configuration wifi : \$interface pour le wpa2-psk\"
            nmcli c modify \$interface 802-11-wireless-security.key-mgmt wpa-psk \\\n802-11-wireless-security.psk " . $wpaPassword . " || true
        fi
";
        }

        $machineKey = '';
        if (($wired8021x !== '' || $wifi8021xSsid !== '') && $machineSam !== '') {
            $machineKey = $this->fetchMachineKey($machineSam);
        }

        if ($wired8021x !== '') {
            $samUpper = strtoupper($machineSam);
            $scriptWired .= "        echo \"configuration ethernet : \$interface pour le 802.1x\"
        nmcli c modify \$interface 802-1x.eap peap 802-1x.identity host/" . $samUpper . " \\\n802-1x.phase2-auth mschapv2 802-1x.password " . $machineKey . " 802-1x.optional true \\\n802-1x.phase1-peaplabel 1 802-1x.auth-timeout 3 802-1x.system-ca-certs true || true
";
        }

        if ($wifi8021xSsid !== '') {
            $samUpper = strtoupper($machineSam);
            // @legacy-bug network.inc.php:37 utilise `$config['802.1x_ssid']`
            //  (avec point) côté SSID au lieu de `$config['802_1x_ssid']`. On reproduit
            //  ce comportement en lisant les deux clés (`802.1x_ssid` prioritaire si
            //  définie, sinon `802_1x_ssid`) pour rester iso-fonctionnel.
            $ssidValue = (string) $this->config->get('802.1x_ssid', $wifi8021xSsid);
            $scriptWifi8021x .= "        if [ \"\$ssid\" == \"" . $ssidValue . "\" ]; then
            echo \"configuration wifi : \$interface pour le 802.1x peap mschapv2\"
            nmcli c modify \$interface 802-1x.eap peap 802-1x.identity host/" . $samUpper . " \\\n802-1x.phase2-auth mschapv2 802-1x.password " . $machineKey . " \\\n802-1x.phase1-peaplabel 1 802-1x.auth-timeout 10 802-1x.system-ca-certs true || true
        fi
";
        }

        $scriptWiredBlock = "    if nmcli -t -f connection.type c show \$interface | grep -q \"ethernet\" ; then
        echo \"configuration filaire : \$interface\"
        nmcli c modify \$interface 802-3-ethernet.wake-on-lan magic || true
" . $scriptWired . "
    fi
";

        $scriptWifiBlock = "    if nmcli -t -f connection.type c show \$interface | grep -q \"802-11-wireless\" ; then
        echo \"configuration wifi : \$interface\"
        ssid=\$(nmcli -t -f 802-11-wireless.ssid c show \$interface | cut -d\":\" -f2)
" . $scriptWpa . "
" . $scriptWifi8021x . "
    fi
";

        return "OLDIFS=\$IFS
IFS=\"
\"
for interface in \$(nmcli -t -f UUID c show) ; do
" . $scriptWiredBlock . "
" . $scriptWifiBlock . "
done
IFS=\$OLDIFS
";
    }

    /**
     * Récupère la clé machine via `pdbedit -Lw` sur le DC AD (iso-legacy).
     *
     * **Validation samAccountName stricte** (AC4.2) avant `exec()` : si la valeur
     * ne matche pas la regex AD (`A-Z0-9_-.\$`), on ne lance pas la commande et
     * on retourne chaîne vide. Le bloc 802.1x sera émis sans password (le bash
     * côté poste tombera proprement avec `|| true`, le poste retry au prochain
     * boot).
     *
     * @legacy-port path="sambaedu/includes/network.inc.php:23"
     * @todo Story 16.4 : remplacer par `samba-tool user getpassword $name --attributes=dBCSPwd`
     *       ou requête LDAP attribut `dBCSPwd` (vuln injection latente identifiée audit §6.F).
     */
    private function fetchMachineKey(string $samAccountName): string
    {
        if (! preg_match(self::SAMACCOUNTNAME_REGEX, $samAccountName)) {
            return '';
        }

        $se4adName = (string) $this->config->get('se4ad_name', '');
        $domain = (string) $this->config->get('domain', '');
        if ($se4adName === '' || $domain === '') {
            return '';
        }

        // Validation par regex stricte amont + escapeshellarg défense en profondeur.
        // Le legacy fait `exec("ssh -i ... root@$host pdbedit -Lw $name | cut -d: -f4")`.
        // On reproduit en mode shell (le `| cut` impose le mode shell) mais avec arg échappé.
        $cmd = 'ssh -i /etc/sambaedu/id_rsa -o StrictHostKeyChecking=no '
            . escapeshellarg('root@' . $se4adName . '.' . $domain)
            . ' pdbedit -Lw '
            . escapeshellarg($samAccountName)
            . ' | cut -d ":" -f4';

        $output = @exec($cmd);
        if ($output === false) {
            return '';
        }
        return trim((string) $output);
    }

    /**
     * Sortie iso-legacy `system_proxy` (profile_file / wgetrc_file / apt 99proxy).
     *
     * @legacy-port path="sambaedu/includes/network.inc.php::system_proxy"
     */
    public function systemProxy(): string
    {
        $script = '
profile_file="/etc/profile"
wgetrc_file="/etc/wgetrc"
';
        $proxyType = (string) $this->config->get('proxy_type', '');

        switch ($proxyType) {
            case 'aucun':
                $script .= "
sed -i '/no_proxy=/d' \$profile_file
sed -i '/http_proxy=/d' \$profile_file
sed -i '/https_proxy=/d' \$profile_file
sed -i '/ftp_proxy=/d' \$profile_file
sed -i '/^export http_proxy/d' \$profile_file
sed -i '/^no_proxy/d' \$wgetrc_file
sed -i '/^http_proxy/d' \$wgetrc_file
sed -i '/^https_proxy/d' \$wgetrc_file
sed -i '/^use_proxy = on/d' \$wgetrc_file
. \$profile_file
# On désactive le cache utilisé par l'installeur dans la conf apt
if [ -f '/etc/apt/apt.conf' ]; then
    rm -f /etc/apt/apt.conf || true
fi
if [ -f '/etc/apt/apt.conf.d/99proxy' ]; then
    rm -f /etc/apt/apt.conf.d/99proxy || true
fi
if [ -f '/etc/apt/apt.conf.d/99proxy.conf' ]; then
    rm -f /etc/apt/apt.conf.d/99proxy.conf || true
fi
";
                break;

            case 'automatique':
                // Fallthrough vers manuel iso-legacy (network.inc.php:105-107).
            case 'manuel':
                $proxyAddress = (string) ($this->config->get('proxy_address') ?? '10.0.0.1');
                $proxyPort = (string) ($this->config->get('proxy_port') ?? '3128');
                $domain = (string) $this->config->get('domain', '');
                $se4fsName = (string) $this->config->get('se4fs_name', '');
                $noProxy = (string) ($this->config->get('no_proxy') ?? ('.' . $domain . ',' . $se4fsName));

                $script .= '
echo "no_proxy=\"' . $noProxy . '\"" > $profile_file
echo "http_proxy=\"http://' . $proxyAddress . ':' . $proxyPort . '\"" >> $profile_file
echo "https_proxy=\"http://' . $proxyAddress . ':' . $proxyPort . '\"" >> $profile_file
echo "ftp_proxy=\"http://' . $proxyAddress . ':' . $proxyPort . '\"" >> $profile_file
echo "export http_proxy https_proxy ftp_proxy no_proxy" >> $profile_file
echo "no_proxy = \"' . $noProxy . '\"" > $wgetrc_file
echo "http_proxy = http://' . $proxyAddress . ':' . $proxyPort . '" >> $wgetrc_file
echo "https_proxy = http://' . $proxyAddress . ':' . $proxyPort . '" >> $wgetrc_file
echo "use_proxy = on" >> $wgetrc_file
. $profile_file
';
                $aptProxy = $this->config->has('apt_proxy')
                    ? (string) $this->config->get('apt_proxy', '')
                    : ('http://' . $proxyAddress . ':' . $proxyPort);

                $script .= '
echo "Acquire::http::proxy \"' . $aptProxy . '\";
Acquire::https::proxy \"' . $aptProxy . '\";
" > /etc/apt/apt.conf.d/99proxy
';
                break;
        }

        return $script;
    }

    /**
     * Sortie iso-legacy `gnome_proxy` (gsettings org.gnome.system.proxy *).
     *
     * @legacy-port path="sambaedu/includes/network.inc.php::gnome_proxy"
     */
    public function gnomeProxy(): string
    {
        $script = '';
        $proxyType = (string) $this->config->get('proxy_type', '');

        switch ($proxyType) {
            case 'automatique':
                $proxyUrl = (string) $this->config->get('proxy_url', '');
                if ($proxyUrl !== '') {
                    $script .= "echo \"configuration proxy : \"
gsettings set org.gnome.system.proxy mode 'auto'
gsettings set org.gnome.system.proxy autoconfig-url '" . $proxyUrl . "'
";
                }
                break;

            case 'manuel':
                $proxyAddress = (string) $this->config->get('proxy_address', '');
                $proxyPort = (string) $this->config->get('proxy_port', '');
                $domain = (string) $this->config->get('domain', '');
                $script .= "echo \"configuration proxy : \"
gsettings set org.gnome.system.proxy mode 'manual'
gsettings set org.gnome.system.proxy.http host '" . $proxyAddress . "'
gsettings set org.gnome.system.proxy.http port " . $proxyPort . "
gsettings set org.gnome.system.proxy.https host '" . $proxyAddress . "'
gsettings set org.gnome.system.proxy.https port " . $proxyPort . "
gsettings set org.gnome.system.proxy.ftp host ''
gsettings set org.gnome.system.proxy.ftp port 0
gsettings set org.gnome.system.proxy.socks host ''
gsettings set org.gnome.system.proxy.socks port 0
gsettings set org.gnome.system.proxy ignore-hosts '[\"localhost\", \"127.0.0.0/8\", \"." . $domain . "\", \"::1\"]
";
                break;

            case 'aucun':
                $script .= "echo \"configuration pas de proxy : \"
gsettings set org.gnome.system.proxy mode 'none'
";
                break;
        }

        return $script;
    }
}

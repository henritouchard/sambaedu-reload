<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Enums\WindowsVersion;
use App\Ipxe\Exceptions\UnattendGenerationException;
use App\Ipxe\Support\WindowsXmlPlaceholders;
use App\Models\Workstation;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 3.5 — D6 / AC2.1 / AC2.2 / AC2.3.
 *
 * Service d'assemblage dynamique du fichier `unattend.xml` text/plain consommé
 * par Windows setup.exe pendant une install Windows automatisée iPXE.
 *
 * **Port natif** de `sambaedu/includes/windows.inc.php:3-380`
 * (`update_xml_unattend()`) simplifié au scope 3.5 (sans Win7 + sans branche
 * `specialize` clonage — déférée 3.7 cf. story 3.5 § D6).
 *
 * **Algorithme iso-legacy** (`windows.inc.php:3-370`) :
 *
 *   1. Charge le template depuis `resources/ipxe/windows/unattend.xml` via
 *      DOMDocument (préserve l'ordre des nodes + formatOutput).
 *   2. Si `version === Win11` → injecte le fragment `RunSynchronousCommand`
 *      BypassTPM/SecureBoot/RAM/CPU/Storage dans `windowsPE/component[name=
 *      Microsoft-Windows-Setup]`.
 *   3. Si `$attrs['bios']` non vide → injecte le fragment `DiskConfiguration`
 *      correspondant (`legacy` ou `uefi`) + bloc `ImageInstall` cible
 *      `disk=0/part=1|2`. Si `disk == 1` → `bios=''` (parité
 *      `unattend.xml.php:28`) donc pas d'injection.
 *   4. Set AutoLogon (Username/Password/Domain) selon `perso`:
 *       - `perso=0` (join) → `se4install_name` / `se4install_passwd` / `domain`.
 *       - `perso=1` → `win_user` / `win_user_passwd` / '' (domaine vide).
 *   5. Set ComputerName partout (oobeSystem + offlineServicing + specialize)
 *      = `$workstation->name`.
 *   6. Set RegisteredOrganization / Organization / FullName / ProductKey.
 *   7. Si `perso=0` → injecte le component `Microsoft-Windows-UnattendedJoin`
 *      avec Credentials + JoinDomain + MachineObjectOU.
 *   8. Injecte le `LocalAccount` (Administrators) avec `adminse_name`/passwd.
 *   9. Set AdministratorPassword = `adminse_passwd`.
 *   10. AutoLogon LogonCount = `4294967295` si `!join && win_autologon == 1`.
 *   11. Interpole `###_ADMINSE_NAME_###`, `###_SE4FS_NAME_###`, `###_NAME_###`
 *       dans les CommandLine + Path nodes via {@see WindowsXmlPlaceholders}.
 *   12. Retourne le XML formatté UTF-8 (DOMDocument `saveXML()`).
 *
 * **Sécurité** :
 *  - Anti-injection : hostname/AD-ou/credentials passent par
 *    {@see WindowsXmlPlaceholders::sanitize()} (escape XML special).
 *  - Aucune écriture disque (pas de `/tmp/unattend.log` parité legacy retirée).
 *  - Aucun secret dans les logs (sha256 only).
 */
final class WindowsUnattendBuilder
{
    /**
     * Namespace utilisé par les `<settings>` Microsoft.
     */
    private const NS_UNATTEND = 'urn:schemas-microsoft-com:unattend';

    /**
     * Namespace `wcm:action` utilisé par les fragments injectés.
     */
    private const NS_WCM = 'http://schemas.microsoft.com/WMIConfig/2002/State';

    /**
     * Fragment XML `<RunSynchronous>` pour bypass Win11 (TPM/SecureBoot/RAM/
     * CPU/Storage). Iso-legacy `windows.inc.php:15-41`.
     */
    private const WIN11_BYPASS_FRAGMENT = '<RunSynchronous>
                <RunSynchronousCommand wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
                    <Description>Switch to legacy Setup</Description>
                    <Order>1</Order>
                    <Path>reg add "HKLM\System\Setup" /v CmdLine /t REG_SZ /d "X:\Sources\Setup.exe" /f</Path>
                </RunSynchronousCommand>
                <RunSynchronousCommand wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
                    <Order>2</Order>
                    <Path>reg add HKLM\System\Setup\LabConfig /v BypassTPMCheck /t reg_dword /d 0x00000001 /f</Path>
                </RunSynchronousCommand>
                <RunSynchronousCommand wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
                    <Order>3</Order>
                    <Path>reg add HKLM\System\Setup\LabConfig /v BypassSecureBootCheck /t reg_dword /d 0x00000001 /f</Path>
                </RunSynchronousCommand>
                <RunSynchronousCommand wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
                    <Order>4</Order>
                    <Path>reg add HKLM\System\Setup\LabConfig /v BypassRAMCheck /t reg_dword /d 0x00000001 /f</Path>
                </RunSynchronousCommand>
                <RunSynchronousCommand wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
                    <Order>5</Order>
                    <Path>reg add HKLM\System\Setup\LabConfig /v BypassCPUCheck /t reg_dword /d 0x00000001 /f</Path>
                </RunSynchronousCommand>
                <RunSynchronousCommand wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
                    <Order>6</Order>
                    <Path>reg add HKLM\System\Setup\LabConfig /v BypassStorageCheck /t reg_dword /d 0x00000001 /f</Path>
                </RunSynchronousCommand>
            </RunSynchronous>';

    /**
     * Fragments XML `<DiskConfiguration>` selon bios. Iso-legacy
     * `windows.inc.php:51-194` (legacy/uefi simple — les variants `_dboot`
     * sont hors-scope 3.5 — D6).
     *
     * @var array<string,string>
     */
    private const DISK_FRAGMENTS = [
        'legacy' => '<DiskConfiguration>
<Disk wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
    <CreatePartitions>
        <CreatePartition wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
            <Order>1</Order>
            <Type>Primary</Type>
            <Extend>true</Extend>
        </CreatePartition>
    </CreatePartitions>
    <ModifyPartitions>
        <ModifyPartition wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
            <Active>true</Active>
            <Format>NTFS</Format>
            <Label>OS</Label>
            <Letter>C</Letter>
            <Order>1</Order>
            <PartitionID>1</PartitionID>
        </ModifyPartition>
    </ModifyPartitions>
    <DiskID>0</DiskID>
    <WillWipeDisk>true</WillWipeDisk>
</Disk>
</DiskConfiguration>',
        'uefi' => '<DiskConfiguration>
<Disk wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
    <CreatePartitions>
        <CreatePartition wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
            <Order>1</Order>
            <Type>EFI</Type>
            <Size>260</Size>
        </CreatePartition>
        <CreatePartition wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
            <Order>2</Order>
            <Type>Primary</Type>
            <Extend>true</Extend>
        </CreatePartition>
    </CreatePartitions>
    <ModifyPartitions>
        <ModifyPartition wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
            <Order>1</Order>
            <PartitionID>1</PartitionID>
            <Label>System</Label>
            <Format>FAT32</Format>
        </ModifyPartition>
        <ModifyPartition wcm:action="add" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
            <Order>2</Order>
            <PartitionID>2</PartitionID>
            <Label>Windows</Label>
            <Letter>C</Letter>
            <Format>NTFS</Format>
        </ModifyPartition>
    </ModifyPartitions>
    <DiskID>0</DiskID>
    <WillWipeDisk>true</WillWipeDisk>
</Disk>
</DiskConfiguration>',
    ];

    /**
     * Fragment XML du component UnattendedJoin (mise au domaine).
     * Iso-legacy `windows.inc.php:269-280`.
     */
    private const JOIN_COMPONENT_FRAGMENT = '<component xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" name="Microsoft-Windows-UnattendedJoin" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS">
 <Identification>
  <Credentials>
   <Domain></Domain>
   <Password></Password>
   <Username></Username>
  </Credentials>
  <JoinDomain></JoinDomain>
  <TimeoutPeriodInMinutes>10</TimeoutPeriodInMinutes>
  <MachineObjectOU></MachineObjectOU>
 </Identification>
</component>';

    public function __construct(
        private readonly \App\Services\ServiceCredentials $credentials,
    ) {
    }

    /**
     * Channel Monolog dédié (iso 3.1 D7).
     */
    private function channel(): string
    {
        return (string) config('ipxe.log.channel', 'ipxe');
    }

    /**
     * Génère l'unattend.xml pour un poste donné.
     *
     * @param  Workstation  $workstation  Poste résolu via {@see WorkstationLocator}.
     * @param  WindowsVersion  $version   Win10|Win11.
     * @param  array{bios:string, disk:int, perso:int, ou?:string}  $attrs
     * @return string                     XML formatté UTF-8 (`<?xml ...?>` en
     *                                    tête).
     * @throws UnattendGenerationException si template manquant ou config invalide.
     */
    public function build(
        Workstation $workstation,
        WindowsVersion $version,
        array $attrs,
    ): string {
        $templatePath = (string) config(
            'ipxe.windows.unattend_template_path',
            resource_path('ipxe/windows/unattend.xml'),
        );

        if (! is_file($templatePath) || ! is_readable($templatePath)) {
            Log::channel($this->channel())->error('ipxe.windows.unattend.template_missing', [
                'action_type' => 'ipxe.windows.unattend.template_missing',
                'path' => $templatePath,
            ]);

            throw new UnattendGenerationException(
                sprintf('Template unattend.xml introuvable : %s', $templatePath),
            );
        }

        $bios = (string) ($attrs['bios'] ?? '');
        $disk = (int) ($attrs['disk'] ?? 0);
        $perso = (int) ($attrs['perso'] ?? 0);
        $join = $perso === 0;

        // Normalisation `bios` : si disk=1 → on annule bios (parité
        // `unattend.xml.php:28` qui set `$attrs['bios'] = ""`).
        if ($disk === 1) {
            $bios = '';
        }

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $xml->preserveWhiteSpace = false;

        try {
            $loaded = $xml->load($templatePath);
        } catch (Throwable $e) {
            throw new UnattendGenerationException(
                sprintf('Erreur chargement template unattend.xml : %s', $e->getMessage()),
                0,
                $e,
            );
        }

        if ($loaded === false) {
            throw new UnattendGenerationException(
                sprintf('Échec parsing template unattend.xml : %s', $templatePath),
            );
        }

        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('ns', self::NS_UNATTEND);

        // 2. Bypass Win11 (TPM/SecureBoot/RAM/CPU/Storage).
        if ($version === WindowsVersion::Win11) {
            $this->injectInComponent(
                $xml,
                $xpath,
                "/ns:unattend/ns:settings[@pass='windowsPE']/ns:component[@name='Microsoft-Windows-Setup']",
                self::WIN11_BYPASS_FRAGMENT,
            );
        }

        // 3. DiskConfiguration + ImageInstall si bios non vide.
        if ($bios !== '' && isset(self::DISK_FRAGMENTS[$bios])) {
            $this->injectDiskConfiguration($xml, $xpath, $bios);
        }

        // 4. Credentials AutoLogon selon perso.
        $domain = (string) config('sambaedu.domain', '');
        if ($join) {
            $autoLogonUser = (string) config('sambaedu.se4install_name', '');
            $autoLogonPasswd = $this->credentials->se4installEffectivePassword();
            $autoLogonDomain = $domain;
            $localUser = (string) config('sambaedu.windows.adminse_name', '');
            $localPasswd = (string) config('sambaedu.windows.adminse_passwd', '');
        } else {
            $autoLogonUser = (string) config('sambaedu.windows.win_user', '');
            $autoLogonPasswd = (string) config('sambaedu.windows.win_user_passwd', '');
            $autoLogonDomain = '';
            $localUser = $autoLogonUser;
            $localPasswd = $autoLogonPasswd;
        }

        $this->setNodeValue(
            $xpath,
            '/ns:unattend/ns:settings/ns:component/ns:AutoLogon/ns:Username',
            $autoLogonUser,
        );
        $this->setNodeValue(
            $xpath,
            '/ns:unattend/ns:settings/ns:component/ns:AutoLogon/ns:Password/ns:Value',
            $autoLogonPasswd,
        );
        $this->setNodeValue(
            $xpath,
            "/ns:unattend/ns:settings[@pass='oobeSystem']/ns:component[@name='Microsoft-Windows-Shell-Setup']/ns:AutoLogon/ns:Domain",
            $autoLogonDomain,
        );

        // 5. ComputerName partout (offlineServicing + specialize + oobeSystem).
        $rawHostname = (string) ($workstation->name ?? '');
        // Convention SambaEdu : hostnames toujours lowercase (cohérent avec
        // MachineBootLog.machine_name, AD computer CN lowercase, etc.).
        $hostname = strtolower(IpxeHostnameSanitizer::sanitizeForIpxeOutput($rawHostname));
        if ($hostname === '') {
            $hostname = '*';
        }
        $computerNodes = $xpath->query('/ns:unattend/ns:settings/ns:component/ns:ComputerName');
        if ($computerNodes !== false) {
            foreach ($computerNodes as $node) {
                // Post-review #3 : `nodeValue =` attend du XML escapé.
                // `sanitizeForIpxeOutput()` autorise `&` (ASCII printable)
                // → passer par sanitize() pour escape XML.
                $node->nodeValue = WindowsXmlPlaceholders::sanitize($hostname);
            }
        }

        // 6. RegisteredOrganization / Organization / FullName / ProductKey.
        $this->setNodeValue(
            $xpath,
            '/ns:unattend/ns:settings/ns:component/ns:RegisteredOrganization',
            $domain,
        );
        $this->setNodeValue(
            $xpath,
            '/ns:unattend/ns:settings/ns:component/ns:UserData/ns:Organization',
            $domain,
        );
        $this->setNodeValue(
            $xpath,
            "/ns:unattend/ns:settings[@pass='windowsPE']/ns:component/ns:UserData/ns:FullName",
            (string) config('sambaedu.se4install_name', ''),
        );
        $winKey = (string) config(
            'sambaedu.windows.win_key',
            'VK7JG-NPHTM-C97JM-9MPGT-3V66T',
        );
        $this->setNodeValue(
            $xpath,
            "/ns:unattend/ns:settings[@pass='windowsPE']/ns:component/ns:UserData/ns:ProductKey/ns:Key",
            $winKey,
        );

        // 7. UnattendedJoin component si join.
        if ($join) {
            $specializeNodes = $xpath->query("/ns:unattend/ns:settings[@pass='specialize']");
            if ($specializeNodes !== false && $specializeNodes->length > 0) {
                $node = $xml->createDocumentFragment();
                @$node->appendXML(self::JOIN_COMPONENT_FRAGMENT);
                $specializeNodes->item(0)?->appendChild($node);
            }

            // Set credentials post-injection. Note : pas de namespace `ns`
            // pour le component injecté (parité legacy `windows.inc.php:290-299`
            // — la query xpath utilise des noms sans namespace dans cette
            // branche).
            $ou = (string) ($attrs['ou'] ?? '');
            if ($ou === '') {
                $ou = (string) config('sambaedu.computers_rdn', 'CN=Computers');
            }

            $joinSelectors = [
                "/ns:unattend/ns:settings[@pass='specialize']/component/Identification/Credentials/Username"
                    => (string) config('sambaedu.se4install_name', ''),
                "/ns:unattend/ns:settings[@pass='specialize']/component/Identification/Credentials/Password"
                    => $this->credentials->se4installEffectivePassword(),
                "/ns:unattend/ns:settings[@pass='specialize']/component/Identification/Credentials/Domain"
                    => $domain,
                "/ns:unattend/ns:settings[@pass='specialize']/component/Identification/JoinDomain"
                    => $domain,
                "/ns:unattend/ns:settings[@pass='specialize']/component/Identification/MachineObjectOU"
                    => $ou,
            ];
            foreach ($joinSelectors as $selector => $value) {
                $this->setNodeValue($xpath, $selector, $value);
            }
        }

        // 8. LocalAccount Administrators.
        $this->injectLocalAccount($xml, $xpath, $localUser, $localPasswd);

        // 9. AutoLogon LogonCount si !join && win_autologon == 1.
        $winAutologon = (int) config('sambaedu.windows.win_autologon', 0);
        if (! $join && $winAutologon === 1) {
            $this->setNodeValue(
                $xpath,
                "/ns:unattend/ns:settings[@pass='oobeSystem']/ns:component[@name='Microsoft-Windows-Shell-Setup']/ns:AutoLogon/ns:LogonCount",
                '4294967295',
            );
        }

        // 10. AdministratorPassword = adminse_passwd (toujours, peu importe
        // perso). Bug post-review : le code utilisait `$localPasswd` qui en
        // mode perso=1 vaut `win_user_passwd` au lieu de `adminse_passwd`.
        $adminsePasswd = (string) config('sambaedu.windows.adminse_passwd', '');
        $this->setNodeValue(
            $xpath,
            '/ns:unattend/ns:settings/ns:component/ns:UserAccounts/ns:AdministratorPassword/ns:Value',
            $adminsePasswd,
        );

        // 11. Interpolation CommandLine + Path nodes
        // (`###_ADMINSE_NAME_###`, `###_SE4FS_NAME_###`, `###_NAME_###`).
        $values = [
            'ADMINSE_NAME' => (string) config('sambaedu.windows.adminse_name', ''),
            'SE4FS_NAME' => (string) config('sambaedu.se4fs_name', ''),
            'NAME' => $hostname,
        ];
        $this->interpolateTextNodes($xml, $values);

        $output = $xml->saveXML();
        if ($output === false) {
            throw new UnattendGenerationException('DOMDocument::saveXML() a échoué.');
        }

        return $output;
    }

    /**
     * Injecte un fragment XML dans le premier component matché par `$xpathQuery`.
     */
    private function injectInComponent(
        DOMDocument $xml,
        DOMXPath $xpath,
        string $xpathQuery,
        string $fragment,
    ): void {
        $matches = $xpath->query($xpathQuery);
        if ($matches === false || $matches->length === 0) {
            return;
        }
        $node = $xml->createDocumentFragment();
        @$node->appendXML($fragment);
        $matches->item(0)?->appendChild($node);
    }

    /**
     * Injecte DiskConfiguration + ImageInstall (cible disk/partition) selon
     * bios. Iso-legacy `windows.inc.php:195-227`.
     */
    private function injectDiskConfiguration(DOMDocument $xml, DOMXPath $xpath, string $bios): void
    {
        $setupComponentMatches = $xpath->query(
            "/ns:unattend/ns:settings[@pass='windowsPE']/ns:component[@name='Microsoft-Windows-Setup']",
        );
        if ($setupComponentMatches === false || $setupComponentMatches->length === 0) {
            return;
        }
        $setupComponent = $setupComponentMatches->item(0);
        if ($setupComponent === null) {
            return;
        }

        // DiskConfiguration fragment.
        $diskFragment = $xml->createDocumentFragment();
        @$diskFragment->appendXML(self::DISK_FRAGMENTS[$bios]);
        $setupComponent->appendChild($diskFragment);

        // Determine PartitionID cible (parité `windows.inc.php:200-209`).
        $diskId = '0';
        $partId = $bios === 'uefi' ? '2' : '1';

        // ImageInstall + OSImage + InstallTo (Disk/Part) + InstallToAvailablePartition.
        $imageInstall = $xml->createElement('ImageInstall');
        $osImage = $xml->createElement('OSImage');
        $installTo = $xml->createElement('InstallTo');
        $installTo->appendChild($xml->createElement('DiskID', $diskId));
        $installTo->appendChild($xml->createElement('PartitionID', $partId));
        $osImage->appendChild($installTo);
        $osImage->appendChild($xml->createElement('InstallToAvailablePartition', 'false'));
        $imageInstall->appendChild($osImage);
        $setupComponent->appendChild($imageInstall);
    }

    /**
     * Injecte un nouvel LocalAccount (Administrators) dans
     * `oobeSystem/Microsoft-Windows-Shell-Setup/UserAccounts/LocalAccounts`.
     *
     * Iso-legacy `windows.inc.php:310-333`.
     */
    private function injectLocalAccount(
        DOMDocument $xml,
        DOMXPath $xpath,
        string $name,
        string $passwd,
    ): void {
        $localAccountsMatches = $xpath->query(
            "/ns:unattend/ns:settings[@pass='oobeSystem']/ns:component[@name='Microsoft-Windows-Shell-Setup']/ns:UserAccounts/ns:LocalAccounts",
        );
        if ($localAccountsMatches === false || $localAccountsMatches->length === 0) {
            return;
        }
        $localAccounts = $localAccountsMatches->item(0);
        if ($localAccounts === null) {
            return;
        }

        $local = $xml->createElement('LocalAccount');
        $local->setAttribute('wcm:action', 'add');

        $local->appendChild($xml->createElement('Name', $name));

        $password = $xml->createElement('Password');
        $password->appendChild($xml->createElement('Value', $passwd));
        $password->appendChild($xml->createElement('PlainText', 'true'));
        $local->appendChild($password);

        $local->appendChild($xml->createElement('Group', 'Administrators'));
        $local->appendChild($xml->createElement('Description', 'Administrateur local SambaEdu'));
        $local->appendChild($xml->createElement('DisplayName', $name));

        $localAccounts->appendChild($local);
    }

    /**
     * Set le `nodeValue` du PREMIER node match si la query retourne au moins
     * 1 élément. Helper iso-legacy `windows.inc.php:236-260`.
     *
     * **Post-review code-review #3** (defense in depth — décision D6) : la
     * valeur passe systématiquement par `WindowsXmlPlaceholders::sanitize()`
     * AVANT affectation. Ce wrapper est *nécessaire* (pas seulement
     * defense-in-depth) car `DOMNode::nodeValue =` attend du XML déjà escapé
     * — un credential brut contenant `&` ou `<` produirait un XML mal formé
     * (warning PHP + node vidé).
     */
    private function setNodeValue(DOMXPath $xpath, string $query, string $value): void
    {
        $matches = $xpath->query($query);
        if ($matches === false || $matches->length === 0) {
            return;
        }
        $node = $matches->item(0);
        if ($node !== null) {
            $node->nodeValue = WindowsXmlPlaceholders::sanitize($value);
        }
    }

    /**
     * Remplace les placeholders `###_<KEY>_###` dans les nodes `CommandLine`
     * + `Path` du document. Iso-legacy `windows.inc.php:347-358`.
     *
     * **Note** : on utilise `textContent` (et non `nodeValue`) car DOMDocument
     * re-escape automatiquement les caractères XML lors de la sérialisation
     * via textContent.
     *
     * **Post-review code-review #3** (defense in depth — décision D6) : chaque
     * valeur de remplacement passe par
     * {@see WindowsXmlPlaceholders::sanitizeForTextContent()} qui filtre les
     * newlines + chars non-printables (mais N'applique PAS htmlspecialchars
     * — DOMDocument escape déjà nativement via textContent =, sinon
     * double-escape).
     *
     * @param  array<string, string>  $values  Clés uppercase.
     */
    private function interpolateTextNodes(DOMDocument $xml, array $values): void
    {
        // Pré-sanitize toutes les valeurs (newlines + non-printables → espace).
        $sanitized = [];
        foreach ($values as $key => $value) {
            $sanitized[$key] = WindowsXmlPlaceholders::sanitizeForTextContent($value);
        }

        foreach (['CommandLine', 'Path'] as $tagName) {
            $nodes = $xml->getElementsByTagName($tagName);
            // On parcourt indexé décroissant pour éviter les effets de bord
            // sur les NodeList live (DOMNodeList est dynamique).
            $snapshot = [];
            foreach ($nodes as $node) {
                $snapshot[] = $node;
            }
            foreach ($snapshot as $node) {
                /** @var DOMNode $node */
                $text = $node->textContent;
                foreach ($sanitized as $key => $value) {
                    $placeholder = '###_' . $key . '_###';
                    if (str_contains($text, $placeholder)) {
                        // Replace direct sur le textContent (DOMDocument
                        // re-escape les `&` automatiquement à la sérialisation).
                        $text = str_replace($placeholder, $value, $text);
                    }
                }
                $node->textContent = $text;
            }
        }
    }
}

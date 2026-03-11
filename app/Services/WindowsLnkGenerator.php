<?php

namespace App\Services;

/**
 * Générateur de fichiers .lnk Windows en PHP pur.
 *
 * Reproduit la logique complète du code C mslink pour créer des raccourcis
 * Windows (.lnk) sans aucune dépendance externe.
 *
 * Extrait de includes/shortcuts.inc.php::create_windows_lnk() pour éviter
 * de charger les dépendances legacy lourdes.
 */
class WindowsLnkGenerator
{
    /**
     * Génère un fichier .lnk Windows et retourne son contenu binaire.
     *
     * @param string $lnkTarget Chemin cible (ex: "C:\\Windows\\notepad.exe" ou "\\\\serveur\\share")
     * @param string|null $name Description (optionnel)
     * @param string|null $workingDir Working directory (optionnel)
     * @param string|null $arguments Arguments (optionnel)
     * @param string|null $icon Icon location (optionnel)
     * @param bool $isPrinterLink True si link imprimante réseau
     * @return string|null Contenu binaire du .lnk ou null si échec
     */
    public static function generate(
        string $lnkTarget,
        ?string $name = null,
        ?string $workingDir = null,
        ?string $arguments = null,
        ?string $icon = null,
        bool $isPrinterLink = false
    ): ?string {
        $tmpPath = sys_get_temp_dir() . '/lnk_' . md5($lnkTarget . microtime(true)) . '.lnk';

        if (self::createLnkFile($lnkTarget, $tmpPath, $name, $workingDir, $arguments, $icon, $isPrinterLink)) {
            $content = file_get_contents($tmpPath);
            @unlink($tmpPath);
            return $content;
        }

        return null;
    }

    /**
     * Crée un fichier .lnk Windows sur disque.
     *
     * Port direct de create_windows_lnk() depuis includes/shortcuts.inc.php.
     */
    public static function createLnkFile(
        string $lnkTarget,
        string $outputFile,
        ?string $name = null,
        ?string $workingDir = null,
        ?string $arguments = null,
        ?string $icon = null,
        bool $isPrinterLink = false
    ): bool {
        $lnkTarget = mb_convert_encoding($lnkTarget, "WINDOWS-1252", "UTF-8");

        // --- Helpers ---
        $charToHexDigit = function (string $c): int {
            $c = strtoupper($c);
            if ($c >= 'A' && $c <= 'F') return ord($c) - ord('A') + 10;
            return ord($c) - ord('0');
        };

        $twoCharsToByte = function (string $c1, string $c2) use ($charToHexDigit): int {
            return ($charToHexDigit($c1) << 4) + $charToHexDigit($c2);
        };

        $convert_CLSID_to_DATA = function (string $src) use ($twoCharsToByte): string {
            $dst = [];
            $dst[0]  = $twoCharsToByte($src[6],  $src[7]);
            $dst[1]  = $twoCharsToByte($src[4],  $src[5]);
            $dst[2]  = $twoCharsToByte($src[2],  $src[3]);
            $dst[3]  = $twoCharsToByte($src[0],  $src[1]);
            $dst[4]  = $twoCharsToByte($src[11], $src[12]);
            $dst[5]  = $twoCharsToByte($src[9],  $src[10]);
            $dst[6]  = $twoCharsToByte($src[16], $src[17]);
            $dst[7]  = $twoCharsToByte($src[14], $src[15]);
            $dst[8]  = $twoCharsToByte($src[19], $src[20]);
            $dst[9]  = $twoCharsToByte($src[21], $src[22]);
            $dst[10] = $twoCharsToByte($src[24], $src[25]);
            $dst[11] = $twoCharsToByte($src[26], $src[27]);
            $dst[12] = $twoCharsToByte($src[28], $src[29]);
            $dst[13] = $twoCharsToByte($src[30], $src[31]);
            $dst[14] = $twoCharsToByte($src[32], $src[33]);
            $dst[15] = $twoCharsToByte($src[34], $src[35]);
            return implode(array_map("chr", $dst));
        };

        // --- Constantes ---
        $HeaderSize = "\x4c\x00\x00\x00";
        $LinkCLSID_ori = "00021401-0000-0000-c000-000000000046";
        $CLSID_Computer_ori = "20d04fe0-3aea-1069-a2d8-08002b30309d";
        $CLSID_Network_ori  = "208d2c60-3aea-1069-a2d7-08002b30309d";

        $LinkCLSID   = $convert_CLSID_to_DATA($LinkCLSID_ori);
        $CLSID_Computer = $convert_CLSID_to_DATA($CLSID_Computer_ori);
        $CLSID_Network  = $convert_CLSID_to_DATA($CLSID_Network_ori);

        $LinkFlags_2_3_4 = "\x01\x00\x00";
        $FileAttributes_Directory = "\x10\x00\x00\x00";
        $FileAttributes_File      = "\x20\x00\x00\x00";

        $CreationTime = str_repeat("\x00", 8);
        $AccessTime   = str_repeat("\x00", 8);
        $WriteTime    = str_repeat("\x00", 8);
        $FileSize     = str_repeat("\x00", 4);
        $IconIndex    = str_repeat("\x00", 4);
        $ShowCommand  = "\x01\x00\x00\x00";
        $Hotkey       = "\x00\x00";
        $Reserved     = "\x00\x00";
        $Reserved2    = "\x00\x00\x00\x00";
        $Reserved3    = "\x00\x00\x00\x00";
        $TerminalID   = "\x00\x00";

        $PREFIX_LOCAL_ROOT        = "\x2f";
        $PREFIX_FOLDER            = "\x31\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
        $PREFIX_FILE              = "\x32\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
        $PREFIX_NETWORK_ROOT      = "\xc3\x01\x81";
        $PREFIX_NETWORK_PRINTER   = "\xc3\x02\xc1";
        $END_OF_STRING            = "\x00";

        $IS_NETWORK_LNK = 0;
        $IS_ROOT_LNK    = 0;
        $TARGET_LEAF    = null;
        $TARGET_ROOT    = null;

        $Item_Data = str_repeat("\x00", 18);

        // --- Détecte type local / réseau ---
        if (substr($lnkTarget, 0, 2) === "\\\\") {
            $IS_NETWORK_LNK = 1;
            if ($isPrinterLink) {
                $PREFIX_ROOT = $PREFIX_NETWORK_PRINTER;
                $PREFIX_ROOT_strlen = strlen($PREFIX_NETWORK_PRINTER);
                $IS_ROOT_LNK = 1;
            } else {
                $PREFIX_ROOT = $PREFIX_NETWORK_ROOT;
                $PREFIX_ROOT_strlen = strlen($PREFIX_NETWORK_ROOT);
            }
            $Item_Data = "\x1f\x58" . $CLSID_Network . str_repeat("\x00", max(0, 18 - 2 - strlen($CLSID_Network)));
        } else {
            $PREFIX_ROOT = $PREFIX_LOCAL_ROOT;
            $PREFIX_ROOT_strlen = strlen($PREFIX_LOCAL_ROOT);
            $Item_Data = "\x1f\x50" . $CLSID_Computer . str_repeat("\x00", max(0, 18 - 2 - strlen($CLSID_Computer)));
        }

        // --- Séparer TARGET_ROOT et TARGET_LEAF ---
        if ($IS_ROOT_LNK) {
            $TARGET_ROOT = $lnkTarget;
            $TARGET_LEAF = null;
        } else {
            if ($IS_NETWORK_LNK) {
                $pos = strrpos($lnkTarget, "\\");
                if ($pos !== false) {
                    $TARGET_LEAF = substr($lnkTarget, $pos + 1);
                    $TARGET_ROOT = substr($lnkTarget, 0, $pos);
                } else {
                    $TARGET_LEAF = null;
                    $TARGET_ROOT = $lnkTarget;
                }
            } else {
                $pos = strpos($lnkTarget, "\\");
                if ($pos !== false) {
                    $TARGET_LEAF = substr($lnkTarget, $pos + 1);
                    $TARGET_ROOT = substr($lnkTarget, 0, $pos);
                } else {
                    $TARGET_LEAF = null;
                    $TARGET_ROOT = $lnkTarget;
                }
                $TARGET_ROOT = $TARGET_ROOT . "\\";
            }
        }
        if ($TARGET_LEAF !== null && strlen($TARGET_LEAF) === 0) {
            $IS_ROOT_LNK = 1;
        }

        // --- Prefix of target ---
        $extension_strlen = 0;
        if ($TARGET_LEAF !== null) {
            $extension = strrchr($TARGET_LEAF, '.');
            if ($extension !== false) {
                $extension = substr($extension, 1);
                $extension_strlen = strlen($extension);
            }
        }

        if ($extension_strlen >= 1 && $extension_strlen <= 3) {
            $PREFIX_OF_TARGET = $PREFIX_FILE;
            $PREFIX_OF_TARGET_strlen = strlen($PREFIX_FILE);
            $FileAttributes = $FileAttributes_File;
        } else {
            $PREFIX_OF_TARGET = $PREFIX_FOLDER;
            $PREFIX_OF_TARGET_strlen = strlen($PREFIX_FOLDER);
            $FileAttributes = $FileAttributes_Directory;
        }

        $TARGET_ROOT_fill_with_0 = $TARGET_ROOT . str_repeat("\x00", 21);
        $TARGET_ROOT_fill_with_0_strlen = strlen($TARGET_ROOT) + 21;

        // --- LinkFlags ---
        $HasLinkTargetIDList = 0x01;
        $HasName = ($name !== null) ? 0x04 : 0x00;
        $HasWorkingDir = ($workingDir !== null) ? 0x10 : 0x00;
        $HasArguments = ($arguments !== null) ? 0x20 : 0x00;
        $HasIconLocation = ($icon !== null) ? 0x40 : 0x00;

        $LinkFlags_1 = $HasLinkTargetIDList + $HasName + $HasWorkingDir + $HasArguments + $HasIconLocation;
        $LinkFlags_1_chr = chr($LinkFlags_1);

        // --- Écriture ---
        $fp = @fopen($outputFile, "wb");
        if (!$fp) return false;

        // Header
        fwrite($fp, $HeaderSize, 4);
        fwrite($fp, $LinkCLSID, 16);
        fwrite($fp, $LinkFlags_1_chr, 1);
        fwrite($fp, $LinkFlags_2_3_4, 3);
        fwrite($fp, $FileAttributes, 4);
        fwrite($fp, $CreationTime, 8);
        fwrite($fp, $AccessTime, 8);
        fwrite($fp, $WriteTime, 8);
        fwrite($fp, $FileSize, 4);
        fwrite($fp, $IconIndex, 4);
        fwrite($fp, $ShowCommand, 4);
        fwrite($fp, $Hotkey, 2);
        fwrite($fp, $Reserved, 2);
        fwrite($fp, $Reserved2, 4);
        fwrite($fp, $Reserved3, 4);

        // --- LinkTargetIDList ---
        if ($IS_ROOT_LNK) {
            $maTaille_Item_Data = 18;
            $maTaille_IDLIST_ITEMS = $PREFIX_ROOT_strlen + $TARGET_ROOT_fill_with_0_strlen + strlen($END_OF_STRING);
            $maTaille_IDLIST = $maTaille_Item_Data + 2 + $maTaille_IDLIST_ITEMS + 2;
            $val = $maTaille_IDLIST + 2;
            fwrite($fp, pack("v", $val));
            fwrite($fp, pack("v", $maTaille_Item_Data + 2));
            fwrite($fp, $Item_Data, 18);
            fwrite($fp, pack("v", $maTaille_IDLIST_ITEMS + 2));
            fwrite($fp, $PREFIX_ROOT, $PREFIX_ROOT_strlen);
            fwrite($fp, $TARGET_ROOT_fill_with_0, $TARGET_ROOT_fill_with_0_strlen);
            fwrite($fp, $END_OF_STRING, 1);
        } else {
            $maTaille_Item_Data = 18;
            $maTaille_IDLIST_ITEMS = $PREFIX_ROOT_strlen + $TARGET_ROOT_fill_with_0_strlen + strlen($END_OF_STRING);
            $maTaille_IDLIST_ITEMS_TARGET = $PREFIX_OF_TARGET_strlen + (($TARGET_LEAF !== null) ? strlen($TARGET_LEAF) : 0) + strlen($END_OF_STRING);
            $maTaille_IDLIST = $maTaille_Item_Data + 2 + $maTaille_IDLIST_ITEMS + 2 + $maTaille_IDLIST_ITEMS_TARGET + 2;

            fwrite($fp, pack("v", $maTaille_IDLIST + 2));
            fwrite($fp, pack("v", $maTaille_Item_Data + 2));
            fwrite($fp, $Item_Data, 18);
            fwrite($fp, pack("v", $maTaille_IDLIST_ITEMS + 2));
            fwrite($fp, $PREFIX_ROOT, $PREFIX_ROOT_strlen);
            fwrite($fp, $TARGET_ROOT_fill_with_0, $TARGET_ROOT_fill_with_0_strlen);
            fwrite($fp, $END_OF_STRING, 1);
            fwrite($fp, pack("v", $maTaille_IDLIST_ITEMS_TARGET + 2));
            fwrite($fp, $PREFIX_OF_TARGET, $PREFIX_OF_TARGET_strlen);
            if ($TARGET_LEAF !== null) fwrite($fp, $TARGET_LEAF, strlen($TARGET_LEAF));
            fwrite($fp, $END_OF_STRING, 1);
        }

        fwrite($fp, $TerminalID, 2);

        // --- StringData ---
        $writeWIN = function ($fp, $str) {
            $winstr = mb_convert_encoding($str, "WINDOWS-1252", "UTF-8");
            $len = strlen($winstr);
            fwrite($fp, pack("v", $len));
            fwrite($fp, $winstr);
        };

        if ($name !== null) {
            $writeWIN($fp, $name);
        }
        if ($workingDir !== null) {
            $writeWIN($fp, $workingDir);
        }
        if ($arguments !== null) {
            $writeWIN($fp, $arguments);
        }
        if ($icon !== null) {
            $writeWIN($fp, $icon);
        }

        fclose($fp);
        return true;
    }
}

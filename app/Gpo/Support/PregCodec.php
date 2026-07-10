<?php

declare(strict_types=1);

namespace App\Gpo\Support;

use RuntimeException;

/**
 * Codec du format binaire `Registry.pol` (PReg) — port natif fidèle des
 * fonctions legacy `read_pol` / `write_pol` (`sambaedu/includes/gpo.inc.php`).
 *
 * Story 38.4 (AC2) — sortie du dernier `require` FS legacy consommé par le
 * plan roaming ({@see \App\Services\Gpo\SysvolPolicyService}). Pur PHP, aucun
 * side effect, aucun `exec` : donc légitime sous `App\Gpo` (garde-fou
 * `GpoNamespaceTest`).
 *
 * Format PReg (documenté par regpol / Microsoft) :
 *   - signature `PReg\x01\x00\x00\x00` (8 octets) ;
 *   - séquence d'entrées `[key;value;type;size;data]` où :
 *       · `[` `]` `;` sont des séparateurs encodés en UTF-16LE (`5B 00`, etc.) ;
 *       · `key` et `value` sont des chaînes UTF-16LE NUL-terminées ;
 *       · `type` et `size` sont des DWORD little-endian (4 octets) ;
 *       · `data` fait `size` octets bruts.
 *
 * **Byte-stabilité** (AC — `project_severance_freezes_effective_state`) : un
 * `decode()` suivi d'un `encode()` sans modification reproduit EXACTEMENT les
 * octets d'origine pour les types manipulés (REG_SZ / REG_DWORD / REG_MULTI_SZ),
 * comme le legacy (les types non manipulés sont conservés bruts).
 *
 * Types de registre — reprend les define() legacy pour rester autonome (plus
 * aucune dépendance à `gpo.inc.php`).
 */
final class PregCodec
{
    public const REG_NONE = 0;
    public const REG_SZ = 1;
    public const REG_EXPAND_SZ = 2;
    public const REG_BINARY = 3;
    public const REG_DWORD = 4;
    public const REG_MULTI_SZ = 7;
    public const REG_QWORD = 11;

    /** Signature d'un fichier PReg valide (`PReg` + version `\x01\x00\x00\x00`). */
    private const MAGIC = "\x50\x52\x65\x67\x01\x00\x00\x00";

    /**
     * Parse le contenu binaire d'un `Registry.pol` en liste d'entrées.
     *
     * Chaque entrée est un tableau `['key'=>..., 'value'=>..., 'type'=>int,
     * 'size'=>int, 'data'=>mixed]` (iso legacy `read_pol`). Les valeurs REG_SZ
     * / REG_MULTI_SZ sont décodées en UTF-8 (NUL retirés) ; REG_DWORD en int ;
     * les autres types sont laissés bruts.
     *
     * Si le contenu ne commence PAS par la signature PReg, on tente un décodage
     * JSON (parité legacy : certaines GPO stockent une représentation JSON).
     * Contenu vide → `[]`.
     *
     * @return list<array{key:string,value:string,type:int,size:int,data:mixed}>
     */
    public function decode(string $info): array
    {
        if ($info === '') {
            return [];
        }

        // Représentation JSON (parité legacy read_pol : `strpos($info, $magic) !== 0`).
        if (strncmp($info, self::MAGIC, strlen(self::MAGIC)) !== 0) {
            $decoded = json_decode($info, true);

            return is_array($decoded) ? $decoded : [];
        }

        $entries = [];
        $body = substr($info, strlen(self::MAGIC));

        while (strlen($body) > 0) {
            if (substr($body, 0, 2) !== "\x5B\x00") { // '[' UTF-16LE
                throw new RuntimeException('Registry.pol corrompu : entrée ne commence pas par "[".');
            }
            $body = substr($body, 2);

            // key (UTF-16LE, terminée par ';' UTF-16LE)
            [$key, $body] = $this->splitOnSemicolon($body);
            $key = $this->utf16ToUtf8($key);

            // value (idem)
            [$value, $body] = $this->splitOnSemicolon($body);
            $value = $this->utf16ToUtf8($value);

            // type (DWORD LE) + délimiteur ';' (2 octets)
            $type = unpack('V', substr($body, 0, 4))[1];
            $body = substr($body, 4 + 2);

            // size (DWORD LE) + délimiteur ';' (2 octets)
            $size = unpack('V', substr($body, 0, 4))[1];
            $body = substr($body, 4 + 2);

            // data (size octets bruts)
            $rawData = substr($body, 0, $size);
            $body = substr($body, $size);

            if ($type === self::REG_SZ || $type === self::REG_EXPAND_SZ || $type === self::REG_MULTI_SZ) {
                $data = $this->utf16ToUtf8($rawData);
            } elseif ($type === self::REG_DWORD) {
                $data = $this->dwordToInt($rawData);
            } else {
                $data = $rawData;
            }

            $entries[] = [
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'size' => $size,
                'data' => $data,
            ];

            if (substr($body, 0, 2) !== "\x5D\x00") { // ']' UTF-16LE
                throw new RuntimeException('Registry.pol corrompu : entrée ne se termine pas par "]".');
            }
            $body = substr($body, 2);
        }

        return $entries;
    }

    /**
     * Sérialise une liste d'entrées (format {@see decode()}) en binaire PReg.
     *
     * Port fidèle de `write_pol` : REG_SZ / REG_MULTI_SZ ré-encodés UTF-16LE
     * avec un `\0` final avant conversion ; REG_DWORD packé LE ; autres types
     * écrits bruts. `size` est recalculé (jamais lu depuis l'entrée), garantie
     * de cohérence à la ré-écriture.
     *
     * @param  list<array{key:string,value:string,type:int,size?:int,data:mixed}>  $entries
     */
    public function encode(array $entries): string
    {
        $info = self::MAGIC;

        foreach ($entries as $entry) {
            $type = (int) $entry['type'];
            $rawData = $entry['data'];

            if ($type === self::REG_SZ || $type === self::REG_EXPAND_SZ || $type === self::REG_MULTI_SZ) {
                $data = $this->utf8ToUtf16(((string) $rawData) . "\0");
            } elseif ($type === self::REG_DWORD) {
                $data = $this->intToDword((int) $rawData);
            } else {
                $data = (string) $rawData;
            }
            $size = strlen($data);

            $info .= $this->utf8ToUtf16('[');
            $info .= $this->utf8ToUtf16(((string) $entry['key']) . "\0");
            $info .= $this->utf8ToUtf16(';');
            $info .= $this->utf8ToUtf16(((string) $entry['value']) . "\0");
            $info .= $this->utf8ToUtf16(';');
            $info .= $this->intToDword($type);
            $info .= $this->utf8ToUtf16(';');
            $info .= $this->intToDword($size);
            $info .= $this->utf8ToUtf16(';');
            $info .= $data;
            $info .= $this->utf8ToUtf16(']');
        }

        return $info;
    }

    /**
     * Extrait les valeurs CSV d'une clé de registre (par nom de `value`),
     * scindées sur `;` — port de `get_pol_key`. Retourne `[]` si absente.
     *
     * @param  list<array<string,mixed>>  $entries
     * @return list<string>
     */
    public function getKeyValues(array $entries, string $value): array
    {
        foreach ($entries as $entry) {
            if (($entry['value'] ?? null) === $value) {
                $data = $entry['data'] ?? '';

                return $data === '' ? [] : explode(';', (string) $data);
            }
        }

        return [];
    }

    /**
     * Remplace (in place) les données d'une clé (par nom de `value`) par la
     * liste `$data` jointe sur `;` — port de `change_pol_key` (mode replace).
     * Si la clé n'existe pas, la liste est retournée inchangée (parité legacy :
     * legacy ne créait pas la clé absente).
     *
     * @param  list<array<string,mixed>>  $entries  Modifié par référence.
     * @param  list<string>  $data
     * @return list<string>  Les données appliquées.
     */
    public function setKeyValues(array &$entries, string $value, array $data): array
    {
        foreach ($entries as $i => $entry) {
            if (($entry['value'] ?? null) === $value) {
                $entries[$i]['data'] = implode(';', $data);
                break;
            }
        }

        return $data;
    }

    // -----------------------------------------------------------------------
    // Helpers binaires — équivalents des dstr2str/str2dstr/dword legacy.
    // -----------------------------------------------------------------------

    /**
     * Sépare le corps sur le premier `;` UTF-16LE (`3B 00`) — parité
     * `explode("\x3B\x00", $body, 2)` legacy.
     *
     * @return array{0:string,1:string}
     */
    private function splitOnSemicolon(string $body): array
    {
        $parts = explode("\x3B\x00", $body, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /** UTF-16LE → UTF-8, NUL retirés (parité `dstr2str` : `preg_replace("/\\0/", "", …)`). */
    private function utf16ToUtf8(string $str): string
    {
        return (string) preg_replace('/\x00/', '', $str);
    }

    /** UTF-8 → UTF-16LE (parité `str2dstr`/`char2dchar` : `iconv`). */
    private function utf8ToUtf16(string $str): string
    {
        $out = @iconv('UTF-8', 'UTF-16LE', $str);

        return $out === false ? '' : $out;
    }

    /** DWORD little-endian (4 octets) → int (parité `dword2int` mais LE natif). */
    private function dwordToInt(string $str): int
    {
        $unpacked = @unpack('V', $str);

        return is_array($unpacked) ? (int) $unpacked[1] : 0;
    }

    /** int → DWORD little-endian (parité `int2dword` : `pack("v2", low, high)`). */
    private function intToDword(int $int): string
    {
        $low = $int % 65536;
        $high = intdiv($int, 65536);

        return pack('v2', $low, $high);
    }
}

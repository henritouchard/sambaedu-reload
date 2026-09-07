<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Trait utilitaire — conversion AD-FILETIME (pwdLastSet) → Carbon UTC.
 *
 * Utilisé par AuthenticationService (sync au login) et UserSyncService (sync batch).
 *
 * Sémantique retenue :
 *   - pwdLastSet == 0   → NULL  (changement obligatoire au prochain login = jamais validé)
 *   - pwdLastSet == -1  → now() (best-effort : compte admin/service sans expiration)
 *   - pwdLastSet > 0    → Carbon UTC depuis AD-FILETIME (intervalles 100ns depuis 1601-01-01)
 *
 * Constante FILETIME_DELTA : nombre de ticks 100ns entre 1601-01-01 et 1970-01-01 UTC.
 *   = 116444736000000000
 *
 * Formule : Unix timestamp = (pwdLastSet - FILETIME_DELTA) / 10_000_000
 */
trait ResolvesPwdLastSet
{
    /**
     * Delta en ticks 100ns entre 1601-01-01 et 1970-01-01 UTC.
     */
    private const FILETIME_DELTA = 116444736000000000;

    /**
     * Résout la valeur brute pwdLastSet (telle que retournée par LdapRecord)
     * en int canonique exploitable par toCarbon().
     *
     * Gère tous les types possibles retournés par LdapRecord :
     *   - null / ''   → 0
     *   - Carbon      → -1 si timestamp > 0, 0 sinon (cf. note ci-dessous)
     *   - array       → premier élément, retraité
     *   - int/string  → cast int
     *
     * Note sur le cas Carbon (LdapRecord auto-caste parfois l'attribut) :
     * une date valide est mappée vers -1 pour que `pwdLastSetToCarbon(-1)`
     * renvoie `Carbon::now()`. La date brute n'est donc PAS préservée — la
     * valeur persistée est l'instant de lecture, pas la vraie date pwdLastSet.
     * Mapper Carbon vers 1 donnerait un timestamp Unix négatif, que le
     * garde-fou de `pwdLastSetToCarbon()` transforme en NULL silencieux.
     */
    protected function resolvePwdLastSetRaw(mixed $rawValue): int
    {
        if ($rawValue === null || $rawValue === '') {
            return 0;
        }

        if ($rawValue instanceof Carbon) {
            // LdapRecord a auto-casté : une date valide signifie que pwdLastSet != 0
            // Mappage vers -1 pour aligner sur le pipeline « -1 → now() ».
            return $rawValue->getTimestamp() > 0 ? -1 : 0;
        }

        if (is_array($rawValue)) {
            $first = $rawValue[0] ?? 0;
            if ($first instanceof Carbon) {
                return $first->getTimestamp() > 0 ? -1 : 0;
            }
            return (int) $first;
        }

        return (int) $rawValue;
    }

    /**
     * Convertit un int pwdLastSet canonique en Carbon UTC.
     *
     * @param  int  $pwdLastSet  Valeur entière pwdLastSet AD
     * @return Carbon|null       NULL si pwdLastSet == 0, Carbon UTC sinon
     */
    protected static function pwdLastSetToCarbon(int $pwdLastSet): ?Carbon
    {
        if ($pwdLastSet === 0) {
            // « changement requis au prochain login » ou « jamais défini »
            return null;
        }

        if ($pwdLastSet === -1) {
            // Compte sans expiration (admin/service) — best-effort : now()
            return Carbon::now();
        }

        // Valeur FILETIME standard : ticks 100ns depuis 1601-01-01 UTC
        $unixTimestamp = intdiv($pwdLastSet - self::FILETIME_DELTA, 10_000_000);

        // Seuil 2100-01-01 UTC — sentinelle anti-AD-corrompu.
        // Valeurs au-delà = très probablement bug AD (1601 base + offset aberrant
        // ou attribut mal interprété par LdapRecord). Idem pour timestamp Unix < 0
        // (FILETIME antérieur à 1970, donc fortement suspect).
        if ($unixTimestamp < 0 || $unixTimestamp > 4102444800) {
            Log::warning('ResolvesPwdLastSet: FILETIME hors plage', [
                'pwd_last_set' => $pwdLastSet,
                'unix_ts' => $unixTimestamp,
            ]);
            return null;
        }

        return Carbon::createFromTimestamp($unixTimestamp, 'UTC');
    }
}

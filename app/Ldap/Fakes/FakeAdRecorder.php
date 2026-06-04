<?php

declare(strict_types=1);

namespace App\Ldap\Fakes;

use App\Models\E2e\AdWriteLog;

/**
 * Cœur du fake AD e2e (Story 21.2, T2/T3) : attribution de GUID factices
 * STABLES + capture des écritures dans le journal Postgres `e2e_ad_writes`.
 *
 * **Singleton e2e uniquement** (bindé dans `AppServiceProvider` sous garde
 * `APP_ENV === 'e2e'`). N'est JAMAIS instancié hors e2e.
 *
 * Invariants :
 *  - D-3 — GUID DÉTERMINISTE par clé d'objet : `guidFor($key)` retourne toujours
 *    le même GUID (format type Active Directory `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`)
 *    pour une même clé (ex. samAccountName / CN). Permet à un parcours de
 *    réutiliser le GUID entre create → move → delete (résolution par GUID stable,
 *    cf. mémoire `project_ad_sync_resolve_by_guid`).
 *  - Aucune I/O réseau / process : la « persistance AD » est uniquement le
 *    journal inspectable. Rien n'atteint `samba-ad-dc` (AC1).
 */
class FakeAdRecorder
{
    /** Canaux physiques (pour tracer quelle surface a été interceptée). */
    public const CHANNEL_LDAPRECORD = 'A:ldaprecord';
    public const CHANNEL_BIND = 'B:bind';
    public const CHANNEL_SAMBATOOL = 'C:samba-tool';

    /**
     * Capture une écriture AD dans le journal et retourne l'entrée persistée.
     *
     * @param  string  $actionType  Ex. `user.create`, `machine.move`, `setpassword`.
     * @param  string|null  $target  Cible logique (samAccountName / CN / salle).
     * @param  array<string,mixed>  $payload  Payload pertinent (jamais de mot de
     *                                        passe clair — voir {@see scrub()}).
     * @param  string  $channel  Canal physique d'origine (constantes CHANNEL_*).
     */
    public function record(
        string $actionType,
        ?string $target,
        array $payload = [],
        string $channel = self::CHANNEL_SAMBATOOL,
    ): AdWriteLog {
        $fakeGuid = $target !== null && $target !== '' ? $this->guidFor($target) : null;

        return AdWriteLog::create([
            'action_type' => $actionType,
            'target' => $target,
            'fake_guid' => $fakeGuid,
            'payload' => $this->scrub($payload),
            'channel' => $channel,
        ]);
    }

    /**
     * GUID factice DÉTERMINISTE et STABLE pour une clé d'objet (D-3).
     *
     * Dérivé d'un hash SHA-1 de la clé (préfixée pour éviter toute collision
     * accidentelle avec un GUID réel), reformaté en GUID canonique. Pur :
     * même entrée → même sortie, sans état.
     */
    public function guidFor(string $key): string
    {
        $hash = sha1('e2e-fake-ad:' . $key);

        // 32 hexdigits → format 8-4-4-4-12 (parité visuelle GUID AD).
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    /**
     * Retire les valeurs sensibles du payload avant journalisation.
     *
     * Les mots de passe ne sont JAMAIS persistés en clair (parité doctrine
     * sécurité du repo : `AdUserManager`/`SambaToolRunner` ne logguent jamais
     * le mot de passe). Toute clé `password`/`newpassword`/`pwd` est masquée.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function scrub(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/pass|pwd/i', (string) $key)) {
                $payload[$key] = '***redacted***';
            }
        }

        return $payload;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Active / désactive le TOTP 6 h d'un compte de service (ex. `se4install`).
 *
 * Invariant de cohérence : on écrit TOUJOURS l'AD d'abord, et on ne persiste
 * la DB qu'après confirmation. Ainsi un échec AD laisse la DB inchangée → pas
 * d'état où la DB serait en avance sur l'AD (et donc pas de désync). La boucle
 * {@see ServiceCredentialTotpReconciler} prend ensuite le relais pour les
 * rollovers de fenêtre 6 h.
 *
 * @see \App\Services\ServiceCredentials
 * @see \App\Services\UserService::changePasswordInAd()
 */
class ServiceCredentialTotpManager
{
    public function __construct(
        private readonly ServiceCredentials $credentials,
        private readonly UserService $users,
    ) {
    }

    public function isActive(string $name): bool
    {
        return $this->credentials->totpSecret($name) !== null;
    }

    /**
     * Active le TOTP : pose `base + code(fenêtre courante)` dans l'AD puis, en
     * cas de succès, persiste base + secret TOTP + compteur appliqué.
     *
     * @param  string|null  $importTotpSecret  Secret base32 existant à importer
     *         (migration `/etc/sambaedu/hashes`) pour préserver des codes déjà
     *         en circulation. Null → génère un secret neuf (ou réutilise celui
     *         déjà en DB).
     * @return bool  true si l'AD a été mis à jour et la DB persistée.
     */
    public function activate(string $name, ?string $importTotpSecret = null): bool
    {
        $base = $this->credentials->password($name) ?? $this->credentials->generateBase();
        $totp = $importTotpSecret
            ?? $this->credentials->totpSecret($name)
            ?? $this->credentials->generateTotpSecret();

        $counter = $this->credentials->currentCounter();
        $password = $base . $this->credentials->codeFor($totp, $counter);

        if (! $this->users->changePasswordInAd($name, $password, mustChangeAtNextLogin: false)) {
            Log::error('TOTP activate: write AD échoué, aucune persistance (DB inchangée)', [
                'name' => $name,
            ]);

            return false;
        }

        $this->credentials->persistActivated($name, $base, $totp, $counter);

        Log::info('TOTP activé pour le compte de service', [
            'name' => $name,
            'counter' => $counter,
            'imported' => $importTotpSecret !== null,
        ]);

        return true;
    }

    /**
     * Importe (adoption NON destructive) le token TOTP de `se4install` depuis le
     * fichier legacy `/etc/sambaedu/hashes`, pour préserver la compatibilité des
     * codes déjà posés en AD par le legacy — SANS réécrire l'AD.
     *
     * On enregistre `base` (config) + `token` (hashes) + fenêtre courante comme
     * compteur appliqué : l'AD détient déjà `base+code(fenêtre courante)`
     * (maintenu par le legacy), SE5 prend le relais au prochain rollover.
     *
     * Idempotent et gracieux : fichier absent / pas de token / TOTP déjà géré en
     * DB → no-op avec raison. One-shot, rejouable (pattern import legacy DHCP).
     *
     * @return array{account:string,found:bool,imported:bool,already_imported:bool,reason:?string}
     */
    public function importSe4installFromLegacyHashes(?string $path = null, ?callable $logger = null): array
    {
        $path ??= '/etc/sambaedu/hashes';
        $name = 'se4install';
        $log = static function (string $level, string $message) use ($logger): void {
            if ($logger !== null) {
                $logger($level, $message);
            }
        };

        $stats = [
            'account' => $name,
            'found' => false,
            'imported' => false,
            'already_imported' => false,
            'reason' => null,
        ];

        if (! is_file($path) || ! is_readable($path)) {
            $stats['reason'] = "Fichier {$path} absent ou illisible — aucun token à importer";
            $log('info', $stats['reason']);

            return $stats;
        }

        $hashes = json_decode((string) file_get_contents($path), true);
        $token = is_array($hashes) ? ($hashes[$name]['token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            $stats['reason'] = "Aucun token TOTP pour {$name} dans {$path}";
            $log('info', $stats['reason']);

            return $stats;
        }
        $stats['found'] = true;

        if ($this->credentials->totpSecret($name) !== null) {
            $stats['already_imported'] = true;
            $stats['reason'] = "TOTP déjà géré en base pour {$name} — import ignoré (idempotent)";
            $log('info', $stats['reason']);

            return $stats;
        }

        $base = $this->credentials->password($name) ?? (string) config('sambaedu.se4install_passwd', '');
        if ($base === '') {
            $stats['reason'] = "Mot de passe de base de {$name} introuvable (ni DB ni config) — import impossible";
            $log('warning', $stats['reason']);

            return $stats;
        }

        // Adoption sans réécriture AD : l'AD détient déjà base+code(fenêtre
        // courante) grâce au legacy → on s'aligne sur cette fenêtre.
        $this->credentials->persistActivated($name, $base, $token, $this->credentials->currentCounter());

        $stats['imported'] = true;
        $stats['reason'] = "Token TOTP de {$name} importé depuis {$path} (adoption, sans réécriture AD)";
        $log('success', $stats['reason']);

        return $stats;
    }

    /**
     * Désactive le TOTP : remet l'AD sur la base seule puis efface le secret
     * TOTP et le compteur appliqué. No-op si le TOTP n'est pas actif.
     *
     * @return bool  true si désactivé (ou déjà inactif).
     */
    public function deactivate(string $name): bool
    {
        if (! $this->isActive($name)) {
            return true;
        }

        $base = $this->credentials->password($name);

        // Remettre l'AD sur la base nue avant d'effacer le secret, sinon on
        // perdrait la capacité de recalculer le mot de passe actuellement posé.
        if ($base !== null
            && ! $this->users->changePasswordInAd($name, $base, mustChangeAtNextLogin: false)) {
            Log::error('TOTP deactivate: write AD (retour base) échoué, TOTP conservé', [
                'name' => $name,
            ]);

            return false;
        }

        $this->credentials->deactivateTotp($name);

        Log::info('TOTP désactivé pour le compte de service', ['name' => $name]);

        return true;
    }
}

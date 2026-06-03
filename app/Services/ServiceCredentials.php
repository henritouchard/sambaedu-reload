<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ServiceCredential;
use Illuminate\Support\Str;
use OTPHP\TOTP;

/**
 * API typée d'accès aux credentials de comptes de service (table
 * `service_credentials`), source de vérité SQL chiffrée at-rest.
 *
 * Pourquoi ici et pas dans `config()`/`.env` : ces secrets sont GÉNÉRÉS au
 * runtime et doivent survivre au reboot. `.env`/config sont immuables au
 * runtime (gelés par `config:cache`, `env()` → null en prod cachée) et non
 * chiffrés. La DB est le domicile naturel d'un état mutable et secret.
 *
 * Pas de mise en cache Redis du secret en clair : un mot de passe Domain Admin
 * ne doit pas traîner déchiffré dans un store partagé. On se contente d'une
 * mémoïsation par requête (le chemin d'appel est l'install, pas le hot-path).
 */
class ServiceCredentials
{
    /**
     * Longueur du mot de passe de base généré. Alphanumérique volontairement
     * (pas de symboles) : ce secret transite par des `.cmd`/`net use`/registre
     * Windows et des preseed — les symboles y posent des problèmes de quoting.
     * L'entropie est compensée par la longueur.
     */
    private const SECRET_LENGTH = 24;

    /** Longueur du secret TOTP en caractères base32 (32 = 160 bits). */
    private const TOTP_SECRET_LENGTH = 32;

    /** Alphabet base32 (RFC 4648) pour le secret TOTP — compatible `oathtool -b`. */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Paramètres TOTP — parité legacy `oathtool --totp=SHA256 --digits=6 -s 6h`.
     * Pas de 6 h (au lieu des 30 s standard) : le mot de passe effectif ne tourne
     * que toutes les 6 h.
     */
    private const TOTP_PERIOD = 21600; // 6 h en secondes
    private const TOTP_DIGEST = 'sha256';
    private const TOTP_DIGITS = 6;

    /**
     * Tolérance de validation (secondes) pour absorber la dérive d'horloge et la
     * latence entre génération et usage, SANS étendre matériellement la fenêtre
     * de 6 h. Doit rester ≪ TOTP_PERIOD.
     */
    private const TOTP_LEEWAY = 300; // 5 min

    /** @var array<string, ServiceCredential|null> Mémoïsation par requête. */
    private array $memo = [];

    /**
     * Mot de passe de base (déchiffré) du compte, ou null si absent.
     */
    public function password(string $name): ?string
    {
        return $this->record($name)?->secret;
    }

    /**
     * Secret base32 du TOTP (déchiffré) du compte, ou null si absent.
     */
    public function totpSecret(string $name): ?string
    {
        return $this->record($name)?->totp_secret;
    }

    public function has(string $name): bool
    {
        return $this->record($name) !== null;
    }

    /**
     * Garantit l'existence du credential : génère base + secret TOTP s'il est
     * absent, sinon renvoie l'existant. Idempotent — pour forcer une rotation,
     * utiliser {@see rotate()}.
     */
    public function ensure(string $name): string
    {
        $existing = $this->record($name);
        if ($existing !== null && $existing->secret !== '') {
            return $existing->secret;
        }

        return $this->rotate($name);
    }

    /**
     * (Re)génère le mot de passe de base ET le secret TOTP, persiste chiffré,
     * et retourne le nouveau mot de passe de base.
     */
    public function rotate(string $name): string
    {
        $secret = $this->generateBase();

        $record = ServiceCredential::query()->updateOrCreate(
            ['name' => $name],
            ['secret' => $secret, 'totp_secret' => $this->generateTotpSecret()],
        );

        $this->memo[$name] = $record;

        return $secret;
    }

    /**
     * Génère un mot de passe de base alphanumérique (pas de symboles — voir
     * SECRET_LENGTH). Ne persiste rien.
     */
    public function generateBase(): string
    {
        return Str::password(self::SECRET_LENGTH, symbols: false);
    }

    /**
     * Génère un secret TOTP base32 (compatible `oathtool -b`). Ne persiste rien.
     */
    public function generateTotpSecret(): string
    {
        return $this->randomBase32();
    }

    /**
     * Calcule le code TOTP pour un secret + compteur donnés, SANS toucher la DB.
     * Utilisé par l'activation (le secret n'est pas encore persisté).
     */
    public function codeFor(string $base32Secret, int $counter): string
    {
        return $this->totp($base32Secret)->at($counter * self::TOTP_PERIOD);
    }

    /**
     * Persiste un credential activé (base + secret TOTP + compteur appliqué) en
     * un seul upsert — à n'appeler QU'APRÈS un write AD confirmé, pour ne
     * jamais laisser la DB en avance sur l'AD.
     */
    public function persistActivated(string $name, string $base, string $totpSecret, int $counter): void
    {
        $record = ServiceCredential::query()->updateOrCreate(
            ['name' => $name],
            ['secret' => $base, 'totp_secret' => $totpSecret, 'totp_applied_counter' => $counter],
        );

        $this->memo[$name] = $record;
    }

    /**
     * Désactive le TOTP d'un compte : efface le secret TOTP et le compteur
     * appliqué, conserve la base. À appeler APRÈS avoir remis l'AD sur la base
     * seule. No-op si le compte n'existe pas.
     */
    public function deactivateTotp(string $name): void
    {
        $record = $this->record($name);
        if ($record === null) {
            return;
        }

        $record->totp_secret = null;
        $record->totp_applied_counter = null;
        $record->save();
        $this->memo[$name] = $record;
    }

    /**
     * Code TOTP courant (6 chiffres, pas de 6 h) du compte, ou null si le
     * compte n'a pas de secret TOTP. Identique à `oathtool --totp=SHA256
     * --digits=6 -s 6h -b <totp_secret>`.
     */
    public function code(string $name): ?string
    {
        $secret = $this->totpSecret($name);

        return $secret === null ? null : $this->totp($secret)->now();
    }

    /**
     * Mot de passe EFFECTIF = base . code TOTP du compteur RÉELLEMENT APPLIQUÉ à
     * l'AD (`totp_applied_counter`), pas du compteur courant. C'est le contrat
     * qui rend impossible toute désync consommateur↔AD : le générateur de script
     * d'install et l'AD référencent la même fenêtre. La réconciliation
     * (sambaedu:totp:reconcile) avance le compteur appliqué uniquement après un
     * write AD confirmé.
     *
     * Renvoie :
     *  - base seule si pas de secret TOTP ;
     *  - base seule si TOTP présent mais jamais encore appliqué à l'AD
     *    (`applied_counter` null) — l'AD détient alors encore la base nue ;
     *  - base . code(applied_counter) sinon.
     */
    public function effectivePassword(string $name): ?string
    {
        $record = $this->record($name);
        $base = $record?->secret;
        if ($base === null) {
            return null;
        }

        $secret = $record->totp_secret;
        $applied = $record->totp_applied_counter;
        if ($secret === null || $applied === null) {
            return $base;
        }

        return $base . $this->totp($secret)->at($applied * self::TOTP_PERIOD);
    }

    /**
     * Mot de passe effectif de `se4install` pour les consommateurs d'imaging,
     * avec REPLI sur la config statique tant que le credential n'est pas géré
     * en DB (transition). Renvoie :
     *  - `base + code(applied)` si TOTP actif,
     *  - `base` si géré en DB sans TOTP,
     *  - `config('sambaedu.se4install_passwd')` (legacy) si aucun row DB.
     *
     * Permet de câbler les builders sans rien casser : sans row → comportement
     * actuel identique. Voir [[project_se4install_credential_totp]].
     */
    public function se4installEffectivePassword(): string
    {
        return $this->effectivePassword('se4install')
            ?? (string) config('sambaedu.se4install_passwd', '');
    }

    /**
     * Compteur TOTP courant = floor(epoch / period). Découpe le temps en
     * fenêtres de 6 h ; change à chaque rollover.
     */
    public function currentCounter(): int
    {
        return intdiv(now()->getTimestamp(), self::TOTP_PERIOD);
    }

    /**
     * Compteur réellement appliqué à l'AD pour ce compte, ou null si jamais
     * appliqué.
     */
    public function appliedCounter(string $name): ?int
    {
        $applied = $this->record($name)?->totp_applied_counter;

        return $applied === null ? null : (int) $applied;
    }

    /**
     * Mot de passe à POSER dans l'AD pour la fenêtre `$counter` = base . code.
     * Renvoie null si pas de base ; renvoie la base nue si pas de secret TOTP.
     * Utilisé par la réconciliation, qui passe `currentCounter()`.
     */
    public function passwordForCounter(string $name, int $counter): ?string
    {
        $record = $this->record($name);
        $base = $record?->secret;
        if ($base === null) {
            return null;
        }

        $secret = $record->totp_secret;

        return $secret === null
            ? $base
            : $base . $this->totp($secret)->at($counter * self::TOTP_PERIOD);
    }

    /**
     * Enregistre le compteur appliqué — à n'appeler QU'APRÈS un write AD
     * confirmé. Sans cet appel (échec AD), le compteur reste en arrière et la
     * réconciliation rejouera au tick suivant (idempotent, auto-réparant).
     */
    public function markApplied(string $name, int $counter): void
    {
        $record = $this->record($name);
        if ($record === null) {
            return;
        }

        $record->totp_applied_counter = $counter;
        $record->save();
        $this->memo[$name] = $record;
    }

    /**
     * Valide un code TOTP fourni (ex. formulaire de login annuaire), avec une
     * tolérance de {@see TOTP_LEEWAY} pour la dérive d'horloge.
     */
    public function verifyCode(string $name, string $code): bool
    {
        $secret = $this->totpSecret($name);

        return $secret !== null
            && $this->totp($secret)->verify($code, null, self::TOTP_LEEWAY);
    }

    /**
     * Importe un secret TOTP base32 existant (migration depuis
     * `/etc/sambaedu/hashes`) pour préserver la compatibilité des codes déjà en
     * circulation. À préférer à une génération fraîche quand le compte existe
     * déjà côté legacy.
     */
    public function importTotpSecret(string $name, string $base32): void
    {
        $record = ServiceCredential::query()->updateOrCreate(
            ['name' => $name],
            ['totp_secret' => $base32],
        );

        $this->memo[$name] = $record;
    }

    private function totp(string $base32Secret): TOTP
    {
        return TOTP::create(
            $base32Secret,
            self::TOTP_PERIOD,
            self::TOTP_DIGEST,
            self::TOTP_DIGITS,
        );
    }

    /**
     * Noms des comptes ayant un secret TOTP (donc à réconcilier). La colonne
     * chiffrée reste `NULL` quand vide → le filtre SQL est fiable sans
     * déchiffrer.
     *
     * @return list<string>
     */
    public function managedTotpNames(): array
    {
        return ServiceCredential::query()
            ->whereNotNull('totp_secret')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    private function record(string $name): ?ServiceCredential
    {
        if (! array_key_exists($name, $this->memo)) {
            $this->memo[$name] = ServiceCredential::query()->where('name', $name)->first();
        }

        return $this->memo[$name];
    }

    private function randomBase32(): string
    {
        $out = '';
        for ($i = 0; $i < self::TOTP_SECRET_LENGTH; $i++) {
            $out .= self::BASE32_ALPHABET[random_int(0, 31)];
        }

        return $out;
    }
}

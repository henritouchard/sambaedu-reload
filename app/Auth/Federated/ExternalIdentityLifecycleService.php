<?php

declare(strict_types=1);

namespace App\Auth\Federated;

use App\Auth\Federated\Jwt\FederatedUserClaims;
use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Story 20.2 — D-1 / D-2 / D-3 / D-4 / D-5.
 *
 * Service de CYCLE DE VIE de l'identité externe fédérée. Extraction (refactor
 * non-régressif — D-2) de la logique d'upsert/sync qui vivait inline dans
 * `FederatedLoginController::upsertIdentity`/`provisionUser` (Story 20.1).
 *
 * Porte la sémantique des 4 états (cf. {@see ExternalIdentity}) et leurs
 * transitions :
 *  - `reconcileOnLogin()`  : 1er login (création) OU reconnexion (réutilisation
 *                            + sync profil D-3) ; refus 403 si révoquée /
 *                            soft-deletée (règle 20.1) ou anonymisée (D-4).
 *  - `deactivate()`        : désactivation administrative (sans suppression).
 *  - `softDeleteWithReason()` : soft-delete tracé.
 *  - `anonymize()`         : purge PII de fin de rétention (idempotent, jamais
 *                            hard-delete, `external_sub`→`anon:<hmac-sha256>` D-5,
 *                            désactive les `User` liés).
 *
 * RGPD / sécurité : AUCUNE PII (`name`/`email`/`login` clair) dans les logs —
 * on ne logge que l'`id` interne + un hash de `sub` (AC16).
 */
class ExternalIdentityLifecycleService
{
    /**
     * Préfixe opaque appliqué à `external_sub` lors de l'anonymisation (D-5).
     * Permet de reconnaître un sub déjà anonymisé (idempotence) et casse la
     * corrélation IdP↔identité tout en préservant l'unicité de la colonne.
     */
    public const ANON_PREFIX = 'anon:';

    /**
     * Réconcilie l'identité externe au login fédéré.
     *
     * - 1er login (sub inconnu) → crée une `ExternalIdentity` active.
     * - Reconnexion (même sub) → réutilise la MÊME row + sync profil (D-3).
     *
     * GARDES (aucune session ne doit s'ouvrir au-delà) :
     *  - identité révoquée (`is_active=false`) ou soft-deletée → 403
     *    `federated.login.identity_revoked` (règle 20.1, portée telle quelle).
     *  - identité ANONYMISÉE (`anonymized_at != null`) → 403
     *    `federated.login.identity_anonymized` (D-4 : anti-résurrection ; une
     *    identité dont la PII a été purgée ne se ressuscite pas par reconnexion ;
     *    la réactivation est une décision admin — Story 20.3).
     *
     * @throws HttpException 403 si l'identité est révoquée / soft-deletée / anonymisée.
     */
    public function reconcileOnLogin(FederatedUserClaims $claims): ExternalIdentity
    {
        $identity = ExternalIdentity::withTrashed()
            ->where('external_sub', $claims->sub)
            ->first();

        // Anti-résurrection (D-4 + D-5) : après anonymisation, `external_sub` a
        // été réécrit en `anon:<hmac-sha256(sub)>` — un lookup par le sub CLAIR ne le
        // retrouve donc plus. On résout AUSSI par la forme anonymisée pour ne
        // pas recréer silencieusement une identité « fraîche » (contournement de
        // la purge RGPD) ni provoquer une collision sur le `User` lié.
        if ($identity === null) {
            $identity = ExternalIdentity::withTrashed()
                ->where('external_sub', self::ANON_PREFIX . $this->hashSub($claims->sub))
                ->whereNotNull('anonymized_at')
                ->first();
        }

        // Garde anti-résurrection (D-4) : une identité anonymisée se reconnaît
        // par `anonymized_at` (flag explicite, indépendant de l'effet de bord
        // D-5 sur le sub réécrit).
        if ($identity !== null && $identity->isAnonymized()) {
            Log::channel('federated-auth')->warning('[ExternalIdentityLifecycleService] federated.login.identity_anonymized', [
                'action_type' => 'federated.login.identity_anonymized',
                'identity_id' => $identity->id,
                'sub_hash' => $this->hashSub($claims->sub),
                'iss' => $claims->iss,
            ]);

            throw new HttpException(403, 'Federated identity anonymized on this instance');
        }

        // Garde révocation (règle 20.1 — Q1/review #1) : une identité existante
        // désactivée ou soft-deletée n'est JAMAIS réarmée par un fresh login.
        if ($identity !== null && ($identity->trashed() || ! $identity->is_active)) {
            Log::channel('federated-auth')->warning('[ExternalIdentityLifecycleService] federated.login.identity_revoked', [
                'action_type' => 'federated.login.identity_revoked',
                'identity_id' => $identity->id,
                'sub_hash' => $this->hashSub($claims->sub),
                'iss' => $claims->iss,
            ]);

            throw new HttpException(403, 'Federated identity revoked on this instance');
        }

        if ($identity === null) {
            $identity = new ExternalIdentity();
            $identity->external_sub = $claims->sub;
            $identity->is_active = true;
        }

        $this->syncProfile($identity, $claims);
        $identity->last_login_at = Carbon::now();
        $identity->save();

        return $identity;
    }

    /**
     * Politique de sync profil (D-3) : un claim présent et NON VIDE écrase la
     * valeur stockée (l'IdP est source de vérité du profil d'affichage) ; un
     * claim absent/vide PRÉSERVE l'existant (pas d'effacement involontaire).
     *
     * Champs synchronisés : `issuer`, `login`, `name`, `email`. JAMAIS le rôle
     * ni `is_active` (séparation identité/accès — D-3).
     */
    private function syncProfile(ExternalIdentity $identity, FederatedUserClaims $claims): void
    {
        $identity->issuer = $claims->iss;
        $identity->login = $claims->login !== '' ? $claims->login : $identity->login;
        $identity->name = $claims->name !== '' ? $claims->name : $identity->name;
        $identity->email = $claims->email !== '' ? $claims->email : $identity->email;
    }

    /**
     * Désactivation administrative / révocation : l'accès est coupé
     * (`is_active=false`) SANS supprimer l'identité (conservée pour l'audit).
     * Idempotent (rejouer sur une identité déjà désactivée est un no-op). Trace
     * le motif. Au prochain check du guard de session (D-5), les sessions
     * vivantes tombent.
     */
    public function deactivate(ExternalIdentity $identity, string $reason): void
    {
        if (! $identity->is_active && $identity->deactivated_reason !== null) {
            return;
        }

        // P-8 : borne le motif à la longueur de colonne (varchar 255) — SQLite de
        // test ne contraint pas, MySQL prod lèverait une QueryException.
        $reason = mb_substr($reason, 0, 255);

        $identity->is_active = false;
        $identity->deactivated_reason = $reason;
        $identity->save();

        Log::channel('federated-auth')->info('[ExternalIdentityLifecycleService] federated.identity.deactivated', [
            'action_type' => 'federated.identity.deactivated',
            'identity_id' => $identity->id,
            'sub_hash' => $this->hashSub($identity->external_sub),
            'reason' => $reason,
        ]);
    }

    /**
     * Soft-delete tracé : l'identité reste résolvable via `withTrashed()` pour
     * l'audit dénormalisé (Story 20.4). Idempotent (déjà trashée = no-op). Ne
     * hard-delete JAMAIS.
     */
    public function softDeleteWithReason(ExternalIdentity $identity, string $reason): void
    {
        if ($identity->trashed()) {
            return;
        }

        // P-8 : borne le motif à la longueur de colonne (varchar 255).
        $reason = mb_substr($reason, 0, 255);

        // P-2 : save() + delete() atomiques (évite un état partiel : motif posé
        // sans soft-delete effectif si le delete() échoue).
        DB::transaction(function () use ($identity, $reason): void {
            $identity->deleted_reason = $reason;
            $identity->save();
            $identity->delete();
        });

        Log::channel('federated-auth')->info('[ExternalIdentityLifecycleService] federated.identity.soft_deleted', [
            'action_type' => 'federated.identity.soft_deleted',
            'identity_id' => $identity->id,
            'sub_hash' => $this->hashSub($identity->external_sub),
            'reason' => $reason,
        ]);
    }

    /**
     * Anonymisation de fin de rétention (D-1 / D-4 / D-5) — cœur RGPD :
     *
     *  - vide la PII : `name`, `email`, `login` (lisible) → null ;
     *  - réécrit `external_sub` en `anon:<hmac-sha256(sub)>` (D-5 : casse la
     *    corrélation IdP↔identité, préserve l'unicité, empêche la résurrection) ;
     *  - pose `anonymized_at` (garde d'idempotence + état terminal) ;
     *  - coupe l'accès (`is_active=false`) et SOFT-DELETE la ligne ;
     *  - DÉSACTIVE chaque `User source='federated'` lié (AC12) sans toucher la
     *    FK (la ligne survit → audit 20.4 + FK `users` cohérentes) ;
     *  - **idempotent** : rejouer sur une identité déjà anonymisée = no-op (le
     *    `sub` n'est pas re-hashé, `anonymized_at` préservé) ;
     *  - ne hard-delete JAMAIS.
     */
    public function anonymize(ExternalIdentity $identity): void
    {
        // Idempotence (D-5) : si déjà anonymisée, on ne re-hashe pas.
        if ($identity->isAnonymized()) {
            return;
        }

        $originalSub = $identity->external_sub;

        // P-2 + M-4 : tout le passage à l'état terminal « anonymisée » est
        // atomique. Sans transaction, un échec entre le save() de l'identité et
        // la désactivation des Users laisse un état partiel IRRÉPARABLE par
        // rejeu (isAnonymized() court-circuite le 2e passage) → des `User`
        // resteraient `is_active=true` rattachés à une identité anonymisée
        // (violation AC12 / accès résiduel post-purge PII).
        DB::transaction(function () use ($identity, $originalSub): void {
            $identity->external_sub = self::ANON_PREFIX . $this->hashSub($originalSub);
            $identity->name = null;
            $identity->email = null;
            $identity->login = null;
            $identity->is_active = false;
            $identity->anonymized_at = Carbon::now();
            if ($identity->deleted_reason === null) {
                $identity->deleted_reason = 'retention_expired';
            }
            $identity->save();

            // Coupe l'accès des Users liés sans casser la FK (AC12). La ligne
            // identité survit (jamais hard-delete) pour l'audit 20.4.
            foreach ($identity->users()->get() as $user) {
                /** @var User $user */
                if ($user->is_active) {
                    $user->is_active = false;
                    $user->save();
                }
            }

            // Soft-delete APRÈS la réécriture (préserve la résolution withTrashed()).
            if (! $identity->trashed()) {
                $identity->delete();
            }
        });

        Log::channel('federated-auth')->info('[ExternalIdentityLifecycleService] federated.identity.anonymized', [
            'action_type' => 'federated.identity.anonymized',
            'identity_id' => $identity->id,
            // On logge le hash du sub ORIGINAL (jamais le sub clair ni la PII).
            'sub_hash' => $this->hashSub($originalSub),
        ]);
    }

    /**
     * Hash non réversible d'un `sub` (HMAC-SHA256 — P-4). Sert à tracer/corréler
     * sans exposer le `sub` clair ni aucune PII (AC16) + à forger le
     * `external_sub` opaque de l'anonymisation (D-5).
     *
     * Le SEL (clé HMAC dédiée, cf. `federated_auth.retention.hash_key`) empêche
     * la ré-identification d'un `sub` à faible entropie par bruteforce/rainbow —
     * sans lui, `anon:<hash>` resterait pseudonyme (pas anonyme RGPD). La clé
     * étant FIXE, le hash reste re-corrélable pour le forensique légal (20.4).
     *
     * @throws \RuntimeException si aucun secret n'est résolu (mauvaise config —
     *         on refuse de hasher sans sel plutôt que d'affaiblir silencieusement).
     */
    public function hashSub(string $sub): string
    {
        $key = (string) config('federated_auth.retention.hash_key', '');
        if ($key === '') {
            throw new \RuntimeException('federated_auth.retention.hash_key est vide — impossible de hasher le sub sans sel (P-4).');
        }

        return hash_hmac('sha256', $sub, $key);
    }
}

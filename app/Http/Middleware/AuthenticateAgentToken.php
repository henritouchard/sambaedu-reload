<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Ipxe\Support\MacAddressNormalizer;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 23.2 — Authentification du canal agent desired-state (FR12-FR15).
 *
 * Protège les futurs endpoints `/api/v1/agent/*` du canal neuf (23.5 state,
 * 24.1 report) par le bearer token per-poste émis par
 * {@see TokenRotationService}. Alias router : `agent.token`
 * ({@see \App\Providers\AgentServiceProvider}).
 *
 * Le poste résolu par le token est la SEULE identité de la requête : les
 * controllers du canal n'acceptent jamais d'identifiant de poste en entrée
 * (portée minimale AC1, règle d'enforcement documentée dans
 * docs/agent/token-lifecycle.md). Injection :
 *
 *  - `agent.workstation` ({@see Workstation}) dans `$request->attributes`.
 *
 * Ordre des vérifications (figé par la story) :
 *
 *  1. bearer absent/malformé → 401 AGENT_TOKEN_MISSING ;
 *  2. lookup sha256 sur `agent_token_hash` OU `agent_previous_token_hash`
 *     (fenêtre de grâce D5) ; introuvable → 401 AGENT_TOKEN_INVALID
 *     (révoqué = hash effacé = introuvable : pas d'oracle) ;
 *  3. anti-clonage (AC5) : MAC divergente → quarantaine + 403 ; hostname
 *     seul divergent → warning sans quarantaine (renommage légitime) ;
 *  4. quarantaine → maj check-in (le poste reste visible, FR15) puis
 *     403 AGENT_QUARANTINED ;
 *  5. rotation — sérialisée sous verrou ligne (transaction + FOR UPDATE,
 *     review 23.2) : auth via previous → ré-émission (réponse perdue, AC4) ;
 *     auth via courant avec grâce ouverte → confirmation ; échéance
 *     dépassée → rotation. Nouveau token renvoyé dans le header
 *     `X-Agent-New-Token` (le corps reste le JSON v1 figé du contrat 23.1) ;
 *  6. maj `agent_last_checkin_at` + injection + next.
 *
 * Réponses d'erreur JSON `{error, message, code}` iso `EnsureWorkstationJwt`.
 * Tout le flux est SQL-only — aucune dépendance annuaire (critère Keycloak,
 * AC7 : le grep de review doit rester vide).
 */
class AuthenticateAgentToken
{
    public const CODE_TOKEN_MISSING = 'AGENT_TOKEN_MISSING';
    public const CODE_TOKEN_INVALID = 'AGENT_TOKEN_INVALID';
    public const CODE_QUARANTINED = 'AGENT_QUARANTINED';

    /** Header de réponse portant le nouveau token lors d'une rotation (AC4). */
    public const HEADER_NEW_TOKEN = 'X-Agent-New-Token';

    /** Headers d'identité présentés par l'agent (anti-clonage AC5, Epic 24). */
    public const HEADER_MAC = 'X-Agent-Mac';
    public const HEADER_HOSTNAME = 'X-Agent-Hostname';

    public function __construct(
        private readonly TokenRotationService $tokens,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $this->extractBearer($request);
        if ($bearer === null) {
            return $this->errorResponse(self::CODE_TOKEN_MISSING, 'Missing Authorization header', 401);
        }

        $hash = hash('sha256', $bearer);
        // OR groupé : si un global scope est un jour posé sur Workstation
        // (archivage), un OR plat (`scope AND courant OR previous`) ferait
        // échapper le previous au scope (review 23.2, défensif).
        $workstation = Workstation::query()
            ->where(function ($query) use ($hash): void {
                $query->where('agent_token_hash', $hash)
                    ->orWhere('agent_previous_token_hash', $hash);
            })
            ->first();

        if ($workstation === null) {
            return $this->errorResponse(self::CODE_TOKEN_INVALID, 'Invalid agent token', 401);
        }

        // ── Anti-clonage (AC5) — avant tout traitement ─────────────────────
        if ($this->isMacMismatch($request, $workstation)) {
            $this->tokens->quarantine($workstation, sprintf(
                'mac mismatch: presented=%s expected=%s',
                (string) MacAddressNormalizer::normalize((string) $request->header(self::HEADER_MAC)),
                $workstation->mac,
            ));

            return $this->errorResponse(self::CODE_QUARANTINED, 'Agent quarantined', 403);
        }
        $this->warnIfHostnameMismatch($request, $workstation);

        // ── Quarantaine (AC3) — check-in léger : le poste reste visible ────
        if ($workstation->agent_quarantined_at !== null) {
            $workstation->agent_last_checkin_at = now();
            $workstation->save();

            return $this->errorResponse(self::CODE_QUARANTINED, 'Agent quarantined', 403);
        }

        // ── Rotation glissante D5 (AC4) — sous verrou ligne ────────────────
        // Sans verrou, deux check-ins simultanés du même poste lisent tous
        // deux `previous = null`, rotatent chacun, et le dernier save()
        // écrase : le token renvoyé par la première réponse n'existe plus en
        // base → lock-out possible, contraire à l'invariant AC4. Le re-fetch
        // FOR UPDATE sérialise et ré-évalue l'état à jour (no-op SQLite de
        // test, verrou réel Postgres). Review 23.2.
        $newToken = null;
        $locked = DB::transaction(function () use ($hash, $workstation, &$newToken): ?Workstation {
            /** @var Workstation|null $locked */
            $locked = Workstation::query()
                ->whereKey($workstation->getKey())
                ->lockForUpdate()
                ->first();

            $matchesCurrent = $locked !== null && $locked->agent_token_hash === $hash;
            $matchesPrevious = $locked !== null && $locked->agent_previous_token_hash === $hash;
            if (! $matchesCurrent && ! $matchesPrevious) {
                // Supprimé, révoqué ou grâce fermée par une requête
                // concurrente : 401 indistinct d'un token inconnu.
                return null;
            }

            if ($matchesPrevious && ! $matchesCurrent) {
                // Réponse de rotation perdue : le poste re-présente l'ancien
                // token → ré-émission systématique (on ne stocke que des hash,
                // impossible de renvoyer le même clair). `previous` est
                // préservé par rotateFor() — jamais de lock-out.
                $newToken = $this->tokens->rotateFor($locked);
            } elseif ($locked->agent_previous_token_hash !== null) {
                // Premier usage du nouveau token : la grâce se ferme.
                $this->tokens->confirmRotation($locked);
            }

            if ($newToken === null && $this->rotationDue($locked)) {
                $newToken = $this->tokens->rotateFor($locked);
            }

            $locked->agent_last_checkin_at = now();
            $locked->save();

            return $locked;
        });

        if ($locked === null) {
            return $this->errorResponse(self::CODE_TOKEN_INVALID, 'Invalid agent token', 401);
        }

        $request->attributes->set('agent.workstation', $locked);

        $response = $next($request);
        if ($newToken !== null) {
            $response->headers->set(self::HEADER_NEW_TOKEN, $newToken);
        }

        return $response;
    }

    /**
     * Extrait le bearer du header Authorization (iso `EnsureWorkstationJwt`).
     */
    private function extractBearer(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');
        if (! is_string($authHeader)) {
            return null;
        }
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($authHeader, 7));

        return $token === '' ? null : $token;
    }

    /**
     * MAC divergente = signal de clonage fiable (AC5). Les deux formes
     * passent par {@see MacAddressNormalizer::normalize()} (forme canonique
     * `aa:bb:cc:dd:ee:ff`) : l'agent Windows émettra naturellement
     * `AA-BB-CC-DD-EE-FF` (ipconfig) — un simple mismatch de séparateur ne
     * doit pas quarantainer un poste légitime (review 23.2). Header absent,
     * fiche sans MAC ou format non reconnu → pas de détection (l'agent
     * Epic 24 enverra les headers systématiquement).
     */
    private function isMacMismatch(Request $request, Workstation $workstation): bool
    {
        $presented = $request->header(self::HEADER_MAC);
        if (! is_string($presented) || trim($presented) === '' || $workstation->mac === null) {
            return false;
        }

        $presentedCanonical = MacAddressNormalizer::normalize($presented);
        $expectedCanonical = MacAddressNormalizer::normalize($workstation->mac);
        if ($presentedCanonical === null || $expectedCanonical === null) {
            return false;
        }

        return $presentedCanonical !== $expectedCanonical;
    }

    /**
     * Hostname seul divergent → warning sans quarantaine : tolère le délai
     * d'un renommage légitime UI/AD vs hostname local (AC5). Comparaison
     * insensible à la casse (sémantique hostname).
     */
    private function warnIfHostnameMismatch(Request $request, Workstation $workstation): void
    {
        $presented = $request->header(self::HEADER_HOSTNAME);
        if (! is_string($presented) || trim($presented) === '' || $workstation->name === null) {
            return;
        }
        if (strcasecmp(trim($presented), $workstation->name) === 0) {
            return;
        }

        Log::channel('agent')->warning('[AuthenticateAgentToken] agent.token.hostname_mismatch', [
            'action_type' => 'agent.token.hostname_mismatch',
            // Input client non authentifié : borné avant log (anti-injection).
            'presented' => Str::limit(trim($presented), 255),
            'expected' => $workstation->name,
        ]);
    }

    /**
     * Échéance de rotation dépassée (AC4). Pas d'expiration calendaire
     * sèche : un token très ancien s'authentifie et se rotate. `null` (état
     * hérité improbable) ou date future (snapshot DB restauré, horloge
     * corrigée) = état incohérent → rotation immédiate, qui repose un
     * `rotated_at` sain (review 23.2).
     */
    private function rotationDue(Workstation $workstation): bool
    {
        $rotatedAt = $workstation->agent_token_rotated_at;
        if ($rotatedAt === null || $rotatedAt->isFuture()) {
            return true;
        }

        // Plancher à 1 jour : un AGENT_TOKEN_ROTATION_DAYS mal configuré
        // (0/négatif) déclencherait une rotation + écriture DB à chaque
        // check-in de tout le parc (review 23.2).
        $days = max(1, (int) config('agent.token_rotation_days', 30));

        return $rotatedAt->copy()->addDays($days)->isPast();
    }

    private function errorResponse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => $status === 401 ? 'unauthorized' : 'forbidden',
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware\Auth;

use App\Auth\Federated\Session\FederatedSession;
use App\Models\ExternalActionAuditLog;
use App\Models\ExternalIdentity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 20.4 — D-2 / D-3 / D-4 / D-5.
 *
 * Capture DÉNORMALISÉE des actions d'administration réalisées par un acteur
 * fédéré, dans la table `external_action_audit_logs`.
 *
 * S'exécute APRÈS le guard de session (le branchement garantit `Auth::user()`
 * peuplé — D-4) et n'agit QUE si la session est marquée « fédérée »
 * (`FederatedSession::isFederated()`), ce qui discrimine l'externe de l'AD
 * locale (AC2). Une requête non fédérée ne touche JAMAIS ce journal et le flux
 * LDAP est strictement inchangé.
 *
 * Écriture en `terminate()` (post-review P-1/P-2) : `handle()` se réduit à
 * `return $next($request)` et toute la logique d'audit vit dans
 * `terminate(Request, Response)`, exécuté par le Kernel APRÈS l'envoi de la
 * réponse au client. Deux bénéfices :
 *  - P-2 : l'INSERT d'audit sort de la pile de réponse → zéro latence ajoutée
 *    au TTFB (l'écriture se fait connexion HTTP déjà fermée).
 *  - P-1 : une requête qui lève une exception non catchée est convertie en
 *    réponse 500 par le handler d'exceptions AVANT que `terminate()` ne soit
 *    appelé → l'action en erreur est désormais auditée (`status_code=500`).
 *
 * IMPORTANT (sémantique Laravel) : `terminate()` reçoit `$request` ET
 * `$response` en ARGUMENTS. Le Kernel re-résout le middleware via le container
 * pour appeler `terminate()` — l'instance peut différer de celle qui a exécuté
 * `handle()`. Aucun état ne doit donc transiter par une propriété d'instance :
 * tout est dérivé des 2 arguments + façades (`Auth::user()`, `config(...)`).
 *
 * Périmètre (D-2 / Q-1) : journalise les requêtes MUTANTES
 * (POST/PUT/PATCH/DELETE) ET les `GET` dont le nom de route figure dans
 * `config('federated_auth.audit.sensitive_get_routes')` (écrans à PII élève).
 * Les `GET` non sensibles ne sont pas journalisés.
 *
 * Écriture best-effort / fail-soft (D-3) : enveloppée d'un `try/catch` — un
 * échec d'audit ne dégrade JAMAIS la réponse métier, et est tracé sans PII
 * dans le channel `federated-auth` (`action_type=federated.audit.write_failed`).
 *
 * Dénormalisation à l'instant de l'action (D-5) : login + sub + nom + rôle
 * Spatie actif sont COPIÉS dans la ligne — avant toute anonymisation ultérieure
 * de l'identité externe (Story 20.2).
 */
class AuditExternalAction
{
    /** Méthodes considérées « mutantes » (toujours auditées). */
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Pass-through : on n'écrit RIEN ici. L'audit est différé à `terminate()`
     * (post-réponse) — cf. doc de classe (P-1/P-2).
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Écriture de l'audit APRÈS l'envoi de la réponse au client (terminable
     * middleware). Reçoit `$request` ET `$response` du Kernel — aucun état
     * d'instance n'est utilisé (l'instance peut différer de celle de `handle()`).
     *
     * P-1 : sur une exception non catchée, le handler a déjà converti le throw
     * en réponse 500 ; `terminate()` est appelé avec ce `$response` 500 → l'action
     * en erreur est auditée (`status_code=500`).
     */
    public function terminate(Request $request, Response $response): void
    {
        // Discrimination externe vs AD (AC2) : aucune écriture hors session
        // fédérée. Le flux LDAP n'est jamais touché.
        if (! FederatedSession::isFederated($request)) {
            return;
        }

        if (! $this->shouldAudit($request)) {
            return;
        }

        $this->writeAudit($request, $response);
    }

    /**
     * La requête entre-t-elle dans le périmètre d'audit (D-2/Q-1) :
     * mutation OU `GET` sur une route sensible (allowlist) ?
     */
    private function shouldAudit(Request $request): bool
    {
        $method = strtoupper($request->getMethod());

        if (in_array($method, self::MUTATING_METHODS, true)) {
            return true;
        }

        if ($method === 'GET') {
            return $this->isSensitiveGetRoute($request);
        }

        return false;
    }

    /**
     * Le `GET` cible-t-il une route nommée présente dans l'allowlist
     * `audit.sensitive_get_routes` (matching wildcard `Str::is`) ?
     */
    private function isSensitiveGetRoute(Request $request): bool
    {
        $routeName = $request->route()?->getName();
        if ($routeName === null || $routeName === '') {
            return false;
        }

        /** @var array<int,string> $patterns */
        $patterns = (array) config('federated_auth.audit.sensitive_get_routes', []);

        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Écriture best-effort de la ligne d'audit dénormalisée (D-3/D-5).
     */
    private function writeAudit(Request $request, Response $response): void
    {
        try {
            /** @var User|null $user */
            $user = Auth::user();
            if (! $user instanceof User) {
                // Pas d'utilisateur résolu (cas limite) : rien à dénormaliser.
                return;
            }

            // external_sub clair lu sur l'identité liée (corrélation + copie).
            $identity = $user->external_identity_id !== null
                ? ExternalIdentity::withTrashed()->find($user->external_identity_id)
                : null;

            // Rôle Spatie ACTIF (cohérence 20.3) : la source de vérité de
            // `actor_role` est `getRoleNames()` (appliqué par `syncRoles`).
            $actorRole = $user->getRoleNames()->first();

            ExternalActionAuditLog::record(
                actorLogin: $user->login,
                actorExternalSub: $identity?->external_sub,
                actorName: $user->fullname,
                actorRole: $actorRole,
                httpMethod: strtoupper($request->getMethod()),
                path: $request->path(),
                statusCode: $response->getStatusCode(),
                routeName: $request->route()?->getName(),
                actionLabel: null,
                externalIdentityId: $user->external_identity_id,
                userId: $user->id,
            );
        } catch (\Throwable $e) {
            // Best-effort (D-3) : on ne casse pas la requête métier. On trace
            // l'échec SANS PII (AC5/AC7). ATTENTION : `$e->getMessage()` d'une
            // erreur DB ré-imprime le SQL AVEC les valeurs liées (login/nom/sub
            // = PII) → on NE logge JAMAIS le message brut, seulement la CLASSE
            // d'exception (suffisante pour le diagnostic, jamais ré-identifiante).
            Log::channel('federated-auth')->warning('[AuditExternalAction] federated.audit.write_failed', [
                'action_type' => 'federated.audit.write_failed',
                'exception' => $e::class,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workstation;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\TargetContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Story 23.5 — `GET /api/v1/agent/state` (route `agent.v1.state`).
 *
 * Premier endpoint authentifié du canal agent desired-state : sert
 * l'enveloppe `se5.desired-state/v1` compilée pour (poste authentifié,
 * user optionnel). Le corps 200 est l'enveloppe contrat BRUTE — jamais le
 * wrapper SE5 `{success, …}` : l'agent parse le contrat v1, et l'ETag est
 * calculé sur l'enveloppe (un wrapper fausserait le hash).
 *
 * Controller mince : la compilation vit dans {@see StateCompiler} (D2),
 * l'auth et toutes les écritures `agent_*` (check-in, rotation) dans le
 * middleware `agent.token` — ici AUCUNE écriture, aucune précédence.
 *
 * Réponse conditionnelle (FR6) : ETag = `StateHasher::hashState()` (forme
 * quotée RFC 7232 via `setEtag()`), `If-None-Match` correspondant → 304
 * sans corps via `isNotModified()` (qui gère guillemets, `W/`, listes, `*`).
 * L'ETag est opaque de bout en bout : l'agent stocke le header verbatim,
 * un cache par couple (poste, user) — cf. docs/agent/state-endpoint.md.
 *
 * Middlewares (routes/api.php) : `auth.v1.secure-headers` + `throttle:60,1`
 * + `agent.token`. Erreurs 401/403 = formats du middleware 23.2, intouchés.
 */
class StateController extends Controller
{
    public function __construct(
        private readonly StateCompiler $compiler,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var Workstation $workstation */
        $workstation = $request->attributes->get('agent.workstation');
        $user = $this->resolveUser($request, $workstation);

        $state = $this->compiler->compile(TargetContext::for($workstation, $user));

        // Flags iso canonicalisation StateHasher : le wire format reste
        // lisible (slashes et UTF-8 non échappés) en curl/jq.
        $response = response()->json($state, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->setEtag($this->compiler->hashState($state));

        if ($response->isNotModified($request)) {
            // isNotModified() a transformé la réponse : 304, corps vidé,
            // ETag conservé. Le 200 est déjà tracé par agent.state.compiled.
            Log::channel('agent')->debug('[StateController] agent.state.not_modified', [
                'action_type' => 'agent.state.not_modified',
                'workstation_id' => $workstation->id,
                'user' => $user?->login,
            ]);
        }

        return $response;
    }

    /**
     * Résolution du user de session (décision n° 1) : `?user=<login>`,
     * lookup case-insensitive. Login inconnu ou compte local (admin local,
     * compte hors SE5 — cas légitime du compagnon) → compilation
     * machine-only, JAMAIS d'erreur : une session locale doit recevoir un
     * état. Le poste (SYSTEM) est l'autorité sur qui est dans SA session —
     * pas de cloisonnement par user dans la portée du token.
     */
    private function resolveUser(Request $request, Workstation $workstation): ?User
    {
        $login = $request->query('user');
        if (! is_string($login) || trim($login) === '') {
            return null;
        }
        $login = trim($login);

        $user = User::findByLogin($login);
        if ($user === null) {
            Log::channel('agent')->info('[StateController] agent.state.unknown_user', [
                'action_type' => 'agent.state.unknown_user',
                'workstation_id' => $workstation->id,
                // Input client non authentifié : borné avant log (P5 23.2).
                'login' => Str::limit($login, 255),
            ]);
        }

        return $user;
    }
}

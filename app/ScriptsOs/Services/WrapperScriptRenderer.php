<?php

declare(strict_types=1);

namespace App\ScriptsOs\Services;

use App\ScriptsOs\Enums\ScriptExecutionAction;
use App\ScriptsOs\Enums\ScriptExecutionOs;
use App\ScriptsOs\Enums\ScriptExecutionSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Story 16.12 — AC3.1 / D4.
 *
 * Génère le wrapper de script (cmd Windows / sh Linux) qui emballe un
 * script user pour capturer stdout/stderr/exit_code/duration et POST le
 * résultat sur `/api/v1/script-execution-logs` avec idempotence
 * `correlation_id` UNIQUE.
 *
 * **Consommé par Story 17.3** (résolution scripts managés). 16.12 livre
 * le service + les templates ; 17.3 appelle `wrap()` lors du rendu de
 * `/api/v1/scripts/{id}/content`.
 *
 * **PAS de dépendance circulaire** : la signature prend un `string
 * $scriptContent` opaque + métadonnées en arguments. Le service ne
 * référence ni `App\Winscripts\*` ni `App\Linscripts\*`.
 *
 * **PAS de secret dans le wrapper rendu** : le poste lit son `access_token`
 * depuis son storage local sécurisé (DPAPI HKLM Windows / fichier 0600
 * Linux — pattern iso 16.11 D11).
 *
 * **Cache statique** : le template Blade ne dépend pas du contenu user
 * (variables injectées) — on cache le template compilé par OS. `clearCache()`
 * exposé pour les tests.
 */
class WrapperScriptRenderer
{
    /** @var array<string,string> */
    private static array $templateCache = [];

    /**
     * Rend le wrapper pour le script donné.
     *
     * @param string                  $scriptContent  Contenu brut du script user (sera encodé base64 dans le wrapper).
     * @param ScriptExecutionAction   $action         Type d'évènement (logon|startup|shutdown|logoff|oneshot).
     * @param ScriptExecutionOs       $os             OS cible (windows|linux).
     * @param int|null                $scriptId       FK soft vers windows_scripts.id ou linux_scripts.id, ou null (legacy).
     * @param ScriptExecutionSource   $source         Origine logique (par défaut managed_script).
     *
     * @return string Le wrapper rendu (texte plain — cmd ou sh selon $os).
     */
    public function wrap(
        string $scriptContent,
        ScriptExecutionAction $action,
        ScriptExecutionOs $os,
        ?int $scriptId = null,
        ScriptExecutionSource $source = ScriptExecutionSource::MANAGED_SCRIPT,
    ): string {
        $correlationId = (string) Str::uuid();
        $scriptContentB64 = base64_encode($scriptContent);

        $vars = [
            'script_content_b64' => $scriptContentB64,
            'correlation_id' => $correlationId,
            'script_id' => $scriptId,
            'source' => $source->value,
            'action' => $action->value,
            'os' => $os->value,
            'endpoint_url' => $this->resolveEndpointUrl(),
            'server_time_iso' => Carbon::now()->toIso8601String(),
        ];

        $rendered = match ($os) {
            ScriptExecutionOs::WINDOWS => $this->renderTemplate('auth.v1.wrapper-cmd', $vars),
            ScriptExecutionOs::LINUX => $this->renderTemplate('auth.v1.wrapper-sh', $vars),
        };

        Log::channel('scriptsos')->debug('scriptsos.wrapper.rendered', [
            'event' => 'scriptsos.wrapper.rendered',
            'os' => $os->value,
            'action' => $action->value,
            'source' => $source->value,
            'script_id' => $scriptId,
            'correlation_id' => $correlationId,
            'bytes_in' => strlen($scriptContent),
            'bytes_out' => strlen($rendered),
        ]);

        return $rendered;
    }

    /**
     * Vide le cache statique des templates (utilisé par les tests pour
     * forcer un re-render après modification d'un template).
     */
    public static function clearCache(): void
    {
        self::$templateCache = [];
    }

    /**
     * Rend un template Blade. Note : on ne peut pas mettre en cache le
     * rendu final (qui dépend des variables) — on cache uniquement la vue
     * compilée Blade (gérée par Laravel). Le cache statique de cette
     * classe sert de marqueur "template existe" pour les tests.
     *
     * @param array<string,mixed> $vars
     */
    private function renderTemplate(string $view, array $vars): string
    {
        // Marqueur cache (utile pour clearCache test) — on stocke le path
        // résolu pour vérifier qu'il existe avant render.
        if (! isset(self::$templateCache[$view])) {
            self::$templateCache[$view] = $view;
        }

        return View::make($view, $vars)->render();
    }

    /**
     * URL absolue de l'endpoint d'ingestion. Utilise la route nommée
     * `scriptsos.logs.ingest` (D3) — fallback string fixe si la route
     * n'est pas encore chargée (cas tests unit isolated).
     */
    private function resolveEndpointUrl(): string
    {
        try {
            return route('scriptsos.logs.ingest', [], true);
        } catch (\Throwable) {
            // Tests unit qui n'ont pas booté les routes — fallback.
            $base = rtrim((string) config('app.url', 'https://localhost'), '/');

            return $base . '/api/v1/script-execution-logs';
        }
    }
}

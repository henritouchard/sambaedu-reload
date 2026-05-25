<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Http\Controllers;

use App\Wpkg\Deployment\Services\WingetPackagesResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @legacy-port path="sambaedu/wpkg/winget_out.php"
 * @see _bmad-output/implementation-artifacts/17-6-portage-endpoints-wpkg-linux-winget.md
 *
 * Story 17.6 / AC2 — Endpoint HTTP `/wpkg/winget_out.php`.
 *
 * Consommé par `install/os/SambaEdu/install.ps1` :
 *   `Invoke-RestMethod -Method Post -Uri .../wpkg/winget_out.php`
 *   `-Form @{ machine=$env:ComputerName; list=$list; action='list' }`
 * où `$list = Get-WinGetPackage | ConvertTo-Json`.
 *
 * Retourne la décision JSON `{install?, upgrade?, uninstall?}`
 * (`Content-Type: text/json` — non-standard, parité stricte legacy `:193`).
 *
 * Résolution du poste par `machine` (= `$env:ComputerName`, déjà le hostname,
 * D1 — aucun écart d'intégration) via `WorkstationPackagesResolver` (15.2,
 * Eloquent-only). Logique de mapping déléguée à `WingetPackagesResolver`.
 *
 * Flag d'activation `config('sambaedu.wpkg.winget_enabled')` (parité
 * `$config['winget']` legacy `:23-26` — `400` si désactivé).
 *
 * Pas d'auth JWT (D2 / `feedback_auth_iso_legacy`) : le poste n'est pas encore
 * enrôlé pendant l'install OS. Protection = `local.request` + throttle.
 *
 * **Aucune écriture `/tmp`** (le legacy le faisait en debug — non porté, AC2.6).
 */
final class WingetOutController
{
    public function handle(Request $request, WingetPackagesResolver $resolver): Response
    {
        // Parité `winget_out.php:23-26` : flag winget off → 400 Bad request.
        if (! config('sambaedu.wpkg.winget_enabled', false)) {
            return $this->badRequest();
        }

        // Parité `winget_out.php:20-22` : action / machine / list (POST).
        $action = (string) ($request->input('action') ?? '');
        $machine = (string) ($request->input('machine') ?? '');
        $list = $request->input('list', '');
        $list = is_string($list) ? $list : '';

        // Parité `winget_out.php:28,195-196` : list vide OU machine vide OU
        // action != "list" → 400 Bad request. (`$list` est la string JSON
        // brute ; `"[]"` n'est PAS vide → machine vierge passe la validation.)
        if ($list === '' || $machine === '' || $action !== 'list') {
            return $this->badRequest();
        }

        // Parité `winget_out.php:29` : json_decode du payload local.
        // Borne D7 : décodage échoue / non-tableau → 400 propre.
        $localApps = json_decode($list, true);
        if (! is_array($localApps)) {
            return $this->badRequest();
        }

        // Robustesse : ne garder que les entrées tableau (chaque entrée
        // Get-WinGetPackage est un objet → array associatif).
        $localApps = array_values(array_filter($localApps, 'is_array'));

        $winget = $resolver->resolve($machine, $localApps);

        // Parité `winget_out.php:193-194` : text/json + JSON_PRETTY_PRINT.
        return response(
            (string) json_encode($winget, JSON_PRETTY_PRINT),
            200,
            ['Content-Type' => 'text/json']
        );
    }

    /**
     * Réponse 400 iso-legacy (`header("HTTP/1.1 400 Bad request")`).
     */
    private function badRequest(): Response
    {
        return response('', 400);
    }
}

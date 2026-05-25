<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Http\Controllers;

use App\Services\AppCustomization\Contracts\AppContextRepository;
use App\Wpkg\Deployment\Support\ApplicationXmlReader;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * @legacy-port path="sambaedu/wpkg/linux_out.php"
 * @see _bmad-output/implementation-artifacts/17-6-portage-endpoints-wpkg-linux-winget.md
 *
 * Story 17.6 / AC1 — Endpoint HTTP `/wpkg/linux_out.php`.
 *
 * Consommé par `applications/wpkg/startup.linux` (`curl -F "id=$id" .../wpkg/linux_out.php`).
 * Génère la liste plain-text des **paquets APT** applicables au poste :
 * `"pkg1 pkg2 pkg3"` (espaces simples, `Content-Type: text/plain`).
 *
 * Parité iso-legacy stricte (correctif post-review #1, décision Henri 2026-05-25
 * « aligner sur les 6 siblings ») :
 *   - Le legacy `linux_out.php:17` fait `apcu_fetch("apps.$id")` où
 *     `$id = md5(strtolower($user).strtolower($machine).$action.$application)`
 *     (posé par le pipeline d'assembly des scripts, `ApplicationScriptsGenerator`).
 *   - Le script `startup.linux` envoie ce **md5** (pas le hostname). Côté natif,
 *     ce contexte pré-calculé est écrit dans le store `app_context` (clé
 *     `apps.<md5>`, TTL 1800s) par `CacheAppContextWriter` (16.7/16.11/16.15),
 *     exactement comme pour les 6 autres endpoints `*_out.php` natifs
 *     (`wallpaper`, `firefox`, `thunderbird`, `network`, `veyon`, `associations`).
 *   - On lit donc `AppContextRepository::findById($id)` (md5 validé
 *     `^[a-f0-9]{32}$`), puis on extrait la liste pré-résolue
 *     `raw['liste_applications']` (passthrough iso-legacy, liste plate d'`app_id`
 *     lowercase). **Aucun appel `WorkstationPackagesResolver`** : le contexte
 *     porte déjà la liste applicable au poste (résolue à l'assembly).
 *
 * Pas d'auth JWT (D2 / `feedback_auth_iso_legacy`) : le poste n'est pas encore
 * enrôlé au boot. Protection = `local.request` (IP allowlist LAN) + throttle.
 */
final class LinuxOutController
{
    public function __construct(
        private readonly AppContextRepository $contextRepository,
    ) {
    }

    public function handle(
        Request $request,
        ApplicationXmlReader $reader,
    ): Response {
        // Parité `linux_out.php:13` : id depuis GET ou POST.
        $id = (string) ($request->input('id') ?? '');

        // Parité `linux_out.php:14-16` : id vide → réponse vide (le legacy fait
        // `exit()` après le header par défaut, soit body "" en 200). On borne
        // aussi un id non-md5 (le legacy `apcu_fetch` sur une clé invalide
        // retournerait false → bloc non exécuté → body vide).
        if (! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return $this->emptyBody();
        }

        // Parité `linux_out.php:17` : `apcu_fetch("apps.$id")`. Contexte
        // expiré/absent → body "" (le legacy n'exécute pas le bloc `if ($info)`,
        // donc aucun header ni body). Iso siblings : Log::debug, pas info
        // (boot de masse).
        $context = $this->contextRepository->findById($id);
        if ($context === null) {
            Log::channel('wpkg-deploy')->debug('[LinuxOutController] context expired', ['id' => $id]);

            return $this->emptyBody();
        }

        // Parité `linux_out.php:18` : `$liste_applications = $info['liste_applications']`.
        // Liste plate d'`app_id` (lowercase, passthrough) pré-résolue à
        // l'assembly du script (équivalent natif de
        // `ApplicationScriptsGenerator::resolveInstalledApplications`).
        $appIds = $this->extractListeApplications($context->raw);

        // S1 (Henri) : `loadByAppIds` filtre `->installed()` (parité packages.xml
        // qui ne contient que les apps Installed) et matche case-insensitive.
        $applications = $reader->loadByAppIds($appIds);

        // Parité `linux_out.php:26-43` : pour chaque appli applicable, le nom du
        // paquet APT (fallback strtolower(app_id)).
        $packages = $applications
            ->map(static fn ($app): string => $reader->aptPackageFor($app))
            ->all();

        // Parité `linux_out.php:44-45` : `header('Content-type: text/plain')` +
        // `echo implode(" ", $liste)` (espaces simples, pas de newline final).
        return response(implode(' ', $packages), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Extrait la liste plate d'`app_id` depuis `raw['liste_applications']`
     * (parité `$info['liste_applications']`). Robuste : ne garde que les chaînes
     * non-vides.
     *
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private function extractListeApplications(array $raw): array
    {
        $liste = $raw['liste_applications'] ?? [];
        if (! is_array($liste)) {
            return [];
        }

        return array_values(array_filter(
            $liste,
            static fn ($v): bool => is_string($v) && $v !== '',
        ));
    }

    /**
     * Réponse iso-legacy body vide. Le legacy ne pose le header `text/plain`
     * que dans le bloc cache-hit (`linux_out.php:44`) ; le natif le pose même
     * sur le cas vide (micro-divergence S4, sans impact — `startup.linux`
     * ignore le Content-Type, il split `for p in $packages` sur les espaces).
     */
    private function emptyBody(): Response
    {
        return response('', 200, ['Content-Type' => 'text/plain']);
    }
}

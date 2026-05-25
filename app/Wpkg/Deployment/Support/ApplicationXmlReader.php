<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Support;

use App\Models\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @legacy-port path="sambaedu/wpkg/linux_out.php"
 * @legacy-port path="sambaedu/wpkg/winget_out.php"
 * @see _bmad-output/implementation-artifacts/17-6-portage-endpoints-wpkg-linux-winget.md
 *
 * Story 17.6 / D4 — Helper d'extraction des attributs depuis le fragment
 * `Application::$xml` (parité DOM legacy `linux_out.php:26-43` /
 * `winget_out.php:70-100`).
 *
 * Le champ `Application::$xml` est le fragment `<package id="<app_id>" ...>...</package>`
 * concaténé par `PackagesXmlService::regenerate()`. C'est la même source que le
 * `packages.xml` legacy chargé par `$xml->load($url_packages)`.
 *
 * Le parsing DOM est **robuste** (pattern iso `PackagesXmlService:40-47`) :
 * `libxml_use_internal_errors(true)` + skip+log si XML invalide. Un fragment
 * cassé d'une seule application ne casse jamais la réponse globale.
 *
 * Chargement **batch** (pas de N+1) : `loadByAppIds()` fait une seule requête
 * Eloquent pour tous les `app_id` résolus par `WorkstationPackagesResolver`.
 *
 * Non-`final` : ce helper est mocké dans `WingetPackagesResolverTest` pour
 * isoler la logique de merge/version du service (parité PackagesXmlService,
 * également non-final pour la même raison de testabilité).
 */
class ApplicationXmlReader
{
    /**
     * Charge en une seule requête les `Application` **installées** correspondant
     * aux `app_id` fournis, indexées par `app_id` (parité : on conserve l'ordre
     * du resolver via le tableau d'entrée, pas l'ordre Eloquent).
     *
     * Filtre `->installed()` (S1, décision Henri 2026-05-25) : parité stricte
     * avec le `packages.xml` legacy, régénéré côté natif depuis
     * `Application::installed()` (`PackagesXmlService:29`). Une app assignée au
     * poste mais au statut `Available`/`UpdateAvailable` est donc exclue — comme
     * elle l'est côté legacy (absente du `packages.xml`).
     *
     * Matching **case-insensitive** sur `app_id` (parité legacy
     * `array_search(strtolower($package->getAttribute('id')), $liste_applications)`
     * où `$liste_applications` est déjà lowercase). Permet à `linux_out`
     * d'utiliser la `liste_applications` lowercase du contexte `apps.<md5>` sans
     * dépendre de la collation DB ; sans impact pour `winget_out` (qui passe les
     * `app_id` bruts du resolver = même casse que la DB).
     *
     * @param  iterable<int, string>  $appIds
     * @return Collection<int, Application>  Applications dans l'ordre des $appIds
     */
    public function loadByAppIds(iterable $appIds): Collection
    {
        $ordered = collect($appIds)
            ->filter(static fn ($id): bool => is_string($id) && $id !== '')
            ->values();

        if ($ordered->isEmpty()) {
            return collect();
        }

        $loweredIds = $ordered
            ->map(static fn (string $id): string => strtolower($id))
            ->unique()
            ->values()
            ->all();

        // 1 requête — pas de N+1 (D4). Filtre `installed()` (S1, parité
        // packages.xml) + match case-insensitive sur `app_id`
        // (`WHERE status = Installed AND LOWER(app_id) IN (...)`).
        $byAppId = Application::query()
            ->installed()
            ->whereIn(DB::raw('LOWER(app_id)'), $loweredIds)
            ->get(['id', 'app_id', 'xml', 'status'])
            // Index case-insensitive (parité legacy lowercase, robustesse collation).
            ->keyBy(static fn (Application $app): string => strtolower((string) $app->app_id));

        // On réordonne selon l'ordre du resolver (alpha ASC, parité D1/D8),
        // match insensible à la casse.
        return $ordered
            ->map(static fn (string $appId) => $byAppId->get(strtolower($appId)))
            ->filter()
            ->values();
    }

    /**
     * Parse le fragment `xml` d'une application en `DOMDocument`, ou retourne
     * `null` si le XML est absent/invalide (skip+log iso PackagesXmlService).
     */
    public function parseFragment(Application $application): ?\DOMDocument
    {
        $xml = (string) ($application->xml ?? '');
        if ($xml === '') {
            return null;
        }

        $doc = new \DOMDocument();
        $doc->formatOutput = true;
        $doc->preserveWhiteSpace = false;

        $prev = libxml_use_internal_errors(true);
        $parsed = $doc->loadXML($xml);
        libxml_use_internal_errors($prev);

        if (! $parsed || $doc->documentElement === null) {
            Log::channel('wpkg-deploy')->warning(
                '[ApplicationXmlReader] XML invalide pour application, skip',
                ['app_id' => $application->app_id]
            );

            return null;
        }

        return $doc;
    }

    /**
     * Extrait le nom du paquet APT du 1er noeud `<linux type="apt">@package`
     * (parité `linux_out.php:29-38`). Fallback `strtolower($app_id)` si aucun
     * noeud apt ou attribut `package` vide.
     */
    public function aptPackageFor(Application $application): string
    {
        $fallback = strtolower((string) $application->app_id);

        $doc = $this->parseFragment($application);
        if ($doc === null) {
            return $fallback;
        }

        $name = '';
        foreach ($doc->getElementsByTagName('linux') as $node) {
            /** @var \DOMElement $node */
            if ($node->getAttribute('type') === 'apt') {
                // Parité legacy : la dernière occurrence apt l'emporte
                // (`foreach` sans break — `linux_out.php:30-34`).
                $name = $node->getAttribute('package');
            }
        }

        // Parité `linux_out.php:36-38` : par défaut on considère qu'il peut
        // exister un paquet debian du nom de l'appli.
        if ($name === '') {
            $name = $fallback;
        }

        return $name;
    }

    /**
     * Extrait les entrées winget (`<windows type="winget">`) d'une application
     * (parité `winget_out.php:72-98`). Chaque entrée est un tableau associatif
     * `{Id, Source, Version?, Custom?, Override?}` (clés optionnelles présentes
     * uniquement si l'attribut XML est non-vide).
     *
     * @return list<array<string, string>>
     */
    public function wingetEntriesFor(Application $application): array
    {
        $doc = $this->parseFragment($application);
        if ($doc === null) {
            return [];
        }

        $entries = [];
        foreach ($doc->getElementsByTagName('windows') as $node) {
            /** @var \DOMElement $node */
            if ($node->getAttribute('type') !== 'winget') {
                continue;
            }

            // #7 — `$app` est réinitialisé à CHAQUE noeud <windows>. Le legacy
            // `winget_out.php` ne le réinitialise jamais (le `$app` d'un package
            // « bave » sur le suivant : attributs Version/Custom/Override/Source
            // hérités d'un package précédent quand le noeud courant ne les pose
            // pas). Cette réinitialisation corrige une pollution d'attributs
            // inter-packages du legacy — divergence bénéfique assumée (la liste
            // produite par chaque package est désormais indépendante).
            $app = [];

            // Ordre des clés iso-legacy `winget_out.php:75-93`.
            if ($node->getAttribute('version') !== '') {
                $app['Version'] = $node->getAttribute('version');
            }

            $source = $node->getAttribute('source');
            $app['Source'] = $source !== '' ? $source : 'winget';

            if ($node->getAttribute('custom') !== '') {
                $app['Custom'] = $node->getAttribute('custom');
            }
            if ($node->getAttribute('override') !== '') {
                $app['Override'] = $node->getAttribute('override');
            }

            $app['Id'] = $node->getAttribute('id');

            $entries[] = $app;
        }

        return $entries;
    }
}

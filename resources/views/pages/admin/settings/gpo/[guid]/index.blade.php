<?php

use App\Components\Traits\WithToasts;
use App\Gpo\Services\GpoService;
use App\Gpo\Services\GpoPublisher;
use App\Gpo\Dto\GpoSummary;
use App\Gpo\Dto\GpoLink;
use App\Gpo\Support\CachedGpoLookups;
use App\Gpo\Support\GpoTemplateRegistry;
use App\Gpo\Support\NativeSectionResolver;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — Détail d'une GPO Active Directory.
 *
 * Story 16.2 + Story 16.9 — Détail GPO sous `/admin/settings/gpo/{guid}`.
 * Convention maison filesystem-based router.
 * Consomme GpoService::get/listContainers/getLinks/getInheritance (Story 16.1).
 * Périmètre : lecture seule. CTAs natifs vers les sections gérables (Firefox /
 * Wallpaper / Shortcuts / Wine / Profils itinérants) via NativeSectionResolver
 * quand l'heuristique sur le displayName matche (Story 16.3a). Le bouton
 * "Éditer dans l'ancienne UI" a été retiré : `gestion_gpo.php` est un menu
 * de maintenance legacy (maj base, export) qui ignore tout paramètre de
 * sélection — l'admin doit passer par les CTAs natifs ou la création
 * legacy (gpo-maj.php depuis le listing).
 *
 * Story 16.3a — Enrichissement :
 * - L'heuristique `NATIVE_SECTIONS_HEURISTICS` est migrée vers NativeSectionResolver (AC1.1/AC1.2).
 * - CTAs natifs primaires en header (AC2.1).
 * - Bouton legacy dégradé en secondaire si match (AC2.2).
 * - Encart 16.2 enrichi avec paramètre ?from_gpo (AC2.3).
 */
new #[Title('Détail GPO - SE4FS')] class extends Component {
    use WithToasts;

    // --- Propriétés ---
    public string $guid = '';
    public ?array $gpo = null;
    public array $containers = [];
    public array $linksByContainer = [];
    public array $inheritanceByContainer = [];
    public bool $showAllContainers = false;
    public array $loadErrors = [];
    public bool $hasError = false;

    // --- Publication étage 2 (SYSVOL) — modale confirmation D5 ---
    public bool $isPublishModalOpen = false;
    public bool $isPublishing = false;
    public bool $forceFlag = false;

    /**
     * Comptage postes par OU (Story 16.5 — AC3.2).
     * @var array<string,int>
     */
    public array $workstationCountByOu = [];

    private GpoService $gpoService;
    private GpoTemplateRegistry $templateRegistry;
    private GpoPublisher $publisher;

    /**
     * Livewire invoque boot() avant mount() à chaque cycle. C'est l'endroit
     * canonique du projet pour injecter les services (cf. pattern
     * pages/users/[login]/index.blade.php). Ne pas dupliquer dans mount().
     */
    public function boot(GpoService $service, GpoTemplateRegistry $registry, GpoPublisher $publisher): void
    {
        $this->gpoService = $service;
        $this->templateRegistry = $registry;
        $this->publisher = $publisher;
    }

    public function mount(string $guid): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        // Normalisation : la regex de route accepte le GUID avec ou sans
        // accolades (URL partagées plus user-friendly), mais samba-tool exige
        // le format canonique avec accolades. On normalise ici une seule fois.
        $normalized = $this->normalizeGuid($guid);
        if ($normalized === null) {
            abort(404, 'GUID de GPO invalide.');
        }

        $this->guid = $normalized;
        $this->loadDetail();
    }

    /**
     * Normalise un GUID au format Microsoft canonique avec accolades.
     * Retourne null si le format n'est pas reconnu (defense-in-depth — la
     * regex de route bloque déjà l'essentiel des inputs invalides).
     */
    private function normalizeGuid(string $guid): ?string
    {
        $stripped = trim($guid, '{}');
        if (preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $stripped) !== 1) {
            return null;
        }
        return '{' . $stripped . '}';
    }

    private function loadDetail(): void
    {
        // 1. Charger la GPO principale.
        // L'exception est rattrapée séparément et bascule la page en mode "erreur"
        // (toast + bandeau) — la page reste navigable conformément à AC2.7.
        // L'absence de la GPO (get() === null) est un vrai 404 et doit échapper
        // au try/catch sinon abort(404) serait confondu avec une erreur réseau.
        $gpoObj = null;
        try {
            $gpoObj = $this->gpoService->get($this->guid);
        } catch (\Throwable $e) {
            $this->hasError = true;
            $this->loadErrors['gpo'] = 'Impossible de charger la GPO : ' . $e->getMessage();
            $this->toast('error', 'Erreur GPO', 'Impossible de charger la GPO : ' . $e->getMessage());
            return;
        }

        if ($gpoObj === null) {
            abort(404, 'GPO inexistante.');
        }
        $this->gpo = $gpoObj->toArray();

        // 2. Charger les containers liés
        try {
            $this->containers = $this->gpoService->listContainers($this->guid);
        } catch (\Throwable $e) {
            $this->loadErrors['containers'] = 'Impossible de charger les containers : ' . $e->getMessage();
            $this->containers = [];
        }

        // 3. Charger links + héritage pour les containers à afficher.
        $this->loadContainerDetails($this->desiredContainers());

        // 4. Story 16.5 — Comptage postes par OU pour l'encart Impact.
        $this->workstationCountByOu = $this->countWorkstationsByOu($this->containers);
    }

    /**
     * Story 16.5 — AC3.2. Comptage postes via suffix-match sur `ad_dn`
     * (Eloquent — cf. T0.4 / DO2 / TD-16.5-2). Pas de colonne `ou_dn`
     * dédiée — on utilise le suffixe DN du poste.
     *
     * @param  list<string>  $ouDns
     * @return array<string,int>
     */
    private function countWorkstationsByOu(array $ouDns): array
    {
        $out = [];
        foreach ($ouDns as $dn) {
            if ($dn === '' || $dn === null) {
                continue;
            }
            // Story 16.5 review #4 : échapper wildcards SQL `%` / `_` avant
            // concaténation. Clause `ESCAPE '\'` explicite pour cohérence
            // SQLite (env tests) — PostgreSQL prod accepte l'échappement
            // backslash nativement.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $dn);
            $pattern = '%,' . $escaped;
            try {
                $out[$dn] = DB::table('workstations')
                    ->whereRaw('ad_dn ILIKE ? ESCAPE ?', [$pattern, '\\'])
                    ->whereNull('archived_at')
                    ->count();
            } catch (\Throwable) {
                try {
                    $out[$dn] = DB::table('workstations')
                        ->whereRaw('ad_dn LIKE ? ESCAPE ?', [$pattern, '\\'])
                        ->whereNull('archived_at')
                        ->count();
                } catch (\Throwable) {
                    $out[$dn] = 0;
                }
            }
        }
        return $out;
    }

    /**
     * Story 16.5 — AC3.2. Total agrégé des postes potentiellement affectés.
     */
    public function getTotalImpactProperty(): int
    {
        return array_sum($this->workstationCountByOu);
    }

    /**
     * Containers à afficher selon l'état du toggle "Afficher tous" (cap 5).
     *
     * @return list<string>
     */
    private function desiredContainers(): array
    {
        return $this->showAllContainers
            ? $this->containers
            : array_slice($this->containers, 0, 5);
    }

    /**
     * Charge links + inheritance pour les containers fournis. N'écrase pas
     * les containers déjà chargés (idempotent — utilisé par toggleShowAll
     * pour ne charger que la diff).
     *
     * @param list<string> $containerDns
     */
    private function loadContainerDetails(array $containerDns): void
    {
        foreach ($containerDns as $dn) {
            // Links — skip si déjà chargé
            if (!array_key_exists($dn, $this->linksByContainer)) {
                try {
                    $this->linksByContainer[$dn] = array_map(
                        fn(GpoLink $l) => $l->toArray(),
                        $this->gpoService->getLinks($dn)
                    );
                } catch (\Throwable $e) {
                    $this->loadErrors["links_{$dn}"] = "Links [{$dn}] : " . $e->getMessage();
                    $this->linksByContainer[$dn] = [];
                }
            }

            // Héritage — skip si déjà chargé
            if (!array_key_exists($dn, $this->inheritanceByContainer)) {
                try {
                    $this->inheritanceByContainer[$dn] = $this->gpoService->getInheritance($dn);
                } catch (\Throwable $e) {
                    $this->loadErrors["inheritance_{$dn}"] = "Héritage [{$dn}] : " . $e->getMessage();
                    $this->inheritanceByContainer[$dn] = true; // défaut safe
                }
            }
        }
    }

    /**
     * Bascule "afficher tous les containers" / "réduire". Ne re-charge ni la
     * GPO ni la liste des containers (elles ne changent pas selon le toggle) —
     * uniquement les détails (links + inheritance) des containers manquants.
     */
    public function toggleShowAll(): void
    {
        $this->showAllContainers = !$this->showAllContainers;
        $this->loadContainerDetails($this->desiredContainers());
    }

    /**
     * Retourne les sections natives matchant le displayName (AC2.4 / Story 16.3a).
     *
     * Délègue à NativeSectionResolver::resolve() — AC1.2 (refactor heuristique).
     * Perf : calcul purement en mémoire, aucun appel I/O.
     */
    public function nativeSectionLinks(): array
    {
        return NativeSectionResolver::resolve($this->gpo['displayName'] ?? '');
    }

    /**
     * Cette GPO est-elle publiable ? Vrai ssi une archive-template SE5 matche
     * son displayName (cf. GpoTemplateRegistry). Une GPO créée à la main /
     * built-in / tierce n'a pas de template → pas de bouton de publication.
     */
    public function getIsPublishableProperty(): bool
    {
        $displayName = $this->gpo['displayName'] ?? '';
        if ($displayName === '') {
            return false;
        }
        try {
            return $this->templateRegistry->isPublishable($displayName);
        } catch (\Throwable) {
            // Répertoire des templates inaccessible (hors VM) : on dégrade en
            // "non publiable" plutôt que de casser la page détail.
            return false;
        }
    }

    public function openPublishModal(): void
    {
        $this->forceFlag = false;
        $this->isPublishModalOpen = true;
    }

    public function closePublishModal(): void
    {
        $this->isPublishModalOpen = false;
    }

    /**
     * Publie l'étage 2 (contenu SYSVOL) de cette GPO via GpoPublisher →
     * shim legacy `import_gpo`. Side effect SYSVOL : confirmation D5 obligatoire.
     * Au prochain reboot, les postes liés appliquent le script startup déposé.
     */
    public function confirmPublish(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $displayName = $this->gpo['displayName'] ?? '';
        $force = $this->forceFlag;
        $this->isPublishing = true;
        $this->isPublishModalOpen = false;

        try {
            $template = $this->publisher->publish($displayName, $force);

            // Le versionNumber SYSVOL a été bumpé par import_gpo : invalider le
            // cache santé de cette GPO avant de recharger le détail (iso 16.14 Q2).
            try {
                app(CachedGpoLookups::class)->forgetGpo($this->guid);
            } catch (\Throwable) {
                // best-effort — ne doit pas masquer le succès métier.
            }

            $this->loadDetail();

            $this->toast(
                'success',
                'GPO publiée',
                "L'étage 2 de « {$template->displayName} » a été déposé dans SYSVOL. "
                . 'Au prochain reboot, les postes liés appliqueront le script de démarrage.',
            );
        } catch (\Throwable $e) {
            $this->toast('error', 'Échec de publication', $e->getMessage());
        } finally {
            $this->isPublishing = false;
            $this->forceFlag = false;
        }
    }

    public function formatVersion(?int $version): string
    {
        if ($version === null) {
            return '—';
        }
        $major = $version >> 16;
        $minor = $version & 0xFFFF;
        return $major . '.' . $minor;
    }
};
?>

@php
    $nativeLinks = $this->nativeSectionLinks();
    $hasNativeLinks = count($nativeLinks) > 0;
    $displayedContainers = $showAllContainers ? $containers : array_slice($containers, 0, 5);
    $hasMoreContainers = count($containers) > 5;
    $gpoVersion = $this->formatVersion($gpo['versionNumber'] ?? null);
    $isActive = isset($gpo['versionNumber']) && $gpo['versionNumber'] > 0;
@endphp

<x-organisms.page :title="$gpo['displayName'] ?? 'Détail GPO'" :scrollable="true"
    description="Détail de la Group Policy Object — lecture seule.">

    <x-slot:actions>
        <div class="flex flex-wrap gap-2 items-center">
            <a href="{{ route('admin.gpo.index') }}" class="btn btn-outline btn-sm">
                <i class="fa-solid fa-arrow-left"></i>
                Retour au listing
            </a>

            {{-- Toutes les actions de la GPO regroupées dans un seul dropdown (pattern /users). --}}
            @can('server.admin')
                <x-molecules.action-menu label="Actions" icon="fa-bars" width="w-72" testid="gpo-actions-menu">
                    {{-- Gérer les liaisons (Story 16.5 — AC3.1) --}}
                    <li>
                        <a href="{{ route('admin.gpo.links', ['guid' => trim((string) $this->guid, '{}')]) }}"
                            data-testid="cta-manage-links">
                            <i class="fa-solid fa-link w-4"></i>
                            Gérer les liaisons
                        </a>
                    </li>

                    {{-- Édition native des sections matchées (Story 16.3a — AC2.1) --}}
                    @if ($hasNativeLinks)
                        <li class="menu-title text-xs opacity-60">Édition native</li>
                        @foreach ($nativeLinks as $key => $link)
                            <li>
                                <a href="{{ \App\Gpo\Support\NativeSectionResolver::buildUrl($key, $this->guid) }}"
                                    data-testid="native-cta-{{ $key }}">
                                    <i class="fa-solid {{ $link['icon'] }} w-4"></i>
                                    {{ $link['label'] }}
                                </a>
                            </li>
                        @endforeach
                    @endif

                    {{-- Publication étage 2 — affichée ssi la GPO a une archive-template SE5. --}}
                    @if ($this->isPublishable)
                        <li class="menu-title text-xs opacity-60">SYSVOL</li>
                        <li>
                            <button type="button" wire:click="openPublishModal"
                                wire:loading.attr="disabled" data-testid="open-publish-modal">
                                <i class="fa-solid fa-upload w-4 text-warning"></i>
                                Publier l'étage 2 (SYSVOL)
                            </button>
                        </li>
                    @endif
                </x-molecules.action-menu>
            @endcan
        </div>
    </x-slot:actions>

    <div class="space-y-6">

        {{-- Note transition : si aucun match natif, signaler que l'édition
             native n'est pas encore disponible pour cette section. --}}
        @if (!$hasNativeLinks)
            <div class="alert alert-info shadow-sm">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <p class="text-sm">
                        Cette page est en <strong>lecture seule</strong>.
                        L'édition native de cette section arrive dans les prochaines stories de l'Epic 16.
                    </p>
                </div>
            </div>
        @endif

        {{-- Erreurs partielles --}}
        @if (count($loadErrors) > 0)
            <div class="alert alert-warning shadow-sm">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <p class="font-medium">Détails partiels — certaines sections n'ont pu être chargées</p>
                    <ul class="text-sm mt-1 list-disc list-inside opacity-80">
                        @foreach ($loadErrors as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        {{-- Métadonnées principales --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <h2 class="card-title text-2xl flex items-center gap-3">
                    <i class="fa-solid fa-file-code text-primary"></i>
                    {{ $gpo['displayName'] ?? '—' }}
                    <span class="badge {{ $isActive ? 'badge-success' : 'badge-ghost' }}">
                        {{ $isActive ? 'Active' : 'Inactive' }}
                    </span>
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label class="text-xs font-semibold text-base-content/50 uppercase tracking-wide">GUID</label>
                        <p class="font-mono text-sm mt-1">{{ $gpo['name'] ?? '—' }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-base-content/50 uppercase tracking-wide">Version</label>
                        <p class="font-mono text-sm mt-1">{{ $gpoVersion }}</p>
                        @if (isset($gpo['versionNumber']))
                            <p class="text-xs text-base-content/40">(entier brut : {{ $gpo['versionNumber'] }})</p>
                        @endif
                    </div>
                </div>

                {{-- DN AD (collapsible) --}}
                @if (isset($gpo['dn']) && $gpo['dn'])
                    <div class="collapse collapse-arrow border border-base-300 rounded-lg mt-4">
                        <input type="checkbox" />
                        <div class="collapse-title text-sm font-medium">
                            <i class="fa-solid fa-sitemap text-primary mr-2"></i> DN Active Directory
                        </div>
                        <div class="collapse-content">
                            <p class="font-mono text-xs text-base-content/70 break-all">{{ $gpo['dn'] }}</p>
                        </div>
                    </div>
                @endif

                {{-- Path SYSVOL (collapsible) --}}
                @if (isset($gpo['path']) && $gpo['path'])
                    <div class="collapse collapse-arrow border border-base-300 rounded-lg mt-2">
                        <input type="checkbox" />
                        <div class="collapse-title text-sm font-medium">
                            <i class="fa-solid fa-folder-open text-warning mr-2"></i> Path SYSVOL
                        </div>
                        <div class="collapse-content">
                            <p class="font-mono text-xs text-base-content/70 break-all">{{ $gpo['path'] }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Encart publication étage 2 — explique pourquoi une GPO est ou non
             publiable depuis SE5 (template présent vs contenu rédigé à la main). --}}
        @can('server.admin')
            @if ($this->isPublishable)
                <div class="alert alert-warning shadow-sm" data-testid="publishable-note">
                    <i class="fa-solid fa-upload"></i>
                    <div>
                        <p class="font-medium">Cette GPO est publiable.</p>
                        <p class="text-sm">
                            Une archive-template SambaEdu correspond à son nom : le bouton
                            <strong>« Publier l'étage 2 (SYSVOL) »</strong> (re)dépose son contenu
                            (script de démarrage, policies) dans SYSVOL via <code class="font-mono">import_gpo</code>.
                            Les postes liés l'appliqueront au prochain reboot.
                        </p>
                    </div>
                </div>
            @else
                <div class="alert alert-info shadow-sm" data-testid="not-publishable-note">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        <p class="font-medium">Cette GPO n'est pas publiable depuis SambaEdu.</p>
                        <p class="text-sm">
                            Aucune archive-template ne correspond à son nom : son contenu SYSVOL est
                            <strong>rédigé à la main</strong> (GPO créée manuellement, built-in Windows ou tierce),
                            pas généré par SambaEdu. Il n'y a donc rien à « publier » — pour la remplir,
                            restaurez-la depuis une sauvegarde ou éditez-la manuellement.
                        </p>
                    </div>
                </div>
            @endif
        @endcan

        {{-- Encart "Impact" — Story 16.5 / AC3.2 / D5 --}}
        <div class="card bg-base-100 shadow-sm border border-base-200" data-testid="impact-card">
            <div class="card-body">
                <h3 class="card-title text-lg flex items-center gap-2">
                    <i class="fa-solid fa-bullseye text-warning"></i>
                    Impact de cette GPO
                </h3>
                @if (count($containers) === 0)
                    <div class="text-center py-6 text-base-content/60" data-testid="impact-empty">
                        <i class="fa-solid fa-link-slash text-2xl mb-2"></i>
                        <p class="text-sm">Cette GPO n'a aucun impact — elle n'est liée à aucune OU.</p>
                        @can('server.admin')
                            <a href="{{ route('admin.gpo.links', ['guid' => trim((string) $this->guid, '{}')]) }}"
                                class="btn btn-sm btn-primary mt-3">
                                <i class="fa-solid fa-link"></i>
                                Lier maintenant
                            </a>
                        @endcan
                    </div>
                @else
                    <ul class="space-y-1 mt-2 text-sm">
                        @foreach ($containers as $dn)
                            <li class="flex items-center justify-between gap-3 px-2 py-1.5 rounded hover:bg-base-200/50">
                                <span class="font-mono text-xs text-base-content/70 truncate flex-1" title="{{ $dn }}">{{ $dn }}</span>
                                <span class="badge badge-ghost badge-sm">
                                    <i class="fa-solid fa-desktop mr-1"></i>
                                    {{ $workstationCountByOu[$dn] ?? 0 }} poste(s)
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex items-center justify-between mt-3 pt-3 border-t border-base-300">
                        <p class="text-sm font-medium">
                            <strong>{{ $this->totalImpact }}</strong> poste(s) potentiellement affecté(s)
                        </p>
                        @can('server.admin')
                            <a href="{{ route('admin.gpo.links', ['guid' => trim((string) $this->guid, '{}')]) }}"
                                class="btn btn-sm btn-outline btn-primary" data-testid="cta-detail-impact">
                                Voir l'impact détaillé
                            </a>
                        @endcan
                    </div>
                @endif
            </div>
        </div>

        {{-- Containers liés --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <h3 class="card-title text-lg flex items-center gap-2">
                    <i class="fa-solid fa-diagram-project text-secondary"></i>
                    Containers liés (OUs / Sites / Domain)
                    <span class="badge badge-neutral badge-sm">{{ count($containers) }}</span>
                </h3>

                @if (count($containers) === 0)
                    <div class="text-center py-8 text-base-content/50">
                        <i class="fa-solid fa-link-slash text-2xl mb-2"></i>
                        <p class="text-sm">Cette GPO n'est liée à aucun container AD.</p>
                    </div>
                @else
                    <div class="space-y-3 mt-2">
                        @foreach ($displayedContainers as $dn)
                            <div class="border border-base-300 rounded-lg overflow-hidden">
                                <div class="bg-base-200/50 px-4 py-2 flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2 flex-1 min-w-0">
                                        <i class="fa-solid fa-folder-tree text-primary flex-shrink-0"></i>
                                        <span class="font-mono text-xs text-base-content/80 truncate" title="{{ $dn }}">{{ $dn }}</span>
                                    </div>
                                    <div class="flex-shrink-0">
                                        @if (isset($inheritanceByContainer[$dn]))
                                            @if ($inheritanceByContainer[$dn])
                                                <span class="badge badge-success badge-sm">Héritage actif</span>
                                            @else
                                                <span class="badge badge-warning badge-sm">Héritage bloqué</span>
                                            @endif
                                        @else
                                            <span class="badge badge-ghost badge-sm">Héritage inconnu</span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Liens GPO de ce container --}}
                                @php $links = $linksByContainer[$dn] ?? []; @endphp
                                @if (count($links) > 0)
                                    <div class="px-4 py-2">
                                        <p class="text-xs font-semibold text-base-content/50 uppercase tracking-wide mb-2">GPOs liées à ce container</p>
                                        <div class="space-y-1">
                                            @foreach ($links as $link)
                                                <div class="flex items-center gap-2 py-1 px-2 rounded hover:bg-base-200/50">
                                                    <span class="font-mono text-xs text-base-content/70 flex-1 truncate" title="{{ $link['gpoName'] }}">
                                                        {{ $link['gpoDisplayName'] ?? $link['gpoName'] }}
                                                    </span>
                                                    <div class="flex gap-1 flex-shrink-0">
                                                        @if ($link['enforced'])
                                                            <span class="badge badge-error badge-xs" title="Enforced — héritage obligatoire vers les enfants">Enforced</span>
                                                        @endif
                                                        @if ($link['disabled'])
                                                            <span class="badge badge-ghost badge-xs">Désactivé</span>
                                                        @endif
                                                        @if (!$link['enforced'] && !$link['disabled'])
                                                            <span class="badge badge-success badge-xs">Actif</span>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @else
                                    <div class="px-4 py-2 text-xs text-base-content/40">Aucune GPO liée à ce container.</div>
                                @endif
                            </div>
                        @endforeach

                        {{-- Bouton "Afficher tous" --}}
                        @if ($hasMoreContainers)
                            <div class="text-center mt-2">
                                @if (!$showAllContainers)
                                    <button type="button" class="btn btn-outline btn-sm" wire:click="toggleShowAll">
                                        <i class="fa-solid fa-chevron-down"></i>
                                        Afficher les {{ count($containers) - 5 }} container(s) restant(s)
                                    </button>
                                @else
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleShowAll">
                                        <i class="fa-solid fa-chevron-up"></i>
                                        Réduire l'affichage
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

    </div>

    {{-- Modale confirmation "Publier l'étage 2" (D5) — side effect SYSVOL --}}
    @can('server.admin')
        @if ($this->isPublishable)
            <x-molecules.modal wire:model="isPublishModalOpen" size="max-w-2xl" height="h-auto"
                :title="'Publier l\'étage 2 — ' . ($gpo['displayName'] ?? '')" icon="fa-shield-halved text-warning">
                <x-molecules.modal.section dense>
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div>
                            <p class="font-medium">Cette action écrit dans SYSVOL.</p>
                            <p class="text-sm">
                                Elle (re)importe l'archive-template, spécialise les placeholders
                                (<code class="font-mono">###_…_###</code>) et pousse le contenu sur SYSVOL via
                                <code class="font-mono">samba-tool</code>/<code class="font-mono">smbclient</code>.
                                Au prochain reboot, les postes liés appliqueront le script de démarrage déposé.
                            </p>
                        </div>
                    </div>
                    <div class="form-control mt-3">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" wire:model.live="forceFlag" class="checkbox checkbox-sm"
                                data-testid="force-flag" />
                            <span class="label-text">Forcer même si la version SYSVOL est déjà à jour (équivalent <code class="font-mono">--force</code>).</span>
                        </label>
                    </div>
                    <div class="alert alert-error mt-3">
                        <i class="fa-solid fa-sitemap"></i>
                        <div>
                            <p class="text-sm">
                                <strong>Liaisons OU :</strong> <code class="font-mono">import_gpo</code> applique les
                                liaisons définies dans la template — et <strong>à défaut de section
                                <code class="font-mono">[links]</code>, lie la GPO au domaine entier</strong>. Vérifiez
                                impérativement les liaisons après publication via
                                <code class="font-mono">/admin/settings/gpo/{guid}/links</code>.
                            </p>
                        </div>
                    </div>
                </x-molecules.modal.section>

                <x-slot:footer>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="closePublishModal"
                        data-testid="modal-cancel">
                        Annuler
                    </button>
                    <button type="button" class="btn btn-warning btn-sm" wire:click="confirmPublish"
                        wire:loading.attr="disabled" data-testid="modal-confirm-publish">
                        <span wire:loading.remove wire:target="confirmPublish">
                            <i class="fa-solid fa-upload"></i>
                            Confirmer la publication
                        </span>
                        <span wire:loading wire:target="confirmPublish">
                            <i class="fa-solid fa-circle-notch fa-spin"></i>
                            Import SYSVOL en cours…
                        </span>
                    </button>
                </x-slot:footer>
            </x-molecules.modal>
        @endif
    @endcan
</x-organisms.page>

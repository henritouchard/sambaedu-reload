<?php

use App\Components\Traits\WithToasts;
use App\Gpo\Services\GpoService;
use App\Gpo\Dto\GpoSummary;
use App\Gpo\Dto\GpoLink;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — Détail d'une GPO Active Directory.
 *
 * Story 16.2 — AC Volet 2. Convention maison filesystem-based router.
 * Consomme GpoService::get/listContainers/getLinks/getInheritance (Story 16.1).
 * Périmètre : lecture seule. Bouton "Éditer dans l'ancienne UI" (Décision D2).
 */
new #[Title('Détail GPO - SE4FS')] class extends Component {
    use WithToasts;

    /**
     * Heuristique displayName → URL native (AC2.4, Décision D9).
     * Chaque entrée : clé = label, valeur = [patterns (lowercase contains), url].
     */
    private const NATIVE_SECTIONS_HEURISTICS = [
        'profils-itinerants' => [
            'patterns' => ['redirections', 'roaming', 'profil', 'no_roam'],
            'url' => '/admin/settings?tab=profils-itinerants',
            'label' => 'Gérer les profils itinérants nativement',
            'icon' => 'fa-users-gear',
        ],
        'wallpapers' => [
            'patterns' => ['wallpaper', 'fond-ecran', 'fond_ecran', 'lockscreen'],
            'url' => '/app/parc-settings/wallpapers',
            'label' => 'Gérer les fonds d\'écran',
            'icon' => 'fa-image',
        ],
        'app-customizations' => [
            'patterns' => ['firefox', 'thunderbird', 'app-custom', 'applications'],
            'url' => '/app/parc-settings/app-customizations',
            'label' => 'Personnaliser les applications',
            'icon' => 'fa-puzzle-piece',
        ],
        'shortcuts' => [
            'patterns' => ['shortcut', 'raccourci', 'shortcuts_out'],
            'url' => '/app/shortcuts',
            'label' => 'Gérer les raccourcis',
            'icon' => 'fa-link',
        ],
    ];

    // --- Propriétés ---
    public string $guid = '';
    public ?array $gpo = null;
    public array $containers = [];
    public array $linksByContainer = [];
    public array $inheritanceByContainer = [];
    public bool $showAllContainers = false;
    public array $loadErrors = [];
    public bool $hasError = false;

    private GpoService $gpoService;

    /**
     * Livewire invoque boot() avant mount() à chaque cycle. C'est l'endroit
     * canonique du projet pour injecter les services (cf. pattern
     * pages/users/[login]/index.blade.php). Ne pas dupliquer dans mount().
     */
    public function boot(GpoService $service): void
    {
        $this->gpoService = $service;
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

    /** Retourne les sections natives matchant le displayName (AC2.4). */
    public function nativeSectionLinks(): array
    {
        if (!isset($this->gpo['displayName'])) {
            return [];
        }
        $displayName = strtolower($this->gpo['displayName']);
        $matches = [];

        foreach (self::NATIVE_SECTIONS_HEURISTICS as $key => $heuristic) {
            foreach ($heuristic['patterns'] as $pattern) {
                if (str_contains($displayName, $pattern)) {
                    $matches[$key] = $heuristic;
                    break;
                }
            }
        }

        return $matches;
    }

    public function legacyEditUrl(): string
    {
        $displayName = $this->gpo['displayName'] ?? '';
        return url('/gpo/gestion_gpo.php') . '?' . http_build_query(['selectionne' => $displayName]);
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
    $displayedContainers = $showAllContainers ? $containers : array_slice($containers, 0, 5);
    $hasMoreContainers = count($containers) > 5;
    $gpoVersion = $this->formatVersion($gpo['versionNumber'] ?? null);
    $isActive = isset($gpo['versionNumber']) && $gpo['versionNumber'] > 0;
@endphp

<x-organisms.page :title="$gpo['displayName'] ?? 'Détail GPO'" :scrollable="true"
    description="Détail de la Group Policy Object — lecture seule. L'édition native arrive dans les prochaines stories.">

    <x-slot:actions>
        <div class="flex gap-2 items-center">
            <a href="{{ route('app.gpo.index') }}" class="btn btn-outline btn-sm">
                <i class="fa-solid fa-arrow-left"></i>
                Retour au listing
            </a>
            <a href="{{ $this->legacyEditUrl() }}" target="_blank" rel="noopener noreferrer"
                class="btn btn-primary btn-sm">
                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                Éditer dans l'ancienne UI
            </a>
        </div>
    </x-slot:actions>

    <div class="space-y-6">

        {{-- Note transition --}}
        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="text-sm">
                    Cette page est en <strong>lecture seule</strong>.
                    L'édition native arrive dans les prochaines stories de l'Epic 16.
                    Utilisez le bouton <strong>"Éditer dans l'ancienne UI"</strong> ci-dessus pour modifier cette GPO.
                </p>
            </div>
        </div>

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

        {{-- Encart sections gérables nativement (AC2.4) --}}
        @if (count($nativeLinks) > 0)
            <div class="alert alert-success shadow-sm">
                <i class="fa-solid fa-circle-check"></i>
                <div class="flex-1">
                    <p class="font-medium">Sections de cette GPO gérables nativement</p>
                    <div class="flex flex-wrap gap-2 mt-2">
                        @foreach ($nativeLinks as $key => $link)
                            <a href="{{ url($link['url']) }}" class="btn btn-sm btn-outline btn-success">
                                <i class="fa-solid {{ $link['icon'] }}"></i>
                                {{ $link['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

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
</x-organisms.page>

<?php

use App\Components\Traits\WithToasts;
use App\Gpo\Services\GpoService;
use App\Models\Workstation;
use App\Repositories\OrganizationalUnitRepository;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Page Livewire SFC — Vue inverse OU → GPOs — Story 16.14 C.
 *
 * Permet de sélectionner une OU AD et d'afficher toutes les GPOs
 * qui s'y appliquent (directes + héritées parents, cap 5 niveaux).
 * Comptage des postes affectés (best-effort).
 *
 * Route : GET /admin/settings/gpo/by-ou (admin.gpo.by-ou).
 * Permission : can:server.admin.
 */
new #[Title('GPOs par OU - SE4FS')] class extends Component {
    use WithToasts;

    #[Url]
    public string $selectedOu = '';

    public string $ouSearch = '';
    public array $ouSuggestions = [];
    private array $allOus = [];

    /** @var list<array{gpoName:string,gpoDisplayName:?string,origin:string,enforced:bool,disabled:bool}> */
    public array $gpoLinks = [];
    public bool $inheritanceBlocked = false;
    public ?int $workstationCount = null;
    public bool $countNa = false;
    public bool $isLoading = false;
    public bool $hasError = false;
    public string $errorMessage = '';

    private GpoService $gpoService;

    public function boot(GpoService $service): void
    {
        $this->gpoService = $service;
    }

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $this->loadOus();

        // Si OU pré-sélectionnée via URL, charger immédiatement
        if (!empty($this->selectedOu)) {
            $this->ouSearch = $this->selectedOu;
            $this->loadGposForOu($this->selectedOu);
        }
    }

    private function loadOus(): void
    {
        try {
            /** @var OrganizationalUnitRepository $repo */
            $repo = app(OrganizationalUnitRepository::class);
            $this->allOus = $repo->listAll(300);
        } catch (\Throwable) {
            $this->allOus = [];
        }
    }

    public function updatedOuSearch(): void
    {
        if (strlen($this->ouSearch) < 2) {
            $this->ouSuggestions = [];
            return;
        }
        $search = strtolower($this->ouSearch);
        $this->ouSuggestions = array_slice(
            array_filter(
                $this->allOus,
                fn($ou) => str_contains(strtolower($ou), $search)
            ),
            0,
            20
        );
    }

    public function selectOu(string $ou): void
    {
        $this->selectedOu = $ou;
        $this->ouSearch = $ou;
        $this->ouSuggestions = [];
        $this->loadGposForOu($ou);
    }

    public function clearOu(): void
    {
        $this->selectedOu = '';
        $this->ouSearch = '';
        $this->ouSuggestions = [];
        $this->gpoLinks = [];
        $this->workstationCount = null;
        $this->countNa = false;
        $this->hasError = false;
    }

    /**
     * Charge les GPOs pour une OU donnée + remontée parents (cap 5 niveaux).
     * AC3.3 D5.
     *
     * Validation par whitelist : $ouDn doit appartenir à $this->allOus pour éviter
     * toute injection vers samba-tool ou la requête LIKE (findings #8 + #17).
     */
    public function loadGposForOu(string $ouDn): void
    {
        if (empty($ouDn)) {
            return;
        }

        // Validation whitelist : l'OU doit être connue du repository
        if (!empty($this->allOus) && !in_array($ouDn, $this->allOus, true)) {
            $this->toast('warning', 'OU inconnue', 'L\'OU sélectionnée n\'est pas reconnue dans l\'annuaire AD.');
            $this->gpoLinks = [];
            $this->workstationCount = null;
            $this->countNa = true;
            return;
        }

        $this->isLoading = true;
        $this->hasError = false;
        $this->gpoLinks = [];
        $this->inheritanceBlocked = false;

        try {
            // 1. Liens directs
            $directLinks = $this->gpoService->getLinks($ouDn);
            $allLinks = [];
            foreach ($directLinks as $link) {
                $allLinks[] = [
                    'gpoName'        => $link->gpoName,
                    'gpoDisplayName' => $link->gpoDisplayName,
                    'origin'         => 'Directe',
                    'originDn'       => $ouDn,
                    'enforced'       => $link->enforced,
                    'disabled'       => $link->disabled,
                ];
            }

            // 2. Héritage
            try {
                $this->inheritanceBlocked = !$this->gpoService->getInheritance($ouDn);
            } catch (\Throwable) {
                $this->inheritanceBlocked = false;
            }

            // 3. Remontée parents si héritage non bloqué (cap 5 niveaux)
            if (!$this->inheritanceBlocked) {
                $parentDn = $this->getParentDn($ouDn);
                $depth = 0;
                while ($parentDn !== null && $depth < 5) {
                    try {
                        $parentLinks = $this->gpoService->getLinks($parentDn);
                        foreach ($parentLinks as $link) {
                            $allLinks[] = [
                                'gpoName'        => $link->gpoName,
                                'gpoDisplayName' => $link->gpoDisplayName,
                                'origin'         => 'Héritée de "' . $parentDn . '"',
                                'originDn'       => $parentDn,
                                'enforced'       => $link->enforced,
                                'disabled'       => $link->disabled,
                            ];
                        }
                    } catch (\Throwable) {
                        // Parent non accessible — on continue
                    }
                    $parentDn = $this->getParentDn($parentDn);
                    $depth++;
                }
            }

            $this->gpoLinks = $allLinks;

            // 4. Comptage postes affectés (best-effort D13)
            $this->countWorkstationsForOu($ouDn);

        } catch (\Throwable $e) {
            $this->hasError = true;
            $this->errorMessage = $e->getMessage();
        } finally {
            $this->isLoading = false;
        }
    }

    /**
     * Retourne le DN parent d'un DN LDAP.
     * Ex: "OU=Salles,OU=Postes,DC=example,DC=org" → "OU=Postes,DC=example,DC=org"
     * Retourne null si le DN est à la racine (DC=... uniquement).
     */
    private function getParentDn(string $dn): ?string
    {
        // Split sur la première virgule
        $commaPos = strpos($dn, ',');
        if ($commaPos === false) {
            return null;
        }
        $parent = substr($dn, $commaPos + 1);
        // Si le parent ne contient que des DC=, c'est la racine
        if (!str_contains(strtoupper($parent), 'OU=') && !str_contains(strtoupper($parent), 'CN=')) {
            return null;
        }
        return $parent;
    }

    /**
     * Comptage postes affectés — best-effort (D13 / AC3.4).
     */
    private function countWorkstationsForOu(string $ouDn): void
    {
        try {
            // Si la colonne ad_dn existe sur Workstation, on filtre par OU substring
            if (\Illuminate\Support\Facades\Schema::hasColumn('workstations', 'ad_dn')) {
                // Escape des wildcards LIKE pour éviter l'injection (finding #8)
                $escapedOuDn = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $ouDn);
                $this->workstationCount = Workstation::query()
                    ->where('ad_dn', 'like', '%,' . $escapedOuDn)
                    ->count();
                $this->countNa = false;
            } else {
                $this->workstationCount = null;
                $this->countNa = true;
            }
        } catch (\Throwable) {
            $this->workstationCount = null;
            $this->countNa = true;
        }
    }
};
?>

<x-organisms.page title="GPOs par OU" :scrollable="true"
    description="Visualisez les GPOs appliquées à une OU et leur ordre d'héritage.">

    <div class="space-y-4">

        {{-- Breadcrumb / back link --}}
        <div class="text-sm breadcrumbs">
            <ul>
                <li><a href="{{ route('admin.gpo.index') }}" class="text-base-content/60 hover:text-primary">Toutes les GPOs</a></li>
                <li class="text-base-content/80">Vue par OU</li>
            </ul>
        </div>

        {{-- Sélecteur OU --}}
        <div class="card bg-base-100 border border-base-300 shadow-sm">
            <div class="card-body py-4">
                <h3 class="font-semibold flex items-center gap-2 mb-3">
                    <i class="fa-solid fa-sitemap text-primary text-sm"></i>
                    Sélectionnez une OU
                </h3>

                <div class="relative max-w-2xl">
                    <div class="flex gap-2">
                        <input type="text"
                            wire:model.live.debounce.300ms="ouSearch"
                            class="input input-bordered input-sm flex-1"
                            placeholder="Rechercher une OU par DN (ex: OU=Salles,DC=…)"
                            data-testid="ou-selector-input" />
                        @if (!empty($selectedOu))
                            <button type="button"
                                class="btn btn-ghost btn-sm"
                                wire:click="clearOu"
                                data-testid="clear-ou-btn">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        @endif
                    </div>

                    @if (!empty($ouSuggestions))
                        <ul class="absolute z-20 mt-1 w-full bg-base-100 border border-base-300 rounded-lg shadow-lg max-h-48 overflow-y-auto"
                            data-testid="ou-suggestions-list">
                            @foreach ($ouSuggestions as $suggestion)
                                <li>
                                    <button type="button"
                                        class="w-full text-left px-3 py-2 text-xs hover:bg-base-200 transition-colors font-mono"
                                        wire:click="selectOu(@js($suggestion))"
                                        title="{{ $suggestion }}">
                                        {{ $suggestion }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if (empty($allOus) && empty($selectedOu))
                        <p class="text-xs text-warning mt-2">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Aucune OU découverte dans l'AD. Vérifiez la sync AD ou
                            <a href="{{ route('admin.settings') }}" class="link">consulter les réglages</a>.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Résultats --}}
        @if (!empty($selectedOu))
            <div data-testid="ou-results-section">

                {{-- Chargement --}}
                @if ($isLoading)
                    <div class="flex items-center gap-3 text-base-content/60 py-4">
                        <span class="loading loading-spinner loading-sm"></span>
                        Chargement des GPOs pour {{ $selectedOu }}…
                    </div>
                @endif

                {{-- Erreur --}}
                @if ($hasError)
                    <div class="alert alert-error shadow-sm">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div>
                            <p class="font-medium">Impossible de charger les GPOs pour cette OU</p>
                            <p class="text-sm opacity-80">{{ $errorMessage }}</p>
                        </div>
                    </div>
                @endif

                @if (!$isLoading && !$hasError)
                    {{-- Badge héritage bloqué --}}
                    @if ($inheritanceBlocked)
                        <div class="alert alert-warning shadow-sm" data-testid="inheritance-blocked-badge">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span class="text-sm font-medium">Héritage GPO bloqué sur cette OU — seules les GPOs directes s'appliquent.</span>
                        </div>
                    @endif

                    {{-- Comptage postes --}}
                    <div class="card bg-base-100 border border-base-300 shadow-sm">
                        <div class="card-body py-3 px-4">
                            <div class="flex items-center gap-3 text-sm">
                                <i class="fa-solid fa-computer text-secondary text-base"></i>
                                @if ($countNa)
                                    <span class="text-base-content/60">
                                        N/A (mapping OU non disponible)
                                    </span>
                                    <span class="text-xs text-base-content/40">— Comptage best-effort basé sur le rattachement WorkstationGroup ↔ OU.</span>
                                @else
                                    <span class="font-semibold">{{ $workstationCount ?? 0 }} postes</span>
                                    <span class="text-base-content/60">affectés par les GPOs appliquées à cette OU</span>
                                    <span class="text-xs text-base-content/40">(best-effort)</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Tableau GPOs --}}
                    @if (empty($gpoLinks))
                        <div class="card bg-base-100 border border-base-300 shadow-sm">
                            <div class="card-body py-8 text-center text-base-content/60" data-testid="empty-ou-state">
                                <i class="fa-solid fa-inbox text-3xl opacity-30 mb-2"></i>
                                <p>Aucune GPO appliquée à cette OU.</p>
                            </div>
                        </div>
                    @else
                        <div class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden">
                            <div class="px-4 py-3 border-b border-base-300 text-sm text-base-content/70 flex items-center justify-between">
                                <span>{{ count($gpoLinks) }} GPO(s) appliquée(s) à <span class="font-mono text-xs">{{ $selectedOu }}</span></span>
                                @if (!$inheritanceBlocked)
                                    <span class="badge badge-info badge-sm">Héritage actif</span>
                                @endif
                            </div>
                            <div class="overflow-x-auto">
                                <table class="table table-zebra table-sm" data-testid="gpo-links-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Nom de la GPO</th>
                                            <th>Origine</th>
                                            <th>Options</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($gpoLinks as $i => $link)
                                            @php
                                                $guidClean = trim($link['gpoName'] ?? '', '{}');
                                            @endphp
                                            <tr>
                                                <td class="text-base-content/40 text-xs font-mono">{{ $i + 1 }}</td>
                                                <td>
                                                    <span class="font-medium">{{ $link['gpoDisplayName'] ?? $link['gpoName'] }}</span>
                                                    <div class="font-mono text-xs text-base-content/40">{{ $link['gpoName'] }}</div>
                                                </td>
                                                <td>
                                                    @if ($link['origin'] === 'Directe')
                                                        <span class="badge badge-primary badge-sm">Directe</span>
                                                    @else
                                                        <span class="badge badge-ghost badge-sm text-xs" title="{{ $link['originDn'] ?? '' }}">
                                                            Héritée
                                                        </span>
                                                        <div class="font-mono text-xs text-base-content/40 mt-0.5 truncate max-w-xs">
                                                            {{ $link['originDn'] ?? '' }}
                                                        </div>
                                                    @endif
                                                </td>
                                                <td>
                                                    <div class="flex gap-1">
                                                        @if ($link['enforced'] ?? false)
                                                            <span class="badge badge-warning badge-xs">Enforced</span>
                                                        @endif
                                                        @if ($link['disabled'] ?? false)
                                                            <span class="badge badge-error badge-xs">Désactivée</span>
                                                        @endif
                                                        @if (!($link['enforced'] ?? false) && !($link['disabled'] ?? false))
                                                            <span class="text-base-content/30 text-xs">—</span>
                                                        @endif
                                                    </div>
                                                </td>
                                                <td>
                                                    @if (!empty($guidClean))
                                                        <a href="{{ route('admin.gpo.show', ['guid' => $guidClean]) }}"
                                                            class="btn btn-ghost btn-xs"
                                                            title="Voir le détail de cette GPO">
                                                            <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
                                                            Détail
                                                        </a>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        @else
            {{-- État initial : aucune OU sélectionnée --}}
            <div class="card bg-base-200 border border-base-300 shadow-sm">
                <div class="card-body py-8 text-center text-base-content/60">
                    <i class="fa-solid fa-sitemap text-3xl opacity-30 mb-2"></i>
                    <p class="text-sm">Sélectionnez une OU ci-dessus pour afficher les GPOs qui s'y appliquent.</p>
                </div>
            </div>
        @endif
    </div>
</x-organisms.page>

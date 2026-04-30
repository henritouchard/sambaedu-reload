<?php

use App\Components\Traits\WithToasts;
use App\Services\RoamingProfileService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Story 1bis.18f — Onglet "Profils itinérants" de /admin/settings.
 *
 * 2 sections :
 *   1. Exclusions du profil itinérant (`ExcludeProfileDirs`)
 *      — liste éditable + modale d'ajout + bouton "Mettre à jour la GPO".
 *   2. Statistiques globales — tableau des dossiers > 8 Mo, drill-down par
 *      utilisateur en modale.
 *
 * Sécurité :
 *   - Double guard `Gate::allows('server.admin')` à mount() ET en première
 *     ligne de chaque méthode publique (paranoïa payload Livewire forgé).
 *   - Validation regex anti path-traversal sur `newExclusion` côté UI
 *     (defense-in-depth — le service revalide aussi).
 *
 * Toasts : trait `WithToasts` — toastSuccess/toastError génériques (jamais
 * `$e->getMessage()` exposé — leçon 5.1b post-review #4).
 */
new class extends Component {
    use WithToasts;

    /**
     * @var array<int, string> Liste à plat des `ExcludeProfileDirs`.
     */
    public array $exclusions = [];

    /**
     * @var array<string, array{sum:int|float, average:float, nb:int, user:array<string,float>}>
     */
    public array $statsGlobal = [];

    /** Modale d'ajout d'exclusion. */
    public bool $showAddModal = false;
    public string $newExclusion = '';

    /** Modale drill-down stats par utilisateur. */
    public bool $showStatsModal = false;
    public string $statsPath = '';

    /** Statut de chargement de la GPO (pour message d'alerte UI). */
    public bool $gpoLoadFailed = false;

    private RoamingProfileService $service;

    public function boot(RoamingProfileService $service): void
    {
        $this->service = $service;
    }

    public function mount(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        $this->reloadExclusions();
        $this->reloadStats();
    }

    private function reloadExclusions(): void
    {
        try {
            $this->exclusions = $this->service->getExclusions();
            $this->gpoLoadFailed = false;
        } catch (\Throwable $e) {
            Log::error('[ProfilsItinerantsTab] Echec lecture exclusions', [
                'error' => $e->getMessage(),
            ]);
            $this->exclusions = [];
            $this->gpoLoadFailed = true;
        }
    }

    private function reloadStats(): void
    {
        try {
            $this->statsGlobal = $this->service->getProfileStatsGlobal();
        } catch (\Throwable $e) {
            Log::error('[ProfilsItinerantsTab] Echec lecture stats', [
                'error' => $e->getMessage(),
            ]);
            $this->statsGlobal = [];
        }
    }

    // =========================================================================
    // EXCLUSIONS — CRUD
    // =========================================================================

    public function addExclusion(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        $candidate = trim($this->newExclusion);

        if ($candidate === '') {
            $this->toastError('Le chemin ne peut pas être vide.');
            return;
        }

        // Defense-in-depth UI : validation alignée sur le service (regex
        // VALUE_REGEX + veto explicite sur `..`).
        if (!RoamingProfileService::isValueSafe($candidate)) {
            $this->toastError('Chemin invalide. Caractères autorisés : lettres, chiffres, `_`, `-`, `.`, `/`, espace. La séquence `..` est interdite.');
            return;
        }

        try {
            $newList = array_values(array_unique(array_merge($this->exclusions, [$candidate])));
            $this->service->setExclusions($newList, false);

            $this->reloadExclusions();
            $this->newExclusion = '';
            $this->showAddModal = false;
            $this->toastSuccess('Exclusion ajoutée.');
        } catch (\Throwable $e) {
            Log::error('[ProfilsItinerantsTab] Echec addExclusion', [
                'error' => $e->getMessage(),
            ]);
            $this->toastError("Impossible d'ajouter l'exclusion.");
        }
    }

    public function removeExclusion(int $key): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        if (!array_key_exists($key, $this->exclusions)) {
            return;
        }

        try {
            $newList = $this->exclusions;
            unset($newList[$key]);
            $newList = array_values($newList);

            $this->service->setExclusions($newList, false);
            $this->reloadExclusions();
            $this->toastSuccess('Exclusion supprimée.');
        } catch (\Throwable $e) {
            Log::error('[ProfilsItinerantsTab] Echec removeExclusion', [
                'error' => $e->getMessage(),
            ]);
            $this->toastError("Impossible de supprimer l'exclusion.");
        }
    }

    public function applyToGpo(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        try {
            $this->service->setExclusions($this->exclusions, true);
            $this->toastSuccess('GPO mise à jour. Les postes Windows recevront le changement à leur prochaine application de stratégie.');
        } catch (\Throwable $e) {
            Log::error('[ProfilsItinerantsTab] Echec applyToGpo', [
                'error' => $e->getMessage(),
            ]);
            $this->toastError("Impossible d'appliquer la GPO.");
        }
    }

    // =========================================================================
    // MODALES
    // =========================================================================

    public function openAddModal(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }
        $this->newExclusion = '';
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
        $this->newExclusion = '';
    }

    public function openStats(string $path): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }
        $this->statsPath = $path;
        $this->showStatsModal = true;
    }

    public function closeStats(): void
    {
        $this->showStatsModal = false;
        $this->statsPath = '';
    }

    /**
     * @return array<int, array{user:string, size_mb:float}>
     */
    public function getStatsForCurrentPathProperty(): array
    {
        if ($this->statsPath === '') {
            return [];
        }
        try {
            return $this->service->getProfileStatsForPath($this->statsPath);
        } catch (\Throwable $e) {
            Log::error('[ProfilsItinerantsTab] Echec drill-down stats', [
                'path' => $this->statsPath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
};
?>

<div class="space-y-6">
    {{-- =====================================================================
         Section 1 — Exclusions du profil itinérant
         ===================================================================== --}}
    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <div class="flex items-center justify-between mb-2">
                <h3 class="card-title text-lg">
                    <i class="fa-solid fa-folder-tree mr-2"></i>
                    Exclusions du profil itinérant
                </h3>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary"
                        wire:click="openAddModal">
                        <i class="fa-solid fa-plus"></i>
                        Ajouter une exclusion
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary"
                        wire:click="applyToGpo"
                        wire:loading.attr="disabled" wire:target="applyToGpo">
                        <span wire:loading wire:target="applyToGpo" class="loading loading-spinner loading-xs"></span>
                        <i wire:loading.remove wire:target="applyToGpo" class="fa-solid fa-arrows-rotate"></i>
                        Mettre à jour la GPO
                    </button>
                </div>
            </div>
            <p class="text-sm opacity-70 mb-4">
                Liste des sous-dossiers du profil utilisateur Windows qui ne sont pas synchronisés
                avec le serveur lors de l'ouverture/fermeture de session
                (clé Registry <code>ExcludeProfileDirs</code> de la GPO <code>redirections</code>).
                Le chemin est relatif à <code>%USERPROFILE%</code>.
            </p>

            @if ($gpoLoadFailed)
                <div class="alert alert-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <span class="font-medium">Impossible de charger la GPO <code>redirections</code>.</span>
                        <p class="text-sm">
                            Vérifiez que la GPO existe sur l'AD Samba. Consultez les logs serveur
                            (<code>storage/logs/laravel.log</code> — préfixe <code>[RoamingProfileService]</code>).
                        </p>
                    </div>
                </div>
            @elseif (count($exclusions) === 0)
                <div class="text-center py-8 text-base-content/60">
                    <i class="fa-solid fa-circle-info text-2xl mb-2"></i>
                    <p>Aucune exclusion configurée.</p>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($exclusions as $key => $value)
                        <div class="flex items-center justify-between gap-2 px-3 py-2 bg-base-200 rounded">
                            <div class="flex items-center gap-2 min-w-0">
                                <i class="fa-solid fa-folder text-primary"></i>
                                <code class="text-sm truncate">%USERPROFILE%\{{ $value }}</code>
                            </div>
                            <button type="button" class="btn btn-sm btn-ghost text-error"
                                wire:click="removeExclusion({{ $key }})"
                                wire:confirm="Supprimer l'exclusion {{ $value }} ?">
                                <i class="fa-solid fa-trash"></i>
                                Supprimer
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- =====================================================================
         Section 2 — Statistiques globales des profils itinérants
         ===================================================================== --}}
    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <div class="flex items-center justify-between mb-2">
                <h3 class="card-title text-lg">
                    <i class="fa-solid fa-chart-bar mr-2"></i>
                    Statistiques des profils itinérants
                </h3>
            </div>
            <p class="text-sm opacity-70 mb-4">
                Dossiers du profil itinérant dont la taille dépasse 8 Mo (statistiques mises à
                jour toutes les nuits par le cron <code>du.sh</code>). Cliquez sur un chemin pour
                consulter le détail par utilisateur.
            </p>

            @if (count($statsGlobal) === 0)
                <div class="alert alert-info">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>
                        Aucune donnée disponible. Le fichier <code>/tmp/du.txt</code> est absent ou vide
                        (le cron <code>du.sh</code> n'a pas encore tourné).
                    </span>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Chemin</th>
                                <th class="text-right">Taille moyenne (Mo)</th>
                                <th class="text-right">Taille totale (Mo)</th>
                                <th class="text-right">Utilisateurs</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($statsGlobal as $path => $row)
                                <tr class="hover cursor-pointer" wire:click="openStats(@js($path))">
                                    <td>
                                        <code class="text-sm">{{ str_replace('/', '\\', $path) }}</code>
                                    </td>
                                    <td class="text-right">{{ $row['average'] ?? 0 }}</td>
                                    <td class="text-right">{{ $row['sum'] ?? 0 }}</td>
                                    <td class="text-right">{{ $row['nb'] ?? 0 }}</td>
                                    <td>
                                        <button type="button" class="btn btn-xs btn-ghost"
                                            wire:click.stop="openStats(@js($path))">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                            Détail
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- =====================================================================
         Modale 1 — Ajout d'une exclusion
         ===================================================================== --}}
    <x-molecules.modal wire:model="showAddModal" closeMethod="closeAddModal"
        title="Ajouter une exclusion" icon="fa-folder-plus text-primary"
        size="max-w-lg" height="h-auto">

        <x-molecules.modal.section title="Chemin à exclure" icon="fa-folder text-primary" dense>
            <p class="text-xs text-base-content/70 mb-2">
                Chemin relatif à <code>%USERPROFILE%</code> (ex: <code>AppData/Local/Mozilla</code>).
                Caractères autorisés : lettres, chiffres, <code>_</code>, <code>-</code>,
                <code>.</code>, <code>/</code>, espace.
            </p>
            <div class="flex items-center gap-2">
                <span class="text-sm text-base-content/60 shrink-0">%USERPROFILE%\</span>
                <input type="text" wire:model.defer="newExclusion"
                    class="input input-bordered input-sm flex-1"
                    placeholder="ex: AppData/Local/Mozilla"
                    wire:keydown.enter="addExclusion" />
            </div>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeAddModal">Annuler</button>
            <button type="button" class="btn btn-primary"
                wire:click="addExclusion"
                wire:loading.attr="disabled" wire:target="addExclusion">
                <span wire:loading wire:target="addExclusion" class="loading loading-spinner loading-xs"></span>
                <i wire:loading.remove wire:target="addExclusion" class="fa-solid fa-plus"></i>
                Ajouter
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- =====================================================================
         Modale 2 — Drill-down stats par utilisateur
         ===================================================================== --}}
    <x-molecules.modal wire:model="showStatsModal" closeMethod="closeStats"
        :title="'Détail des profils — ' . str_replace('/', '\\', $statsPath)"
        icon="fa-chart-pie text-primary" size="max-w-2xl" height="h-auto">

        <x-molecules.modal.section title="Utilisateurs" icon="fa-users text-primary" dense>
            @php($rows = $this->statsForCurrentPath)
            @if (count($rows) === 0)
                <div class="text-sm text-base-content/60 py-4 text-center">
                    Aucune donnée disponible pour ce dossier.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Utilisateur</th>
                                <th class="text-right">Taille (Mo)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td>{{ $row['user'] }}</td>
                                    <td class="text-right">{{ $row['size_mb'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeStats">Fermer</button>
        </x-slot:footer>
    </x-molecules.modal>
</div>

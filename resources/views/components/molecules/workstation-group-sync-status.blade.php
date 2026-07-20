<?php

// TODO: supprimer ce composant qui ne devrait plus être utilisé

use Livewire\Component;
use App\Services\AdSync\AdSyncChecker;
use App\Models\WorkstationGroup;
use App\Jobs\AdSync\WorkstationGroupAdSyncJob;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Log;

new class extends Component {
    use WithToasts;
    
    public array $status = [];
    public bool $isChecking = false;
    public bool $showModal = false;
    
    // Sélection des divergences (par clé unique)
    public array $selectedItems = [];
    
    // État des opérations
    public bool $isProcessing = false;

    public function getListeners()
    {
        return ['refresh-sync-status-workstation-groups' => 'checkSync'];
    }

    public function mount(): void
    {
        $this->checkSync();
    }

    public function checkSync(): void
    {
        $this->isChecking = true;
        $this->selectedItems = [];

        try {
            $checker = app(AdSyncChecker::class);
            $this->status = $checker->checkWorkstationGroups();
        } catch (\Exception $e) {
            Log::error('[WorkstationGroupSyncStatus] Erreur: ' . $e->getMessage());
            $this->status = [
                'synced' => false,
                'error' => $e->getMessage(),
                'last_check' => now()->toIso8601String(),
            ];
        }

        $this->isChecking = false;
    }

    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }
    
    /**
     * Construit une vue unifiée des divergences regroupées par UUID/nom
     */
    public function getUnifiedDivergencesProperty(): array
    {
        $unified = [];
        
        // Traiter les recommendations (renommages détectés via UUID) - priorité haute
        if (isset($this->status['recommendations'])) {
            foreach ($this->status['recommendations'] as $rec) {
                $key = 'uuid_' . $rec['uuid'];
                $unified[$key] = [
                    'key' => $key,
                    'type' => 'rename',
                    'uuid' => $rec['uuid'],
                    'sql_name' => $rec['sql_name'],
                    'sql_id' => $rec['sql_id'] ?? null,
                    'ad_name' => $rec['ad_name'],
                    'suggestion' => $rec['suggestion'],
                    'can_import' => true,  // Peut appliquer le nom AD sur SQL
                    'can_export' => true,  // Peut appliquer le nom SQL sur AD
                ];
            }
        }
        
        // Traiter les éléments manquants dans AD (présents en SQL uniquement)
        if (isset($this->status['missing_in_ad'])) {
            foreach ($this->status['missing_in_ad'] as $item) {
                // Skip si déjà traité via recommendation (même UUID)
                if (!empty($item['uuid']) && isset($unified['uuid_' . $item['uuid']])) {
                    continue;
                }
                
                $key = !empty($item['uuid']) ? 'uuid_' . $item['uuid'] : 'sql_' . ($item['id'] ?? $item['name']);
                $unified[$key] = [
                    'key' => $key,
                    'type' => 'missing_in_ad',
                    'uuid' => $item['uuid'] ?? null,
                    'sql_name' => $item['name'],
                    'sql_id' => $item['id'] ?? null,
                    'ad_name' => null,
                    'is_physical' => $item['is_physical'] ?? false,
                    'can_import' => false,
                    'can_export' => true,  // Peut créer dans AD
                ];
            }
        }
        
        // Traiter les éléments manquants en SQL (présents dans AD uniquement)
        if (isset($this->status['missing_in_sql'])) {
            foreach ($this->status['missing_in_sql'] as $item) {
                // Skip si déjà traité via recommendation (même UUID)
                if (!empty($item['uuid']) && isset($unified['uuid_' . $item['uuid']])) {
                    continue;
                }
                
                $key = !empty($item['uuid']) ? 'uuid_' . $item['uuid'] : 'ad_' . $item['name'];
                $unified[$key] = [
                    'key' => $key,
                    'type' => 'missing_in_sql',
                    'uuid' => $item['uuid'] ?? null,
                    'sql_name' => null,
                    'sql_id' => null,
                    'ad_name' => $item['name'],
                    'dn' => $item['dn'] ?? null,
                    'can_import' => true,  // Peut créer en SQL
                    'can_export' => false,
                ];
            }
        }
        
        // Traiter les différences de noms (casse)
        if (isset($this->status['name_mismatches'])) {
            foreach ($this->status['name_mismatches'] as $item) {
                $key = 'mismatch_' . ($item['id'] ?? $item['sql_name']);
                $unified[$key] = [
                    'key' => $key,
                    'type' => 'name_mismatch',
                    'uuid' => null,
                    'sql_name' => $item['sql_name'],
                    'sql_id' => $item['id'] ?? null,
                    'ad_name' => $item['ad_name'],
                    'can_import' => true,  // Peut appliquer le nom AD sur SQL
                    'can_export' => true,  // Peut appliquer le nom SQL sur AD
                ];
            }
        }
        
        return array_values($unified);
    }
    
    public function selectAll(): void
    {
        $this->selectedItems = collect($this->unifiedDivergences)->pluck('key')->toArray();
    }
    
    public function clearSelections(): void
    {
        $this->selectedItems = [];
    }
    
    public function getSelectedCountProperty(): int
    {
        return count($this->selectedItems);
    }
    
    
    /**
     * Exporter vers AD (créer dans AD ou appliquer nom SQL sur AD)
     * - missing_in_ad : Dispatcher le job de création AD
     * - rename/name_mismatch : Dispatcher le job de renommage AD
     */
    public function exportToAd(string $key): void
    {
        $this->isProcessing = true;
        
        try {
            $item = collect($this->unifiedDivergences)->firstWhere('key', $key);
            if (!$item) {
                throw new \Exception('Élément non trouvé');
            }
            
            $name = $item['sql_name'] ?? $item['ad_name'];
            
            if ($item['type'] === 'missing_in_ad') {
                // Dispatcher le job pour créer le groupe dans AD
                $group = WorkstationGroup::find($item['sql_id']);
                if (!$group) {
                    throw new \Exception('Groupe SQL non trouvé');
                }
                
                dispatch(WorkstationGroupAdSyncJob::create($group->id));
                $this->toastSuccess("Création de '{$name}' dans AD en cours...");
                
            } elseif (in_array($item['type'], ['rename', 'name_mismatch'])) {
                // Dispatcher le job pour renommer dans AD
                $group = WorkstationGroup::find($item['sql_id']);
                if (!$group) {
                    throw new \Exception('Groupe SQL non trouvé');
                }
                
                dispatch(WorkstationGroupAdSyncJob::rename(
                    $group->id,
                    $item['ad_name'],  // ancien nom (dans AD)
                    $item['sql_name']  // nouveau nom (depuis SQL)
                ));
                $this->toastSuccess("Renommage AD : '{$item['ad_name']}' → '{$item['sql_name']}' en cours...");
            }
            
            // Attendre un peu pour laisser le job s'exécuter (si sync)
            usleep(500000); // 500ms
            $this->checkSync();
        } catch (\Exception $e) {
            Log::error('[WorkstationGroupSyncStatus] Erreur export AD: ' . $e->getMessage());
            $this->toastError('Erreur lors de l\'export: ' . $e->getMessage());
        }
        
        $this->isProcessing = false;
    }
    
    
    /**
     * Export groupé vers AD
     */
    public function bulkExportToAd(): void
    {
        if (empty($this->selectedItems)) {
            $this->toastWarning('Aucun élément sélectionné');
            return;
        }
        
        $this->isProcessing = true;
        $count = 0;
        
        try {
            foreach ($this->selectedItems as $key) {
                $item = collect($this->unifiedDivergences)->firstWhere('key', $key);
                if (!$item || !$item['can_export']) {
                    continue;
                }
                
                if ($item['type'] === 'missing_in_ad') {
                    $group = WorkstationGroup::find($item['sql_id']);
                    if ($group) {
                        dispatch(WorkstationGroupAdSyncJob::create($group->id));
                        $count++;
                    }
                } elseif (in_array($item['type'], ['rename', 'name_mismatch'])) {
                    $group = WorkstationGroup::find($item['sql_id']);
                    if ($group) {
                        dispatch(WorkstationGroupAdSyncJob::rename(
                            $group->id,
                            $item['ad_name'],
                            $item['sql_name']
                        ));
                        $count++;
                    }
                }
            }
            
            if ($count > 0) {
                $this->toastSuccess("{$count} job(s) d'export vers AD lancé(s)");
            }
            
            $this->clearSelections();
            usleep(500000); // 500ms
            $this->checkSync();
        } catch (\Exception $e) {
            Log::error('[WorkstationGroupSyncStatus] Erreur bulk export: ' . $e->getMessage());
            $this->toastError('Erreur: ' . $e->getMessage());
        }
        
        $this->isProcessing = false;
    }
    
    public function getTotalDivergencesProperty(): int
    {
        return count($this->unifiedDivergences);
    }
};
?>

<div wire:poll.300s="checkSync" class="mb-4">
    @if (isset($status['error']) && $status['error'])
        {{-- Erreur de connexion AD --}}
        <div class="alert alert-error shadow-sm">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                <h3 class="font-bold">Erreur de vérification AD (Groupes)</h3>
                <p class="text-sm">{{ $status['error'] }}</p>
            </div>
            <button type="button" class="btn btn-sm btn-ghost" wire:click="checkSync" wire:loading.attr="disabled">
                <i class="fa-solid fa-rotate" wire:loading.class="fa-spin"></i>
                Réessayer
            </button>
        </div>
    @elseif (isset($status['synced']) && $status['synced'])
        {{-- Tout est synchronisé --}}
        <div class="alert alert-success shadow-sm">
            <i class="fa-solid fa-circle-check"></i>
            <div>
                <span class="font-medium">Groupes de postes synchronisés</span>
                <span class="text-sm opacity-70">
                    ({{ $status['sql_count'] ?? 0 }} en base, {{ $status['ad_count'] ?? 0 }} dans AD)
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs opacity-60">
                    Vérifié
                    {{ isset($status['last_check']) ? \Carbon\Carbon::parse($status['last_check'])->diffForHumans() : 'jamais' }}
                </span>
                <button type="button" class="btn btn-sm btn-ghost" wire:click="checkSync" wire:loading.attr="disabled"
                    title="Revérifier maintenant">
                    <i class="fa-solid fa-rotate" wire:loading.class="fa-spin"></i>
                </button>
            </div>
        </div>
    @elseif ($this->totalDivergences > 0)
        {{-- Divergences détectées - Ligne cliquable --}}
        <div class="alert alert-warning shadow-sm cursor-pointer hover:bg-warning/20 transition-colors"
            wire:click="openModal">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div class="flex-1">
                <span class="font-medium">{{ $this->totalDivergences }} divergence(s) détectée(s) pour les
                    groupes</span>
                <span class="text-sm opacity-70">
                    (AD: {{ $status['ad_count'] ?? 0 }} / SQL: {{ $status['sql_count'] ?? 0 }})
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs opacity-60">
                    {{ isset($status['last_check']) ? \Carbon\Carbon::parse($status['last_check'])->diffForHumans() : '' }}
                </span>
                <button type="button" class="btn btn-sm btn-ghost" wire:click.stop="checkSync"
                    wire:loading.attr="disabled" title="Revérifier">
                    <i class="fa-solid fa-rotate" wire:loading.class="fa-spin"></i>
                </button>
                <span class="text-xs opacity-60">Cliquez pour voir les détails</span>
            </div>
        </div>
    @else
        {{-- État initial / chargement --}}
        <div class="alert shadow-sm">
            <i class="fa-solid fa-spinner fa-spin"></i>
            <span>Vérification de la synchronisation des groupes...</span>
        </div>
    @endif

    {{-- Modale des divergences avec actions --}}
    @if ($showModal)
        <div class="modal modal-open" wire:click.self="closeModal">
            <div class="modal-box max-w-4xl max-h-[90vh]">
                <button type="button" class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2"
                    wire:click="closeModal">✕</button>

                <h3 class="font-bold text-lg mb-4">
                    <i class="fa-solid fa-folder-tree mr-2"></i>
                    Synchronisation AD/SQL - Groupes de postes
                </h3>

                {{-- Barre d'actions groupées --}}
                @if (count($this->unifiedDivergences) > 0)
                    <div class="flex justify-between items-center mb-4 p-3 bg-base-200 rounded-lg">
                        <div class="flex items-center gap-2">
                            <button type="button" class="btn btn-xs btn-ghost" wire:click="selectAll">
                                Tout sélectionner
                            </button>
                            @if ($this->selectedCount > 0)
                                <button type="button" class="btn btn-xs btn-ghost" wire:click="clearSelections">
                                    Désélectionner
                                </button>
                                <span class="badge badge-primary">{{ $this->selectedCount }} sélectionné(s)</span>
                            @endif
                        </div>
                        @if ($this->selectedCount > 0)
                            <div class="flex items-center gap-2">
                                <button type="button" class="btn btn-xs btn-primary gap-1"
                                    wire:click="bulkExportToAd" wire:loading.attr="disabled"
                                    title="Exporter vers AD">
                                    <i class="fa-solid fa-database"></i>
                                    <i class="fa-solid fa-arrow-right text-xs"></i>
                                    <i class="fa-solid fa-server"></i>
                                    Exporter vers AD
                                </button>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="overflow-y-auto max-h-[55vh]">
                    @if (count($this->unifiedDivergences) > 0)
                        <table class="table table-sm w-full">
                            <thead class="sticky top-0 bg-base-100 z-10">
                                <tr>
                                    <th class="w-8"></th>
                                    <th><i class="fa-solid fa-database"></i> Nom SQL</th>
                                    <th class="w-8"></th>
                                    <th><i class="fa-solid fa-server"></i> Nom AD</th>
                                    <th class="w-12 text-center">Type</th>
                                    <th class="w-32 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->unifiedDivergences as $item)
                                    <tr class="hover">
                                        <td>
                                            <input type="checkbox" class="checkbox checkbox-xs"
                                                wire:model.live="selectedItems" value="{{ $item['key'] }}">
                                        </td>
                                        <td>
                                            @if ($item['sql_name'])
                                                <code class="bg-base-200 px-1.5 py-0.5 rounded text-xs">{{ $item['sql_name'] }}</code>
                                            @else
                                                <span class="text-xs opacity-50 italic">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if ($item['type'] === 'rename' || $item['type'] === 'name_mismatch')
                                                <i class="fa-solid fa-arrows-left-right text-warning text-xs" title="Les noms sont différents entre SQL et AD"></i>
                                            @elseif ($item['type'] === 'missing_in_ad')
                                                <i class="fa-solid fa-arrow-right text-error text-xs" title="Ce groupe existe en SQL mais pas dans Active Directory"></i>
                                            @elseif ($item['type'] === 'missing_in_sql')
                                                <i class="fa-solid fa-arrow-left text-info text-xs" title="Ce groupe existe dans Active Directory mais pas en SQL"></i>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($item['ad_name'])
                                                <code class="bg-base-200 px-1.5 py-0.5 rounded text-xs">{{ $item['ad_name'] }}</code>
                                            @else
                                                <span class="text-xs opacity-50 italic">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if ($item['type'] === 'rename')
                                                <span class="badge badge-warning badge-xs" title="Renommage détecté : le même UUID existe des deux côtés mais avec des noms différents">
                                                    <i class="fa-solid fa-lightbulb"></i>
                                                </span>
                                            @elseif ($item['type'] === 'name_mismatch')
                                                <span class="badge badge-ghost badge-xs" title="Différence de casse ou de caractères entre les noms SQL et AD">
                                                    <i class="fa-solid fa-font"></i>
                                                </span>
                                            @elseif ($item['type'] === 'missing_in_ad')
                                                <span class="badge badge-error badge-xs" title="Ce groupe n'existe pas encore dans Active Directory">
                                                    <i class="fa-solid fa-server"></i>
                                                </span>
                                            @elseif ($item['type'] === 'missing_in_sql')
                                                <span class="badge badge-info badge-xs" title="Ce groupe n'existe pas encore dans la base de données SQL">
                                                    <i class="fa-solid fa-database"></i>
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if ($item['can_export'])
                                                <button type="button" 
                                                    class="btn btn-xs btn-primary gap-1"
                                                    wire:click="exportToAd('{{ $item['key'] }}')"
                                                    wire:loading.attr="disabled"
                                                    title="{{ $item['type'] === 'missing_in_ad' ? 'Créer ce groupe dans Active Directory' : 'Appliquer le nom SQL sur Active Directory' }}">
                                                    <i class="fa-solid fa-database"></i>
                                                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                                    <i class="fa-solid fa-server"></i>
                                                </button>
                                            @else
                                                <span class="text-xs opacity-50">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="text-center py-8">
                            <i class="fa-solid fa-check-circle text-success text-4xl mb-3"></i>
                            <p class="font-medium">Tout est synchronisé !</p>
                            <p class="text-sm opacity-70">Aucune divergence détectée entre AD et SQL.</p>
                        </div>
                    @endif
                </div>

                <div class="modal-action">
                    <button type="button" class="btn btn-ghost" wire:click="closeModal">Fermer</button>
                    <button type="button" class="btn btn-primary" wire:click="checkSync"
                        wire:loading.attr="disabled">
                        <i class="fa-solid fa-rotate" wire:loading.class="fa-spin"></i>
                        Actualiser
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

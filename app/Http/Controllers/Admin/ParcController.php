<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Facades\SEConfig;
use App\Services\WorkstationGroupLdapService;
use App\Services\WorkstationService;
use App\Services\ImportExportService;
use App\Services\SE4\PowerShellRemoteService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class ParcController extends Controller
{
    public function __construct(
        private WorkstationGroupLdapService $parcService,
        private WorkstationService $workstationService,
        private ImportExportService $importExportService,
        private PowerShellRemoteService $psService
    ) {
    }

    /**
     * Affichage principal - Dashboard des parcs
     */
    public function index(Request $request): View
    {
        try {
            // Récupérer les filtres de la requête
            $filters = [
                'search' => $request->input('search'),
                'parc' => $request->input('parc'),
                'status' => $request->input('status'),
            ];

            // Récupérer l'établissement de l'utilisateur courant
            $etabUai = SEConfig::getCurrentEstablishmentCode();

            // Récupérer les groupes (DeviceGroup/OU) et les tags orphelins (DeviceGroupTag/Group)
            // Filtrer directement par établissement de l'utilisateur courant
            // Note: Les tags orphelins ne sont pas affichés dans les listes car ils doivent être
            // considérés comme des tags à associer directement aux machines, pas comme des parcs
            $groupsData = $this->parcService->getGroupsWithTags($etabUai);
            $rootGroup = $groupsData['rootGroup'];
            $groups = $groupsData['groups'];
            $orphanTags = $groupsData['orphanTags'] ?? []; // Conservé pour usage futur mais non affiché

            // Appliquer les filtres si nécessaire (seulement sur les groupes non-racine)
            $filteredGroups = $this->applyFiltersToGroups($groups, $filters);

            // Construire la liste des parcs à afficher :
            // 1. Les groupes (DeviceGroup/OU) convertis en Parc
            // Les tags orphelins (DeviceGroupTag/Group) ne sont PAS affichés dans les listes
            // car ils doivent être considérés comme des tags à associer directement aux machines
            $parcs = [];
            $allParcs = [];

            // Pour les groupes filtrés (DeviceGroup/OU) - ils n'ont PAS de tags associés
            foreach ($filteredGroups as $groupData) {
                $groupName = $groupData['group']['name'];

                // Convertir le groupe (OU) en Parc DataObject
                if (isset($groupData['groupModel'])) {
                    try {
                        $parcs[] = [
                            'parc' => $groupData['groupModel']->toBusinessObject(),
                            'groupName' => $groupName,
                            'associatedTags' => [], // Les groupes n'ont pas de tags
                            'isGroup' => true, // Indicateur que c'est un groupe (OU)
                        ];
                    } catch (\Exception $e) {
                        Log::warning('Erreur lors de la conversion d\'un groupe', [
                            'group_dn' => $groupData['group']['dn'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // Pour tous les groupes non filtrés (pour le dropdown)
            foreach ($groups as $groupData) {
                $groupName = $groupData['group']['name'];

                if (isset($groupData['groupModel'])) {
                    try {
                        $allParcs[] = [
                            'parc' => $groupData['groupModel']->toBusinessObject(),
                            'groupName' => $groupName,
                            'associatedTags' => [],
                            'isGroup' => true,
                        ];
                    } catch (\Exception $e) {
                        Log::warning('Erreur lors de la conversion d\'un groupe', [
                            'group_dn' => $groupData['group']['dn'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // Calculer les statistiques globales
            $globalStats = $this->parcService->getGlobalStats();
            $detailedStats = $this->parcService->getDetailedStatsByType();
            $statsByEtab = $this->parcService->getStatsByEtab();

            // Préparer les options de filtres pour la vue
            $filterOptions = [
                'type' => [
                    'all' => 'Tous les types',
                    'building' => 'Bâtiment',
                    'room' => 'Salle',
                    'lab' => 'Laboratoire',
                ],
                'status' => [
                    'all' => 'Tous les statuts',
                    'online' => 'En ligne',
                    'offline' => 'Hors ligne',
                ],
            ];

            return view('admin.parcs.index', [
                'parcs' => $parcs,
                'allParcs' => $allParcs, // Pour le dropdown de filtres
                'rootGroup' => $rootGroup, // Le parc racine (computers) pour le titre/entête
                'groupsWithTags' => $filteredGroups, // Pour une future évolution de la vue
                'globalStats' => $globalStats,
                'detailedStats' => $detailedStats,
                'statsByEtab' => $statsByEtab,
                'currentFilters' => $filters,
                'filters' => $filterOptions,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors du chargement des groupes', ['error' => $e->getMessage()]);
            ToastMagic::error('Erreur', 'Impossible de charger la liste des groupes');
            return view('admin.parcs.index', [
                'parcs' => [],
                'allParcs' => [],
                'rootGroup' => null,
                'groupsWithTags' => [],
                'globalStats' => [],
                'detailedStats' => [],
                'statsByEtab' => [],
                'currentFilters' => [],
                'filters' => [],
            ]);
        }
    }

    /**
     * Applique les filtres sur les groupes avec leurs tags
     */
    private function applyFiltersToGroups(array $groupsWithTags, array $filters): array
    {
        if (empty($groupsWithTags)) {
            return [];
        }

        $filtered = [];

        foreach ($groupsWithTags as $groupData) {
            $group = $groupData['group'];
            $tags = $groupData['tags'];
            $shouldInclude = true;

            // Filtre par recherche (nom du groupe, description, ou nom des tags)
            if (!empty($filters['search'])) {
                $search = strtolower($filters['search']);
                $groupName = strtolower($group['name'] ?? '');
                $groupDesc = strtolower($group['description'] ?? '');

                $matchesGroup = strpos($groupName, $search) !== false ||
                    strpos($groupDesc, $search) !== false;

                // Vérifier aussi dans les tags
                $matchesTag = false;
                foreach ($tags as $tag) {
                    $tagName = strtolower($tag->name ?? $tag->cn ?? '');
                    $tagDesc = strtolower($tag->description ?? '');
                    if (strpos($tagName, $search) !== false || strpos($tagDesc, $search) !== false) {
                        $matchesTag = true;
                        break;
                    }
                }

                if (!$matchesGroup && !$matchesTag) {
                    $shouldInclude = false;
                }
            }

            // Filtre par parc (tag)
            if ($shouldInclude && !empty($filters['parc']) && $filters['parc'] !== 'all') {
                $hasMatchingTag = false;
                foreach ($tags as $tag) {
                    if ($tag->cn === $filters['parc'] || $tag->samAccountName === $filters['parc']) {
                        $hasMatchingTag = true;
                        break;
                    }
                }
                if (!$hasMatchingTag) {
                    $shouldInclude = false;
                }
            }

            // Filtre par statut (basé sur les tags)
            if ($shouldInclude && !empty($filters['status']) && $filters['status'] !== 'all') {
                $hasActiveTag = false;
                foreach ($tags as $tag) {
                    if ($tag->isActive) {
                        $hasActiveTag = true;
                        break;
                    }
                }
                if ($filters['status'] === 'online' && !$hasActiveTag) {
                    $shouldInclude = false;
                }
                if ($filters['status'] === 'offline' && $hasActiveTag) {
                    $shouldInclude = false;
                }
            }

            if ($shouldInclude) {
                $filtered[] = $groupData;
            }
        }

        return $filtered;
    }

    /**
     * Affichage détaillé d'un parc/groupe
     */
    public function show(Request $request, string $parcId): View|RedirectResponse
    {
        try {
            // Essayer d'abord de récupérer comme DeviceGroup (OU/Salle)
            $groupDetails = $this->parcService->getDeviceGroupDetails($parcId);

            if ($groupDetails) {
                // C'est un DeviceGroup (OU/Salle)
                // Gérer les onglets pour cette vue
                $tab = $request->query('tab', 'informations');

                return view('admin.parcs.show-group', [
                    'groupDetails' => $groupDetails,
                    'parc' => $groupDetails['group'], // Pour compatibilité avec la vue existante
                    'currentTab' => $tab,
                ]);
            }

            // Sinon, essayer comme DeviceGroupTag (Group/Parc)
            $parc = $this->parcService->getParcById($parcId);
            if (!$parc) {
                ToastMagic::error('Erreur', 'Parc ou groupe non trouvé');
                return redirect()->route('admin.parcs.index');
            }

            // Les données machines et stats sont maintenant gérées par les composants Livewire
            // On ne passe que le DTO Parc à la vue
            return view('admin.parcs.show', compact('parc'));
        } catch (\Exception $e) {
            Log::error('Erreur lors du chargement du parc', ['parc_id' => $parcId, 'error' => $e->getMessage()]);
            ToastMagic::error('Erreur', 'Impossible de charger les détails du parc');
            return redirect()->route('admin.parcs.index');
        }
    }

    /**
     * Formulaire de création d'un parc
     */
    public function create(Request $request): View|RedirectResponse
    {
        try {
            // Récupérer l'établissement de l'utilisateur courant
            $etabUai = SEConfig::getCurrentEstablishmentCode();

            $parentParcs = $this->parcService->getParentCapableParcs($etabUai); // Seuls les parcs qui peuvent avoir des enfants
            $allParcs = $this->parcService->getAllParcs($etabUai);

            return view('admin.parcs.create', compact('parentParcs', 'allParcs'));
        } catch (\Exception $e) {
            Log::error('Erreur lors du chargement du formulaire de création', ['error' => $e->getMessage()]);
            ToastMagic::error('Erreur', 'Impossible de charger le formulaire');
            return redirect()->route('admin.parcs.index');
        }
    }

    /**
     * Création d'un nouveau parc
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'parent_id' => 'nullable|string',
            'type' => 'required|string|in:building,room,lab'
        ]);

        try {
            $success = $this->parcService->createParc($request->all());

            if ($success) {
                ToastMagic::success('Succès', 'Parc créé avec succès');
                return redirect()->route('admin.parcs.index');
            } else {
                ToastMagic::error('Erreur', 'Impossible de créer le parc');
                return back()->withInput();
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du parc', ['data' => $request->all(), 'error' => $e->getMessage()]);
            ToastMagic::error('Erreur', 'Une erreur est survenue lors de la création');
            return back()->withInput();
        }
    }

    /**
     * Formulaire d'édition d'un parc
     */
    public function edit(Request $request, string $parcId): View|RedirectResponse
    {
        try {
            $parc = $this->parcService->getParcById($parcId);
            if (!$parc) {
                ToastMagic::error('Erreur', 'Parc non trouvé');
                return redirect()->route('admin.parcs.index');
            }

            // Récupérer l'établissement de l'utilisateur courant
            $etabUai = SEConfig::getCurrentEstablishmentCode();

            $parentParcs = $this->parcService->getAllParcs($etabUai);
            return view('admin.parcs.edit', compact('parc', 'parentParcs'));
        } catch (\Exception $e) {
            Log::error('Erreur lors du chargement du formulaire d\'édition', ['parc_id' => $parcId, 'error' => $e->getMessage()]);
            ToastMagic::error('Erreur', 'Impossible de charger le formulaire d\'édition');
            return redirect()->route('admin.parcs.index');
        }
    }


    /**
     * Suppression d'un parc
     */
    public function destroy(string $parcId): RedirectResponse
    {
        try {
            $success = $this->parcService->deleteParc($parcId);

            if ($success) {
                ToastMagic::success('Succès', 'Parc supprimé avec succès');
            } else {
                ToastMagic::error('Erreur', 'Impossible de supprimer le parc (contient peut-être des machines)');
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du parc', ['parc_id' => $parcId, 'error' => $e->getMessage()]);
            ToastMagic::error('Erreur', 'Une erreur est survenue lors de la suppression');
        }

        return redirect()->route('admin.parcs.index');
    }

    /**
     * Actions de masse sur les machines d'un parc
     */
    public function massAction(Request $request, string $parcId): JsonResponse
    {
        $request->validate([
            'action' => 'required|string|in:wake,shutdown,restart,script,powershell',
            'machines' => 'required|array',
            'machines.*' => 'string',
            'script' => 'nullable|string',
            'command' => 'nullable|string',
            'command_type' => 'nullable|string|in:powershell,cmd'
        ]);

        try {
            $machines = $request->input('machines');
            $action = $request->input('action');
            $result = false;
            $results = [];

            switch ($action) {
                case 'wake':
                    $result = $this->workstationService->wakeOnLan($machines);
                    break;
                case 'shutdown':
                    $result = $this->workstationService->shutdownMachines($machines);
                    break;
                case 'restart':
                    $result = $this->workstationService->restartMachines($machines);
                    break;
                case 'script':
                    // TODO: Implémenter executeScript dans MachineService
                    $result = false;
                    break;
                case 'powershell':
                    $command = $request->input('command');
                    $commandType = $request->input('command_type', 'powershell');

                    if (empty($command)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Commande PowerShell requise'
                        ], 400);
                    }

                    // Exécuter la commande sur chaque machine
                    foreach ($machines as $machineName) {
                        if ($commandType === 'powershell') {
                            $machineResult = $this->psService->executeCommand($machineName, $command);
                        } else {
                            $machineResult = $this->psService->executeCmdCommand($machineName, $command);
                        }

                        $results[] = [
                            'machine' => $machineName,
                            'success' => $machineResult['success'],
                            'output' => $machineResult['output'],
                            'error' => $machineResult['error'] ?? null
                        ];
                    }

                    // Considérer comme succès si au moins une machine a réussi
                    $successCount = count(array_filter($results, fn($r) => $r['success']));
                    $result = $successCount > 0;

                    return response()->json([
                        'success' => $result,
                        'message' => "Commande exécutée sur {$successCount}/" . count($machines) . " machine(s)",
                        'results' => $results
                    ]);
            }

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Action lancée avec succès'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors du lancement de l\'action'
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'action de masse', [
                'parc_id' => $parcId,
                'action' => $request->input('action'),
                'machines' => $request->input('machines'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recherche de machines
     */
    public function searchMachines(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:2'
        ]);

        try {
            // TODO: Implémenter searchMachines dans MachineService
            $machines = [];

            return response()->json([
                'success' => true,
                'machines' => $machines
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche de machines', [
                'query' => $request->input('query'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche'
            ], 500);
        }
    }

    /**
     * Import CSV
     */
    public function importCsv(Request $request): RedirectResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048'
        ]);

        try {
            $file = $request->file('csv_file');
            $filePath = $file->storeAs('temp', 'import_' . time() . '.csv');

            $result = $this->importExportService->importFromCSV(storage_path('app/' . $filePath));

            if ($result['success']) {
                ToastMagic::success('Succès', $result['message']);
            } else {
                ToastMagic::error('Erreur', $result['message']);
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'import CSV', ['error' => $e->getMessage()]);
            ToastMagic::error('Erreur', 'Une erreur est survenue lors de l\'import');
        }

        return back();
    }

    /**
     * Export CSV
     */
    public function exportCsv(string $parcId = null): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        try {
            $filePath = $this->importExportService->exportToCSV($parcId);

            return response()->download($filePath, 'export_parcs_' . date('Y-m-d_H-i-s') . '.csv')
                ->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'export CSV', ['parc_id' => $parcId, 'error' => $e->getMessage()]);
            ToastMagic::error('Erreur', 'Une erreur est survenue lors de l\'export');
            return redirect()->back();
        }
    }

    /**
     * Affiche la gestion des raccourcis du parc
     */
    public function shortcuts(string $parcId): View|RedirectResponse
    {
        try {
            $parc = $this->parcService->getParcById($parcId);
            if (!$parc) {
                ToastMagic::error('Erreur', 'Parc non trouvé');
                return redirect()->route('admin.parcs.index');
            }

            // TODO: Récupérer les raccourcis du parc depuis le service
            $shortcuts = []; // Placeholder pour l'instant

            return view('admin.parcs.shortcuts', compact('parc', 'shortcuts'));
        } catch (\Exception $e) {
            Log::error('Erreur lors du chargement des raccourcis', ['parc_id' => $parcId, 'error' => $e->getMessage()]);
            ToastMagic::error('Erreur', 'Impossible de charger les raccourcis du parc');
            return redirect()->route('admin.parcs.show', $parcId);
        }
    }

    /**
     * Affiche les applications installées du parc
     */
    public function applications(string $parcId): View|RedirectResponse
    {
        try {
            $parc = $this->parcService->getParcById($parcId);
            if (!$parc) {
                ToastMagic::error('Erreur', 'Parc non trouvé');
                return redirect()->route('admin.parcs.index');
            }

            // TODO: Récupérer les applications du parc depuis le service
            $applications = []; // Placeholder pour l'instant

            return view('admin.parcs.applications', compact('parc', 'applications'));
        } catch (\Exception $e) {
            Log::error('Erreur lors du chargement des applications', ['parc_id' => $parcId, 'error' => $e->getMessage()]);
            ToastMagic::error('Erreur', 'Impossible de charger les applications du parc');
            return redirect()->route('admin.parcs.show', $parcId);
        }
    }

    /**
     * API - Récupère les statistiques d'un parc
     */
    public function apiStats(string $parcId): JsonResponse
    {
        try {
            $stats = $this->parcService->getParcStats($parcId);
            return response()->json(['success' => true, 'data' => $stats]);
        } catch (\Exception $e) {
            Log::error('Erreur API stats parc', ['parc_id' => $parcId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur lors de la récupération des statistiques'], 500);
        }
    }

    /**
     * API - Recherche de parcs
     */
    public function apiSearch(Request $request): JsonResponse
    {
        $request->validate(['query' => 'required|string|min:2']);

        try {
            $parcs = $this->parcService->searchParcs($request->input('query'));
            return response()->json([
                'success' => true,
                'data' => $parcs->toApiFormat()
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur API recherche parcs', ['query' => $request->input('query'), 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur lors de la recherche'], 500);
        }
    }

    /**
     * API - Récupère les parcs par type
     */
    public function apiGetByType(Request $request, string $type): JsonResponse
    {
        try {
            $parcs = $this->parcService->getParcsByType($type);
            return response()->json([
                'success' => true,
                'data' => $parcs->toApiFormat()
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur API parcs par type', ['type' => $type, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur lors de la récupération'], 500);
        }
    }

    /**
     * API - Récupère la hiérarchie complète
     */
    public function apiHierarchy(): JsonResponse
    {
        try {
            $hierarchy = $this->parcService->getHierarchyWithCounts();
            return response()->json([
                'success' => true,
                'data' => $hierarchy
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur API hiérarchie', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur lors de la récupération de la hiérarchie'], 500);
        }
    }

    /**
     * API - Vérifie l'existence d'un parc
     */
    public function apiExists(string $parcId): JsonResponse
    {
        try {
            $exists = $this->parcService->parcExists($parcId);
            return response()->json([
                'success' => true,
                'exists' => $exists
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur API existence parc', ['parc_id' => $parcId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur lors de la vérification'], 500);
        }
    }

}

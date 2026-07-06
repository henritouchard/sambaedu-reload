<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Services\ControlHub\ControlHubService;
use App\Models\ControlHubConnection;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Log;

new #[Title('Control Hub - Instance SE4FS')] class extends Component {
    use WithToasts;

    private ControlHubService $controlHubService;

    // Données du composant
    public array $handshakeStatus = [];
    public array $config = [];
    public ?array $currentStatus = null;
    public ?string $error = null;

    // Formulaire handshake
    public string $controlHubUrl = '';
    public string $masterApiKey = '';

    // État
    public bool $isLoading = false;
    public bool $isRefreshing = false;

    public function boot(ControlHubService $controlHubService)
    {
        $this->controlHubService = $controlHubService;
    }

    public function mount()
    {
        $this->loadData();
    }

    /**
     * Charger les données du Control Hub
     */
    public function loadData()
    {
        try {
            $this->handshakeStatus = $this->getHandshakeStatus();
            $this->config = $this->controlHubService->getConfig();
            $this->currentStatus = $this->getCurrentStatus();
            $this->controlHubUrl = $this->config['base_url'] ?? '';
            $this->error = null;
        } catch (\Exception $e) {
            Log::error('Erreur lors du chargement de la page ControlHub', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error = 'Erreur lors du chargement de la page : ' . $e->getMessage();
            $this->handshakeStatus = ['configured' => false];
            $this->config = [];
            $this->currentStatus = null;
        }
    }

    /**
     * Actualiser le statut
     */
    public function refreshStatus()
    {
        $this->isRefreshing = true;
        $this->loadData();
        $this->isRefreshing = false;
        $this->toastSuccess('Statut actualisé');
    }

    /**
     * Exécuter le handshake
     */
    public function executeHandshake()
    {
        $this->validate(
            [
                'controlHubUrl' => 'required|url',
                'masterApiKey' => 'required|string|min:32|max:255',
            ],
            [
                'controlHubUrl.required' => 'L\'URL ControlHub est requise',
                'controlHubUrl.url' => 'L\'URL ControlHub doit être une URL valide',
                'masterApiKey.required' => 'La Master API Key est requise',
                'masterApiKey.min' => 'La Master API Key doit contenir au moins 32 caractères',
            ],
        );

        $this->isLoading = true;

        try {
            $response = $this->controlHubService->performHandshake($this->masterApiKey, $this->controlHubUrl);

            if ($response->success) {
                Log::info('Handshake ControlHub réussi', [
                    'api_token' => $response->apiToken ? substr($response->apiToken, 0, 20) . '...' : null,
                    'heartbeat_interval' => $response->heartbeatInterval,
                    'custom_url' => $this->controlHubUrl,
                ]);

                $this->controlHubService->startAutomaticHeartbeat();
                $this->masterApiKey = '';
                $this->loadData();
                $this->toastSuccess('Handshake ControlHub réussi! Heartbeat automatique démarré.');
            } else {
                Log::error('Échec du handshake ControlHub', [
                    'message' => $response->message,
                ]);
                $this->toastError('Handshake ControlHub échoué! Vérifiez les informations et la validité de la master key.');
            }
        } catch (\Exception $e) {
            Log::error('Exception lors du handshake ControlHub', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->toastError('Erreur lors du handshake ControlHub : ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    /**
     * Tester le heartbeat
     */
    public function testHeartbeat()
    {
        try {
            $connection = ControlHubConnection::current();
            if (!$connection || !$connection->api_token) {
                $this->toastError('Aucun handshake configuré. Veuillez d\'abord effectuer le handshake.');
                return;
            }

            $result = $this->controlHubService->performHeartbeat();
            $this->loadData();

            if ($result->success) {
                $this->toastSuccess('Test heartbeat exécuté avec succès');
            } else {
                $this->toastError('Test heartbeat échoué : ' . $result->message);
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'exécution du heartbeat ControlHub', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $connection = ControlHubConnection::current();
            if ($connection) {
                $connection->updateStatus('error', 'heartbeat');
            }

            $this->toastError('Erreur lors du test heartbeat : ' . $e->getMessage());
        }
    }

    /**
     * Arrêter le heartbeat automatique
     */
    public function stopHeartbeat()
    {
        try {
            $wasActive = $this->controlHubService->isHeartbeatActive();
            $this->controlHubService->stopAutomaticHeartbeat();
            $isNowActive = $this->controlHubService->isHeartbeatActive();

            if ($wasActive && $isNowActive) {
                $this->toastError('L\'arrêt du heartbeat a échoué - le heartbeat est toujours actif');
            } elseif (!$wasActive) {
                $this->toastInfo('Le heartbeat était déjà arrêté');
            } else {
                $this->toastSuccess('Heartbeat automatique arrêté avec succès');
            }
            $this->loadData();
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'arrêt du heartbeat', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->toastError('Erreur lors de l\'arrêt du heartbeat : ' . $e->getMessage());
        }
    }

    /**
     * Redémarrer le heartbeat automatique
     */
    public function restartHeartbeat()
    {
        try {
            $this->controlHubService->restartAutomaticHeartbeat();
            $this->loadData();
            $this->toastSuccess('Heartbeat automatique redémarré');
        } catch (\Exception $e) {
            Log::error('Erreur lors du redémarrage du heartbeat', [
                'exception' => $e->getMessage(),
            ]);
            $this->toastError('Erreur lors du redémarrage du heartbeat : ' . $e->getMessage());
        }
    }

    /**
     * Supprimer la connexion ControlHub
     */
    public function deleteConnection()
    {
        try {
            $result = $this->controlHubService->deleteConnection();

            if ($result['success']) {
                $this->toastSuccess($result['message']);
            } else {
                if ($result['type'] === 'warning') {
                    $this->toastWarning($result['message']);
                } else {
                    $this->toastError($result['message']);
                }
            }
            $this->loadData();
        } catch (\Exception $e) {
            Log::error('Erreur inattendue lors de la suppression de la connexion ControlHub', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->toastError('Erreur inattendue lors de la suppression de la connexion : ' . $e->getMessage());
        }
    }

    /**
     * Obtenir le statut actuel
     */
    private function getCurrentStatus(): ?array
    {
        $connection = ControlHubConnection::current();
        if ($connection) {
            return [
                'status' => $connection->status,
                'error_type' => $connection->error_type,
                'color' => $connection->getStatusColor(),
                'last_heartbeat' => $connection->last_heartbeat_at,
                'failures_count' => $connection->heartbeat_failures ?? 0,
            ];
        } else {
            return [
                'status' => 'offline',
                'error_type' => null,
                'color' => 'info',
                'last_heartbeat' => null,
                'failures_count' => 0,
            ];
        }
    }

    /**
     * Obtenir le statut du handshake
     */
    private function getHandshakeStatus(): array
    {
        try {
            $connection = ControlHubConnection::current();

            $isConfigured = $connection && !empty($connection->api_token) && !empty($connection->se4fs_api_token);

            $controlHubApiToken = $connection ? $connection->api_token : null;
            $se4fsApiToken = $connection ? $connection->se4fs_api_token : null;
            $webhookUrl = $connection ? $connection->getWebhookUrl() : null;
            $heartbeatUrl = $connection ? $connection->getHeartbeatUrl() : null;
            $heartbeatInterval = $connection ? $connection->heartbeat_interval : null;

            $lastHandshake = $connection ? $connection->last_handshake_at : null;
            $controlHubBaseUrl = $connection ? $connection->base_url : null;

            $tokenStatus = 'valid';
            $tokenAlert = null;

            // Rotation = re-handshake seul depuis la story 39.5 (renouvellement
            // hors-handshake retiré, `needsRenewal()` supprimé du modèle) : seul
            // l'état "expiré" subsiste.
            if ($connection && $connection->isExpired()) {
                $tokenStatus = 'expired';
                $tokenAlert = 'Le token ControlHub a expiré. Un nouveau handshake est nécessaire.';
            }

            return [
                'configured' => $isConfigured,
                'api_token' => $controlHubApiToken ? substr($controlHubApiToken, 0, 8) . '...' : null,
                'se4fs_api_token' => $se4fsApiToken ? substr($se4fsApiToken, 0, 8) . '...' : null,
                'webhook_url' => $webhookUrl,
                'heartbeat_url' => $heartbeatUrl,
                'heartbeat_interval' => $heartbeatInterval,
                'last_handshake' => $lastHandshake,
                'base_url' => $controlHubBaseUrl,
                'instance_id' => config('controlHub.se4fs.instance_id'),
                'token_status' => $tokenStatus,
                'token_alert' => $tokenAlert,
                'token_expires_at' => $connection ? $connection->expires_at : null,
                'is_active' => $connection ? $connection->is_active : false,
                'last_heartbeat_at' => $connection ? $connection->last_heartbeat_at : null,
                'heartbeat_active' => $connection ? $connection->heartbeat_enabled : false,
                'heartbeat_failures' => $connection ? $connection->heartbeat_failures ?? 0 : 0,
            ];
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du statut du handshake', [
                'exception' => $e->getMessage(),
            ]);

            return [
                'configured' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
};
?>

<x-organisms.page title="Control Hub" description="Administrez la connexion au hub centralisé" icon="fa-brands fa-hubspot">
    <div class="space-y-4">

        <!-- Status section -->
        <div class="card bg-base-100 shadow-md">
            <div class="card-body">
                <div class="flex justify-between items-center">
                    <h4 class="card-title text-2xl mb-4 flex items-center gap-2">
                        <i class="fas fa-signal text-primary"></i>
                        Statut actuel
                    </h4>
                    <button wire:click="refreshStatus" wire:loading.attr="disabled"
                        class="btn btn-outline btn-primary btn-sm">
                        <i class="fas fa-refresh" wire:loading.class="fa-spin" wire:target="refreshStatus"></i>
                        Actualiser
                    </button>
                </div>
                <div class="ml-8">
                    <!-- Nouveau statut de connexion -->
                    @if ($handshakeStatus['configured'] ?? false)
                        <div class="flex gap-2 items-center">
                            <div class="inline-grid *:[grid-area:1/1]">
                                <div class="status status-lg status-success animate-ping"></div>
                                <div class="status status-lg status-success"></div>
                            </div> Le handshake a été configuré avec succès.
                        </div>
                    @else
                        <div class="flex gap-2 items-center">
                            <div class="inline-grid *:[grid-area:1/1]">
                                <div class="status status-lg status-error animate-ping"></div>
                                <div class="status status-lg status-error"></div>
                            </div> Le handshake n'a pas été configuré ou a échoué.
                        </div>
                    @endif

                    @if ($currentStatus)
                        <div class="flex gap-2 items-center">
                            <div class="inline-grid *:[grid-area:1/1]">
                                <div class="status status-lg status-{{ $currentStatus['color'] }} animate-ping">
                                </div>
                                <div class="status status-lg status-{{ $currentStatus['color'] }}"></div>
                            </div> Statut de la connexion : <div class="badge badge-{{ $currentStatus['color'] }}">
                                {{ ucfirst($currentStatus['status']) }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        @if ($handshakeStatus['configured'] ?? false)
            @php
                $heartbeatActive = $handshakeStatus['heartbeat_active'] ?? false;
                $lastHeartbeat = $handshakeStatus['last_heartbeat_at'] ?? null;
                $failures = $handshakeStatus['heartbeat_failures'] ?? 0;
            @endphp

            <div class="card bg-base-100 shadow-md">
                <div class="card-body">
                    <x-atoms.tooltip color="" position="top" icon="true">
                        <x-slot name="label">
                            <h4 class="card-title text-2xl flex items-center gap-2">
                                <i class="fa-solid fa-heart-pulse text-primary"></i>
                                Heartbeat Automatique
                            </h4>
                        </x-slot>
                        Le heartbeat automatique est un appel à l'API ControlHub qui s'exécute toutes les 5
                        minutes pour maintenir la connexion avec le hub connecté.
                    </x-atoms.tooltip>

                    <div class="ml-8 space-y-4">
                        <div class="mt-4">
                            @if ($heartbeatActive)
                                <div class="flex gap-2 items-center">
                                    <div class="inline-grid *:[grid-area:1/1]">
                                        <div class="status status-lg status-success animate-ping"></div>
                                        <div class="status status-lg status-success"></div>
                                    </div> Le heartbeat est actif.
                                </div>
                            @else
                                <div class="flex gap-2 items-center">
                                    <div class="inline-grid *:[grid-area:1/1]">
                                        <div class="status status-lg status-error animate-ping"></div>
                                        <div class="status status-lg status-error"></div>
                                    </div> Le heartbeat est inactif.
                                </div>
                            @endif
                        </div>
                        <div class="mt-4">
                            Dernier Heartbeat :
                            {{ $currentStatus['last_heartbeat'] ? $currentStatus['last_heartbeat']->format('d/m/Y H:i:s') : 'Aucun' }}
                            - après {{ $currentStatus['failures_count'] }} échecs.
                        </div>
                    </div>
                    <div class="flex gap-4 mt-4 justify-center w-full">
                        @if (!$heartbeatActive)
                            <button wire:click="restartHeartbeat" wire:loading.attr="disabled" class="btn btn-success">
                                <i class="fas fa-play"></i>
                                Démarrer
                            </button>
                        @else
                            <button wire:click="stopHeartbeat" wire:loading.attr="disabled" class="btn btn-error">
                                <i class="fas fa-stop"></i>
                                Arrêter
                            </button>
                        @endif
                        <button wire:click="testHeartbeat" wire:loading.attr="disabled" class="btn btn-info">
                            <i class="fas fa-vial"></i>
                            Tester
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <!-- Handshake form with modern styling -->
        <div class="card bg-base-100 shadow-md">
            <div class="card-body">
                <h3 class="card-title text-2xl mb-6 flex items-center gap-2">
                    <i class="fas fa-handshake text-primary"></i>
                    Handshake
                </h3>

                <!-- Infos configuration actuelle du handshake -->
                @if ($handshakeStatus['configured'] ?? false)
                    <div class="ml-8">
                        <h4 class="card-title text-xl mb-6 flex items-center gap-2">
                            <i class="fas fa-cog"></i>
                            Configuration actuelle
                        </h4>

                        <div class="overflow-x-auto mb-4">
                            <table class="table table-zebra w-full">
                                <tbody>
                                    <tr>
                                        <th class="font-semibold">URL ControlHub</th>
                                        <td>
                                            @if (!empty($handshakeStatus['configured']) && !empty($handshakeStatus['base_url']))
                                                <span
                                                    class="text-success font-semibold">{{ $handshakeStatus['base_url'] }}</span>
                                                <div class="text-xs text-base-content/70 mt-1">URL utilisée lors
                                                    du
                                                    dernier handshake</div>
                                            @else
                                                <span
                                                    class="text-base-content/70">{{ $config['base_url'] ?? 'Non définie' }}</span>
                                                <div class="text-xs text-base-content/70 mt-1">URL par défaut
                                                    (aucun handshake effectué)</div>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="font-semibold">Instance ID SE4FS</th>
                                        <td class="font-mono">{{ $config['instance_id'] ?? 'Non défini' }}</td>
                                    </tr>
                                    <tr>
                                        <th class="font-semibold">API Token</th>
                                        <td class="font-mono text-sm">
                                            {{ $handshakeStatus['api_token'] ?? 'Non défini' }}</td>
                                    </tr>
                                    <tr>
                                        <th class="font-semibold">Token SE4FS</th>
                                        <td class="font-mono text-sm">
                                            {{ $handshakeStatus['se4fs_api_token'] ?? 'Non défini' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="flex justify-center gap-4 mt-8">
                            <x-atoms.tooltip color="warning" position="top">
                                <x-slot name="label">
                                    <button type="button" class="btn btn-error btn-outline"
                                        onclick="deleteConnectionModal.showModal()">
                                        <i class="fas fa-trash"></i>
                                        Supprimer la connexion
                                    </button>
                                </x-slot>
                                Cette action supprimera définitivement toutes les données de connexion
                                ControlHub et arrêtera le heartbeat automatique.
                            </x-atoms.tooltip>
                        </div>
                    </div>
                @endif
                @if (empty($handshakeStatus['configured']) ?? true)
                    <div class="ml-8">
                        <h4 class="card-title text-xl mb-6 flex items-center gap-2">
                            <i class="fas fa-cog"></i>
                            Paramètres du handshake
                        </h4>
                        <form wire:submit="executeHandshake" class="space-y-6">
                            <div class="form-control flex flex-col gap-2">
                                <x-atoms.tooltip color="" position="right" icon="true">
                                    <x-slot name="label">
                                        <h3 class="font-bold">URL ou IP de l'application ControlHub</h3>
                                    </x-slot>
                                    Saisissez l'URL complète ou l'adresse IP avec le port
                                    de votre instance ControlHub.
                                    Ex: http://192.168.1.100:8080 ou https://controlhub.example.com

                                </x-atoms.tooltip>
                                <input type="text"
                                    class="input input-bordered w-[70%] @error('controlHubUrl') input-error @enderror"
                                    wire:model="controlHubUrl"
                                    placeholder="Ex: http://192.168.1.100:8080 ou https://controlhub.example.com"
                                    required>

                                @error('controlHubUrl')
                                    <div class="alert alert-error">
                                        <i class="fas fa-exclamation-circle"></i>
                                        <span><strong>URL ControlHub :</strong> {{ $message }}</span>
                                    </div>
                                @enderror
                            </div>

                            <div class="form-control flex flex-col gap-2">
                                <x-atoms.tooltip color="" position="right" icon="true">
                                    <x-slot name="label">
                                        <h3 class="font-bold">Master API Key</h3>
                                    </x-slot>
                                    La master API key ne peut être utilisée qu'une seule fois.
                                    Elle vous est fournie par l'administrateur ControlHub.
                                </x-atoms.tooltip>
                                <input type="text"
                                    class="input input-bordered w-[70%] @error('masterApiKey') input-error @enderror"
                                    wire:model="masterApiKey"
                                    placeholder="Entrez la master API key fournie par ControlHub" required
                                    minlength="32" maxlength="255">
                                @error('masterApiKey')
                                    <div class="alert alert-error">
                                        <i class="fas fa-exclamation-circle"></i>
                                        <span><strong>Master API Key :</strong> {{ $message }}</span>
                                    </div>
                                @enderror
                            </div>

                            <div class="flex justify-center">
                                <button type="submit" class="btn btn-primary btn-lg" wire:loading.attr="disabled">
                                    <span wire:loading wire:target="executeHandshake"
                                        class="loading loading-spinner"></span>
                                    <i class="fas fa-handshake" wire:loading.remove
                                        wire:target="executeHandshake"></i>
                                    Exécuter le handshake
                                </button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Modale de confirmation de suppression -->
    <dialog id="deleteConnectionModal" class="modal">
        <div class="modal-box">
            <h3 class="text-lg font-bold text-error">
                <i class="fas fa-exclamation-triangle"></i>
                Supprimer la connexion ControlHub
            </h3>
            <div class="py-4">
                <div class="alert alert-warning mb-4">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <h4 class="font-bold">Attention !</h4>
                        <p class="text-sm">Cette action est irréversible et aura les conséquences suivantes :</p>
                    </div>
                </div>

                <ul class="list-disc list-inside space-y-2 text-sm mb-4 ml-3">
                    <li>Suppression définitive de toutes les données de connexion au centre de contrôle</li>
                    <li>Arrêt immédiat du heartbeat automatique</li>
                    <li>Suppression des tokens d'authentification au centre de contrôle</li>
                    <li>Perte de la configuration par rapport au centre de contrôle</li>
                    <li>Nécessité de refaire un handshake complet pour reconnecter, donc de demander un nouveau token à
                        l'administrateur du centre</li>
                </ul>

                <p class="text-base-content font-bold">
                    Êtes-vous certain de vouloir supprimer la connexion ControlHub ?
                </p>
            </div>
            <div class="modal-action">
                <form method="dialog">
                    <button class="btn btn-ghost">Annuler</button>
                </form>
                <button wire:click="deleteConnection" onclick="deleteConnectionModal.close()" class="btn btn-error">
                    <i class="fas fa-trash"></i>
                    Supprimer définitivement
                </button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>Fermer</button>
        </form>
    </dialog>
</x-organisms.page>

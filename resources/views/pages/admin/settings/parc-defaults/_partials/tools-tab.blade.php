<?php

use App\Components\Traits\WithToasts;
use App\Models\AgentTool;
use App\Services\Agent\Tools\AgentToolException;
use App\Services\Agent\Tools\AgentToolService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Story 27.17 — Onglet « Outils agent » de /admin/settings/parc-defaults.
 *
 * Surface d'administration du catalogue `agent_tools` (aujourd'hui : le portable
 * Rainmeter), réutilisant le SEUL écrivain {@see AgentToolService} (upload/toggle).
 *
 * ⚠️ CANAL SÉPARÉ — l'outil agent N'EST PAS un item de state Broadcast : il est
 * livré par le MANIFEST des outils (l'agent télécharge + extrait vers
 * `C:\ProgramData\SambaEdu\Rainmeter`), toujours-actif et NON OVERRIDABLE par
 * poste. Cet onglet le présente donc distinctement des onglets « state ».
 *
 * Chaque action mutante re-garde `Gate::authorize('server.admin')` (double
 * protection : middleware route + action adressable via /livewire/update).
 * Décision Henri : tout en `server.admin`.
 */
new class extends Component {
    use WithFileUploads, WithToasts;

    /** Archive ZIP portable uploadée (champ `WithFileUploads`). */
    public $archive = null;

    /** Version saisie par l'admin → dérive le filename serveur (anti-traversal). */
    public string $version = '';

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }
    }

    #[Computed]
    public function tool(): ?AgentTool
    {
        return AgentTool::query()
            ->where('key', AgentToolService::RAINMETER_KEY)
            ->first();
    }

    public function importTool(): void
    {
        Gate::authorize('server.admin');

        $maxBytes = (int) config('agent.tool_max_upload_bytes');
        $maxKilobytes = max(1, (int) floor($maxBytes / 1024));

        $this->validate([
            'archive' => "required|file|mimes:zip|max:{$maxKilobytes}",
            'version' => 'required|string|max:32',
        ], [
            'archive.required' => 'Sélectionnez l\'archive .zip du portable Rainmeter.',
            'archive.mimes' => 'Le portable doit être une archive .zip.',
            'archive.max' => 'Archive trop volumineuse (au-delà de la borne autorisée).',
            'version.required' => 'Indiquez la version (ex. 4.5.18).',
            'version.max' => 'Version trop longue (32 caractères max).',
        ]);

        try {
            $tool = app(AgentToolService::class)->upload(
                $this->archive,
                trim($this->version),
                Auth::id(),
            );
        } catch (AgentToolException $e) {
            $this->toastError("Upload refusé ({$e->reason}). Vérifiez l'archive et réessayez.");

            return;
        }

        $this->reset(['archive', 'version']);
        unset($this->tool);
        $this->toastSuccess("Portable Rainmeter « {$tool->filename} » importé (SHA-256 calculé serveur). Activez-le pour le déployer au parc.");
    }

    public function toggle(): void
    {
        Gate::authorize('server.admin');

        $tool = $this->tool;
        if ($tool === null) {
            $this->toastError('Aucun outil dans le catalogue : importez d\'abord le portable Rainmeter.');

            return;
        }

        $next = ! $tool->enabled;
        app(AgentToolService::class)->toggle($tool, $next);

        unset($this->tool);
        $this->toastSuccess($next
            ? 'Rainmeter activé : les postes le déploieront à leur prochain check-in.'
            : 'Rainmeter désactivé : plus de (re)provisioning (les postes déjà équipés le conservent).');
    }
};
?>

<div>
    <x-molecules.settings-section
        title="Outils agent — canal séparé (manifest)"
        icon="fa-solid fa-screwdriver-wrench"
        color="primary"
        description="Outils livrés par l'agent via le MANIFEST (téléchargement + extraction sur le poste). Ce N'EST PAS un item de state : toujours-actif quand activé, NON overridable par poste.">

        <div class="w-full flex flex-col gap-6">
            <div class="alert alert-warning shadow-sm">
                <i class="fa-solid fa-circle-info"></i>
                <div class="text-sm">
                    Contrairement aux autres onglets (fond d'écran, registre, applications) qui produisent des
                    <strong>items de state</strong> overridables par parc/poste, l'outil de rendu Rainmeter est un
                    <strong>outil obligatoire du parc</strong> livré hors-state. Il est déployé d'office à tous les
                    postes quand il est activé.
                </div>
            </div>

            {{-- Catalogue : l'outil de rendu Rainmeter (mono-version, D5) --}}
            <div class="card bg-base-100 shadow-sm border border-base-200 w-full">
                <div class="card-body">
                    <h3 class="card-title text-base">Outil de rendu (overlay)</h3>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Outil</th>
                                    <th>Fichier</th>
                                    <th>Hash (court)</th>
                                    <th>Taille</th>
                                    <th>État</th>
                                    <th>Importé</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if ($this->tool)
                                    <tr wire:key="tool-{{ $this->tool->id }}">
                                        <td class="font-semibold">{{ $this->tool->name }}</td>
                                        <td class="font-mono text-xs">{{ $this->tool->filename }}</td>
                                        <td class="font-mono text-xs text-base-content/60">{{ \Illuminate\Support\Str::limit($this->tool->sha256, 12, '…') }}</td>
                                        <td class="text-sm text-base-content/60">{{ number_format($this->tool->size / (1024 * 1024), 1, ',', ' ') }} Mio</td>
                                        <td>
                                            @if ($this->tool->enabled)
                                                <span class="badge badge-success">activé</span>
                                            @else
                                                <span class="badge badge-ghost">désactivé</span>
                                            @endif
                                        </td>
                                        <td class="text-sm text-base-content/60">{{ $this->tool->uploaded_at?->diffForHumans() ?? '—' }}</td>
                                        <td class="text-right whitespace-nowrap">
                                            @if ($this->tool->enabled)
                                                <button type="button" class="btn btn-sm btn-ghost text-warning"
                                                    wire:click="toggle"
                                                    wire:confirm="Désactiver Rainmeter ? Les postes ne le (re)provisionneront plus (ceux déjà équipés le conservent).">
                                                    <i class="fa-solid fa-toggle-off"></i> Désactiver
                                                </button>
                                            @else
                                                <button type="button" class="btn btn-sm btn-ghost text-success"
                                                    wire:click="toggle"
                                                    wire:confirm="Activer Rainmeter ? Les postes le déploieront automatiquement à leur prochain check-in.">
                                                    <i class="fa-solid fa-toggle-on"></i> Activer
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @else
                                    <tr>
                                        <td colspan="7" class="text-center text-base-content/50 py-8">
                                            Aucun outil dans le catalogue — importez le portable Rainmeter ci-dessous.
                                            Le provisioning serveur (install/update) l'enregistre automatiquement
                                            s'il est embarqué dans le dépôt.
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Upload du portable Rainmeter (WithFileUploads) --}}
            <div class="card bg-base-200 w-full">
                <div class="card-body">
                    <h3 class="card-title text-base">Importer le portable Rainmeter</h3>
                    <p class="text-sm text-base-content/60">
                        Déposez l'archive <code>.zip</code> de l'option « Portable » de Rainmeter (elle doit contenir
                        <code>Rainmeter.exe</code> et un dossier <code>Skins/</code> à la racine). Le serveur calcule le
                        SHA-256 et range le fichier. Un nouvel import remplace la version active (mono-version).
                    </p>

                    <div class="form-control">
                        <label class="label"><span class="label-text">Version (ex. 4.5.18)</span></label>
                        <input type="text" wire:model="version" placeholder="4.5.18"
                            class="input input-bordered w-full max-w-xs" />
                        @error('version') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-control mt-2">
                        <label class="label"><span class="label-text">Archive portable (.zip)</span></label>
                        <input type="file" wire:model="archive" accept=".zip"
                            class="file-input file-input-bordered w-full max-w-md" />
                        @error('archive') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                        <div wire:loading wire:target="archive" class="text-sm text-base-content/60 mt-1">
                            <i class="fa-solid fa-spinner fa-spin"></i> Téléversement en cours…
                        </div>
                    </div>

                    <div class="card-actions justify-end mt-3">
                        <button type="button" class="btn btn-primary" wire:click="importTool"
                            wire:loading.attr="disabled" wire:target="importTool,archive">
                            <i class="fa-solid fa-upload"></i> Importer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </x-molecules.settings-section>
</div>

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
 * Story 25.6 — Section « Tools » du catalogue d'outils agent (AC2, AC3, AC6).
 *
 * Surface d'administration du catalogue `agent_tools` (aujourd'hui : le portable
 * Rainmeter). Façade UI sur le SEUL écrivain de la table
 * {@see AgentToolService} : ce composant n'appelle JAMAIS `save()`/
 * `updateOrCreate()` directement — il délègue `upload()` (ingestion validée :
 * extension/MIME/taille, structure ZIP `Rainmeter.exe` + `Skins/`, SHA-256
 * CALCULÉ SERVEUR, filename dérivé serveur anti-traversal — D5 mono-version) et
 * `toggle()` (bascule GLOBALE `enabled` — D3 ; désactivé → no-op côté agent,
 * SANS désinstaller — D4).
 *
 * Chaque méthode mutante (upload, toggle) est gardée
 * `Gate::authorize('server.admin')` : le middleware `can:server.admin`
 * protège la PAGE, mais l'action reste adressable via `/livewire/update` (double
 * protection iso `releases-rings`). Tout refus métier
 * ({@see AgentToolException}) est catché → `toastError`, jamais une 500.
 * Retours via {@see WithToasts}.
 */
return new class extends Component {
    use WithFileUploads, WithToasts;

    /** Archive ZIP portable uploadée (champ `WithFileUploads`). */
    public $archive = null;

    /** Version saisie par l'admin → dérive le filename serveur (anti-traversal). */
    public string $version = '';

    #[Computed]
    public function tool(): ?AgentTool
    {
        return AgentTool::query()
            ->where('key', AgentToolService::RAINMETER_KEY)
            ->first();
    }

    /**
     * Ingestion d'un nouveau portable Rainmeter. Le serveur valide tout
     * (extension/MIME/taille/structure ZIP) et calcule le SHA-256 — l'UI ne fait
     * qu'une pré-validation légère (présence, taille Livewire) puis délègue.
     */
    public function importTool(): void
    {
        Gate::authorize('server.admin');

        $maxBytes = (int) config('agent.tool_max_upload_bytes');
        $maxKilobytes = max(1, (int) floor($maxBytes / 1024));

        $this->validate([
            // `mimes:zip` + borne taille Livewire (le service revalide et
            // inspecte la structure ZIP — défense en profondeur).
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
            // SEUL écrivain : le service calcule le SHA-256/taille serveur,
            // vérifie la structure ZIP, range le fichier confiné et émet
            // `agent.tool.uploaded`. L'UI ne touche jamais la table.
            $tool = app(AgentToolService::class)->upload(
                $this->archive,
                trim($this->version),
                Auth::id(),
            );
        } catch (AgentToolException $e) {
            // Message UI générique + `reason` machine seulement : le détail
            // (chemins disque internes) reste dans le log serveur côté service —
            // jamais exposé dans le toast (P6).
            $this->toastError("Upload refusé ({$e->reason}). Vérifiez l'archive et réessayez.");

            return;
        }

        $this->reset(['archive', 'version']);
        unset($this->tool);
        $this->toastSuccess("Portable Rainmeter « {$tool->filename} » importé (SHA-256 calculé serveur). Activez-le pour le déployer au parc.");
    }

    /**
     * Bascule l'activation GLOBALE du tool (D3). Activé → exposé au manifest et
     * déployé ; désactivé → no-op côté agent, SANS désinstaller (D4).
     */
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

<div class="flex flex-col gap-6">
    {{-- Catalogue : l'outil de rendu Rainmeter (mono-version, D5) --}}
    <div>
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-lg font-semibold">Outil de rendu (overlay)</h2>
        </div>
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
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    {{-- Upload du portable Rainmeter (WithFileUploads) --}}
    <div class="card bg-base-200">
        <div class="card-body">
            <h3 class="card-title text-base">Importer le portable Rainmeter</h3>
            <p class="text-sm text-base-content/60">
                Déposez l'archive <code>.zip</code> de l'option « Portable » de Rainmeter (elle doit contenir
                <code>Rainmeter.exe</code> et un dossier <code>Skins/</code> à la racine). Le serveur calcule le
                SHA-256 et range le fichier — aucun téléchargement Internet, l'import manuel est le seul chemin.
                Un nouvel import remplace la version active (mono-version).
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

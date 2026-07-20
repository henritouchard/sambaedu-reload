<?php

use App\Components\Traits\WithToasts;
use App\ScriptsOs\Models\ScriptExecutionLog;
use App\ScriptsOs\Support\Humanize;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 16.12 — AC5.1 / AC5.2 / D6.
 *
 * Page Livewire SFC détail — `/admin/settings/scripts-logs/{id}`.
 *
 *  - 404 si UUID inexistant.
 *  - Affiche stdout/stderr en `<pre>` (Blade escape natif → pas de XSS).
 *  - Boutons "Copier" déclenchent un event Alpine.js `copy-to-clipboard`.
 *  - Permission `server.admin` checké en mount() (en plus du middleware route).
 */
new #[Title('Détail log exécution - SE4FS')] class extends Component {
    use WithToasts;

    #[Locked]
    public string $id = '';

    public ?ScriptExecutionLog $log = null;

    public function mount(string $id): void
    {
        abort_unless(
            auth()->check() && Gate::allows('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $this->id = $id;
        $this->log = ScriptExecutionLog::query()->find($id);

        abort_if($this->log === null, 404, 'Log d\'exécution introuvable.');
    }

    public function copyStdout(): void
    {
        $this->dispatch('copy-to-clipboard', text: (string) ($this->log?->stdout_excerpt ?? ''));
        $this->toastSuccess('Stdout copié dans le presse-papier');
    }

    public function copyStderr(): void
    {
        $this->dispatch('copy-to-clipboard', text: (string) ($this->log?->stderr_excerpt ?? ''));
        $this->toastSuccess('Stderr copié dans le presse-papier');
    }
};
?>

<x-organisms.page :title="'Détail log ' . \Illuminate\Support\Str::limit($id, 12, '…')"
    icon="fa-solid fa-clipboard-list"
    description="Métadonnées + stdout/stderr complets de l'exécution."
    :back="route('admin.scripts-logs.index')">

    @php
        $statusBadge = match ($log?->status?->value) {
            'success' => 'badge-success',
            'failure' => 'badge-error',
            'timeout' => 'badge-warning',
            'skipped' => 'badge-neutral',
            default => 'badge-ghost',
        };
    @endphp

    <div class="space-y-6"
        x-data="{
            copy(text) {
                // `navigator.clipboard` n'est dispo qu'en secure context
                // (HTTPS ou localhost). En LAN HTTP (cas se4fs.localdev.fr
                // côté admin), on fallback sur `document.execCommand('copy')`
                // via une textarea hors-écran — déprécié mais toujours
                // largement supporté.
                if (window.isSecureContext && navigator.clipboard?.writeText) {
                    navigator.clipboard.writeText(text).catch((e) => console.error('clipboard failed', e));
                    return;
                }
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '0';
                ta.style.left = '0';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) { console.error('execCommand copy failed', e); }
                document.body.removeChild(ta);
            }
        }"
        @copy-to-clipboard.window="copy($event.detail.text)">

        {{-- Header status --}}
        <div class="card bg-base-100 shadow-sm border border-base-300">
            <div class="card-body py-4">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h2 class="card-title flex items-center gap-2">
                        <i class="fa-solid fa-circle-info text-primary"></i>
                        Exécution
                        <span class="badge {{ $statusBadge }} badge-lg uppercase font-mono" data-testid="status-badge">
                            {{ $log?->status?->value }}
                        </span>
                    </h2>
                    <span class="badge badge-ghost font-mono text-xs">id: {{ $log?->id }}</span>
                </div>
            </div>
        </div>

        {{-- Métadonnées 2 colonnes --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body py-4">
                    <h3 class="card-title text-sm flex items-center gap-2">
                        <i class="fa-solid fa-tags"></i>
                        Contexte
                    </h3>
                    <dl class="text-sm space-y-1">
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">Poste</dt>
                            <dd class="font-mono text-xs" data-testid="meta-workstation">{{ $log?->workstation_uuid }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">Script</dt>
                            <dd class="font-mono text-xs">
                                @if ($log?->script_id !== null)
                                    #{{ $log?->script_id }}
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">Source</dt>
                            <dd>{{ $log?->script_source?->value }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">Action</dt>
                            <dd>{{ $log?->action?->value }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">OS</dt>
                            <dd>{{ $log?->os?->value }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body py-4">
                    <h3 class="card-title text-sm flex items-center gap-2">
                        <i class="fa-solid fa-stopwatch"></i>
                        Exécution
                    </h3>
                    <dl class="text-sm space-y-1">
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">Statut</dt>
                            <dd><span class="badge {{ $statusBadge }} badge-sm">{{ $log?->status?->value }}</span></dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">Exit code</dt>
                            <dd class="font-mono text-xs">{{ $log?->exit_code ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">Durée</dt>
                            <dd class="font-mono text-xs">{{ Humanize::duration((int) $log?->duration_ms) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">Démarré</dt>
                            <dd class="font-mono text-xs"
                                title="{{ $log?->started_at?->toIso8601String() }}">
                                {{ $log?->started_at?->toDateTimeString() }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">Reporté</dt>
                            <dd class="font-mono text-xs">{{ $log?->reported_at?->toDateTimeString() }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium">Correlation</dt>
                            <dd class="font-mono text-xs">{{ $log?->correlation_id ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        {{-- stdout --}}
        <div class="card bg-base-100 shadow-sm border border-base-300">
            <div class="card-body py-4">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="card-title text-sm flex items-center gap-2">
                        <i class="fa-solid fa-terminal"></i>
                        Stdout
                    </h3>
                    <button type="button" class="btn btn-outline btn-xs" wire:click="copyStdout"
                        data-testid="copy-stdout">
                        <i class="fa-regular fa-copy"></i>
                        Copier
                    </button>
                </div>
                {{-- Blade `{{ }}` escape natif HTML — pas de XSS possible
                     même si stdout contient `<script>alert(1)</script>`. --}}
                <pre class="bg-base-200 p-4 rounded text-xs overflow-x-auto whitespace-pre-wrap"
                    data-testid="stdout-pre">{{ $log?->stdout_excerpt ?? '(empty)' }}</pre>
            </div>
        </div>

        {{-- stderr --}}
        <div class="card bg-base-100 shadow-sm border border-base-300">
            <div class="card-body py-4">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="card-title text-sm flex items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                        Stderr
                    </h3>
                    <button type="button" class="btn btn-outline btn-xs" wire:click="copyStderr"
                        data-testid="copy-stderr">
                        <i class="fa-regular fa-copy"></i>
                        Copier
                    </button>
                </div>
                <pre class="bg-base-200 p-4 rounded text-xs overflow-x-auto whitespace-pre-wrap"
                    data-testid="stderr-pre">{{ $log?->stderr_excerpt ?? '(empty)' }}</pre>
            </div>
        </div>

    </div>
</x-organisms.page>

<?php

use App\Components\Traits\WithToasts;
use App\Services\Extensions\ExtensionCatalogService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 54.1 (AC1) — /admin/extensions/{id} : FICHE d'une extension.
 *
 * Lecture seule (patron `admin/shares/[id]` sans les gestes d'écriture) : tout
 * ce qui est affiché ici — version, description, scopes DEMANDÉS, dépendances,
 * URL d'entrée, visibilité — provient du **manifest**, seul contrat public du
 * système d'extensions (FR5).
 *
 * Les listes vides sont rendues PROPREMENT (« Aucun scope demandé », « Aucune
 * dépendance ») : jamais une section cassée.
 *
 * ⚠️ Les `scopes` sont une INFORMATION admin (FR3) : ils ne sont ni accordés ni
 * consommés en 54.1 (Epics 55/56). Les rôles de visibilité sont STOCKÉS ici,
 * RÉSOLUS par le lanceur en Story 54.3.
 *
 * Sécurité : `can:server.admin` sur la route + double garde dans `mount()`.
 * Identifiant inconnu ⇒ 404.
 */
new #[Title('Extension')] class extends Component {
    use WithToasts;

    /** Identifiant serveur-autoritatif (jamais re-piloté depuis le client). */
    #[Locked]
    public int $id = 0;

    /** @var array<string, mixed> */
    public array $extension = [];

    public function mount(string $id): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->id = (int) $id;

        $extension = app(ExtensionCatalogService::class)->find($this->id);
        if ($extension === null) {
            abort(404);
        }

        $this->extension = $extension;
    }
};
?>

<x-organisms.page :title="$extension['name']" icon="fa-solid fa-puzzle-piece"
    :back="route('admin.extensions')" back-text="Retour à la bibliothèque">

    <div class="flex flex-col gap-6">

        {{-- ===================== Identité ===================== --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <div class="flex items-start gap-4">
                    <div class="hidden sm:flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <i class="{{ $extension['icon'] !== '' ? $extension['icon'] : 'fa-solid fa-puzzle-piece' }} text-xl"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="card-title text-lg flex items-center gap-2 flex-wrap">
                            <span class="truncate">{{ $extension['name'] }}</span>
                            <span class="badge badge-sm badge-outline gap-1">
                                <i class="{{ $extension['type_icon'] }} text-[10px]"></i>
                                {{ $extension['type_label'] }}
                            </span>
                            <span class="badge badge-sm {{ $extension['status_badge'] }}"
                                data-testid="extension-status">{{ $extension['status_label'] }}</span>
                        </h2>

                        <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-code-branch w-4 text-center opacity-50"></i>
                                <span>Version</span>
                                <span class="font-mono">{{ $extension['version'] !== '' ? $extension['version'] : '—' }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-building w-4 text-center opacity-50"></i>
                                <span>Éditeur</span>
                                <span>{{ $extension['publisher'] !== '' ? $extension['publisher'] : 'Non renseigné' }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-box-archive w-4 text-center opacity-50"></i>
                                <span>Source</span>
                                <span>{{ $extension['source_name'] }}</span>
                                <span class="badge badge-ghost badge-xs">{{ $extension['source_kind_label'] }}</span>
                                @if ($extension['source_is_official'])
                                    <span class="badge badge-xs badge-success gap-1" title="Source officielle SambaEdu">
                                        <i class="fa-solid fa-certificate text-[9px]"></i> Officielle
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-fingerprint w-4 text-center opacity-50"></i>
                                <span>Identifiant</span>
                                <span class="font-mono text-xs">{{ $extension['key'] }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70 sm:col-span-2">
                                <i class="fa-solid fa-arrow-up-right-from-square w-4 text-center opacity-50"></i>
                                <span>Cible</span>
                                <span class="font-mono text-xs break-all"
                                    data-testid="extension-entry-url">{{ $extension['entry_url'] !== '' ? $extension['entry_url'] : '—' }}</span>
                            </div>
                        </dl>

                        @if ($extension['description'] !== '')
                            <p class="mt-4 text-sm text-base-content/80 whitespace-pre-line">{{ $extension['description'] }}</p>
                        @else
                            <p class="mt-4 text-sm text-base-content/50 italic">Aucune description fournie par le manifest.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== Ce que l'extension demande ===================== --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- Scopes demandés (information admin — jamais consommés en 54.1). --}}
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body">
                    <h3 class="card-title text-base">
                        <i class="fa-solid fa-shield-halved text-primary"></i> Autorisations demandées
                        <span class="badge badge-neutral badge-sm">{{ count($extension['scopes']) }}</span>
                    </h3>
                    <p class="text-xs text-base-content/50">
                        Ce que l'extension déclare vouloir consulter. Rien n'est accordé aujourd'hui :
                        c'est une information de transparence.
                    </p>
                    @if (count($extension['scopes']) === 0)
                        <p class="text-sm text-base-content/50 py-3" data-testid="no-scopes">Aucun scope demandé.</p>
                    @else
                        <ul class="flex flex-wrap gap-2 pt-2" data-testid="scopes-list">
                            @foreach ($extension['scopes'] as $scope)
                                <li wire:key="scope-{{ $loop->index }}">
                                    <span class="badge badge-outline font-mono text-xs">{{ $scope }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- Dépendances déclarées. --}}
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body">
                    <h3 class="card-title text-base">
                        <i class="fa-solid fa-diagram-project text-primary"></i> Dépendances
                        <span class="badge badge-neutral badge-sm">{{ count($extension['dependencies']) }}</span>
                    </h3>
                    <p class="text-xs text-base-content/50">
                        Les autres extensions dont celle-ci a besoin pour fonctionner.
                    </p>
                    @if (count($extension['dependencies']) === 0)
                        <p class="text-sm text-base-content/50 py-3" data-testid="no-dependencies">Aucune dépendance.</p>
                    @else
                        <ul class="flex flex-wrap gap-2 pt-2" data-testid="dependencies-list">
                            @foreach ($extension['dependencies'] as $dependency)
                                <li wire:key="dependency-{{ $loop->index }}">
                                    <span class="badge badge-outline font-mono text-xs">{{ $dependency }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- ===================== Public visé ===================== --}}
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body">
                <h3 class="card-title text-base">
                    <i class="fa-solid fa-users text-primary"></i> Public visé
                </h3>
                <p class="text-xs text-base-content/50">
                    Rôles métier auxquels la tuile est destinée. L'autorisation réelle reste du ressort de
                    l'extension elle-même.
                </p>
                @if (count($extension['visibility_roles']) === 0)
                    <p class="text-sm text-base-content/50 py-3">Aucun rôle déclaré.</p>
                @else
                    <ul class="flex flex-wrap gap-2 pt-2" data-testid="visibility-roles">
                        @foreach ($extension['visibility_roles'] as $role)
                            <li wire:key="role-{{ $loop->index }}">
                                <span class="badge badge-ghost">{{ $role }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</x-organisms.page>

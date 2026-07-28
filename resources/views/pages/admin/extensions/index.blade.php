<?php

use App\Components\Traits\WithToasts;
use App\Services\Extensions\ExtensionCatalogService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 54.1 (AC1) — /admin/extensions : BIBLIOTHÈQUE des extensions.
 *
 * Page en LECTURE SEULE : elle expose ce que l'admin peut intégrer, d'où ça
 * vient et dans quel état c'est. Le geste « Intégrer » / « Désinstaller » et son
 * journal d'audit arrivent en Story 54.2 — l'état `status` est ici AFFICHÉ,
 * jamais muté.
 *
 * Le registre est MULTI-SOURCES dès ce socle (AR7) : chaque carte porte la
 * source d'origine. En 54.1 une seule source existe (« Embarquée (SambaEdu) »,
 * manifests du dépôt) ; les sources distantes relèvent de l'Epic 56.
 *
 * NFR15 — 3 couches strictes : toute la donnée vient de
 * {@see ExtensionCatalogService}, aucun Eloquent dans le composant.
 *
 * Sécurité (3 couches) : middlewares du groupe `admin` + `can:server.admin` sur
 * la route + double garde `Gate::allows('server.admin')` ci-dessous.
 */
new #[Title('Extensions')] class extends Component {
    use WithToasts;

    /**
     * Les extensions du registre, déjà mises en forme par le service.
     *
     * @var list<array<string, mixed>>
     */
    public array $extensions = [];

    public function mount(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        try {
            $this->extensions = app(ExtensionCatalogService::class)->library();
        } catch (\Throwable $e) {
            // Une bibliothèque illisible ne doit pas rendre une 500 : on affiche
            // l'état vide et on le dit.
            report($e);
            $this->extensions = [];
            $this->toastError("Impossible de charger la bibliothèque d'extensions. Consultez les journaux serveur.");
        }
    }

    /** Nombre d'extensions déjà intégrées (indicateur d'en-tête). */
    public function integratedCount(): int
    {
        return count(array_filter(
            $this->extensions,
            static fn (array $extension): bool => ($extension['status'] ?? '') === 'integrated',
        ));
    }
};
?>

<x-organisms.page title="Extensions" icon="fa-solid fa-puzzle-piece"
    description="Bibliothèque des extensions disponibles pour cette instance : ce que vous pouvez intégrer, d'où ça vient et ce que ça demande.">

    <div class="flex flex-col gap-6 pt-2">

        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">À quoi sert cette page</p>
                <p class="text-sm opacity-80">
                    Chaque extension est décrite par un <strong>manifest</strong> fourni par sa source.
                    Ouvrez une fiche pour voir sa version, sa description, les
                    <strong>autorisations qu'elle demande</strong> et ses dépendances.
                    L'intégration au lanceur se fera depuis cette bibliothèque.
                </p>
            </div>
        </div>

        @if (count($extensions) === 0)
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body items-center text-center py-16">
                    <i class="fa-solid fa-puzzle-piece text-4xl opacity-30 mb-3"></i>
                    <h3 class="text-lg font-semibold mb-1">Aucune extension</h3>
                    <p class="text-base-content/60 max-w-lg">
                        Aucune extension n'est disponible sur cette instance. Les extensions embarquées
                        sont chargées au déploiement du serveur.
                    </p>
                </div>
            </div>
        @else
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <p class="text-sm text-base-content/60">
                    {{ count($extensions) }} extension(s) au catalogue —
                    {{ $this->integratedCount() }} intégrée(s).
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" data-testid="extensions-grid">
                @foreach ($extensions as $extension)
                    <a href="{{ route('admin.extensions.show', ['id' => $extension['id']]) }}"
                        wire:key="extension-{{ $extension['id'] }}"
                        data-testid="extension-card-{{ $extension['id'] }}"
                        class="card bg-base-100 border border-base-300 shadow-sm transition-all hover:shadow-md hover:border-primary/40">
                        <div class="card-body gap-3">
                            <div class="flex items-start gap-3">
                                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <i class="{{ $extension['icon'] !== '' ? $extension['icon'] : 'fa-solid fa-puzzle-piece' }} text-lg"></i>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h2 class="card-title text-base truncate">{{ $extension['name'] }}</h2>
                                    <p class="text-xs text-base-content/60 truncate">
                                        {{ $extension['publisher'] !== '' ? $extension['publisher'] : 'Éditeur non renseigné' }}
                                    </p>
                                </div>
                                <span class="badge badge-sm {{ $extension['status_badge'] }} shrink-0">
                                    {{ $extension['status_label'] }}
                                </span>
                            </div>

                            @if ($extension['description'] !== '')
                                <p class="text-sm text-base-content/70 line-clamp-2">{{ $extension['description'] }}</p>
                            @endif

                            <div class="flex items-center gap-2 flex-wrap text-xs">
                                <span class="badge badge-sm badge-outline gap-1">
                                    <i class="{{ $extension['type_icon'] }} text-[10px]"></i>
                                    {{ $extension['type_label'] }}
                                </span>
                                <span class="badge badge-sm badge-ghost gap-1">
                                    <i class="fa-solid fa-box-archive text-[10px]"></i>
                                    {{ $extension['source_name'] }}
                                </span>
                                @if ($extension['version'] !== '')
                                    <span class="text-base-content/40 font-mono">v{{ $extension['version'] }}</span>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-organisms.page>

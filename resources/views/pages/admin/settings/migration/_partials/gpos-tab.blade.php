<?php

use App\Services\Gpo\GpoEffectivenessResolver;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Onglet « GPO » de /admin/settings/migration — inventaire des GPO du domaine
 * avec leur EFFECTIVITÉ RÉELLE sur les périmètres de cette instance.
 *
 * Remplace le listing `/admin/settings/gpo`, dont le badge « Active » signifiait
 * en réalité `versionNumber > 0` — c'est-à-dire « éditée au moins une fois »,
 * sans aucun rapport avec le fait d'être appliquée. Sur un parc neutralisé par
 * blocage d'héritage, ce badge affichait en vert des GPO inertes.
 *
 * DEUX périmètres, volontairement affichés côte à côte : une GPO porte une
 * moitié MACHINE et une moitié UTILISATEUR qui suivent des chemins d'héritage
 * distincts. Bloquer l'héritage sur l'OU des postes ne neutralise que la
 * première — les stratégies utilisateur continuent de s'appliquer au logon.
 * N'afficher que le périmètre machine produirait un « Neutralisée » faussement
 * rassurant.
 *
 * Périmètre : LECTURE SEULE. Aucune écriture AD, aucun `samba-tool` — LdapRecord
 * direct, ~12 opérations LDAP quel que soit le nombre de GPO.
 */
new class extends Component {
    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }
    }

    /**
     * @return array{available: bool, error: ?string, perimeters: array<string, mixed>, gpos: list<array<string, mixed>>}
     */
    #[Computed]
    public function report(): array
    {
        return app(GpoEffectivenessResolver::class)->resolve();
    }

    /**
     * Nombre de GPO effectives PAR périmètre.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function effectiveCounts(): array
    {
        $counts = [];

        foreach ($this->report()['gpos'] as $gpo) {
            foreach ($gpo['statuses'] as $perimeter => $verdict) {
                $counts[$perimeter] ??= 0;
                if ($verdict['status'] === GpoEffectivenessResolver::STATUS_EFFECTIVE) {
                    $counts[$perimeter]++;
                }
            }
        }

        return $counts;
    }

    public function refresh(): void
    {
        unset($this->report, $this->effectiveCounts);
    }

    /** Version AD 32 bits → `major.minor` (16 bits hauts / bas). */
    public function formatVersion(?int $version): string
    {
        if ($version === null) {
            return '—';
        }

        return sprintf('%d.%d', $version >> 16, $version & 0xFFFF);
    }
};
?>

<div class="flex flex-col gap-4" data-testid="migration-gpos-tab">

    @php
        $report = $this->report();
        $effective = $this->effectiveCounts();

        $badges = [
            \App\Services\Gpo\GpoEffectivenessResolver::STATUS_EFFECTIVE => [
                'label' => 'Effective', 'class' => 'badge-success', 'icon' => 'fa-circle-check',
            ],
            \App\Services\Gpo\GpoEffectivenessResolver::STATUS_NEUTRALIZED => [
                'label' => 'Neutralisée', 'class' => 'badge-ghost', 'icon' => 'fa-ban',
            ],
            \App\Services\Gpo\GpoEffectivenessResolver::STATUS_LINK_DISABLED => [
                'label' => 'Lien désactivé', 'class' => 'badge-ghost', 'icon' => 'fa-link-slash',
            ],
            \App\Services\Gpo\GpoEffectivenessResolver::STATUS_OUT_OF_SCOPE => [
                'label' => 'Hors périmètre', 'class' => 'badge-ghost', 'icon' => 'fa-arrow-up-right-from-square',
            ],
        ];

        $perimeterLabels = [
            \App\Services\Gpo\GpoEffectivenessResolver::PERIMETER_COMPUTERS => 'Postes',
            \App\Services\Gpo\GpoEffectivenessResolver::PERIMETER_PEOPLE => 'Utilisateurs',
        ];
    @endphp

    @if (! $report['available'])
        <div role="alert" class="alert alert-error" data-testid="gpos-unavailable">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <div class="font-semibold">Effectivité indéterminée</div>
                <div class="text-sm">{{ $report['error'] }}</div>
                <div class="text-sm opacity-80">
                    Aucun verdict n'est affiché : une liste vide serait interprétée à tort comme « plus aucune GPO ».
                </div>
            </div>
        </div>
    @else
        {{-- Synthèse par périmètre. Une GPO peut être éteinte côté machine et
             vivante côté utilisateur : les deux comptes sont donc distincts. --}}
        <div class="alert {{ ($effective['people'] ?? 0) > 1 || ($effective['computers'] ?? 0) > 1 ? 'alert-warning' : 'alert-info' }}"
            data-testid="gpos-summary">
            <i class="fa-solid fa-circle-info"></i>
            <div class="text-sm">
                <div>
                    <span class="font-semibold" data-testid="gpos-effective-computers">{{ $effective['computers'] ?? 0 }}</span>
                    GPO effective(s) sur les <strong>postes</strong>
                    (<span class="font-mono text-xs">{{ $report['perimeters']['computers']['dn'] ?? '—' }}</span>)
                    ·
                    <span class="font-semibold" data-testid="gpos-effective-people">{{ $effective['people'] ?? 0 }}</span>
                    sur les <strong>utilisateurs</strong>
                    (<span class="font-mono text-xs">{{ $report['perimeters']['people']['dn'] ?? '—' }}</span>).
                </div>
                <div class="opacity-80 mt-1">
                    Les deux moitiés d'une GPO (machine / utilisateur) héritent par des chemins distincts : un blocage
                    posé sur l'OU des postes ne neutralise PAS les stratégies utilisateur, qui continuent de
                    s'appliquer à chaque ouverture de session.
                </div>
                <div class="opacity-80 mt-1">
                    Cible d'extinction : une seule GPO effective, <span class="font-mono text-xs">SE_agent_bootstrap</span>.
                    Les GPO de domaine sont partagées entre établissements — on ne les délie jamais, on les neutralise
                    par <span class="font-mono text-xs">gPOptions=1</span> côté collège.
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="text-xs text-base-content/60 flex flex-col gap-0.5">
                @foreach ($perimeterLabels as $key => $label)
                    @if (isset($report['perimeters'][$key]))
                        <div>
                            <span class="font-semibold">{{ $label }}</span> :
                            <span class="font-mono">{{ implode(' → ', $report['perimeters'][$key]['chain']) }}</span>
                            @if (! empty($report['perimeters'][$key]['blockedNodes']))
                                · blocage sur
                                <span class="font-mono">{{ implode(', ', $report['perimeters'][$key]['blockedNodes']) }}</span>
                            @else
                                · <span class="text-warning">aucun blocage d'héritage</span>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
            <button type="button" class="btn btn-sm btn-ghost" wire:click="refresh" data-testid="gpos-refresh">
                <i class="fa-solid fa-rotate"></i>
                Recalculer
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="table table-sm table-zebra" data-testid="gpos-table">
                <thead>
                    <tr>
                        <th>GPO</th>
                        <th>Version</th>
                        <th>Postes</th>
                        <th>Utilisateurs</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['gpos'] as $gpo)
                        @php
                            // Accolades strippées : UrlGenerator réinterprète `{}` comme
                            // placeholder de route et lève UrlGenerationException.
                            $detailUrl = route('admin.gpo.show', ['guid' => trim((string) $gpo['guid'], '{}')]);
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ $detailUrl }}" class="font-semibold hover:text-primary">
                                    {{ $gpo['displayName'] }}
                                </a>
                            </td>
                            <td class="font-mono text-xs">{{ $this->formatVersion($gpo['versionNumber']) }}</td>
                            @foreach ($perimeterLabels as $key => $label)
                                @php
                                    $verdict = $gpo['statuses'][$key] ?? null;
                                    $badge = $badges[$verdict['status'] ?? 'out_of_scope'] ?? $badges['out_of_scope'];
                                @endphp
                                <td data-testid="gpo-cell-{{ $key }}-{{ $verdict['status'] ?? 'out_of_scope' }}">
                                    <div class="flex flex-col gap-1">
                                        <div class="flex items-center gap-1 flex-wrap">
                                            <span class="badge badge-xs {{ $badge['class'] }} gap-1">
                                                <i class="fa-solid {{ $badge['icon'] }} text-[0.6rem]"></i>
                                                {{ $badge['label'] }}
                                            </span>
                                            @if ($verdict['enforced'] ?? false)
                                                <span class="badge badge-xs badge-warning gap-1">
                                                    <i class="fa-solid fa-lock text-[0.6rem]"></i>
                                                    Enforced
                                                </span>
                                            @endif
                                        </div>
                                        <span class="text-[0.7rem] text-base-content/60">{{ $verdict['detail'] ?? '' }}</span>
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-base-content/50 py-6">
                                Aucune GPO dans le domaine.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="text-xs text-base-content/50">
            Non pris en compte : liens de site, filtrage de sécurité / WMI, désactivation partielle d'une moitié de GPO
            (attribut <span class="font-mono">flags</span>), et ordre de précédence entre GPO effectives. La
            <span class="font-mono">Default Domain Policy</span> porte en outre la politique de mot de passe du
            domaine, qui s'applique indépendamment de l'héritage GPO.
        </div>
    @endif

</div>

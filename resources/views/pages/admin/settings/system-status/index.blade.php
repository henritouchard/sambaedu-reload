<?php

use App\Components\Traits\WithToasts;
use App\Doctor\Checks\Ad\LdapBindCheck;
use App\Doctor\Checks\Apache\ApacheConfigCheck;
use App\Doctor\Checks\ControlHub\ControlHubReachableCheck;
use App\Doctor\Checks\Database\PostgresConnectionCheck;
use App\Doctor\Checks\Extensions\ExtensionsAuditTrailCheck;
use App\Doctor\Checks\Extensions\ExtensionsOidcClientsCheck;
use App\Doctor\Checks\Extensions\ExtensionsReachableCheck;
use App\Doctor\Checks\Gpo\DcReachableCheck;
use App\Doctor\Checks\Gpo\KerberosTicketCheck;
use App\Doctor\Checks\Ipxe\IpxeConfigCheck;
use App\Doctor\EnvironmentCheck;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /admin/settings/system-status — État du système (MVP).
 *
 * Checks d'environnement À LA DEMANDE (bouton « Lancer les checks ») :
 * réutilise l'architecture {@see \App\Doctor\EnvironmentCheck} de
 * `sambaedu:doctor`, organisée ici en sections thématiques. Aucun check
 * n'est exécuté au chargement de la page (certains font du réseau).
 *
 * NB : l'inventaire des distros installables a été déplacé vers la page
 * dédiée `/admin/settings/os` (2026-07-18) — les distros sont des sources
 * d'installation, pas un diagnostic d'environnement.
 */
new #[Title('État du système')] class extends Component {
    use WithToasts;

    /**
     * Onglet actif (convention onglets : #[Url(keep:true)] $tab).
     *  - « general » : checks d'environnement (connectivité AD/DB/hub/Apache/iPXE).
     *  - « logs »    : Error Logger embarqué (erreurs legacy PHP + exceptions
     *    Laravel) — diagnostic runtime, pas un outil de migration.
     */
    #[Url(keep: true)]
    public string $tab = 'general';

    private const TABS = ['general', 'logs'];

    /**
     * Sections thématiques → classes de checks (exécutés dans l'ordre).
     * Toutes implémentent EnvironmentCheck et sont résolues via le container.
     */
    private const CHECK_SECTIONS = [
        'Active Directory' => [DcReachableCheck::class, LdapBindCheck::class, KerberosTicketCheck::class],
        'Base de données' => [PostgresConnectionCheck::class],
        'controlHub' => [ControlHubReachableCheck::class],
        'Apache' => [ApacheConfigCheck::class],
        'iPXE' => [IpxeConfigCheck::class],
        // Story 56.5 — santé du système d'extensions. Les trois checks sont
        // read-only : le premier sonde les backends `127.0.0.1:<port>` en direct
        // (comme controlHub fait son HEAD), les deux autres lisent un marqueur
        // et le registre des clients. Exécution au `wire:init`, donc APRÈS le
        // premier rendu — la sonde réseau n'allonge jamais le chargement.
        'Extensions' => [
            ExtensionsReachableCheck::class,
            ExtensionsAuditTrailCheck::class,
            ExtensionsOidcClientsCheck::class,
        ],
    ];

    /**
     * Résultats des checks par section :
     * [section => [['name','level','detail','fix'], ...]].
     *
     * @var array<string, array<int, array{name: string, level: string, detail: string, fix: ?string}>>
     */
    public array $results = [];

    /** Checks déjà lancés au moins une fois (affichage de l'état vide). */
    public bool $checksRan = false;

    public function mount(): void
    {
        $this->ensureAdmin();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'general';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    /**
     * Defense in depth (fix review F7) : les actions Livewire repassent par
     * le guard — le middleware route couvre le canal `livewire/update`, mais
     * on ne dépend pas que de lui.
     */
    private function ensureAdmin(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }
    }

    /** Horodatage lisible de la dernière exécution des checks. */
    public ?string $lastRunAt = null;

    /**
     * Exécute tous les checks.
     *
     * Déclenchée par `wire:init` juste APRÈS le premier rendu (la page
     * s'affiche instantanément, les checks réseau arrivent en différé —
     * objectif « coup d'œil » sans bloquer le chargement), puis par le
     * bouton « Rafraîchir ».
     */
    public function runChecks(): void
    {
        $this->ensureAdmin();

        $results = [];
        foreach (self::CHECK_SECTIONS as $section => $classes) {
            foreach ($classes as $class) {
                $results[$section][] = $this->runOne($class);
            }
        }

        $this->results = $results;
        $this->checksRan = true;
        $this->lastRunAt = \Illuminate\Support\Carbon::now()->format('H:i:s');
    }

    /**
     * Exécute un check en isolant toute exception (un check qui crashe ne
     * doit pas casser la page — il devient un résultat `error`).
     *
     * @param class-string<EnvironmentCheck> $class
     * @return array{name: string, level: string, detail: string, fix: ?string}
     */
    private function runOne(string $class): array
    {
        try {
            /** @var EnvironmentCheck $check */
            $check = app($class);
            $result = $check->run();

            return [
                'name' => $check->name(),
                'level' => $result->level->value,
                'detail' => $result->detail,
                'fix' => $result->fix,
            ];
        } catch (\Throwable $e) {
            return [
                'name' => class_basename($class),
                'level' => 'error',
                'detail' => sprintf('check en erreur : %s', substr($e->getMessage(), 0, 160)),
                'fix' => null,
            ];
        }
    }
};
?>

<x-organisms.page title="État du système"
    icon="fa-solid fa-heart-pulse"
    description="Diagnostic du serveur : connectivité et environnement (Général) et journaux d'erreurs runtime (Logs)."
    back="{{ route('admin.settings') }}">

    <div class="flex flex-col gap-6 pt-4">

        @php
            $statusTabs = [
                'general' => ['label' => 'Général', 'icon' => 'fa-solid fa-heart-pulse'],
                'logs' => ['label' => 'Logs', 'icon' => 'fa-solid fa-bug'],
            ];
        @endphp
        <x-molecules.tabs :tabs="$statusTabs" :active="$tab" />

        @if ($tab === 'general')
    {{-- wire:init : les checks se lancent automatiquement juste APRÈS le
         premier rendu (la page s'affiche tout de suite, l'état des
         connexions arrive en différé — objectif « coup d'œil »). --}}
    <div class="flex flex-col gap-8" wire:init="runChecks">

        {{-- ============================================================
             Checks d'environnement
             ============================================================ --}}
        <div class="flex items-center justify-between">
            <p class="text-sm text-base-content/70">
                @if ($lastRunAt)
                    Dernière vérification à {{ $lastRunAt }}.
                @else
                    Vérification des connexions en cours…
                @endif
            </p>
            <button class="btn btn-primary" wire:click="runChecks" wire:loading.attr="disabled" data-testid="run-checks">
                <span wire:loading.remove wire:target="runChecks"><i class="fa-solid fa-rotate mr-2"></i>Rafraîchir</span>
                <span wire:loading wire:target="runChecks"><span class="loading loading-spinner loading-sm mr-2"></span>Vérification…</span>
            </button>
        </div>

        @if (! $checksRan)
            {{-- Skeleton affiché entre le rendu initial et le retour du
                 wire:init (quelques secondes si AD/hub injoignables). --}}
            <div class="rounded-2xl border-2 border-dashed border-base-300 p-8 text-center text-base-content/50"
                data-testid="checks-loading-state" wire:loading.class="opacity-60" wire:target="runChecks">
                <span class="loading loading-spinner loading-lg mb-3"></span>
                <p>Vérification de l'état des connexions (AD, base de données, controlHub, Apache, iPXE)…</p>
            </div>
        @else
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                @foreach ($results as $section => $checks)
                    @php
                        $worst = collect($checks)->pluck('level')->contains('error')
                            ? 'error'
                            : (collect($checks)->pluck('level')->contains('warn') ? 'warning' : 'success');
                    @endphp
                    <div class="card bg-base-100 shadow-md border border-{{ $worst }}/30"
                        data-testid="check-section-{{ \Illuminate\Support\Str::slug($section) }}">
                        <div class="card-body p-5">
                            <h3 class="card-title text-base flex items-center justify-between">
                                {{ $section }}
                                <span class="badge badge-{{ $worst }} badge-sm">
                                    {{ $worst === 'success' ? 'OK' : ($worst === 'warning' ? 'Attention' : 'Erreur') }}
                                </span>
                            </h3>
                            <ul class="flex flex-col gap-2 mt-2">
                                @foreach ($checks as $check)
                                    <li class="flex items-start gap-3 text-sm">
                                        @if ($check['level'] === 'ok')
                                            <i class="fa-solid fa-circle-check text-success mt-0.5"></i>
                                        @elseif ($check['level'] === 'warn')
                                            <i class="fa-solid fa-triangle-exclamation text-warning mt-0.5"></i>
                                        @else
                                            <i class="fa-solid fa-circle-xmark text-error mt-0.5"></i>
                                        @endif
                                        <div>
                                            <span class="font-medium">{{ $check['name'] }}</span>
                                            <span class="text-base-content/70">— {{ $check['detail'] }}</span>
                                            @if ($check['fix'])
                                                <p class="text-xs text-base-content/50 mt-0.5">
                                                    <i class="fa-solid fa-wrench mr-1"></i>{{ $check['fix'] }}
                                                </p>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
        @elseif ($tab === 'logs')
            {{-- Error Logger embarqué : erreurs legacy PHP + exceptions Laravel
                 (diagnostic runtime). Sorti de « Migration SE4 → SE5 » car il
                 sert aussi le fonctionnement natif SE5. --}}
            <livewire:pages::admin.error-logger.index />
        @endif

    </div>
</x-organisms.page>

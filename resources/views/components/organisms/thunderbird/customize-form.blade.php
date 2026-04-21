<?php

use App\Components\Traits\WithToasts;
use App\Enums\AppKind;
use App\Services\AppCustomization\AppCustomizationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Form Livewire SFC — personnalisation Thunderbird.
 *
 * Story 4.8 — Task 4.6 (AC 8). MVP : section Proxy uniquement.
 * Les champs mail servers peuvent être ajoutés en follow-up (stub documenté).
 */
new class extends Component {
    use WithToasts;

    private const ALLOWED_SCOPE_TYPES = [
        \App\Models\User::class,
        \App\Models\UserGroup::class,
        \App\Models\WorkstationGroup::class,
    ];

    public string $appKind = 'thunderbird';
    public ?string $scopeType = null;
    public ?int $scopeId = null;

    public string $proxyMode = 'manual';
    public string $proxyHost = '';
    public string $proxyPort = '';
    public bool $proxyLocked = true;

    public function mount(string $appKind, ?string $scopeType = null, ?int $scopeId = null): void
    {
        $this->appKind = $appKind;
        $this->scopeType = $scopeType;
        $this->scopeId = $scopeId;
        $this->loadExistingOverride();
    }

    private function loadExistingOverride(): void
    {
        $customization = \App\Models\AppCustomization::query()
            ->ofKind($this->appKind)
            ->when($this->scopeType === null, fn($q) => $q->whereNull('customizable_id')->where('is_default', true))
            ->when($this->scopeType !== null, fn($q) => $q->where('customizable_type', $this->scopeType)->where('customizable_id', $this->scopeId))
            ->first();

        if ($customization === null) {
            return;
        }

        $proxy = (array) ($customization->policies_json['policies']['Proxy'] ?? []);
        $this->proxyMode = (string) ($proxy['Mode'] ?? 'manual');
        $this->proxyLocked = (bool) ($proxy['Locked'] ?? true);

        $httpProxy = (string) ($proxy['HTTPProxy'] ?? '');
        $httpProxy = preg_replace('#^https?://#', '', $httpProxy) ?? '';
        if (str_contains($httpProxy, ':')) {
            [$host, $port] = explode(':', $httpProxy, 2);
            $this->proxyHost = $host;
            $this->proxyPort = $port;
        } else {
            $this->proxyHost = $httpProxy;
        }
    }

    public function save(): void
    {
        if (! Gate::allows('app.customize')) {
            $this->toastAccessDenied();
            return;
        }

        $proxy = [
            'Mode' => $this->proxyMode,
            'Locked' => $this->proxyLocked,
        ];
        if ($this->proxyMode === 'manual' && $this->proxyHost !== '' && $this->proxyPort !== '') {
            // Fidèle `tb_import_policy` L233 — préfixe http:// obligatoire.
            $proxy['HTTPProxy'] = 'http://' . $this->proxyHost . ':' . $this->proxyPort;
        }

        $policies = [
            'policies' => [
                'Proxy' => $proxy,
            ],
        ];

        try {
            $scope = $this->resolveScope();
            $user = Auth::user();
            $author = $user instanceof \App\Models\User ? $user : null;

            app(AppCustomizationService::class)->savePolicies(
                AppKind::from($this->appKind),
                $scope,
                $policies,
                $author,
            );

            $this->toastSuccess('Personnalisation Thunderbird enregistrée.');
            $this->dispatch('customization-saved', appKind: $this->appKind);
        } catch (\InvalidArgumentException $e) {
            $this->toastError('Validation : ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->toastError('Erreur : ' . $e->getMessage());
        }
    }

    private function resolveScope(): ?Model
    {
        if ($this->scopeType === null || $this->scopeId === null) {
            return null;
        }
        if (! in_array($this->scopeType, self::ALLOWED_SCOPE_TYPES, true)) {
            throw new \InvalidArgumentException('Type de scope non autorisé.');
        }
        /** @var class-string<Model> $cls */
        $cls = $this->scopeType;
        return $cls::query()->find($this->scopeId);
    }
};
?>

<div class="space-y-6">
    <section>
        <h3 class="font-semibold mb-2">Proxy</h3>
        <div class="space-y-3">
            <div>
                <label class="label py-1">
                    <span class="label-text text-xs">Mode</span>
                </label>
                <select wire:model="proxyMode" class="select select-bordered select-sm w-full">
                    <option value="manual">Manuel</option>
                    <option value="none">Aucun</option>
                    <option value="autoDetect">Automatique</option>
                </select>
            </div>
            <div class="grid grid-cols-[3fr_1fr] gap-2">
                <div>
                    <label class="label py-1"><span class="label-text text-xs">Host</span></label>
                    <input type="text" wire:model="proxyHost" placeholder="proxy.local" class="input input-bordered input-sm w-full" />
                </div>
                <div>
                    <label class="label py-1"><span class="label-text text-xs">Port</span></label>
                    <input type="text" wire:model="proxyPort" placeholder="3128" class="input input-bordered input-sm w-full" />
                </div>
            </div>
            <p class="text-xs text-base-content/60">
                Les champs mail servers (SMTP/IMAP) ne sont pas personnalisables dans ce MVP.
                Contacter le support pour étendre le scope.
            </p>
        </div>
    </section>

    <div class="modal-action">
        <button type="button" class="btn btn-primary btn-sm" wire:click="save">
            <i class="fa-solid fa-save"></i>
            Enregistrer
        </button>
    </div>
</div>

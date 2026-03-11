<?php

use Livewire\Component;
use Livewire\Attributes\On;
use App\Repositories\RightRepository;
use App\Repositories\UserRepository;
use App\Services\RightsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Devrabiul\ToastMagic\Facades\ToastMagic;

new class extends Component {
    public bool $isOpen = false;
    public bool $isLoading = false;

    // Utilisateur ciblé
    public string $targetLogin = '';
    public string $targetDn = '';

    // Droits : groupCn => bool (activé/désactivé)
    public array $rightsState = [];

    // État initial pour détecter les changements
    public array $initialRightsState = [];

    // Définitions des droits pour l'affichage
    public array $rightsDefinitions = [];

    // Services
    private RightRepository $rightRepository;
    private UserRepository $userRepository;
    private RightsService $rightsService;

    public function boot(RightRepository $rightRepository, UserRepository $userRepository, RightsService $rightsService)
    {
        $this->rightRepository = $rightRepository;
        $this->userRepository = $userRepository;
        $this->rightsService = $rightsService;
    }

    #[On('open-rights-drawer')]
    public function open(string $login): void
    {
        if (!Gate::allows('manage-rights')) {
            ToastMagic::error('Vous n\'avez pas les droits pour gérer les permissions');
            return;
        }

        $this->targetLogin = $login;
        $this->isOpen = true;
        $this->isLoading = true;

        $this->loadRightsData();

        $this->isLoading = false;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    private function loadRightsData(): void
    {
        $ldapUser = $this->userRepository->findLdapModelByLogin($this->targetLogin);
        if (!$ldapUser) {
            ToastMagic::error('Utilisateur introuvable dans LDAP');
            $this->isOpen = false;
            return;
        }
        $this->targetDn = $ldapUser->getDn();

        $userRightGroups = $this->userRepository->findByLogin($this->targetLogin)?->rights ?? [];

        // Seuls les groupes référencés dans getAllRightsValues() sont de vrais groupes de droits
        $knownRightsGroups = $this->rightRepository->getAllRightsValues();
        $definitions = RightsService::getRightsDefinitions();

        $this->rightsDefinitions = [];
        $this->rightsState = [];

        foreach ($knownRightsGroups as $cn => $bitmask) {
            $rightGroup = $this->rightRepository->findByName($cn);
            $description = $rightGroup?->getFirstAttribute('description') ?? '';
            $isActive = in_array($cn, $userRightGroups);

            // Trouver les labels correspondants au bitmask
            $labels = [];
            foreach ($definitions as $mask => $info) {
                if ($bitmask > 0 && ($bitmask & $mask) > 0) {
                    $labels[] = $info['label'];
                }
            }

            $this->rightsDefinitions[$cn] = [
                'cn' => $cn,
                'description' => $description,
                'bitmask' => $bitmask,
                'bitmaskHex' => $bitmask > 0 ? '0x' . strtoupper(dechex($bitmask)) : '0x00',
                'labels' => $labels,
            ];

            $this->rightsState[$cn] = $isActive;
        }

        $this->initialRightsState = $this->rightsState;
    }

    public function toggleRight(string $groupCn): void
    {
        if (isset($this->rightsState[$groupCn])) {
            $this->rightsState[$groupCn] = !$this->rightsState[$groupCn];
        }
    }

    public function saveChanges(): void
    {
        if (!Gate::allows('manage-rights')) {
            ToastMagic::error('Droits insuffisants');
            return;
        }

        $this->isLoading = true;
        $added = 0;
        $removed = 0;
        $errors = 0;

        foreach ($this->rightsState as $groupCn => $isActive) {
            $wasActive = $this->initialRightsState[$groupCn] ?? false;

            if ($isActive === $wasActive) {
                continue;
            }

            try {
                $rightGroup = $this->rightRepository->findByName($groupCn);
                if (!$rightGroup) {
                    $errors++;
                    continue;
                }

                $members = $rightGroup->getAttribute('member') ?? [];

                if ($isActive && !in_array($this->targetDn, $members)) {
                    $members[] = $this->targetDn;
                    $rightGroup->member = $members;
                    $rightGroup->save();
                    $added++;
                    Log::info('RightsDrawer: droit ajouté', ['group' => $groupCn, 'user' => $this->targetLogin]);
                } elseif (!$isActive && in_array($this->targetDn, $members)) {
                    $members = array_values(array_filter($members, fn($m) => $m !== $this->targetDn));
                    $rightGroup->member = $members;
                    $rightGroup->save();
                    $removed++;
                    Log::info('RightsDrawer: droit retiré', ['group' => $groupCn, 'user' => $this->targetLogin]);
                }
            } catch (\Exception $e) {
                Log::error('RightsDrawer: erreur modification droit', [
                    'group' => $groupCn,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        $this->rightsService->invalidateCache();
        $this->userRepository->invalidateCache($this->targetLogin);

        $this->isLoading = false;

        if ($errors > 0) {
            ToastMagic::warning("{$added} ajoutée(s), {$removed} retirée(s), {$errors} erreur(s)");
        } else {
            $total = $added + $removed;
            ToastMagic::success("{$total} permission(s) modifiée(s) pour {$this->targetLogin}");
        }

        $this->isOpen = false;
        $this->js('window.location.reload()');
    }
};
?>

<div>
    <dialog class="modal" x-data="{ open: @entangle('isOpen') }" :class="{ 'modal-open': open }" x-cloak>
        <div class="modal-box w-11/12 max-w-2xl">
            <!-- Header -->
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-bold flex items-center gap-2">
                        <svg class="w-5 h-5 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z">
                            </path>
                        </svg>
                        Gestion des permissions
                    </h3>
                    <p class="text-sm text-base-content/60 mt-1">
                        Utilisateur : <span class="font-mono font-semibold text-primary">{{ $targetLogin }}</span>
                    </p>
                </div>
                <button type="button" wire:click="close" class="btn btn-sm btn-circle btn-ghost">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            @if ($isLoading && empty($rightsDefinitions))
                <div class="flex items-center justify-center py-12">
                    <span class="loading loading-spinner loading-lg text-warning"></span>
                </div>
            @else
                <!-- Liste des droits avec toggles -->
                <div class="space-y-1 max-h-[60vh] overflow-y-auto pr-1">
                    @foreach ($rightsDefinitions as $cn => $def)
                        <div
                            class="flex items-center justify-between p-3 rounded-xl hover:bg-base-200/50 transition-colors border border-transparent hover:border-base-300">
                            <div class="flex-1 min-w-0 mr-4">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-sm">{{ $cn }}</span>
                                    <code
                                        class="text-xs bg-base-200 px-1.5 py-0.5 rounded font-mono text-base-content/50">{{ $def['bitmaskHex'] }}</code>
                                </div>
                                @if (!empty($def['labels']))
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach ($def['labels'] as $label)
                                            <span
                                                class="badge badge-xs badge-warning badge-outline">{{ $label }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                @if (!empty($def['description']))
                                    <p class="text-xs text-base-content/50 mt-1 truncate">{{ $def['description'] }}</p>
                                @endif
                            </div>
                            <input type="checkbox" wire:click="toggleRight('{{ $cn }}')"
                                @checked($rightsState[$cn] ?? false) class="toggle toggle-warning" />
                        </div>
                    @endforeach
                </div>

                @if (empty($rightsDefinitions))
                    <div class="text-center py-8 text-base-content/50">
                        <i class="fa-solid fa-shield-halved text-3xl mb-2"></i>
                        <p>Aucun groupe de droits disponible</p>
                    </div>
                @endif
            @endif

            <!-- Footer -->
            <div class="modal-action">
                <button type="button" class="btn btn-ghost" wire:click="close">
                    Annuler
                </button>
                <button type="button" wire:click="saveChanges" wire:loading.attr="disabled" class="btn btn-warning"
                    @disabled($rightsState === $initialRightsState)>
                    <span wire:loading wire:target="saveChanges" class="loading loading-spinner loading-sm"></span>
                    <i wire:loading.remove wire:target="saveChanges" class="fa-solid fa-check"></i>
                    Enregistrer
                </button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button wire:click="close">close</button>
        </form>
    </dialog>
</div>

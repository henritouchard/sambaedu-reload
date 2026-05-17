<?php

use App\Components\Traits\WithToasts;
use App\Gpo\Dto\GpoLink;
use App\Gpo\Services\GpoService;
use App\Repositories\OrganizationalUnitRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — Gestion des liaisons GPO ↔ OU (Story 16.5 / Volet 2).
 * Story 16.9 — déplacement sous `/admin/settings/gpo/{guid}/links`.
 *
 * Pattern strict iso-Story 16.2 (page détail) :
 *  - filesystem-based router (`/admin/settings/gpo/{guid}/links`)
 *  - `boot(GpoService, OrganizationalUnitRepository)` DI Livewire
 *  - `mount(string $guid)` avec normalizeGuid + abort_unless permission
 *  - regex GUID tolérante (16.2 fix #9)
 *
 * Sécurité (AC5.1 / AC5.2) :
 *  - middleware `can:server.admin` côté route
 *  - re-check permission dans `mount()` (defense in depth)
 *  - GUID malformé → 404 SANS appel `samba-tool`
 *  - validation regex GUID + DN AVANT toute action write (déléguée à
 *    `GpoService::assertValidGuid()` / `assertValidContainerDn()`)
 *
 * UX :
 *  - 4 actions par lien : toggle disabled / toggle enforced / réordonner / délier
 *  - 1 action par OU : toggle inheritance
 *  - Modales `<x-molecules.modal>` pour TOUTE confirmation write AD (D6)
 *  - Toasts via `WithToasts` (CLAUDE.md)
 *  - Loading state Livewire (wire:loading) sur les actions lentes (reorderLinks
 *    peut prendre 2-3s).
 */
new #[Title('Liaisons GPO - SE4FS')] class extends Component {
    use WithToasts;

    // --- Propriétés persistées ---
    // Story 16.5 review #7 : `#[Locked]` interdit toute mutation client-side
    // de propriétés sensibles (audit trail / bypass de modale). Les méthodes
    // serveur peuvent toujours muter ces propriétés (Locked = client-side only).
    #[Locked]
    public string $guid = '';
    public ?array $gpo = null;
    /** @var list<string> */
    public array $containers = [];
    /** @var array<string, list<array>> */
    public array $linksByContainer = [];
    /** @var array<string, bool> */
    public array $inheritanceByContainer = [];
    /** @var array<string, string> DN → display name */
    public array $availableOus = [];
    /** @var array<string, int> DN → count workstations */
    public array $workstationCountByOu = [];

    // --- État modale ---
    public bool $isModalOpen = false;
    /** Type d'action en attente de confirmation (add, remove, toggleDisabled, toggleEnforced, moveUp, moveDown, toggleInheritance). */
    #[Locked]
    public ?string $pendingActionType = null;
    /** Paramètres de l'action en attente (DN container, GUID GPO, etc.). */
    #[Locked]
    public array $pendingActionParams = [];
    #[Locked]
    public string $pendingActionTitle = '';
    #[Locked]
    public string $pendingActionMessage = '';

    // --- Sélecteur OU dans modale d'ajout ---
    public string $selectedOuForAdd = '';
    public string $ouSearchQuery = '';

    // --- Erreurs partielles ---
    public array $loadErrors = [];

    private GpoService $gpoService;
    private OrganizationalUnitRepository $ouRepo;

    public function boot(GpoService $service, OrganizationalUnitRepository $ouRepo): void
    {
        $this->gpoService = $service;
        $this->ouRepo = $ouRepo;
    }

    public function mount(string $guid): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $normalized = $this->normalizeGuid($guid);
        if ($normalized === null) {
            abort(404, 'GUID de GPO invalide.');
        }

        $this->guid = $normalized;
        $this->loadAll();
    }

    /**
     * Normalise un GUID au format Microsoft canonique avec accolades.
     * Iso Story 16.2.
     */
    private function normalizeGuid(string $guid): ?string
    {
        $stripped = trim($guid, '{}');
        if (preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $stripped) !== 1) {
            return null;
        }
        return '{' . $stripped . '}';
    }

    /**
     * Charge l'état complet de la page depuis AD + repository OU + Eloquent
     * workstations. Idempotent — appelé au mount + après chaque action write.
     */
    public function loadAll(): void
    {
        // 1. GPO principale
        try {
            $gpoObj = $this->gpoService->get($this->guid);
        } catch (\Throwable $e) {
            $this->loadErrors['gpo'] = $e->getMessage();
            $this->toast('error', 'Erreur', 'Impossible de charger la GPO : ' . $e->getMessage());
            return;
        }
        if ($gpoObj === null) {
            abort(404, 'GPO inexistante.');
        }
        $this->gpo = $gpoObj->toArray();

        // 2. Containers liés
        try {
            $this->containers = $this->gpoService->listContainers($this->guid);
        } catch (\Throwable $e) {
            $this->loadErrors['containers'] = $e->getMessage();
            $this->containers = [];
        }

        // 3. Liens + héritage par container
        $this->linksByContainer = [];
        $this->inheritanceByContainer = [];
        foreach ($this->containers as $dn) {
            try {
                $this->linksByContainer[$dn] = array_map(
                    fn (GpoLink $l) => $l->toArray(),
                    $this->gpoService->getLinks($dn),
                );
            } catch (\Throwable $e) {
                $this->loadErrors["links_{$dn}"] = $e->getMessage();
                $this->linksByContainer[$dn] = [];
            }
            try {
                $this->inheritanceByContainer[$dn] = $this->gpoService->getInheritance($dn);
            } catch (\Throwable $e) {
                $this->loadErrors["inheritance_{$dn}"] = $e->getMessage();
                $this->inheritanceByContainer[$dn] = true;
            }
        }

        // 4. Liste OUs disponibles
        try {
            $this->availableOus = $this->ouRepo->listAll();
        } catch (\Throwable $e) {
            $this->loadErrors['ous'] = $e->getMessage();
            $this->availableOus = [];
        }

        // 5. Comptage postes par OU (DO2 — Workstation::ad_dn suffix match)
        $this->workstationCountByOu = $this->countWorkstationsByOu(array_keys($this->availableOus + array_flip($this->containers)));
    }

    /**
     * Compte les postes Eloquent par OU via suffix-match sur `ad_dn`.
     *
     * DO2 (T0.4) : la table `workstations` a une colonne `ad_dn` (DN complet
     * du poste) mais pas de colonne `ou_dn` dédiée. On compte donc les postes
     * dont le `ad_dn` se TERMINE par `,<ouDn>` (l'OU est suffixe du DN). C'est
     * du SQL pur (rapide) et reste cohérent tant que `ad_dn` est synchronisé
     * (workflow d'inscription poste).
     *
     * Cf. `docs/tech-debt-gpo.md` TD-16.5-2 pour la limitation : un poste dont
     * `ad_dn` n'est pas peuplé ne sera pas compté (signal opérationnel mais
     * pas iso-AD strict).
     *
     * @param  list<string>  $ouDns
     * @return array<string,int>
     */
    private function countWorkstationsByOu(array $ouDns): array
    {
        $out = [];
        foreach ($ouDns as $dn) {
            if ($dn === '' || $dn === null) {
                continue;
            }
            // Story 16.5 review #4 : échapper les wildcards SQL `%` et `_` dans
            // le DN avant concaténation dans le pattern ILIKE/LIKE (sinon faux
            // matches sur DN exotiques). Le backslash doit être échappé EN
            // PREMIER pour éviter le double-échappement. On force `ESCAPE '\'`
            // explicite pour couvrir SQLite (env tests) qui ne reconnaît pas
            // l'échappement backslash par défaut (PG le fait nativement).
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $dn);
            $pattern = '%,' . $escaped;
            try {
                $out[$dn] = DB::table('workstations')
                    ->whereRaw('ad_dn ILIKE ? ESCAPE ?', [$pattern, '\\'])
                    ->whereNull('archived_at')
                    ->count();
            } catch (\Throwable) {
                // SQLite tests : ILIKE n'existe pas, on retombe sur LIKE
                // (avec la même clause ESCAPE pour traiter `\%` / `\_`).
                try {
                    $out[$dn] = DB::table('workstations')
                        ->whereRaw('ad_dn LIKE ? ESCAPE ?', [$pattern, '\\'])
                        ->whereNull('archived_at')
                        ->count();
                } catch (\Throwable) {
                    $out[$dn] = 0;
                }
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Ouverture des modales de confirmation (D6 — toute action write confirmée)
    // -------------------------------------------------------------------------

    public function openAddLinkModal(): void
    {
        $this->selectedOuForAdd = '';
        $this->ouSearchQuery = '';
        $this->pendingActionType = 'add';
        $this->pendingActionParams = [];
        $this->pendingActionTitle = 'Lier la GPO à une OU';
        $this->pendingActionMessage = 'Sélectionnez une OU pour lier cette GPO. La propagation aux postes se fera au prochain `gpupdate /force`.';
        $this->isModalOpen = true;
    }

    public function openRemoveLinkModal(string $ouDn): void
    {
        $this->pendingActionType = 'remove';
        $this->pendingActionParams = ['ouDn' => $ouDn];
        $name = $this->gpo['displayName'] ?? '';
        $this->pendingActionTitle = 'Supprimer la liaison';
        $this->pendingActionMessage = sprintf(
            'Vous allez délier la GPO « %s » de l\'OU « %s ». Les postes liés à cette OU n\'appliqueront plus cette GPO au prochain gpupdate. Cette action est immédiate.',
            $name,
            $ouDn,
        );
        $this->isModalOpen = true;
    }

    public function openToggleDisabledModal(string $ouDn, bool $newDisabled): void
    {
        $this->pendingActionType = 'toggleDisabled';
        $this->pendingActionParams = ['ouDn' => $ouDn, 'newDisabled' => $newDisabled];
        $this->pendingActionTitle = $newDisabled ? 'Désactiver la liaison' : 'Activer la liaison';
        $this->pendingActionMessage = $newDisabled
            ? 'Désactiver cette liaison la rendra inactive sans la supprimer. Confirmer ?'
            : 'Réactiver cette liaison la rendra à nouveau effective au prochain gpupdate. Confirmer ?';
        $this->isModalOpen = true;
    }

    public function openToggleEnforcedModal(string $ouDn, bool $newEnforced): void
    {
        $this->pendingActionType = 'toggleEnforced';
        $this->pendingActionParams = ['ouDn' => $ouDn, 'newEnforced' => $newEnforced];
        $this->pendingActionTitle = $newEnforced ? 'Forcer la liaison' : 'Ne plus forcer la liaison';
        $this->pendingActionMessage = $newEnforced
            ? 'Forcer une liaison la rend prioritaire sur les enfants et empêche son override par `block inheritance`. Confirmer ?'
            : 'Lever l\'option « forcé » sur cette liaison la rendra surclassable par les OUs enfants. Confirmer ?';
        $this->isModalOpen = true;
    }

    public function openMoveLinkModal(string $ouDn, int $oldPosition, int $newPosition): void
    {
        $this->pendingActionType = 'move';
        $this->pendingActionParams = [
            'ouDn' => $ouDn,
            'oldPosition' => $oldPosition,
            'newPosition' => $newPosition,
        ];
        $this->pendingActionTitle = 'Réordonner la liaison';
        $this->pendingActionMessage = sprintf(
            'Vous allez modifier l\'ordre de précédence des liaisons de l\'OU. La GPO passera en position %d (avant : %d). Cette opération réécrit toutes les liaisons de l\'OU (peut prendre 2-3s).',
            $newPosition + 1,
            $oldPosition + 1,
        );
        $this->isModalOpen = true;
    }

    public function openToggleInheritanceModal(string $ouDn, bool $newEnabled): void
    {
        $this->pendingActionType = 'toggleInheritance';
        $this->pendingActionParams = ['ouDn' => $ouDn, 'newEnabled' => $newEnabled];
        $this->pendingActionTitle = $newEnabled ? 'Réactiver l\'héritage' : 'Bloquer l\'héritage';
        $this->pendingActionMessage = $newEnabled
            ? 'L\'OU appliquera à nouveau les GPOs héritées des conteneurs parents.'
            : 'L\'OU n\'appliquera plus les GPOs héritées des conteneurs parents (sauf liaisons forcées).';
        $this->isModalOpen = true;
    }

    public function closeModal(): void
    {
        $this->isModalOpen = false;
        $this->pendingActionType = null;
        $this->pendingActionParams = [];
        $this->pendingActionTitle = '';
        $this->pendingActionMessage = '';
    }

    public function close(): void
    {
        // Alias attendu par <x-molecules.modal> (closeMethod=close par défaut).
        $this->closeModal();
    }

    // -------------------------------------------------------------------------
    // Dispatcher de l'action confirmée
    // -------------------------------------------------------------------------

    public function confirmPendingAction(): void
    {
        if ($this->pendingActionType === null) {
            return;
        }
        $type = $this->pendingActionType;
        $params = $this->pendingActionParams;
        $this->closeModal();

        try {
            match ($type) {
                'add' => $this->doAddLink(),
                'remove' => $this->doRemoveLink($params['ouDn']),
                'toggleDisabled' => $this->doToggleDisabled($params['ouDn'], $params['newDisabled']),
                'toggleEnforced' => $this->doToggleEnforced($params['ouDn'], $params['newEnforced']),
                'move' => $this->doMove($params['ouDn'], $params['oldPosition'], $params['newPosition']),
                'toggleInheritance' => $this->doToggleInheritance($params['ouDn'], $params['newEnabled']),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->toast('error', 'Échec de l\'opération', $e->getMessage());
            // Story 16.5 review #2 : refresh même en cas d'échec — un toggle
            // peut avoir réussi removeLink puis échoué setLink (avec rollback
            // best effort) ; on veut que l'UI reflète l'état AD réel.
            $this->loadAll();
            return;
        }

        // Refresh état après tout write réussi.
        $this->loadAll();
    }

    private function doAddLink(): void
    {
        $dn = trim($this->selectedOuForAdd);
        if ($dn === '') {
            throw new \RuntimeException('Aucune OU sélectionnée.');
        }
        // Story 16.5 review #S2 : garde serveur "OU déjà liée". UI bloque déjà
        // mais sans cette défense un client manipulant `selectedOuForAdd` via
        // DevTools pourrait provoquer un toast trompeur (setLink idempotent).
        if (in_array($dn, $this->containers, true)) {
            throw new \RuntimeException('La GPO est déjà liée à cette OU.');
        }
        $this->gpoService->setLink($dn, $this->guid);
        $this->toast('success', 'Liaison créée', 'GPO liée à l\'OU avec succès.');
    }

    private function doRemoveLink(string $ouDn): void
    {
        $this->gpoService->removeLink($ouDn, $this->guid);
        $this->toast('success', 'Liaison supprimée', 'La GPO n\'est plus liée à cette OU.');
    }

    private function doToggleDisabled(string $ouDn, bool $newDisabled): void
    {
        $current = $this->findLinkInContainer($ouDn);
        $initialEnforced = (bool) ($current['enforced'] ?? false);
        $initialDisabled = (bool) ($current['disabled'] ?? false);

        // Toggle disabled : dellink + setlink avec nouveau flag (T0.9 — safe path).
        // Story 16.5 review #2 : si setLink échoue après removeLink réussi, on
        // tente un rollback (re-setLink avec flags initiaux) pour ne pas laisser
        // le lien disparu en AD. Pattern iso `GpoService::reorderLinks`.
        $this->gpoService->removeLink($ouDn, $this->guid);
        try {
            $this->gpoService->setLink($ouDn, $this->guid, enforce: $initialEnforced, disable: $newDisabled);
        } catch (\Throwable $applyError) {
            try {
                $this->gpoService->setLink($ouDn, $this->guid, enforce: $initialEnforced, disable: $initialDisabled);
            } catch (\Throwable $rollbackError) {
                Log::channel('gpo')->error('gpo.link.toggle.disabled: rollback FAILED — état AD potentiellement incohérent', [
                    'target_dn' => $ouDn,
                    'gpo_name' => $this->guid,
                    'apply_error' => $applyError->getMessage(),
                    'rollback_error' => $rollbackError->getMessage(),
                ]);
                throw new \RuntimeException(sprintf(
                    'Toggle disabled échoué (%s) ET rollback échoué (%s) — état AD potentiellement incohérent sur %s.',
                    $applyError->getMessage(),
                    $rollbackError->getMessage(),
                    $ouDn,
                ), 0, $applyError);
            }
            // Rollback réussi → on relève l'erreur originale pour que le toast
            // erreur s'affiche normalement dans `confirmPendingAction`.
            throw $applyError;
        }

        // Logging dédié action_type — non émis par setLink/removeLink (qui logguent
        // gpo.link.add / gpo.link.remove). On ajoute un log toggle explicite.
        \App\Gpo\Support\GpoLogger::action('gpo.link.toggle.disabled', context: [
            'target_dn' => $ouDn,
            'gpo_name' => $this->guid,
            'from' => $initialDisabled,
            'to' => $newDisabled,
        ])->success();
        $this->toast('success', 'Liaison mise à jour', $newDisabled ? 'Liaison désactivée.' : 'Liaison réactivée.');
    }

    private function doToggleEnforced(string $ouDn, bool $newEnforced): void
    {
        $current = $this->findLinkInContainer($ouDn);
        $initialDisabled = (bool) ($current['disabled'] ?? false);
        $initialEnforced = (bool) ($current['enforced'] ?? false);

        // Story 16.5 review #2 : rollback en cas d'échec setLink après removeLink réussi.
        $this->gpoService->removeLink($ouDn, $this->guid);
        try {
            $this->gpoService->setLink($ouDn, $this->guid, enforce: $newEnforced, disable: $initialDisabled);
        } catch (\Throwable $applyError) {
            try {
                $this->gpoService->setLink($ouDn, $this->guid, enforce: $initialEnforced, disable: $initialDisabled);
            } catch (\Throwable $rollbackError) {
                Log::channel('gpo')->error('gpo.link.toggle.enforced: rollback FAILED — état AD potentiellement incohérent', [
                    'target_dn' => $ouDn,
                    'gpo_name' => $this->guid,
                    'apply_error' => $applyError->getMessage(),
                    'rollback_error' => $rollbackError->getMessage(),
                ]);
                throw new \RuntimeException(sprintf(
                    'Toggle enforced échoué (%s) ET rollback échoué (%s) — état AD potentiellement incohérent sur %s.',
                    $applyError->getMessage(),
                    $rollbackError->getMessage(),
                    $ouDn,
                ), 0, $applyError);
            }
            throw $applyError;
        }

        \App\Gpo\Support\GpoLogger::action('gpo.link.toggle.enforced', context: [
            'target_dn' => $ouDn,
            'gpo_name' => $this->guid,
            'from' => $initialEnforced,
            'to' => $newEnforced,
        ])->success();
        $this->toast('success', 'Liaison mise à jour', $newEnforced ? 'Liaison désormais forcée.' : 'Liaison non forcée.');
    }

    private function doMove(string $ouDn, int $oldPosition, int $newPosition): void
    {
        // Récupère l'ordre courant des GUIDs sur l'OU et déplace l'index.
        $currentLinks = $this->linksByContainer[$ouDn] ?? [];
        $order = array_map(fn ($l) => $l['gpoName'], $currentLinks);
        if (! isset($order[$oldPosition])) {
            throw new \RuntimeException('Position initiale invalide.');
        }
        $guid = $order[$oldPosition];
        // Retire puis insère à la nouvelle position.
        array_splice($order, $oldPosition, 1);
        $newPosition = max(0, min(count($order), $newPosition));
        array_splice($order, $newPosition, 0, [$guid]);

        $this->gpoService->reorderLinks($ouDn, $order);
        $this->toast('success', 'Ordre mis à jour', 'Les liaisons ont été réordonnées.');
    }

    private function doToggleInheritance(string $ouDn, bool $newEnabled): void
    {
        $this->gpoService->setInheritance($ouDn, $newEnabled);
        $this->toast('success', 'Héritage mis à jour', $newEnabled ? 'Héritage activé.' : 'Héritage bloqué.');
    }

    /**
     * Helper : trouve le lien correspondant à $this->guid dans un container donné.
     *
     * @return array<string,mixed>|null
     */
    private function findLinkInContainer(string $ouDn): ?array
    {
        foreach ($this->linksByContainer[$ouDn] ?? [] as $link) {
            if (($link['gpoName'] ?? '') === $this->guid) {
                return $link;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Computed properties
    // -------------------------------------------------------------------------

    /**
     * OUs candidates pour ajout (toutes les OUs hors celles déjà liées).
     *
     * @return array<string,string>
     */
    public function getCandidateOusProperty(): array
    {
        $linked = array_flip($this->containers);
        $candidates = array_diff_key($this->availableOus, $linked);
        if ($this->ouSearchQuery !== '') {
            $needle = mb_strtolower($this->ouSearchQuery);
            $candidates = array_filter($candidates, function ($name, $dn) use ($needle) {
                return str_contains(mb_strtolower((string) $name), $needle)
                    || str_contains(mb_strtolower((string) $dn), $needle);
            }, ARRAY_FILTER_USE_BOTH);
        }
        return $candidates;
    }

    /**
     * Total agrégé du nombre de postes potentiellement impactés (somme distincte
     * par OU pour rester explicite — un même poste peut être compté plusieurs
     * fois s'il appartient à des OUs imbriquées).
     */
    public function getTotalImpactProperty(): int
    {
        $sum = 0;
        foreach ($this->containers as $dn) {
            $sum += $this->workstationCountByOu[$dn] ?? 0;
        }
        return $sum;
    }
};
?>

@php
    $displayName = $gpo['displayName'] ?? 'GPO inconnue';
@endphp

<x-organisms.page :title="'Liaisons — ' . $displayName" :scrollable="true"
    description="Gestion des liaisons GPO ↔ OU AD et propagation aux postes.">

    <x-slot:actions>
        <div class="flex flex-wrap gap-2 items-center">
            <a href="{{ route('admin.gpo.show', ['guid' => trim((string) $this->guid, '{}')]) }}" class="btn btn-outline btn-sm">
                <i class="fa-solid fa-arrow-left"></i>
                Retour à la GPO
            </a>
            <a href="{{ route('admin.gpo.index') }}" class="btn btn-ghost btn-sm">
                <i class="fa-solid fa-list"></i>
                Liste des GPOs
            </a>
        </div>
    </x-slot:actions>

    <div class="space-y-6">

        {{-- Erreurs partielles --}}
        @if (count($loadErrors) > 0)
            <div class="alert alert-warning shadow-sm" data-testid="load-errors">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <p class="font-medium">Chargement partiel</p>
                    <ul class="text-sm mt-1 list-disc list-inside opacity-80">
                        @foreach ($loadErrors as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        {{-- Header GPO --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body py-4">
                <h2 class="card-title flex items-center gap-3">
                    <i class="fa-solid fa-link text-primary"></i>
                    {{ $displayName }}
                    <span class="badge badge-neutral badge-sm font-mono">{{ $this->guid }}</span>
                </h2>
                <p class="text-sm text-base-content/70">
                    Cette page contrôle <strong>uniquement</strong> les liaisons et la propagation.
                    Les modifications de contenu de la GPO se font dans la page détail ou via les sections natives.
                </p>
            </div>
        </div>

        {{-- Liens actuels --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="card-title text-lg flex items-center gap-2">
                        <i class="fa-solid fa-diagram-project text-secondary"></i>
                        Liens actuels
                        <span class="badge badge-neutral badge-sm">{{ count($containers) }}</span>
                    </h3>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="openAddLinkModal"
                        data-testid="open-add-link">
                        <i class="fa-solid fa-plus"></i>
                        Ajouter une liaison
                    </button>
                </div>

                @if (count($containers) === 0)
                    <div class="text-center py-8 text-base-content/60" data-testid="empty-links">
                        <i class="fa-solid fa-link-slash text-3xl mb-2"></i>
                        <p>Cette GPO n'est liée à aucune OU. Elle ne sera appliquée à aucun poste tant qu'elle n'est pas
                            liée.</p>
                    </div>
                @else
                    <div class="space-y-3 mt-3">
                        @foreach ($containers as $ouDn)
                            @php
                                $links = $linksByContainer[$ouDn] ?? [];
                                $inherit = $inheritanceByContainer[$ouDn] ?? true;
                                $wsCount = $workstationCountByOu[$ouDn] ?? 0;
                                // Trouve la position de notre GPO dans le gpLink de cette OU.
                                $ourPos = null;
                                $ourLink = null;
                                foreach ($links as $idx => $l) {
                                    if (($l['gpoName'] ?? '') === $this->guid) {
                                        $ourPos = $idx;
                                        $ourLink = $l;
                                        break;
                                    }
                                }
                            @endphp
                            <div class="border border-base-300 rounded-lg overflow-hidden"
                                data-testid="link-row-{{ $loop->index }}">
                                <div class="bg-base-200/50 px-4 py-2 flex items-center justify-between gap-3 flex-wrap">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-sm">{{ \App\LdapModels\OrganizationalUnitModel::extractOuNameFromDn($ouDn) ?: $ouDn }}</p>
                                        <p class="font-mono text-xs text-base-content/60 truncate" title="{{ $ouDn }}">{{ $ouDn }}</p>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <span class="badge badge-ghost badge-sm" title="Postes contenus dans l'OU">
                                            <i class="fa-solid fa-desktop mr-1"></i> {{ $wsCount }} poste(s)
                                        </span>
                                        @if ($inherit)
                                            <button type="button" class="badge badge-success badge-sm gap-1"
                                                wire:click="openToggleInheritanceModal({{ \Illuminate\Support\Js::from($ouDn) }}, false)"
                                                title="Cliquer pour bloquer l'héritage">
                                                <i class="fa-solid fa-arrow-down"></i> Héritage actif
                                            </button>
                                        @else
                                            <button type="button" class="badge badge-warning badge-sm gap-1"
                                                wire:click="openToggleInheritanceModal({{ \Illuminate\Support\Js::from($ouDn) }}, true)"
                                                title="Cliquer pour réactiver l'héritage">
                                                <i class="fa-solid fa-ban"></i> Héritage bloqué
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                @if ($ourLink !== null)
                                    <div class="px-4 py-3 flex items-center justify-between gap-2 flex-wrap">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="badge badge-info badge-sm">Position {{ $ourPos + 1 }} / {{ count($links) }}</span>
                                            @if ($ourLink['enforced'] ?? false)
                                                <span class="badge badge-error badge-sm">Forcé</span>
                                            @endif
                                            @if ($ourLink['disabled'] ?? false)
                                                <span class="badge badge-ghost badge-sm">Désactivé</span>
                                            @endif
                                            @if (! ($ourLink['enforced'] ?? false) && ! ($ourLink['disabled'] ?? false))
                                                <span class="badge badge-success badge-sm">Actif</span>
                                            @endif
                                        </div>
                                        <div class="flex flex-wrap gap-1">
                                            {{-- Toggle disabled --}}
                                            <button type="button" class="btn btn-xs btn-ghost"
                                                wire:click="openToggleDisabledModal({{ \Illuminate\Support\Js::from($ouDn) }}, {{ ($ourLink['disabled'] ?? false) ? 'false' : 'true' }})"
                                                data-testid="toggle-disabled">
                                                <i class="fa-solid fa-power-off"></i>
                                                {{ ($ourLink['disabled'] ?? false) ? 'Activer' : 'Désactiver' }}
                                            </button>
                                            {{-- Toggle enforced --}}
                                            <button type="button" class="btn btn-xs btn-ghost"
                                                wire:click="openToggleEnforcedModal({{ \Illuminate\Support\Js::from($ouDn) }}, {{ ($ourLink['enforced'] ?? false) ? 'false' : 'true' }})"
                                                data-testid="toggle-enforced">
                                                <i class="fa-solid fa-lock{{ ($ourLink['enforced'] ?? false) ? '-open' : '' }}"></i>
                                                {{ ($ourLink['enforced'] ?? false) ? 'Ne plus forcer' : 'Forcer' }}
                                            </button>
                                            {{-- Move up --}}
                                            @if ($ourPos > 0)
                                                <button type="button" class="btn btn-xs btn-ghost"
                                                    wire:click="openMoveLinkModal({{ \Illuminate\Support\Js::from($ouDn) }}, {{ $ourPos }}, {{ $ourPos - 1 }})"
                                                    data-testid="move-up">
                                                    <i class="fa-solid fa-arrow-up"></i>
                                                </button>
                                            @endif
                                            {{-- Move down --}}
                                            @if ($ourPos !== null && $ourPos < count($links) - 1)
                                                <button type="button" class="btn btn-xs btn-ghost"
                                                    wire:click="openMoveLinkModal({{ \Illuminate\Support\Js::from($ouDn) }}, {{ $ourPos }}, {{ $ourPos + 1 }})"
                                                    data-testid="move-down">
                                                    <i class="fa-solid fa-arrow-down"></i>
                                                </button>
                                            @endif
                                            {{-- Unlink --}}
                                            <button type="button" class="btn btn-xs btn-error"
                                                wire:click="openRemoveLinkModal({{ \Illuminate\Support\Js::from($ouDn) }})"
                                                data-testid="unlink">
                                                <i class="fa-solid fa-link-slash"></i>
                                                Délier
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($this->totalImpact > 0)
                    <p class="text-xs text-base-content/60 mt-3" data-testid="total-impact">
                        Impact agrégé : <strong>{{ $this->totalImpact }}</strong> poste(s) potentiellement affecté(s)
                        au prochain `gpupdate /force`.
                    </p>
                @endif
            </div>
        </div>

        {{-- Encart "Création GPO" — Volet 7 --}}
        <div class="alert alert-info shadow-sm" data-testid="create-gpo-notice">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="text-sm">
                    Vous souhaitez <strong>créer, dupliquer ou supprimer</strong> une GPO ? Cette fonctionnalité reste
                    disponible dans l'ancienne interface.
                </p>
                <p class="text-xs text-base-content/60 mt-1">
                    La création de GPOs sera portée nativement dans une story future (16-4 actuellement en pause).
                </p>
                <a href="{{ legacy_url('/gpo/gpo-maj.php') }}" target="_blank" rel="noopener noreferrer"
                    class="btn btn-sm btn-outline mt-2">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    Ouvrir dans l'ancienne UI
                </a>
            </div>
        </div>
    </div>

    {{-- Modale de confirmation (D6) --}}
    <x-molecules.modal wire:model="isModalOpen" size="max-w-2xl" height="h-auto"
        :title="$pendingActionTitle ?: 'Confirmer'" icon="fa-shield-halved text-primary">
        <x-molecules.modal.section dense>
            <p class="text-sm">{{ $pendingActionMessage }}</p>

            {{-- Sélecteur OU pour l'action "ajouter" (AC2.3) --}}
            @if ($pendingActionType === 'add')
                <div class="mt-3 space-y-2">
                    <label class="text-xs font-medium text-base-content/70 block">Filtrer</label>
                    <input type="text" wire:model.live.debounce.200ms="ouSearchQuery"
                        placeholder="Rechercher une OU par nom ou DN..."
                        class="input input-bordered input-sm w-full" data-testid="ou-search" />
                    <label class="text-xs font-medium text-base-content/70 block mt-2">OU cible</label>
                    <select wire:model="selectedOuForAdd" class="select select-bordered select-sm w-full"
                        data-testid="ou-select">
                        <option value="">— Choisir une OU —</option>
                        @foreach ($this->candidateOus as $dn => $name)
                            <option value="{{ $dn }}">{{ $name }} — {{ $dn }}</option>
                        @endforeach
                    </select>
                    @if (count($this->candidateOus) === 0)
                        <p class="text-xs text-warning">Toutes les OUs accessibles sont déjà liées (ou aucune OU
                            chargée).</p>
                    @endif
                </div>
            @endif
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="closeModal" data-testid="modal-cancel">
                Annuler
            </button>
            <button type="button"
                class="btn btn-sm {{ $pendingActionType === 'remove' ? 'btn-error' : 'btn-primary' }}"
                wire:click="confirmPendingAction"
                wire:loading.attr="disabled"
                @if ($pendingActionType === 'add' && $selectedOuForAdd === '') disabled @endif
                data-testid="modal-confirm">
                <span wire:loading.remove wire:target="confirmPendingAction">
                    <i class="fa-solid fa-check"></i>
                    Confirmer
                </span>
                <span wire:loading wire:target="confirmPendingAction">
                    <i class="fa-solid fa-circle-notch fa-spin"></i>
                    Application en cours…
                </span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</x-organisms.page>

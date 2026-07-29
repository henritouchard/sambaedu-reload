{{--
    Story 56.3 (AC1, AC3, AC4) — LA modale de confirmation des opérations
    `app`, à TROIS usages : intégrer, mettre à jour, désinstaller.

    Pourquoi une seule modale et un seul fichier :

      * ce qu'il faut montrer AVANT d'installer des composants système est le
        même dans les trois cas — d'où ça vient, et ce que ça demande. Trois
        modales auraient divergé au premier ajustement de texte ;
      * la bibliothèque et la fiche l'incluent toutes les deux. Le texte
        d'avertissement « source non officielle » de la Story 56.1 est déjà
        écrit deux fois dans ces pages ; le recopier une troisième et une
        quatrième fois garantissait qu'une seule serait corrigée le jour où il
        change (leçon review 56.1 #3 : une règle, un énoncé).

    ⚠️ CETTE MODALE N'ACCORDE RIEN. Les scopes y sont AFFICHÉS parce que
    l'admin doit savoir ce que l'extension déclare vouloir ; le consentement
    révocable et les jetons de service sont la Story 56.4.

    Contrat attendu du composant hôte (identique sur les deux pages) :
      - `bool   $isAppOperationOpen`
      - `string $appOperation`   ('install' | 'update' | 'remove')
      - `array  $appTarget`      (fiche résolue PAR LE SERVICE — jamais le client)
      - méthodes `confirmAppOperation()` / `closeAppOperation()`
--}}
@php
    $target = $appTarget ?? [];
    $operation = $appOperation ?? '';
    $isOfficial = (bool) ($target['source_is_official'] ?? false);
    $host = (string) ($target['source_host'] ?? '');
    $name = (string) ($target['name'] ?? '');
    $scopes = (array) ($target['scopes'] ?? []);

    $titles = [
        'install' => 'Intégrer l\'extension',
        'update' => 'Mettre à jour l\'extension',
        'remove' => 'Désinstaller l\'extension',
    ];
    $icons = [
        'install' => 'fa-plug text-primary',
        'update' => 'fa-arrow-up text-info',
        'remove' => 'fa-trash-can text-error',
    ];
    $confirmLabels = [
        'install' => 'Intégrer',
        'update' => 'Mettre à jour',
        'remove' => 'Désinstaller',
    ];
    $confirmClasses = [
        'install' => 'btn-primary',
        'update' => 'btn-info',
        'remove' => 'btn-error',
    ];
@endphp

<x-molecules.modal wire:model="isAppOperationOpen" size="max-w-xl" height="h-auto"
    close-method="closeAppOperation" :title="$titles[$operation] ?? 'Opération sur l\'extension'"
    :icon="$icons[$operation] ?? 'fa-puzzle-piece text-primary'">

    <x-molecules.modal.section>
        <p class="text-sm">
            <strong>{{ $name }}</strong>
            @if ($operation === 'install')
                est une extension <strong>applicative</strong> : SE5 va télécharger son paquet, vérifier son
                empreinte, l'installer, démarrer son service et l'exposer sous <span class="font-mono">/ext/{{ $target['key'] ?? '' }}</span>.
            @elseif ($operation === 'update')
                passe de la version <span class="font-mono" data-testid="update-from">{{ $target['installed_version'] ?? '—' }}</span>
                à la version <span class="font-mono" data-testid="update-to">{{ $target['version'] ?? '—' }}</span>.
                Seuls le paquet et le service sont remplacés : l'adresse, le port et l'accès SSO ne changent pas.
            @else
                sera retirée de cette instance.
            @endif
        </p>

        @if ($operation === 'remove')
            <p class="text-sm text-base-content/60 mt-2" data-testid="app-remove-purge-text">
                Les composants système installés seront retirés : le paquet, son service, l'exposition
                <span class="font-mono">/ext/{{ $target['key'] ?? '' }}</span> et le client SSO de l'extension.
                <strong>Les données propres à l'extension seront purgées</strong> — cette opération n'est pas
                réversible autrement qu'en réinstallant l'extension à neuf.
            </p>
        @endif
    </x-molecules.modal.section>

    {{-- ── Provenance : d'où vient ce qu'on s'apprête à installer ───────── --}}
    <x-molecules.modal.section title="Provenance">
        <div class="flex items-center gap-2 flex-wrap text-sm">
            <i class="fa-solid fa-box-archive opacity-50"></i>
            <span>{{ $target['source_name'] ?? '' }}</span>
            @if ($host !== '')
                <span class="font-mono text-xs text-base-content/60" data-testid="app-operation-host">{{ $host }}</span>
            @endif
            @if ($isOfficial)
                <span class="badge badge-sm badge-success gap-1" data-testid="app-operation-official">
                    <i class="fa-solid fa-certificate text-[10px]"></i> Officielle
                </span>
            @else
                <span class="badge badge-sm badge-warning gap-1" data-testid="app-operation-third-party">
                    <i class="fa-solid fa-triangle-exclamation text-[10px]"></i> Tierce
                </span>
            @endif
        </div>

        {{-- Avertissement 56.1, texte REPRIS TEL QUEL (jamais une variante). --}}
        @unless ($isOfficial)
            <div class="alert alert-warning mt-3" data-testid="app-operation-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <p class="text-sm">
                        <strong>Source non officielle : {{ $host !== '' ? $host : 'dépôt inconnu' }}</strong>
                        — vous installez sous votre responsabilité.
                    </p>
                    <p class="text-sm opacity-80 mt-2">
                        <strong>{{ $name }}</strong> provient d'un dépôt tiers. SE5 a vérifié que son
                        catalogue est bien signé par la clé enregistrée pour cette source, mais cela n'engage que le
                        dépôt : ni SambaEdu ni votre académie n'ont audité ce que fait cette extension.
                    </p>
                </div>
            </div>
        @endunless
    </x-molecules.modal.section>

    {{-- ── Scopes DEMANDÉS (affichés, jamais accordés — 56.4) ───────────── --}}
    @if ($operation !== 'remove')
        <x-molecules.modal.section title="Autorisations demandées">
            @if (count($scopes) === 0)
                <p class="text-sm text-base-content/50" data-testid="app-operation-no-scopes">Aucun scope demandé.</p>
            @else
                <ul class="flex flex-wrap gap-2" data-testid="app-operation-scopes">
                    @foreach ($scopes as $scope)
                        <li><span class="badge badge-outline font-mono text-xs">{{ $scope }}</span></li>
                    @endforeach
                </ul>
            @endif
            <p class="text-xs text-base-content/50 mt-2">
                Ce que l'extension déclare vouloir consulter. Rien n'est accordé par cette confirmation.
            </p>
        </x-molecules.modal.section>
    @endif

    <x-slot:footer>
        <button type="button" class="btn btn-ghost" wire:click="closeAppOperation">Annuler</button>
        <button type="button" class="btn {{ $confirmClasses[$operation] ?? 'btn-primary' }}"
            wire:click="confirmAppOperation" data-testid="confirm-app-operation">
            <i class="fa-solid {{ str_replace([' text-primary', ' text-info', ' text-error'], '', $icons[$operation] ?? 'fa-puzzle-piece') }}"></i>
            {{ $confirmLabels[$operation] ?? 'Confirmer' }}
        </button>
    </x-slot:footer>
</x-molecules.modal>

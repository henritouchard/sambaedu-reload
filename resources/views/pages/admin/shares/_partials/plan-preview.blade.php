{{--
    Story 60.3 — APERÇU DU PLAN d'un partage, AVANT toute application.

    Premier livrable VISIBLE de l'epic : il montre ce que le plan dit (nœuds,
    octrois par identité SE5, plafond, clôture) et ce que le backend en fait, nœud
    par nœud. Il n'écrit rien : le backend qui l'alimente est celui qui n'exécute
    rien, résolu par le registre — le chemin du contrat, pas un raccourci vers une
    classe.

    NEUTRALITÉ : ce rendu ne contient AUCUN vocabulaire de la couche d'exécution
    (mode de permission, commande de pose de liste d'accès, chemin absolu, nom de
    groupe système). Les sujets s'affichent par leurs noms SE5, résolus depuis les
    identités internes du plan. La racine s'affiche « (racine) », jamais son jeton
    brut. Un test l'exerce sur le HTML rendu.

    Entrée attendue ($preview) :
      backend    : ['label' => string, 'description' => string]
      root       : string  (chemin RELATIF — la racine réelle est un savoir de backend)
      template   : string
      nodes[]    : path, display_path, label, nature, plafond (?int), closure (list<string>),
                   grants[] (label, access_label, suspended),
                   outcome (valeur d'enum), detail (?string)
--}}
@php
    use App\Enums\FileBackendOutcome;

    /**
     * Les SEPT états ont sept rendus distincts — et les deux déclins ne se
     * ressemblent pas : « non supporté par ce backend » (limite permanente du
     * modèle) n'est pas « non piloté par SE5 pour l'instant » (dette datée de
     * notre code). Masquer et griser ne disent pas la même chose à l'administrateur.
     */
    $outcomeMeta = static function (FileBackendOutcome $outcome): array {
        return match ($outcome) {
            FileBackendOutcome::Conforme => ['badge-success', 'fa-circle-check', ''],
            FileBackendOutcome::Applique => ['badge-success', 'fa-wand-magic-sparkles', ''],
            FileBackendOutcome::EnAttente => ['badge-info', 'fa-hourglass-half', ''],
            FileBackendOutcome::Echec => ['badge-error', 'fa-circle-xmark', ''],
            FileBackendOutcome::NonExprimable => ['badge-neutral', 'fa-ban', 'Limite du modèle de ce backend — définitive.'],
            FileBackendOutcome::NonImplemente => ['badge-warning', 'fa-clock-rotate-left', "Le mécanisme existe côté backend ; SE5 ne le pilote pas encore."],
            FileBackendOutcome::NonExecute => ['badge-ghost', 'fa-eye', ''],
        };
    };
@endphp

{{-- `data-plan-preview` borne EXACTEMENT ce bloc pour le test de neutralité : sans
     marqueur, l'extraction démarrait sur le texte « Racine du plan » et laissait
     donc le bandeau ci-dessous hors de la zone mesurée — une frontière dessinée un
     div trop tard par rapport à ce qu'elle affirmait couvrir. --}}
<div class="space-y-4" data-plan-preview>
    <div class="alert alert-info py-2">
        <i class="fa-solid fa-circle-info"></i>
        <div class="text-sm min-w-0">
            <span class="font-medium">{{ $preview['backend']['label'] }}</span>
            <span class="opacity-80">— {{ $preview['backend']['description'] }}</span>
        </div>
    </div>

    <div class="text-sm text-base-content/70 flex flex-wrap items-center gap-x-4 gap-y-1">
        <span>
            <i class="fa-solid fa-folder-tree opacity-50 mr-1"></i>
            Racine du plan : <span class="font-mono">{{ $preview['root'] }}</span>
        </span>
        <span class="text-base-content/40 text-xs">{{ count($preview['nodes']) }} nœud(s)</span>
    </div>

    <div class="overflow-x-auto rounded-lg border border-base-300">
        <table class="table table-sm">
            <thead>
                <tr class="text-xs uppercase">
                    <th>Dossier</th>
                    <th>Accès prévus</th>
                    <th>Plafond</th>
                    <th>Résultat</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($preview['nodes'] as $node)
                    @php($outcome = FileBackendOutcome::from($node['outcome']))
                    @php($meta = $outcomeMeta($outcome))
                    <tr wire:key="plan-node-{{ $loop->index }}">
                        <td class="align-top">
                            <div class="font-medium">{{ $node['display_path'] }}</div>
                            <div class="text-xs text-base-content/60">{{ $node['label'] }}</div>
                            <div class="text-xs text-base-content/40">{{ $node['nature'] }}</div>
                            @if (count($node['closure']) > 0)
                                <div class="text-xs text-base-content/60 mt-1">
                                    <i class="fa-solid fa-circle-minus opacity-50 mr-1"></i>
                                    N'a rien reçu ici : {{ implode(', ', $node['closure']) }}
                                </div>
                            @endif
                        </td>
                        <td class="align-top">
                            @if (count($node['grants']) === 0)
                                <span class="text-sm text-base-content/40">Aucun accès prévu</span>
                            @else
                                <ul class="space-y-1">
                                    @foreach ($node['grants'] as $grant)
                                        <li class="text-sm flex items-center gap-2 flex-wrap">
                                            <span>{{ $grant['label'] }}</span>
                                            <span class="badge badge-sm {{ $grant['access_label'] === 'Modifier' ? 'badge-success' : 'badge-info' }}">
                                                {{ $grant['access_label'] }}
                                            </span>
                                            @if ($grant['suspended'])
                                                <span class="badge badge-sm badge-ghost">Suspendu</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        <td class="align-top text-sm">
                            @if ($node['plafond'] === null)
                                <span class="text-base-content/40">—</span>
                            @else
                                {{ number_format($node['plafond'] / (1024 * 1024), 0, ',', ' ') }} Mo
                            @endif
                        </td>
                        <td class="align-top">
                            <span class="badge badge-sm {{ $meta[0] }} gap-1">
                                <i class="fa-solid {{ $meta[1] }} text-[10px]"></i>
                                {{ $outcome->label() }}
                            </span>
                            @if ($meta[2] !== '')
                                <div class="text-xs text-base-content/50 mt-1">{{ $meta[2] }}</div>
                            @endif
                            @if (!empty($node['detail']))
                                <div class="text-xs text-base-content/60 mt-1">{{ $node['detail'] }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

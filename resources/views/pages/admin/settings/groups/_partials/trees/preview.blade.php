{{--
    Story 62.6 — L'APERÇU du plan résolu, AVANT enregistrement.

    Il résout l'état du formulaire sur un groupe d'ESSAI et le fait décrire par le
    backend d'aperçu — celui qui n'exécute rien et qui le dit. Ce qui s'affiche ici
    vient donc de deux sources et de deux seulement : le PLAN (chemins substitués,
    natures, octrois, plafonds) et le RAPPORT du backend (issue par nœud, détail —
    dont la phrase de clôture quand un nœud en porte une).

    Aucun chemin absolu n'apparaît : un aperçu ne vise aucun endroit réel, et le
    backend d'aperçu ne prétend pas le contraire.
--}}
<x-molecules.modal.section title="Aperçu du plan résolu" icon="fa-eye text-primary" dense>

    <div class="flex flex-wrap items-end gap-2">
        <div class="flex flex-col gap-1 grow min-w-64">
            <label class="label" for="trial-group">
                <span class="label-text font-medium">Groupe d'essai</span>
            </label>
            <select id="trial-group" wire:model.live="previewGroupId" class="select select-bordered select-sm w-full"
                data-testid="field-trial-group">
                @foreach ($this->trialGroups as $trial)
                    <option value="{{ $trial['id'] }}">{{ $trial['name'] }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" class="btn btn-outline btn-sm" wire:click="preview" data-testid="run-preview">
            <i class="fa-solid fa-eye"></i> Prévisualiser
        </button>
        @if ($previewData !== [] || $previewError !== '')
            <button type="button" class="btn btn-ghost btn-sm" wire:click="closePreview"
                data-testid="clear-preview">Effacer</button>
        @endif
    </div>

    @if ($this->trialGroups === [])
        <p class="text-xs text-warning mt-2" data-testid="no-trial-group">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            Aucun groupe de ce type n'existe encore : créez-en un pour voir l'arborescence résolue.
            L'enregistrement, lui, reste possible sans aperçu.
        </p>
    @endif

    @if ($previewError !== '')
        <div class="alert alert-error text-sm mt-3" data-testid="preview-error">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>{{ $previewError }}</span>
        </div>
    @endif

    @if ($previewData !== [])
        <p class="text-xs opacity-70 mt-3" data-testid="preview-header">
            Résolu pour <strong>{{ $previewData['group'] }}</strong> — dossier racine
            <code>{{ $previewData['root'] }}</code>. Rien n'est écrit : c'est un aperçu.
        </p>

        <div class="overflow-x-auto mt-2">
            <table class="table table-xs" data-testid="preview-table">
                <thead>
                    <tr>
                        <th>Dossier</th>
                        <th>Nature</th>
                        <th>Octrois</th>
                        <th>Plafond</th>
                        <th>Issue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($previewData['nodes'] as $row)
                        <tr wire:key="preview-{{ $loop->index }}">
                            <td>
                                <code>{{ $row['path'] }}</code>
                                <span class="block opacity-70">{{ $row['label'] }}</span>
                                @if ($row['more'] > 0)
                                    <span class="badge badge-xs badge-ghost mt-1"
                                        data-testid="folded-{{ $loop->index }}">
                                        … et {{ $row['more'] }} autres dossiers de membres
                                    </span>
                                @endif
                            </td>
                            <td>{{ $row['nature'] }}</td>
                            <td>
                                @forelse ($row['grants'] as $grant)
                                    <span class="block">
                                        {{ $grant['label'] }} — {{ $grant['verbs'] }}
                                        @if ($grant['suspendable'])
                                            <span class="badge badge-xs badge-ghost">suspendable</span>
                                        @endif
                                    </span>
                                @empty
                                    <span class="opacity-50">—</span>
                                @endforelse
                            </td>
                            <td>
                                @if ($row['plafond'] !== '')
                                    {{ $row['plafond'] }}
                                    @if ($row['quota_declaration'] !== null)
                                        <span class="badge badge-xs {{ $row['quota_declaration']['model_limit'] ? 'badge-error' : 'badge-warning' }} block mt-1"
                                            data-testid="quota-outcome-{{ $loop->index }}">
                                            {{ $row['quota_declaration']['label'] }}
                                        </span>
                                        <span class="text-[0.65rem] opacity-70 block leading-tight">
                                            {{ $row['quota_declaration']['detail'] }}
                                        </span>
                                    @endif
                                @else
                                    <span class="opacity-50">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-xs badge-ghost" data-testid="outcome-{{ $loop->index }}">
                                    {{ $row['outcome_label'] }}
                                </span>
                                <span class="text-[0.65rem] opacity-70 block leading-tight mt-1"
                                    data-testid="detail-{{ $loop->index }}">
                                    {{ $row['detail'] }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($previewData['traversal'] !== [])
            <div class="alert alert-info text-sm mt-3" data-testid="traversal-note">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <p class="font-medium">
                        Passage vers les dossiers profonds
                        <span class="tooltip tooltip-right"
                            data-tip="Un « couloir » est le passage minimal ouvert sur les dossiers parents pour atteindre un dossier profond : on passe devant la porte, on n'entre pas — ni listage, ni lecture, ni dépôt sur ces parents.">
                            <i class="fa-solid fa-circle-question opacity-70" aria-hidden="true"></i>
                        </span>
                    </p>
                    @foreach ($previewData['traversal'] as $note)
                        <p class="opacity-80">{{ $note }}</p>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</x-molecules.modal.section>

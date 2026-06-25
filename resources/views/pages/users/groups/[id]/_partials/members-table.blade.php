{{-- Table de membres réutilisée par les onglets Élèves / Profs.
     Params : $rows (collection de membres mappés), $withHeadTeacher (bool —
     affiche l'icône « Professeur principal » après le nom dans l'onglet Profs). --}}
@php($withHeadTeacher = $withHeadTeacher ?? false)
<div class="overflow-x-auto">
    <table class="table table-zebra">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Login</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $member)
                <tr>
                    <td class="font-medium">
                        <span class="inline-flex items-center gap-2">
                            <a href="{{ route('app.user.show', ['login' => $member['login']]) }}"
                                class="link link-hover hover:text-primary">
                                {{ $member['label'] }}
                            </a>
                            @if ($withHeadTeacher && ($member['is_head_teacher'] ?? false) && ($member['role'] ?? null) === 'prof')
                                <i class="fa-solid fa-chalkboard-user text-success"
                                    title="Professeur principal"></i>
                            @endif
                        </span>
                    </td>
                    <td>
                        <code class="text-sm bg-base-200 px-2 py-0.5 rounded font-mono">{{ $member['login'] }}</code>
                    </td>
                    <td class="text-right">
                        <div class="flex items-center justify-end gap-1">
                            @can('update-group')
                                <button type="button" class="btn btn-ghost btn-xs text-error hover:bg-error/10"
                                    wire:click="removeMember({{ $member['id'] }})"
                                    wire:confirm="Retirer ce membre du groupe ?">
                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                </button>
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

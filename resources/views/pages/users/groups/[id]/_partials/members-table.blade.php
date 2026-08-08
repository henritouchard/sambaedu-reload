{{-- Table de membres réutilisée par les onglets Élèves / Profs.
     Params : $rows (collection de membres mappés), $withHeadTeacher (bool —
     affiche l'icône « Professeur principal » après le nom dans l'onglet Profs).
     Story 42.3 — colonne « Rôle » (arête `edge_role`/`edge_role_label`, D1) :
     lecture pour tous, select pour les porteurs d'`update-group`. `$type`
     (rôle GLOBAL du groupe, propriété du composant parent) est disponible ici
     par héritage de scope Blade (@include partage get_defined_vars()) — il
     gate l'option « Prof principal » (D3). --}}
@php($withHeadTeacher = $withHeadTeacher ?? false)
{{-- Story 60.2 — libellés du rôle d'arête par TYPE de groupe, depuis la table
     canonique : « Enseignant » en classe, « Porteur » en projet, « Référent » en
     équipe, repli générique ailleurs. Les VALEURS envoyées au serveur restent
     `member|manager|owner`. --}}
@php($edgeRoleOptions = \App\Support\RoleCatalog::options($type ?? null))
<div class="overflow-x-auto">
    <table class="table table-zebra">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Login</th>
                <th>Rôle</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $member)
                <tr wire:key="member-row-{{ $member['id'] }}">
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
                    <td>
                        @can('update-group')
                            <select wire:key="member-role-{{ $member['id'] }}"
                                wire:change="updateMemberRole({{ $member['id'] }}, $event.target.value)"
                                class="select select-bordered select-sm">
                                <option value="member" @selected($member['edge_role'] === 'member')>{{ $edgeRoleOptions['member'] }}</option>
                                <option value="manager" @selected($member['edge_role'] === 'manager')>{{ $edgeRoleOptions['manager'] }}</option>
                                @if (($type ?? null) === 'classe' || $member['edge_role'] === 'owner')
                                    <option value="owner" @selected($member['edge_role'] === 'owner')>{{ $edgeRoleOptions['owner'] }}</option>
                                @endif
                            </select>
                        @else
                            <span class="text-sm">{{ $member['edge_role_label'] }}</span>
                        @endcan
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

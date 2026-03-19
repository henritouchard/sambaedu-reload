<div class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-8 h-8 bg-info/10 rounded-lg flex items-center justify-center">
                <i class="fa-solid fa-users text-info text-sm"></i>
            </div>
            <h3 class="text-lg font-semibold">Membres du groupe</h3>
        </div>
        @if (count($this->members) > 0)
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
                        @foreach ($this->members as $member)
                            <tr>
                                <td class="font-medium">{{ $member['label'] }}</td>
                                <td>
                                    <code
                                        class="text-sm bg-base-200 px-2 py-0.5 rounded font-mono">{{ $member['login'] }}</code>
                                </td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('app.user.show', ['login' => $member['login']]) }}"
                                            class="btn btn-ghost btn-xs gap-1">
                                            <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
                                            Voir
                                        </a>
                                        <button type="button"
                                            class="btn btn-ghost btn-xs text-error hover:bg-error/10"
                                            wire:click="removeMember({{ $member['id'] }})"
                                            wire:confirm="Retirer ce membre du groupe ?">
                                            <i class="fa-solid fa-trash-can text-xs"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3 text-sm text-base-content/50">
                {{ count($this->members) }} membre(s) dans ce groupe
            </div>
        @else
            <div class="text-center py-8">
                <i class="fa-solid fa-users-slash text-4xl text-base-content/20 mb-3"></i>
                <p class="text-base-content/50">Aucun membre dans ce groupe</p>
            </div>
        @endif
    </div>
</div>

@php($students = $this->students)
@php($teachers = $this->teachers)
<div class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-8 h-8 bg-info/10 rounded-lg flex items-center justify-center">
                <i class="fa-solid fa-users text-info text-sm"></i>
            </div>
            <h3 class="text-lg font-semibold">Membres du groupe</h3>
        </div>

        @if (count($this->members) > 0)
            {{-- Onglets Élèves / Profs : la bascule est purement client (Alpine),
                 les deux jeux de données sont déjà chargés côté serveur. --}}
            <div x-data="{ tab: 'students' }">
                <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit mb-4">
                    <button type="button" role="tab" class="tab" :class="{ 'tab-active': tab === 'students' }"
                        :aria-selected="tab === 'students'" aria-controls="members-tab-students"
                        @click="tab = 'students'">
                        <i class="fa-solid fa-graduation-cap mr-2"></i>
                        Élèves
                        <span class="badge badge-sm badge-ghost ml-2">{{ count($students) }}</span>
                    </button>
                    <button type="button" role="tab" class="tab" :class="{ 'tab-active': tab === 'teachers' }"
                        :aria-selected="tab === 'teachers'" aria-controls="members-tab-teachers"
                        @click="tab = 'teachers'">
                        <i class="fa-solid fa-chalkboard-user mr-2"></i>
                        Profs
                        <span class="badge badge-sm badge-ghost ml-2">{{ count($teachers) }}</span>
                    </button>
                </div>

                {{-- Onglet Élèves (visible par défaut) --}}
                <div id="members-tab-students" role="tabpanel" x-show="tab === 'students'">
                    @if (count($students) > 0)
                        @include('pages.users.groups.[id]._partials.members-table', [
                            'rows' => $students,
                            'withHeadTeacher' => false,
                        ])
                    @else
                        <div class="text-center py-8">
                            <i class="fa-solid fa-graduation-cap text-4xl text-base-content/20 mb-3"></i>
                            <p class="text-base-content/50">Aucun élève dans ce groupe</p>
                        </div>
                    @endif
                </div>

                {{-- Onglet Profs : `style="display:none"` initial (le projet n'a
                     pas de règle CSS globale `[x-cloak]`) → pas d'empilement des
                     deux panneaux avant l'init Alpine ; x-show prend le relais. --}}
                <div id="members-tab-teachers" role="tabpanel" x-show="tab === 'teachers'" style="display: none;">
                    @if (count($teachers) > 0)
                        @include('pages.users.groups.[id]._partials.members-table', [
                            'rows' => $teachers,
                            'withHeadTeacher' => true,
                        ])
                    @else
                        <div class="text-center py-8">
                            <i class="fa-solid fa-chalkboard-user text-4xl text-base-content/20 mb-3"></i>
                            <p class="text-base-content/50">Aucun enseignant dans ce groupe</p>
                        </div>
                    @endif
                </div>
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

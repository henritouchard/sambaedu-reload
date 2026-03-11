@props([
    'title' => 'Assignation de groupes',
    'users' => [],
    'availableGroups' => [],
    'action' => '',
    'method' => 'POST',
    'showResetOption' => true
])

<!-- Modal -->
<div id="groupAssignmentModal" class="modal z-50">
    <div class="modal-box max-w-4xl">
        <form method="{{ $method }}" action="{{ $action }}">
            @csrf
            
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-semibold">{{ $title }}</h3>
                <button type="button" class="btn btn-sm btn-circle btn-ghost" onclick="closeGroupAssignmentModal()">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Liste des utilisateurs concernés -->
            @if(!empty($users))
                <div class="mb-6">
                    <h4 class="text-sm font-medium mb-2 text-base-content/70">Utilisateurs concernés ({{ count($users) }})</h4>
                    <div class="bg-base-200 p-3 rounded-lg max-h-20 overflow-y-auto">
                        <div class="flex flex-wrap gap-1">
                            @foreach($users as $user)
                                <span class="badge badge-primary badge-sm">{{ $user }}</span>
                            @endforeach
                        </div>
                    </div>
                    
                    <!-- Conserver les utilisateurs pour la soumission -->
                    @foreach($users as $user)
                        <input type="hidden" name="users[]" value="{{ $user }}">
                    @endforeach
                </div>
            @endif

            <!-- Filtre des groupes -->
            <div class="mb-4">
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Rechercher un groupe</span>
                    </label>
                    <input type="text" 
                           id="groupFilter" 
                           placeholder="Tapez pour filtrer les groupes..." 
                           class="input input-bordered input-sm w-full"
                           onkeyup="filterGroups()">
                </div>
            </div>

            <!-- Sélection des groupes -->
            <div class="mb-6">
                <h4 class="text-sm font-medium mb-3">Groupes à assigner</h4>
                @if($availableGroups->count() > 0)
                    <div id="groupsList" class="grid grid-cols-1 md:grid-cols-2 gap-3 max-h-80 overflow-y-auto p-4 border border-base-300 rounded-lg">
                        @foreach($availableGroups as $group)
                            <div class="group-item" data-group-name="{{ strtolower($group['name']) }}" data-group-cn="{{ strtolower($group['cn']) }}">
                                <label class="flex items-start gap-3 cursor-pointer hover:bg-base-100 p-3 rounded-lg">
                                    <input type="checkbox" 
                                           name="groups[]" 
                                           value="{{ $group['cn'] }}" 
                                           class="checkbox checkbox-primary mt-1 group-checkbox">
                                    <div class="flex-1">
                                        <div class="font-medium group-name">{{ $group['name'] }}</div>
                                        @if(!empty($group['description']))
                                            <div class="text-sm text-base-content/60 group-description">{{ $group['description'] }}</div>
                                        @endif
                                        <div class="text-xs text-base-content/50 group-cn">CN: {{ $group['cn'] }}</div>
                                    </div>
                                </label>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="alert alert-warning">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 15.5c-.77.833.192 2.5 1.732 2.5z">
                            </path>
                        </svg>
                        <span>Aucun groupe disponible</span>
                    </div>
                @endif
            </div>

            <!-- Option de réinitialisation -->
            @if($showResetOption)
                <div class="mb-4">
                    <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border border-error/20 bg-error/5">
                        <input type="checkbox" name="reset_groups" class="checkbox checkbox-error mt-1">
                        <div class="flex-1">
                            <div class="font-medium text-error">Réinitialiser avec ces groupes</div>
                            <div class="text-sm text-base-content/60">
                                Si cochée, les utilisateurs se verront supprimer tous les groupes auxquels ils appartiennent 
                                et assigner les groupes sélectionnés
                            </div>
                        </div>
                    </label>
                </div>
            @endif

            <!-- Actions -->
            <div class="flex justify-between items-center">
                <button type="button" class="btn btn-ghost" onclick="closeGroupAssignmentModal()">
                    Annuler
                </button>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary" @if($availableGroups->count() === 0) disabled @endif>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Assigner les groupes
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Fonctions pour la modal
function showGroupAssignmentModal(options = {}) {
    console.log('showGroupAssignmentModal called with options:', options);
    const modal = document.getElementById('groupAssignmentModal');
    console.log('Modal element:', modal);
    if (!modal) {
        console.error('Modal not found!');
        return;
    }
    
    // Réinitialiser le filtre
    const filterInput = document.getElementById('groupFilter');
    if (filterInput) filterInput.value = '';
    
    // Réinitialiser les checkboxes
    const checkboxes = modal.querySelectorAll('.group-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    
    // Réinitialiser l'option de réinitialisation
    const resetCheckbox = modal.querySelector('input[name="reset_groups"]');
    if (resetCheckbox) resetCheckbox.checked = false;
    
    // Afficher tous les groupes
    const groupItems = modal.querySelectorAll('.group-item');
    groupItems.forEach(item => item.style.display = 'block');
    
    // Afficher la modal avec la méthode DaisyUI
    console.log('Adding modal-open class');
    modal.classList.add('modal-open');
    document.body.style.overflow = 'hidden';
    console.log('Modal should now be visible');
}

function closeGroupAssignmentModal() {
    const modal = document.getElementById('groupAssignmentModal');
    if (modal) {
        modal.classList.remove('modal-open');
        document.body.style.overflow = 'auto';
    }
}

function filterGroups() {
    const filter = document.getElementById('groupFilter').value.toLowerCase();
    const groupItems = document.querySelectorAll('.group-item');
    
    groupItems.forEach(item => {
        const name = item.querySelector('.group-name')?.textContent.toLowerCase() || '';
        const cn = item.querySelector('.group-cn')?.textContent.toLowerCase() || '';
        const description = item.querySelector('.group-description')?.textContent.toLowerCase() || '';
        
        if (name.includes(filter) || cn.includes(filter) || description.includes(filter)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

function selectAllGroups() {
    const visibleCheckboxes = document.querySelectorAll('.group-item:not([style*="display: none"]) .group-checkbox');
    visibleCheckboxes.forEach(cb => cb.checked = true);
}

function deselectAllGroups() {
    const checkboxes = document.querySelectorAll('.group-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
}

// Fermer la modal avec la touche Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeGroupAssignmentModal();
    }
});

// Fermer la modal en cliquant à l'extérieur
document.addEventListener('click', function(e) {
    const modal = document.getElementById('groupAssignmentModal');
    if (modal && e.target === modal) {
        closeGroupAssignmentModal();
    }
});
</script>

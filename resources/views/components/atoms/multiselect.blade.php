@props([
    'name' => 'items',
    'options' => [],
    'selected' => [],
    'placeholder' => 'Sélectionnez...',
    'id' => null,
])

@php
    $componentId = $id ?? 'multiselect-' . uniqid();
    $selectedArray = is_array($selected) ? $selected : [];
@endphp

<div class="multiselect-container" id="{{ $componentId }}">
    <!-- Inputs hidden pour envoyer les valeurs -->
    @foreach($selectedArray as $value)
        <input type="hidden" name="{{ $name }}[]" value="{{ $value }}" class="multiselect-hidden-input">
    @endforeach

    <details class="dropdown w-full">
        <summary class="btn highlight w-full justify-between">
            <span class="multiselect-label truncate text-left flex-1" data-placeholder="{{ $placeholder }}">
                @if(count($selectedArray) > 0)
                    {{ implode(', ', $selectedArray) }}
                @else
                    <span class="text-base-content/50">{{ $placeholder }}</span>
                @endif
            </span>
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </summary>
        <ul class="menu dropdown-content bg-base-100 rounded-box z-[1] w-full p-2 shadow-sm mt-1 max-h-60 overflow-auto">
            @if(count($options) > 0)
                @foreach($options as $option)
                    <li>
                        <label class="flex items-center gap-2 cursor-pointer hover:bg-base-200 rounded-lg px-4 py-2">
                            <input 
                                type="checkbox" 
                                class="checkbox checkbox-sm multiselect-checkbox" 
                                value="{{ $option }}"
                                {{ in_array($option, $selectedArray) ? 'checked' : '' }}
                                onchange="updateMultiselect(this, '{{ $componentId }}')"
                            >
                            <span>{{ $option }}</span>
                        </label>
                    </li>
                @endforeach
            @else
                <li class="px-4 py-2 text-base-content/50 text-sm">Aucune option disponible</li>
            @endif
        </ul>
    </details>

    <!-- Compteur de sélections -->
    <div class="multiselect-counter mt-1 text-sm text-base-content/60" style="{{ count($selectedArray) > 0 ? '' : 'display: none;' }}">
        <span class="count">{{ count($selectedArray) }}</span> élément<span class="plural" style="{{ count($selectedArray) > 1 ? '' : 'display: none;' }}">s</span> sélectionné<span class="plural" style="{{ count($selectedArray) > 1 ? '' : 'display: none;' }}">s</span>
    </div>
</div>

<script>
    function updateMultiselect(checkbox, containerId) {
        const container = document.getElementById(containerId);
        const checkboxes = container.querySelectorAll('.multiselect-checkbox');
        const label = container.querySelector('.multiselect-label');
        const counter = container.querySelector('.multiselect-counter');
        const countSpan = counter.querySelector('.count');
        const pluralSpans = counter.querySelectorAll('.plural');
        const hiddenInputsContainer = container;
        const placeholder = label.getAttribute('data-placeholder');
        
        // Supprimer tous les inputs hidden existants
        hiddenInputsContainer.querySelectorAll('.multiselect-hidden-input').forEach(input => input.remove());
        
        // Récupérer toutes les valeurs sélectionnées
        const selected = Array.from(checkboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.value);
        
        // Créer les nouveaux inputs hidden
        selected.forEach(value => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '{{ $name }}[]';
            input.value = value;
            input.className = 'multiselect-hidden-input';
            hiddenInputsContainer.appendChild(input);
        });
        
        // Mettre à jour le label
        if (selected.length > 0) {
            label.innerHTML = selected.join(', ');
        } else {
            label.innerHTML = `<span class="text-base-content/50">${placeholder}</span>`;
        }
        
        // Mettre à jour le compteur
        countSpan.textContent = selected.length;
        counter.style.display = selected.length > 0 ? '' : 'none';
        pluralSpans.forEach(span => {
            span.style.display = selected.length > 1 ? '' : 'none';
        });
    }
</script>


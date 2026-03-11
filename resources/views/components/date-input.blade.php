@props([
    'name' => 'date',
    'value' => '',
    'label' => 'Date',
    'required' => false,
    'class' => 'input input-bordered w-full'
])

@php
    // Convertir la date du format français (dd/mm/yyyy) vers format d'affichage
    $displayValue = '';
    if (!empty($value)) {
        if (is_string($value) && strpos($value, '/') !== false) {
            // Déjà au format français
            $displayValue = $value;
        } elseif (is_string($value) && strpos($value, '-') !== false) {
            // Format ISO (yyyy-mm-dd) vers français (dd/mm/yyyy)
            $date = DateTime::createFromFormat('Y-m-d', $value);
            if ($date) {
                $displayValue = $date->format('d/m/Y');
            }
        }
    }
@endphp

<div class="form-control">
    <label class="label">
        <span class="label-text font-medium text-base-content/70">{{ $label }}</span>
    </label>
    <input 
        type="text" 
        name="{{ $name }}" 
        value="{{ $displayValue }}" 
        class="{{ $class }}" 
        placeholder="JJ/MM/AAAA"
        pattern="\d{2}/\d{2}/\d{4}"
        maxlength="10"
        {{ $required ? 'required' : '' }}
        oninput="this.value = this.value.replace(/[^0-9\/]/g, '').replace(/(\d{2})(\d{2})(\d{4})/, '$1/$2/$3').replace(/\/+/g, '/').substr(0, 10);"
        onblur="if(this.value && this.value.length === 8 && !this.value.includes('/')) this.value = this.value.replace(/(\d{2})(\d{2})(\d{4})/, '$1/$2/$3');"
    >
    <label class="label">
        <span class="label-text-alt text-base-content/50">Format: JJ/MM/AAAA</span>
    </label>
</div>

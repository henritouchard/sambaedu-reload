{{--
    Checkbox d'en-tête « tout sélectionner / tout désélectionner ».

    L'état visuel (checked / indeterminate) est piloté par les PROPRIÉTÉS DOM
    via x-effect à partir de $wire : le morph Livewire ne resynchronise que
    l'attribut `checked`, que le navigateur ignore dès que l'utilisateur a
    cliqué la case — d'où les incohérences avec @checked(...) + wire:click.

    - ids   : identifiants des lignes visibles (array ou Collection)
    - model : nom de la propriété Livewire (array) portant la sélection

    Le toggle agit uniquement sur les lignes visibles : il les ajoute à la
    sélection, ou les en retire si elles y sont déjà toutes.
--}}
@props([
    'ids',
    'model',
])
@php
    $idsJson = json_encode(array_values(array_map('strval', $ids instanceof \Illuminate\Support\Collection ? $ids->all() : $ids)));
@endphp
<input type="checkbox" {{ $attributes->merge(['class' => 'checkbox']) }}
    wire:key="select-all-{{ $model }}-{{ md5($idsJson) }}"
    x-data="{ ids: {{ $idsJson }} }"
    x-effect="
        const sel = ($wire.{{ $model }} ?? []).map(String);
        $el.checked = ids.length > 0 && ids.every(id => sel.includes(id));
        $el.indeterminate = !$el.checked && ids.some(id => sel.includes(id));
    "
    @click="
        const sel = ($wire.{{ $model }} ?? []).map(String);
        const allSelected = ids.length > 0 && ids.every(id => sel.includes(id));
        $wire.set('{{ $model }}', allSelected ? sel.filter(id => !ids.includes(id)) : [...new Set([...sel, ...ids])]);
    ">

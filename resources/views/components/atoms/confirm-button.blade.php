{{--
Bouton avec confirmation modale DaisyUI

Usage:
<x-atoms.confirm-button method="removeMemberByDn" :params="[$member['dn']]" title="Retirer le membre"
    message="Voulez-vous retirer {{ $member['name'] }} ?" confirm-text="Retirer" variant="warning" class="btn-sm">
    <i class="fa-solid fa-user-minus"></i>
</x-atoms.confirm-button>

Props:
- method: Nom de la méthode Livewire à appeler
- params: Tableau des paramètres à passer (optionnel)
- title: Titre de la modal (défaut: 'Confirmation')
- message: Message de confirmation (défaut: 'Êtes-vous sûr ?')
- confirm-text: Texte du bouton de confirmation (défaut: 'Confirmer')
- cancel-text: Texte du bouton d'annulation (défaut: 'Annuler')
- variant: Variante du bouton de confirmation (primary, error, warning, success)
--}}

@props([
    'method' => null,
    'params' => [],
    'title' => 'Confirmation',
    'message' => 'Êtes-vous sûr ?',
    'confirmText' => 'Confirmer',
    'cancelText' => 'Annuler',
    'variant' => 'primary',
])

<button
    type="button"
    x-data
    @click="
        const wireEl = $el.closest('[wire\\:id]');
        const wireId = wireEl ? wireEl.getAttribute('wire:id') : null;
        $dispatch('open-confirm-modal', {
            title: @js($title),
            message: @js($message),
            confirmText: @js($confirmText),
            cancelText: @js($cancelText),
            variant: @js($variant),
            method: @js($method),
            params: @js($params),
            wireId: wireId
        })
    "
    {{ $attributes->merge(['class' => 'btn']) }}
>
    {{ $slot }}
</button>

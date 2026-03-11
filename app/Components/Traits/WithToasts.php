<?php

namespace App\Components\Traits;

/**
 * Trait pour afficher des toasts via ToastMagic dans les composants Livewire
 * 
 * Usage:
 *   use App\Components\Traits\WithToasts;
 *   
 *   class MyComponent extends Component {
 *       use WithToasts;
 *       
 *       public function save() {
 *           $this->toast('success', 'Succès', 'Enregistré avec succès');
 *           $this->toastSuccess('Enregistré avec succès');
 *           $this->toastError('Une erreur est survenue');
 *       }
 *   }
 */
trait WithToasts
{
    /**
     * Affiche un toast via ToastMagic
     */
    protected function toast(string $status, string $title, string $message): void
    {
        $this->dispatch('toastMagic', status: $status, title: $title, message: $message);
    }

    /**
     * Toast de succès
     */
    protected function toastSuccess(string $message, string $title = 'Succès'): void
    {
        $this->toast('success', $title, $message);
    }

    /**
     * Toast d'erreur
     */
    protected function toastError(string $message, string $title = 'Erreur'): void
    {
        $this->toast('error', $title, $message);
    }

    /**
     * Toast d'avertissement
     */
    protected function toastWarning(string $message, string $title = 'Attention'): void
    {
        $this->toast('warning', $title, $message);
    }

    /**
     * Toast d'information
     */
    protected function toastInfo(string $message, string $title = 'Information'): void
    {
        $this->toast('info', $title, $message);
    }

    /**
     * Toast d'accès refusé (raccourci pratique)
     */
    protected function toastAccessDenied(string $message = 'Vous n\'avez pas les droits pour effectuer cette action'): void
    {
        $this->toast('error', 'Accès refusé', $message);
    }
}

<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 4.7 — page « défauts établissement » (wallpaper + lockscreen).
 *
 * Story 27.17 (option a) : l'édition du DÉFAUT Broadcast est désormais
 * consolidée dans /admin/settings/parc-defaults (onglets « Fond d'écran » et
 * « Écran de verrouillage »). Cette page ne contient plus d'éditeur propre —
 * elle REDIRIGE vers la surface consolidée pour ne pas dédoubler le point
 * d'édition (un seul endroit fait foi). Le lien profond GPO `?from_gpo=<GUID>`
 * (généré par {@see \App\Gpo\Support\NativeSectionResolver}) reste fonctionnel :
 * le paramètre est PROPAGÉ à la page cible, qui affiche le breadcrumb « Retour à
 * la GPO ».
 *
 * Sécurité : l'accès reste gardé par `can:wallpaper.manage` (route) — la page
 * cible re-garde `server.admin`.
 */
new #[Title('Fonds d\'écran — SE4FS')] class extends Component {
    public function mount(): void
    {
        abort_unless(
            Gate::allows('wallpaper.manage') || Gate::allows('server.admin'),
            403,
            'Permission wallpaper.manage requise.',
        );

        // Propage `from_gpo` (lien profond GPO) pour que la page cible rende le
        // breadcrumb de retour. On ne le transmet que si présent et non vide.
        $params = ['tab' => 'wallpaper'];
        $fromGpo = request()->query('from_gpo');
        if (is_string($fromGpo) && $fromGpo !== '') {
            $params['from_gpo'] = $fromGpo;
        }

        $this->redirectRoute('admin.settings.parc-defaults', $params, navigate: false);
    }
};
?>

<div></div>

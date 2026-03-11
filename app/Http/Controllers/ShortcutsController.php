<?php

/**
 * Contrôleur pour la gestion des raccourcis (edition, creation, suppression, etc...)
 */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ShortcutsService;
use Illuminate\Support\Facades\Validator;
use Devrabiul\ToastMagic\Facades\ToastMagic;



class ShortcutsController extends Controller
{
    private ShortcutsService $shortcutsService;

    public function __construct(ShortcutsService $shortcutsService)
    {
        $this->shortcutsService = $shortcutsService;
    }

    /**
     * Afficher la liste des raccourcis avec filtres
     */
    public function index(Request $request)
    {
        // Récupérer tous les raccourcis
        $allShortcuts = $this->shortcutsService->getAllShortcuts();

        // Récupérer les filtres actuels
        $currentFilters = [
            'search' => $request->get('search', ''),
            'type' => $request->get('type', 'all'),
            'place' => $request->get('place', 'all'),
            'owner' => $request->get('owner', '')
        ];


        // Appliquer les filtres
        $shortcuts = $this->shortcutsService->filterShortcuts($allShortcuts, $currentFilters);

        // Obtenir les options de filtres
        $filters = $this->shortcutsService->getFilterOptions();


        return view('client.shortcuts.index', [
            'shortcuts' => $shortcuts,
            'totalShortcuts' => count($shortcuts),
            'currentFilters' => $currentFilters,
            'filters' => $filters,
            'shortcutsService' => $this->shortcutsService
        ]);
    }

    /**
     * Afficher le formulaire de création d'un raccourci
     */
    public function create()
    {
        $filters = $this->shortcutsService->getFilterOptions();
        
        return view('client.shortcuts.create', [
            'filters' => $filters
        ]);
    }

    /**
     * Sauvegarder un nouveau raccourci
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'owner' => 'nullable|string|max:500',
            'place' => 'required|in:desktop,startup,taskbar',
            'windows_link' => 'nullable|string|max:500',
            'windows_args' => 'nullable|string|max:500',
            'windows_path' => 'nullable|string|max:500',
            'windows_icon' => 'nullable|string|max:500',
            'linux_link' => 'nullable|string|max:500',
            'linux_args' => 'nullable|string|max:500',
            'linux_path' => 'nullable|string|max:500',
            'linux_startupwmclass' => 'nullable|string|max:255',
            'icon_file' => 'nullable|file|mimes:png,jpg,jpeg,gif,ico|max:2048'
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $success = $this->shortcutsService->saveShortcutWithIcon(
            $request->all(),
            $request->file('icon_file')
        );

        if ($success) {
            ToastMagic::success('Création du raccourci', 'Raccourci créé avec succès');
            return redirect()->route('app.clients.shortcuts');
        } else {
            ToastMagic::error('Création du raccourci', 'Erreur lors de la création du raccourci');
            return redirect()->back()
                ->withInput();
        }
    }

    /**
     * Afficher le formulaire d'édition d'un raccourci
     */
    public function edit(string $key)
    {
        $shortcut = $this->shortcutsService->getShortcutByKey($key);
        if (!isset($shortcut)) {
            ToastMagic::error('Raccourci', 'Raccourci non trouvé');
            return redirect()->route('app.clients.shortcuts');
        }

        $filters = $this->shortcutsService->getFilterOptions();

        return view('client.shortcuts.edit', [
            'shortcut' => $shortcut,
            'filters' => $filters
        ]);
    }

    /**
     * Mettre à jour un raccourci
     */
    public function update(Request $request, string $key)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'owner' => 'nullable|string|max:500',
            'place' => 'required|in:desktop,startup,taskbar',
            'windows_link' => 'nullable|string|max:500',
            'windows_args' => 'nullable|string|max:500',
            'windows_path' => 'nullable|string|max:500',
            'windows_icon' => 'nullable|string|max:500',
            'linux_link' => 'nullable|string|max:500',
            'linux_args' => 'nullable|string|max:500',
            'linux_path' => 'nullable|string|max:500',
            'linux_startupwmclass' => 'nullable|string|max:255',
            'icon_file' => 'nullable|file|mimes:png,jpg,jpeg,gif,ico|max:2048'
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = $request->all();
        // On réutilise la clef (l'index dans le fichier shortcuts.json) pour éviter les doublons si les choses ne se sont pas passées correctement
        $data['key'] = $key;

        $success = $this->shortcutsService->saveShortcutWithIcon(
            $data,
            $request->file('icon_file')
        );

        if ($success) {
            ToastMagic::success('Modification du raccourci', 'Raccourci modifié avec succès');
            return redirect()->route('app.clients.shortcuts')
                ->with('_cache_bust', time()); // Force le rafraîchissement

        } else {
            ToastMagic::error('Modification du raccourci', 'Erreur lors de la modification du raccourci');
            return redirect()->back()
                ->withInput();
        }
    }

    /**
     * Supprimer un raccourci
     */
    public function destroy(string $key)
    {
        $success = $this->shortcutsService->deleteShortcut($key);

        if ($success) {
            ToastMagic::success('Suppression du raccourci', 'Raccourci supprimé avec succès');
            return redirect()->route('app.clients.shortcuts')
                ->with('_cache_bust', time()); // Force le rafraîchissement

        } else {
            ToastMagic::error('Suppression du raccourci', 'Erreur lors de la suppression du raccourci');
            return redirect()->route('app.clients.shortcuts');
        }
    }


    /**
     * Supprimer plusieurs raccourcis
     */
    public function bulkDelete(Request $request)
    {
        $keysJson = $request->input('keys', '[]');
        $keys = json_decode($keysJson, true);
        
        if (empty($keys) || !is_array($keys)) {
            ToastMagic::error('Suppression multiple', 'Aucun raccourci sélectionné');
            return redirect()->route('app.clients.shortcuts');
        }

        $deleted = 0;
        foreach ($keys as $key) {
            if ($this->shortcutsService->deleteShortcut($key)) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            ToastMagic::success('Suppression multiple', "$deleted raccourci(s) supprimé(s) avec succès");
            return redirect()->route('app.clients.shortcuts');
        } else {
            ToastMagic::error('Suppression multiple', 'Erreur lors de la suppression des raccourcis');
            return redirect()->route('app.clients.shortcuts');
        }
    }
}

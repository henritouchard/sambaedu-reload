<?php

namespace App\Observers;

use App\Models\Shortcut;
use App\Services\ShortcutCompilerService;
use Illuminate\Support\Facades\Log;

/**
 * Observer sur le modèle Shortcut.
 *
 * Déclenche automatiquement la pré-compilation lors de la création
 * ou modification d'un raccourci, uniquement si des champs pertinents
 * pour l'export ont changé.
 */
class ShortcutObserver
{
    /**
     * Champs dont la modification déclenche une recompilation.
     * Tout autre champ (is_dynamic, compiled_data, compiled_at, timestamps...)
     * est ignoré.
     */
    private const COMPILABLE_FIELDS = [
        'name',
        'place',
        'owner',
        'windows_link',
        'windows_args',
        'windows_path',
        'windows_icon',
        'linux_link',
        'linux_args',
        'linux_path',
        'linux_icon',
        'linux_startupwmclass',
        'icon_path',
        'ad_users',
        'ad_user_groups',
    ];

    private ShortcutCompilerService $compiler;

    public function __construct(ShortcutCompilerService $compiler)
    {
        $this->compiler = $compiler;
    }

    /**
     * Après la création d'un raccourci : compiler.
     */
    public function created(Shortcut $shortcut): void
    {
        $this->compileIfNeeded($shortcut);
    }

    /**
     * Après la mise à jour d'un raccourci : recompiler uniquement si
     * un champ pertinent pour l'export a changé (nom, link, args, path, icon, place...).
     */
    public function updated(Shortcut $shortcut): void
    {
        $changedFields = array_keys($shortcut->getChanges());
        $relevantChanges = array_intersect($changedFields, self::COMPILABLE_FIELDS);

        if (empty($relevantChanges)) {
            return;
        }

        Log::debug('ShortcutObserver: recompilation triggered', [
            'shortcut_id' => $shortcut->id,
            'changed_fields' => $relevantChanges,
        ]);

        $this->compileIfNeeded($shortcut);
    }

    private function compileIfNeeded(Shortcut $shortcut): void
    {
        try {
            $this->compiler->compile($shortcut);
        } catch (\Exception $e) {
            Log::error('ShortcutObserver: compilation failed', [
                'shortcut_id' => $shortcut->id,
                'name' => $shortcut->name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Services\ShortcutCompilerService;
use Illuminate\Console\Command;

/**
 * Commande Artisan pour pré-compiler tous les raccourcis.
 *
 * Usage :
 *   php artisan shortcuts:compile        # Compile tous les raccourcis
 *   php artisan shortcuts:compile --id=5 # Compile un raccourci spécifique
 */
class CompileShortcutsCommand extends Command
{
    protected $signature = 'shortcuts:compile {--id= : ID d\'un raccourci spécifique à compiler}';
    protected $description = 'Pré-compile les raccourcis pour un export rapide vers les postes Windows/Linux';

    public function handle(ShortcutCompilerService $compiler): int
    {
        $id = $this->option('id');

        if ($id) {
            $shortcut = \App\Models\Shortcut::find($id);
            if (!$shortcut) {
                $this->error("Raccourci #{$id} non trouvé.");
                return self::FAILURE;
            }

            $compiler->compile($shortcut);
            $this->info("Raccourci « {$shortcut->name} » compilé (dynamique: " . ($shortcut->is_dynamic ? 'oui' : 'non') . ").");
            return self::SUCCESS;
        }

        $this->info('Compilation de tous les raccourcis...');
        $count = $compiler->compileAll();
        $this->info("{$count} raccourci(s) compilé(s).");

        return self::SUCCESS;
    }
}

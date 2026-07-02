<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NetworkShare;
use App\Services\Filesystem\NetworkShareService;
use Illuminate\Console\Command;

/**
 * Epic 34 (gap Docs/Progs) — Amorce les partages ÉTABLISSEMENT historiques
 * (`/var/sambaedu/Docs`, `/var/sambaedu/Progs`) sous forme de LECTEURS RÉSEAU
 * MANAGÉS, plutôt que de recréer un provisionneur bespoke.
 *
 * **Choix de modélisation.** Le modèle managé ({@see NetworkShare}) est PLAT :
 * un dossier, une audience, un axe `ro|rw`. Il NE reproduit PAS la sous-structure
 * legacy à ACL hétérogènes (`Docs/public` world-writable, `Progs/ro` + `Progs/rw`).
 * On crée donc des lecteurs plats canoniques ; si le découpage ro/rw est requis,
 * l'admin crée deux lecteurs distincts (ex. « Logiciels (lecture) » et
 * « Logiciels (dépôt) »). Le dossier `public` (accès `other`) n'est
 * volontairement PAS porté (footgun legacy — le modèle force `other:---`).
 *
 * **Audience laissée à l'admin.** Les partages sont créés SANS assignation :
 * l'audience (qui / ro / rw) est une décision de POLITIQUE par établissement,
 * pas un défaut à coder en dur. Tant qu'aucune assignation n'est posée, seuls
 * les `domain admins` ont accès (base canonique) — sûr par défaut.
 *
 * Idempotent : un `directory_name` déjà pris est laissé tel quel (ni écrasé, ni
 * dupliqué). Dry-run par défaut ; `--apply` pour écrire + provisionner.
 */
class SharesSeedEtablissementCommand extends Command
{
    protected $signature = 'shares:seed-etablissement
        {--apply : Crée réellement les lecteurs manquants + provisionne (sinon dry-run)}
        {--performed-by= : Auteur inscrit dans quota_audit_logs (défaut : shares:seed-etablissement)}';

    protected $description = 'Amorce les partages établissement (Documents, Progs) en lecteurs réseau gérés plats (dry-run par défaut).';

    /**
     * Lecteurs établissement canoniques : nom affiché → nom de répertoire FS.
     * Audience vide (assignée ensuite par l'admin).
     */
    private const SEEDS = [
        ['name' => 'Documents établissement', 'directory_name' => 'Documents'],
        ['name' => 'Logiciels (Progs)', 'directory_name' => 'Progs'],
    ];

    public function handle(NetworkShareService $shareService): int
    {
        $apply = (bool) $this->option('apply');
        $performedBy = (string) ($this->option('performed-by') ?: 'shares:seed-etablissement');

        if (! preg_match('/^[a-zA-Z0-9._:-]+$/', $performedBy)) {
            $this->error('--performed-by invalide (regex /^[a-zA-Z0-9._:-]+$/).');

            return self::FAILURE;
        }

        $toCreate = [];
        foreach (self::SEEDS as $seed) {
            $exists = NetworkShare::where('directory_name', $seed['directory_name'])->exists();
            $this->line(sprintf(
                '  %s %s (Partages/%s)',
                $exists ? '<fg=gray>[déjà présent]</>' : '<fg=green>[à créer]  </>',
                $seed['name'],
                $seed['directory_name'],
            ));
            if (! $exists) {
                $toCreate[] = $seed;
            }
        }

        if ($toCreate === []) {
            $this->info('Tous les partages établissement existent déjà — rien à faire.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->info(sprintf('[DRY-RUN] %d lecteur(s) seraient créés. Relancez avec --apply.', count($toCreate)));
            $this->line('<fg=gray>Note : créés SANS audience — pensez à assigner les groupes/utilisateurs depuis l\'UI ensuite.</>');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($toCreate as $seed) {
            $share = NetworkShare::create([
                'name' => $seed['name'],
                'directory_name' => $seed['directory_name'],
                'created_by_user_id' => null,
            ]);

            $ok = $shareService->provision($share, $performedBy);
            if ($ok) {
                $this->info(sprintf('  [OK]   %s créé et provisionné.', $seed['name']));
            } else {
                $failed++;
                $this->warn(sprintf('  [WARN] %s créé mais provisioning en échec (voir logs).', $seed['name']));
            }
        }

        $this->newLine();
        $this->line('<fg=gray>Rappel : ces lecteurs n\'ont PAS d\'audience. Assignez-les (groupes/utilisateurs, ro/rw) depuis l\'UI des lecteurs réseau.</>');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

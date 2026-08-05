<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DirectoryTemplate;
use App\Models\UserGroup;
use App\Services\Filesystem\ClassTreeShareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 60.5 — LA VOIE DE PEUPLEMENT de l'arbre NEUF.
 *
 * La création d'un groupe matérialise son arbre ; mais une instance en place a
 * déjà ses classes, créées bien avant que la recette existe. Sans cette commande,
 * l'arbre neuf ne se peuplerait qu'au fil des créations à venir, et la comparaison
 * des deux arbres — la raison d'être de la story — serait impossible à mener sur un
 * parc réel.
 *
 * ---------------------------------------------------------------------------
 * **DEUX COMMANDES, DEUX ARBRES — à ne jamais fusionner.**
 *
 * `shares:resync-class` reste l'outil de l'arbre HISTORIQUE, le seul réellement
 * servi aux établissements. Celle-ci peuple l'arbre NEUF, celui qu'on compare.
 * Elles écrivent dans des zones disjointes, avec des autorités différentes, et
 * l'une ne doit ni appeler l'autre ni la remplacer. Le jour où l'arbre servi
 * basculera, ce sera une décision explicite — une story de migration avec aperçu
 * avant exécution — pas un effet de bord de cette commande.
 *
 * **Exécution DIRECTE, et c'est le régime des commandes.** Une commande est déjà
 * hors requête : enfiler y ferait perdre son sens au code de retour, qui ne dirait
 * plus que « j'ai posté des tâches ». Les écrans, eux, enfilent.
 *
 * **IDEMPOTENTE par construction.** Le backend lit l'état effectif avant d'écrire :
 * un second passage sur un arbre déjà conforme n'émet aucune commande. Rejouer
 * cette commande sur tout un parc ne coûte donc que des lectures.
 *
 * **Les classes dont les groupes d'annuaire ne se résolvent pas DÉCLINENT sans rien
 * écrire.** C'est la sonde à trois verdicts du backend : le nom attendu est nommé
 * dans le détail, rien n'est posé, rien n'est purgé. Comportement iso au pré-contrôle
 * de l'arbre historique, qui saute exactement les mêmes classes — sur l'instance de
 * référence, 129 des 150 classes n'ont aucun groupe d'annuaire résolvable.
 *
 * Codes de retour :
 *  - `0` : tout ce qui devait être matérialisé l'a été (ou il n'y avait rien).
 *  - `1` : au moins une classe a échoué ou décliné.
 *  - `2` : aucune recette d'arbre n'est accrochée — rien à faire, et ce n'est pas
 *          un succès : c'est le signe que le peuplement des recettes n'a pas été
 *          joué sur cette instance.
 */
class MaterializeClassTreesCommand extends Command
{
    protected $signature = 'shares:materialize-class-trees
        {--class= : Nom (ou nom nu) d\'une classe précise ; sinon tous les groupes du type accroché}
        {--dry-run : Liste ce qui serait matérialisé, sans rien créer ni écrire}';

    protected $description = 'Matérialise l\'arbre de classe NEUF (racine dédiée) des groupes existants. '
        . 'N\'écrit JAMAIS dans l\'arbre historique. Codes : 0=ok ; 1=au moins un échec ; 2=aucune recette d\'arbre accrochée.';

    public function __construct(private readonly ClassTreeShareService $trees)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $classFilter = trim((string) ($this->option('class') ?? ''));

        $templates = DirectoryTemplate::query()
            ->whereNotNull('attached_group_type')
            ->whereNotNull('path_pattern')
            ->orderBy('id')
            ->get()
            ->filter(static fn (DirectoryTemplate $t): bool => $t->materializesOnGroupCreation());

        if ($templates->isEmpty()) {
            $this->error('Aucune recette d\'arbre n\'est accrochée à un type de groupe. '
                . 'Jouez d\'abord le peuplement des recettes : php artisan db:seed --class=DirectoryTemplateSeeder');

            return 2;
        }

        $done = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($templates as $template) {
            $type = (string) $template->attachedGroupType();

            $groups = UserGroup::query()
                ->whereRaw('LOWER(type) = ?', [mb_strtolower($type)])
                ->when($classFilter !== '', function ($query) use ($classFilter): void {
                    // Le nom stocké peut être NU ou encore préfixé sur une instance
                    // en place : on accepte les deux plutôt que d'obliger
                    // l'exploitant à deviner laquelle des deux formes sa base porte.
                    $needle = mb_strtolower($classFilter);
                    $query->where(function ($q) use ($needle): void {
                        $q->whereRaw('LOWER(name) = ?', [$needle])
                            ->orWhereRaw('LOWER(name) = ?', ['classe_' . $needle]);
                    });
                })
                ->orderBy('name')
                ->get();

            if ($groups->isEmpty()) {
                $this->warn(sprintf('Recette « %s » : aucun groupe de type « %s » à traiter.', $template->key, $type));

                continue;
            }

            $this->line(sprintf(
                '<options=bold>%s</> — %d groupe(s) de type « %s »%s',
                $template->key,
                $groups->count(),
                $type,
                $dryRun ? ' <comment>(simulation)</comment>' : '',
            ));

            foreach ($groups as $group) {
                if ($dryRun) {
                    $existing = $this->trees->existingShareFor($group, $template);
                    $this->line(sprintf(
                        '  · %s — %s',
                        (string) $group->name,
                        $existing === null ? 'arbre à créer' : 'arbre déjà relié, réconciliation à rejouer',
                    ));
                    $skipped++;

                    continue;
                }

                try {
                    $result = $this->trees->materialize($group, $template, direct: true);
                } catch (Throwable $e) {
                    $failed++;
                    $this->line(sprintf('  <fg=red>✗</> %s — %s', (string) $group->name, $e->getMessage()));

                    continue;
                }

                if ($result['materialized']) {
                    $done++;
                    $this->line(sprintf('  <fg=green>✓</> %s', (string) $group->name));

                    continue;
                }

                $failed++;
                $this->line(sprintf(
                    '  <fg=yellow>!</> %s — %s',
                    (string) $group->name,
                    $result['reason'] ?? 'au moins un nœud n\'est pas dans l\'état voulu (voir la fiche du partage).',
                ));
            }
        }

        if ($dryRun) {
            $this->info(sprintf('Simulation : %d groupe(s) listé(s), rien n\'a été créé ni écrit.', $skipped));

            return 0;
        }

        $this->newLine();
        $this->info(sprintf('%d arbre(s) matérialisé(s), %d en échec ou déclinés.', $done, $failed));

        Log::info('[shares:materialize-class-trees] terminé', [
            'materialized' => $done,
            'failed' => $failed,
            'class_filter' => $classFilter !== '' ? $classFilter : null,
        ]);

        return $failed > 0 ? 1 : 0;
    }
}

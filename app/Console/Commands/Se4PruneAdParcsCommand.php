<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Config\LdapDnHelper;
use App\Console\Commands\Concerns\InteractsWithSe4Extinction;
use App\LdapModels\DeviceGroupTagModel;
use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 38.7 — Purge PRÉVISIONNELLE des `CN` hérités de `OU=Parcs`.
 *
 * `OU=Parcs` est devenu un conteneur en LECTURE SEULE : SE5 n'y écrit plus rien
 * (ni parcs logiques, ni miroir CN des salles, ni profils applicatifs). Les `CN`
 * qui y subsistent — hérités de SE4 ou créés par SE5 avant cette story — n'ont
 * plus aucun lecteur SE5. Cette commande permet, LE MOMENT VENU, de les retirer.
 *
 * Elle rejoint la famille `se4:*` et RÉUTILISE {@see InteractsWithSe4Extinction}
 * (garde root, refus tant que l'extinction à blanc SE4 n'est pas en place). Cette
 * dernière garde est essentielle : tant que SE4 sert, `gpo/applications.php`
 * résout encore les parcs d'une machine depuis son `memberOf` dans `OU=Parcs`
 * (`includes/applications.inc.php:922`). On ne purge donc qu'après extinction.
 *
 * Dry-run par défaut (lecture seule, exécutable à tout moment). `--confirm` pour
 * agir ; c'est là seulement que s'appliquent les gardes root + extinction.
 *
 * Deux exclusions, journalisées NOMMÉMENT (jamais silencieuses) :
 *   - tout `CN` homonyme d'un `app_profiles.name` : collision documentée (un
 *     parc logique et un profil applicatif homonymes sont LE MÊME objet AD) —
 *     purger détruirait le groupe AD du profil. Conceptuellement « sans objet »
 *     puisque SE5 ne crée plus de représentation AD des profils, mais des objets
 *     de collision antérieurs à 38.7 peuvent subsister : on protège quand même ;
 *   - tout `CN` homonyme d'un groupe PHYSIQUE : le miroir de salle est vivant
 *     (même si SE5 ne l'écrit plus, l'`OU` correspondante l'est).
 *
 * PAS branchée dans un scheduler ni dans `import:sync-from-ad`.
 */
class Se4PruneAdParcsCommand extends Command
{
    use InteractsWithSe4Extinction;

    protected $signature = 'se4:prune-ad-parcs
        {--confirm : Confirme la suppression effective des CN (sinon dry-run)}';

    protected $description = 'Purge prévisionnelle des CN hérités de OU=Parcs (lecture seule sans --confirm)';

    protected $help = <<<'HELP'
    Supprime dans l'annuaire les objets résiduels du conteneur des parcs, hérités du
    serveur SE4.

    Ce conteneur est devenu une zone en LECTURE SEULE pour SE5, qui n'y écrit plus
    rien. Ce qui y subsiste n'a plus aucun lecteur côté SE5 — mais en a encore côté
    SE4 tant que celui-ci sert.

      <info>php artisan se4:prune-ad-parcs</info>             simulation (par défaut)
      <info>php artisan se4:prune-ad-parcs --confirm</info>   supprime réellement

    Sans <comment>--confirm</comment>, la commande est en LECTURE SEULE et peut être lancée à
    n'importe quel moment pour voir ce qui serait retiré.

    ⚠️ La suppression effective est REFUSÉE tant que le serveur SE4 n'a pas été
    débranché : il y résout encore les parcs d'une machine. Purgez après extinction,
    jamais avant.
    HELP;

    /**
     * Seam de test (HÔTE, sans AD) : liste des entrées `OU=Parcs`. Chaque objet
     * doit exposer `getParcName(): ?string` et `getDn(): string`. Null = requête
     * LDAP réelle {@see DeviceGroupTagModel}.
     *
     * @var iterable<object>|null
     */
    public static ?iterable $parcsEntriesSeam = null;

    public function handle(LdapDnHelper $dnHelper): int
    {
        $confirm = (bool) $this->option('confirm');

        $appProfileNames = AppProfile::query()
            ->pluck('name')
            ->mapWithKeys(fn ($n) => [mb_strtolower((string) $n) => (string) $n])
            ->all();

        $physicalNames = WorkstationGroup::query()
            ->where('is_physical', true)
            ->pluck('name')
            ->mapWithKeys(fn ($n) => [mb_strtolower((string) $n) => (string) $n])
            ->all();

        $entries = static::$parcsEntriesSeam
            ?? DeviceGroupTagModel::in($dnHelper->parcsDn())->get();

        /** @var list<object> $targets */
        $targets = [];
        $excludedProfiles = [];
        $excludedPhysical = [];

        foreach ($entries as $entry) {
            $name = $entry->getParcName() ?? '';
            if ($name === '') {
                continue;
            }
            $lower = mb_strtolower($name);

            if (isset($appProfileNames[$lower])) {
                $excludedProfiles[] = $entry;
                $this->line("  [exclu — profil applicatif] {$entry->getDn()}");
                Log::info('[se4:prune-ad-parcs] CN exclu (collision app_profiles.name)', ['name' => $name, 'dn' => $entry->getDn()]);
                continue;
            }

            if (isset($physicalNames[$lower])) {
                $excludedPhysical[] = $entry;
                $this->line("  [exclu — salle physique]    {$entry->getDn()}");
                Log::info('[se4:prune-ad-parcs] CN exclu (homonyme groupe physique)', ['name' => $name, 'dn' => $entry->getDn()]);
                continue;
            }

            $targets[] = $entry;
        }

        $this->table(
            ['Scope', 'Nombre'],
            [
                ['CN à purger', count($targets)],
                ['Exclus — profils applicatifs', count($excludedProfiles)],
                ['Exclus — salles physiques', count($excludedPhysical)],
            ],
        );

        foreach ($targets as $entry) {
            $this->line("  [à purger] {$entry->getDn()}");
        }

        if (! $confirm) {
            $this->newLine();
            $this->comment('DRY-RUN — aucune écriture LDAP. Relancer avec --confirm pour purger (après le GO d\'extinction SE4).');

            return self::SUCCESS;
        }

        // ── Chemin d'action (--confirm) : gardes root + extinction ───────────
        if (! $this->ensureRoot()) {
            return self::FAILURE;
        }

        if (! $this->ensureLegacyPathConfigured()) {
            return self::FAILURE;
        }

        if (is_dir($this->legacyPath())) {
            $this->error(sprintf(
                'Refusé : %s existe encore — l\'extinction à blanc SE4 n\'est pas en place (SE4 lit encore OU=Parcs via gpo/applications.php).',
                $this->legacyPath(),
            ));

            return self::FAILURE;
        }

        $vhost = $this->vhostEnabled();
        if ($vhost === null) {
            $this->error('Impossible de déterminer l\'état du vhost (a2query introuvable) — abandon.');

            return self::FAILURE;
        }
        if ($vhost) {
            $this->error('Refusé : le vhost sambaedu-legacy est encore actif — l\'extinction à blanc n\'est pas en place.');

            return self::FAILURE;
        }

        if ($targets === []) {
            $this->info('Rien à purger.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $errors = 0;
        $first = true;

        foreach ($targets as $entry) {
            $dn = $entry->getDn();

            try {
                $entry->delete();
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  ✗ {$dn} : {$e->getMessage()}");
                $first = false;
                continue;
            }

            // Vérification post-suppression : un delete() qui « réussit » sans
            // droit d'écriture laisse l'objet en place (piège du faux succès,
            // cf. [[project_sysvol_wwwadmin_no_write_rights_and_silent_success]]).
            if ($this->stillExists($dn)) {
                if ($first) {
                    $this->error('Refusé : la suppression n\'a eu aucun effet — le compte de service n\'a pas les droits d\'écriture sur OU=Parcs. Abandon (aucun faux succès).');

                    return self::FAILURE;
                }
                $errors++;
                $this->error("  ✗ {$dn} : suppression sans effet (droits d'écriture ?)");
                $first = false;
                continue;
            }

            $deleted++;
            $first = false;
            $this->line("  ✓ CN supprimé : {$dn}");
            Log::info('[se4:prune-ad-parcs] CN purgé', ['dn' => $dn]);
        }

        $this->newLine();
        $this->info(sprintf('Purge terminée : %d supprimé(s), %d erreur(s).', $deleted, $errors));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Un `CN` est-il toujours présent dans l'AD après suppression ? (détection du
     * faux succès). Isolé pour rester testable.
     */
    protected function stillExists(string $dn): bool
    {
        return DeviceGroupTagModel::find($dn) !== null;
    }
}

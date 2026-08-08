<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Services\Filesystem\Acl\AclInspectionService;
use App\Services\Filesystem\NetworkShareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Epic 34 (reprise legacy `acls/`) — IMPORT one-shot d'un répertoire legacy vers
 * un lecteur réseau géré : matérialise les entrées ACL MAPPABLES en assignations
 * (`network_share_assignables`), puis reconverge le disque via
 * {@see NetworkShareService::provision()}.
 *
 * **Migration, pas capacité vivante.** FS→SQL ne se justifie qu'une fois : après
 * import, le SQL est autoritaire et le disque redevient une projection. La
 * commande capture donc l'INTENTION D'ACCÈS (qui a ro/rw), pas l'emplacement :
 * elle crée un NOUVEAU répertoire managé `/var/sambaedu/Partages/<directory>` —
 * elle NE déplace PAS le contenu du dossier legacy inspecté (migration de
 * données = hors périmètre, à traiter séparément).
 *
 * **Fail-closed.** Les entrées non-mappables (principal inconnu, `other::`
 * ouvert, exécution seule…) sont IGNORÉES et LISTÉES — jamais devinées. En mode
 * `--strict`, leur présence bloque l'import (exit 2) tant qu'elles n'ont pas été
 * arbitrées.
 *
 * Sécurité : dry-run par DÉFAUT. Rien n'est écrit sans `--apply`.
 *
 * Exit codes : 0 = ok (ou dry-run) ; 1 = erreur (path illisible / collision de
 * nom / provisioning KO) ; 2 = `--strict` et des entrées non-mappables subsistent.
 */
class SharesImportFromFsCommand extends Command
{
    protected $signature = 'shares:import-from-fs
        {path : Répertoire legacy absolu sous /var/sambaedu à importer}
        {--name= : Nom affiché du lecteur (défaut : nom du dossier)}
        {--directory-name= : Nom de répertoire managé sous Partages/ (défaut : nom du dossier, assaini)}
        {--apply : Écrit réellement (sinon dry-run : rien n\'est modifié)}
        {--strict : Échoue (exit 2) si des entrées non-mappables subsistent}
        {--performed-by= : Auteur inscrit dans quota_audit_logs (défaut : shares:import-from-fs)}';

    protected $description = 'Importe les ACL POSIX d\'un répertoire legacy en assignations d\'un lecteur réseau géré (dry-run par défaut ; --apply pour écrire).';

    protected $help = <<<'HELP'
    Importe les droits d'accès d'un répertoire hérité du serveur SE4 sous forme de
    lecteur réseau géré : les entrées d'ACL exploitables deviennent des assignations,
    puis le disque est reprovisionné à partir de la base.

      <info>php artisan shares:import-from-fs /var/sambaedu/MonDossier</info>            aperçu
      <info>php artisan shares:import-from-fs /var/sambaedu/MonDossier --apply</info>    écrit

    <comment>Ce qui est importé, c'est l'INTENTION D'ACCÈS — qui a lecture, qui a écriture —
    pas l'emplacement.</comment> La commande crée un NOUVEAU répertoire géré sous
    <info>Partages/</info> et NE DÉPLACE PAS le contenu du dossier d'origine. Le déplacement
    des données est une opération distincte, à mener séparément.

    Les entrées non exploitables — utilisateur inconnu, accès ouvert à tous, droit
    d'exécution seul — sont IGNORÉES et LISTÉES, jamais devinées. Utilisez
    <comment>--strict</comment> pour que leur présence fasse échouer l'import plutôt que de le
    laisser passer incomplet.

    Import de migration : une fois fait, c'est la base qui fait autorité et le disque
    n'en est plus que la projection.
    HELP;

    public function handle(AclInspectionService $inspection, NetworkShareService $shareService): int
    {
        $path = (string) $this->argument('path');
        $apply = (bool) $this->option('apply');
        $strict = (bool) $this->option('strict');
        $performedBy = (string) ($this->option('performed-by') ?: 'shares:import-from-fs');

        if (! preg_match('/^[a-zA-Z0-9._:-]+$/', $performedBy)) {
            $this->error('--performed-by invalide (regex /^[a-zA-Z0-9._:-]+$/).');

            return self::FAILURE;
        }

        if (! $inspection->validateInspectPath($path)) {
            $this->error(sprintf('Path refusé : "%s" (absolu sous %s, sans « .. »).', $path, $inspection->inspectRoot()));

            return self::FAILURE;
        }

        $result = $inspection->inspect($path);
        if ($result === null) {
            $this->error(sprintf('Lecture des ACL impossible sur "%s" (voir logs).', $path));

            return self::FAILURE;
        }

        // Dérive nom affiché + nom de répertoire managé depuis le basename.
        $basename = basename($path);
        $name = (string) ($this->option('name') ?: $basename);
        $directoryName = (string) ($this->option('directory-name') ?: $this->sanitizeDirectoryName($basename));

        if (! $shareService->isValidDirectoryName($directoryName)) {
            $this->error(sprintf(
                'Nom de répertoire managé invalide : "%s". Précisez --directory-name (alphanum + ._- , ne commence pas par « . »).',
                $directoryName,
            ));

            return self::FAILURE;
        }

        $collision = NetworkShare::where('directory_name', $directoryName)->first();
        if ($collision !== null) {
            $this->error(sprintf(
                'Un lecteur réseau utilise déjà le répertoire "%s" (#%d « %s »). Précisez --directory-name.',
                $directoryName,
                $collision->id,
                $collision->name,
            ));

            return self::FAILURE;
        }

        // --- Rapport de classification ---------------------------------------
        $this->info(sprintf('Import depuis : %s', $path));
        $this->line(sprintf('  → lecteur managé : « %s »  (Partages/%s)', $name, $directoryName));
        $this->newLine();

        if ($result['mappable'] === []) {
            $this->warn('Aucune entrée mappable : rien à importer.');
        } else {
            $this->line('<fg=green;options=bold>Assignations à créer :</>');
            $this->table(
                ['Type', 'Cible', 'Accès'],
                array_map(fn (array $m): array => [
                    $m['target_type'] === \App\Models\User::class ? 'Utilisateur' : 'Groupe',
                    $m['label'],
                    strtoupper($m['access']),
                ], $result['mappable']),
            );
        }

        if ($result['unmappable'] !== []) {
            $this->newLine();
            $this->warn(sprintf('%d entrée(s) NON mappable(s) — ignorée(s) :', count($result['unmappable'])));
            $this->table(
                ['ACL disque', 'Motif'],
                array_map(fn (array $u): array => [$u['raw'], $u['reason']], $result['unmappable']),
            );

            if ($strict) {
                $this->newLine();
                $this->error('--strict : des entrées non-mappables subsistent. Arbitrez-les avant d\'importer.');

                return 2;
            }
        }

        $this->newLine();

        // --- Dry-run : on s'arrête ici --------------------------------------
        if (! $apply) {
            $this->info('[DRY-RUN] Aucune modification. Relancez avec --apply pour créer le lecteur et provisionner.');

            return self::SUCCESS;
        }

        // --- Application ------------------------------------------------------
        $share = DB::transaction(function () use ($name, $directoryName, $result, $performedBy): NetworkShare {
            $share = NetworkShare::create([
                'name' => $name,
                'directory_name' => $directoryName,
                'created_by_user_id' => null,
            ]);

            foreach ($result['mappable'] as $m) {
                NetworkShareAssignable::create([
                    'network_share_id' => $share->id,
                    'assignable_type' => $m['target_type'],
                    'assignable_id' => $m['target_id'],
                    'access' => $m['access'],
                ]);
            }

            return $share;
        });

        $ok = $shareService->provision($share, $performedBy);

        $this->info(sprintf(
            'Lecteur « %s » (#%d) créé avec %d assignation(s).',
            $share->name,
            $share->id,
            count($result['mappable']),
        ));

        if ($ok) {
            $this->info(sprintf('Provisionné : le répertoire %s a été (re)créé avec les ACL dérivées du SQL.', $share->directory_name));
        } else {
            $this->warn('Le provisioning a échoué (voir logs). Les assignations SQL sont bien enregistrées ; relancez le provisioning depuis l\'UI ou corrigez le serveur.');

            return self::FAILURE;
        }

        $this->line('<fg=gray>Note : seul l\'accès (qui/ro/rw) a été importé. Le CONTENU du dossier legacy inspecté n\'a pas été déplacé.</>');

        return self::SUCCESS;
    }

    /**
     * Assainit un basename legacy en `directory_name` sûr : retire le préfixe
     * `Classe_` éventuel, remplace tout caractère hors `[A-Za-z0-9._-]` par `_`,
     * et garantit un 1er caractère ≠ `.`.
     */
    private function sanitizeDirectoryName(string $basename): string
    {
        $name = preg_replace('/^Classe_/i', '', $basename) ?? $basename;
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? $name;
        $name = ltrim($name, '.');

        return $name === '' ? 'import' : $name;
    }
}

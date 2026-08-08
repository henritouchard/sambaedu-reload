<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Filesystem\Acl\AclInspectionService;
use Illuminate\Console\Command;

/**
 * Epic 34 (reprise legacy `acls/`) — INSPECTION read-only des ACL POSIX d'un
 * répertoire legacy, avec classification vers le modèle de lecteurs réseau
 * managés.
 *
 * C'est l'analogue SE5 (non destructif, scriptable) du `visuacls.php` legacy :
 * là où l'ancien affichait `getfacl` en cases à cocher pour édition manuelle,
 * cette commande LIT l'état effectif et RÉPOND à la question « qu'est-ce qui
 * serait importable dans un `NetworkShare` ? » — elle est le dry-run partagé de
 * `shares:import-from-fs`.
 *
 * Aucune écriture (ni ACL, ni SQL). Trois seaux en sortie :
 *  - MAPPABLE   : deviendrait une assignation `user`/`group` (`ro`/`rw`) ;
 *  - STRUCTUREL : base canonique (owner/group/other/mask/domain admins) — ignoré ;
 *  - NON MAPPABLE : principal inconnu, `other::` ouvert, exécution seule, … —
 *                 SIGNALÉ (jamais importé silencieusement).
 *
 * Exit codes : 0 = lu et classé (même si tout est non-mappable) ; 1 = path
 * refusé / illisible (`getfacl` en échec).
 */
class SharesInspectFsCommand extends Command
{
    protected $signature = 'shares:inspect-fs
        {path : Répertoire absolu sous /var/sambaedu à inspecter}
        {--json : Sortie JSON brute (pour scripting) au lieu du tableau lisible}';

    protected $description = 'Inspecte (read-only) les ACL POSIX d\'un répertoire legacy et classe ce qui serait importable en lecteur réseau géré.';

    protected $help = <<<'HELP'
    Lit les droits POSIX d'un répertoire et dit ce qui serait importable en lecteur
    réseau géré. <comment>Aucune écriture</comment> — ni sur le disque, ni en base.

      <info>php artisan shares:inspect-fs /var/sambaedu/MonDossier</info>
      <info>php artisan shares:inspect-fs /var/sambaedu/MonDossier --json</info>

    Le résultat range chaque entrée dans l'une de trois catégories :

      <comment>exploitable</comment>    deviendrait une assignation, en lecture ou en écriture ;
      <comment>structurel</comment>     socle canonique (propriétaire, groupe, masque) — ignoré ;
      <comment>non exploitable</comment> utilisateur inconnu, accès ouvert à tous, exécution seule…

    C'est l'aperçu de <info>shares:import-from-fs</info>, et l'outil à dégainer pour
    répondre à « qu'y a-t-il vraiment sur ce dossier ? » sans rien risquer.
    HELP;

    public function handle(AclInspectionService $service): int
    {
        $path = (string) $this->argument('path');

        if (! $service->validateInspectPath($path)) {
            $this->error(sprintf(
                'Path refusé : "%s". Attendu : absolu, sous %s, sans « .. », profondeur raisonnable.',
                $path,
                $service->inspectRoot(),
            ));

            return self::FAILURE;
        }

        $result = $service->inspect($path);
        if ($result === null) {
            $this->error(sprintf('Lecture des ACL impossible sur "%s" (dossier absent ou getfacl en échec — voir logs).', $path));

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['path' => $path] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($path, $result);

        return self::SUCCESS;
    }

    /**
     * @param  array{structural: list<array{raw: string, note: string}>, mappable: list<array{target_type: string, target_id: int, label: string, access: string, raw: string}>, unmappable: list<array{raw: string, reason: string}>}  $result
     */
    private function renderHuman(string $path, array $result): void
    {
        $this->info("Inspection ACL : {$path}");
        $this->newLine();

        $this->line('<fg=green;options=bold>MAPPABLE</> — deviendrait une assignation du lecteur réseau :');
        if ($result['mappable'] === []) {
            $this->line('  (aucune)');
        } else {
            $rows = array_map(fn (array $m): array => [
                $m['target_type'] === \App\Models\User::class ? 'Utilisateur' : "Groupe",
                $m['label'],
                strtoupper($m['access']),
                $m['raw'],
            ], $result['mappable']);
            $this->table(['Type', 'Cible', 'Accès', 'ACL disque'], $rows);
        }
        $this->newLine();

        if ($result['unmappable'] !== []) {
            $this->line('<fg=yellow;options=bold>NON MAPPABLE</> — signalé, JAMAIS importé silencieusement :');
            $rows = array_map(fn (array $u): array => [$u['raw'], $u['reason']], $result['unmappable']);
            $this->table(['ACL disque', 'Motif'], $rows);
            $this->newLine();
        }

        $this->line('<fg=gray>STRUCTUREL — base canonique, ignorée (' . count($result['structural']) . ' entrée(s)).</>');
        $this->newLine();

        $this->info(sprintf(
            'Bilan : %d mappable(s), %d non-mappable(s), %d structurelle(s). Aucune modification appliquée.',
            count($result['mappable']),
            count($result['unmappable']),
            count($result['structural']),
        ));

        if ($result['mappable'] !== []) {
            $this->line('→ Pour matérialiser : <options=bold>php artisan shares:import-from-fs ' . $path . ' --dry-run</>');
        }
    }
}

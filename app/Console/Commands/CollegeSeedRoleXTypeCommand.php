<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GroupTypeRole;
use App\Support\RoleCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Story 62.3 — LE PROFIL SCOLAIRE s'INSTALLE : « Élève », « Enseignant »,
 * « Professeur principal », « Porteur », « Référent ».
 *
 * **Pourquoi une commande, et pas la migration.** Ces sept lignes étaient posées
 * par `2026_08_08_180000_create_group_type_roles_table`. Deux raisons de les en
 * sortir, et aucune n'est cosmétique :
 *
 *  1. **une déclaration RESTREINT.** {@see RoleCatalog::assignableKeys()} rend TOUT
 *     le catalogue à un type SANS déclaration, et SEULEMENT les rôles déclarés à un
 *     type qui en a. Poser ces lignes FERME `classe`, `projet` et `equipe` et laisse
 *     ouverts tous les autres types. C'est iso aujourd'hui — trois rôles au
 *     catalogue, et la règle D3 bloque déjà `owner` hors classe — mais au premier
 *     rôle personnalisé créé par un administrateur, celui-ci serait attribuable
 *     partout SAUF dans les trois types les plus utilisés. Fermer un type est une
 *     décision ; une migration ne la prend pas à la place de personne ;
 *  2. **SE5 est multi-vertical.** Une instance fraîche sans rapport avec une école
 *     n'a aucune raison de recevoir « Élève » et « Professeur principal ».
 *
 * **Additive par défaut, et c'est le contrat.** Une déclaration déjà présente est
 * LAISSÉE EN PLACE, libellé compris : un administrateur a pu le changer depuis
 * l'onglet « Types de groupes », et le sien fait foi. `--resync` est le SEUL chemin
 * qui écrase, et il faut le taper.
 *
 * **Elle remplace `GroupTypeRoleSeeder`**, supprimé : deux entrées pour un même
 * geste, l'une jouée sans y penser par `db:seed` (donc sur toute instance neuve,
 * scolaire ou non) et l'autre écrasant les libellés locaux sans confirmation, ne
 * pouvaient pas coexister.
 *
 * Idempotente, rejouable. Elle n'écrit RIEN hors de `group_type_roles` : ni
 * appartenance, ni groupe, ni catalogue.
 */
final class CollegeSeedRoleXTypeCommand extends Command
{
    protected $signature = 'college:seed:role-x-type
        {--resync : Réapplique les libellés de référence sur les déclarations DÉJÀ présentes — SEUL chemin qui écrase un libellé local}';

    protected $description = 'Installe le profil de vocabulaire scolaire (rôles déclarés par type de groupe) — additive et idempotente.';

    protected $help = <<<'HELP'
    Installe le vocabulaire SCOLAIRE des rôles d'appartenance : quels rôles ont un sens
    dans une classe, un projet, une équipe, et comment ils s'y disent.

      <info>php artisan college:seed:role-x-type</info>            installe ce qui manque
      <info>php artisan college:seed:role-x-type --resync</info>   réaligne aussi les libellés

    Sept déclarations sont posées :

      <comment>classe</comment>   Élève / Enseignant / Professeur principal
      <comment>projet</comment>   Membre (libellé du catalogue) / Porteur
      <comment>equipe</comment>   Membre (libellé du catalogue) / Référent

    Par défaut la commande est ADDITIVE : une déclaration déjà présente est laissée
    telle quelle, libellé compris — un libellé changé depuis l'écran des types fait foi.
    <comment>--resync</comment> réapplique les libellés de référence sur les lignes existantes ;
    c'est le seul chemin qui écrase, et il est explicite.

    ⚠️ Déclarer des rôles sur un type le FERME : seuls les rôles déclarés y sont ensuite
    attribuables. Un type sans aucune déclaration garde, lui, tout le catalogue.
    HELP;

    /**
     * Les SEPT déclarations du profil scolaire.
     *
     * Valeurs IDENTIQUES à celles que la migration posait — c'est ce qui rend la
     * parité d'affichage des stories 62.1/62.3 restaurable d'un geste, et ce que
     * les suites de parité installent désormais explicitement dans leur `setUp()`.
     *
     * Les deux `label` à `null` ne sont pas un oubli : `projet`×`member` et
     * `equipe`×`member` sont DÉCLARÉS SANS SURCHARGE (ils se lisent « Membre », le
     * libellé du catalogue). Les omettre rendrait `member` — le rôle par défaut de
     * TOUT rattachement — inattribuable dans le moindre projet dès la première
     * déclaration.
     *
     * `owner` n'est déclaré QUE sur `classe` : c'est la donnée qui dit ce que la
     * garde D3 (« professeur principal ⇒ classe ») dit en littéral dans les écrans,
     * et {@see GroupTypeRole::assertOwnerStaysOnClasse()} refuserait l'inverse.
     *
     * @var list<array{group_type_key: string, group_role_key: string, label: ?string}>
     */
    private const DECLARATIONS = [
        ['group_type_key' => 'classe', 'group_role_key' => 'member', 'label' => 'Élève'],
        ['group_type_key' => 'classe', 'group_role_key' => 'manager', 'label' => 'Enseignant'],
        ['group_type_key' => 'classe', 'group_role_key' => 'owner', 'label' => 'Professeur principal'],
        ['group_type_key' => 'projet', 'group_role_key' => 'member', 'label' => null],
        ['group_type_key' => 'projet', 'group_role_key' => 'manager', 'label' => 'Porteur'],
        ['group_type_key' => 'equipe', 'group_role_key' => 'member', 'label' => null],
        ['group_type_key' => 'equipe', 'group_role_key' => 'manager', 'label' => 'Référent'],
    ];

    public function handle(): int
    {
        if (! $this->tableIsReady()) {
            $this->error(
                'Refusé : la table des déclarations « group_type_roles » n\'existe pas. '
                . 'Jouez d\'abord « php artisan migrate », puis relancez cette commande.',
            );

            return self::FAILURE;
        }

        $resync = (bool) $this->option('resync');

        $rows = [];
        $created = 0;
        $kept = 0;
        $realigned = 0;

        foreach (self::DECLARATIONS as $declaration) {
            $existing = GroupTypeRole::query()
                ->where('group_type_key', $declaration['group_type_key'])
                ->where('group_role_key', $declaration['group_role_key'])
                ->first();

            try {
                [$action, $counter] = $existing === null
                    ? [$this->create($declaration), 'created']
                    : $this->reconcile($existing, $declaration, $resync);
            } catch (InvalidArgumentException $e) {
                // Garde du modèle : type absent du catalogue, rôle inconnu,
                // `owner` hors classe. On NOMME la paire fautive plutôt que de
                // laisser remonter une trace — et on s'arrête : ce qui a déjà été
                // posé est valide, un rejeu reprendra le reste.
                $this->error(sprintf(
                    'Refusé sur « %s × %s » : %s',
                    $declaration['group_type_key'],
                    $declaration['group_role_key'],
                    $e->getMessage(),
                ));

                return self::FAILURE;
            }

            match ($counter) {
                'created' => $created++,
                'realigned' => $realigned++,
                default => $kept++,
            };

            $rows[] = [
                $declaration['group_type_key'],
                $declaration['group_role_key'],
                $this->render($declaration['label']),
                $action,
            ];
        }

        // La résolution est mémoïsée : sans ce vidage, une lecture faite dans le
        // MÊME processus (test, tinker, chaînage de commandes) continuerait de lire
        // la carte d'avant.
        RoleCatalog::flush();

        $this->table(['Type', 'Rôle', 'Libellé de référence', 'Action'], $rows);

        $this->info(sprintf(
            'Profil scolaire installé : %d créée(s), %d laissée(s) en place, %d réalignée(s)%s.',
            $created,
            $kept,
            $realigned,
            $resync ? '' : ' (relancer avec --resync pour réaligner les libellés existants)',
        ));

        $this->newLine();
        $this->warn(
            'Déclarer des rôles sur un type le FERME : à partir de maintenant, seuls les rôles déclarés '
            . 'sont attribuables dans « classe », « projet » et « equipe ». Un rôle du catalogue qui n\'y '
            . 'est pas déclaré — y compris un rôle personnalisé créé plus tard — n\'y sera plus proposé ; '
            . 'ajoutez-le depuis l\'onglet « Types de groupes » des réglages. Les types SANS aucune '
            . 'déclaration, eux, gardent tout le catalogue.',
        );

        return self::SUCCESS;
    }

    /**
     * La table existe-t-elle, et est-elle interrogeable ?
     *
     * `Schema::hasTable()` suffit sur une base saine ; l'enveloppe couvre le cas
     * « pas de connexion du tout », qui doit refuser aussi proprement qu'une
     * migration non jouée plutôt que de remonter une trace de pilote.
     */
    private function tableIsReady(): bool
    {
        try {
            return Schema::hasTable('group_type_roles');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array{group_type_key: string, group_role_key: string, label: ?string}  $declaration
     */
    private function create(array $declaration): string
    {
        GroupTypeRole::create($declaration);

        return 'créée';
    }

    /**
     * Une déclaration DÉJÀ présente : laissée en place, ou réalignée si `--resync`.
     *
     * @param  array{group_type_key: string, group_role_key: string, label: ?string}  $declaration
     * @return array{0: string, 1: string}
     */
    private function reconcile(GroupTypeRole $existing, array $declaration, bool $resync): array
    {
        $local = $existing->label === null ? null : (string) $existing->label;

        if ($local === $declaration['label']) {
            return ['déjà conforme', 'kept'];
        }

        if (! $resync) {
            // ADDITIVE : le libellé local fait foi. C'est le contrat de la
            // commande, et la contradiction que la review 62.3 #2 relevait entre
            // la migration (« on ne réécrit jamais un libellé local ») et le
            // seeder (qui le réécrivait) est close ici — il n'y a plus qu'un
            // geste, et il ne réécrit rien sans qu'on le lui demande.
            return [sprintf('laissée en place (« %s »)', $this->render($local)), 'kept'];
        }

        $existing->label = $declaration['label'];
        $existing->save();

        return [
            sprintf('réalignée (« %s » → « %s »)', $this->render($local), $this->render($declaration['label'])),
            'realigned',
        ];
    }

    /** Un `null` n'est pas un vide : c'est « déclaré sans surcharge ». */
    private function render(?string $label): string
    {
        return $label ?? '— (libellé du catalogue)';
    }
}

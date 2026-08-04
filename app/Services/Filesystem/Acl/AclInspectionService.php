<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Acl;

use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\Backend\Posix\PosixSubjectProjector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Inspection read-only des ACL POSIX d'un répertoire legacy, et CLASSIFICATION
 * de chaque entrée vers le modèle managé (`network_share_assignables`).
 *
 * **Rôle.** Socle commun de deux usages (Epic 34 — reprise du legacy `acls/`) :
 *  1. `shares:inspect-fs` — diagnostic pur : « qu'y a-t-il sur ce dossier, et
 *     qu'est-ce qui serait importable ? » (répond à la question du gisement).
 *  2. `shares:import-from-fs` — matérialise les entrées MAPPABLES en assignations.
 *
 * **Direction de la vérité.** L'import FS→SQL n'a de sens qu'en MIGRATION
 * one-shot : une fois l'intention d'accès capturée en SQL (autoritaire), le
 * disque redevient une projection ({@see \App\Services\Filesystem\NetworkShareService::provision()}). Ce
 * service ne fait donc AUCUNE écriture ACL — il lit (`getfacl`) et classe.
 *
 * **Mapping inverse robuste.** Le nom de groupe Unix disque
 * (`equipe_3sb-1229y`) est ré-associé à son {@see UserGroup} par
 * FORWARD-PROJECTION : on projette chaque UserGroup candidat via
 * {@see PosixSubjectProjector::unixGroupForGroup()} et on indexe le résultat →
 * aucun strip fragile du suffixe établissement (mémoire
 * acl_equipe_group_missing_etab_suffix).
 *
 * **Fail-closed.** Toute entrée qu'on ne sait pas mapper 1:1 vers le modèle
 * réduit (`user`/`group` × `ro`/`rw`) tombe dans `unmappable` AVEC un motif —
 * jamais de sur-octroi ni de perte silencieuse (l'`other::rwx`, l'exécution
 * seule, un principal AD non tracké, etc. sont signalés, pas devinés).
 */
class AclInspectionService
{
    /**
     * Racine legacy des partages Samba SE4 (`$chemininit` du legacy `acls.php`).
     * Overridable en tests (iso `AclService::$classesRoot`).
     */
    public static string $root = '/var/sambaedu';

    /**
     * Profondeur maximale sous {@see inspectRoot()}. Généreuse (les ACL legacy
     * vivent aussi bien sur `Classes/Classe_x/_echange` que sur des homes) mais
     * bornée (anti-parcours d'arbre non maîtrisé).
     */
    private const MAX_DEPTH = 8;

    /**
     * Sujets d'ACL STRUCTURELS (base canonique) : ni importés, ni signalés comme
     * perte. `domain admins` est le garde-fou admin de tout partage managé.
     */
    private const STRUCTURAL_NAMED = ['domain\040admins', 'domain admins'];

    public function __construct(private readonly PosixSubjectProjector $projector)
    {
    }

    public function inspectRoot(): string
    {
        return rtrim((string) config('filesystem.acl_inspect_root', static::$root), '/');
    }

    /**
     * Garde de path (anti-traversal) calquée sur `AclService::validatePath`,
     * paramétrée sur {@see inspectRoot()} et {@see MAX_DEPTH}. Read-only mais on
     * durcit quand même (le path est interpolé dans un shell-out `getfacl`).
     */
    public function validateInspectPath(string $path): bool
    {
        $root = $this->inspectRoot();

        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        if (! str_starts_with($path, $root . '/') && $path !== $root) {
            return false;
        }
        if (! preg_match('#^/[A-Za-z0-9_./-]+$#', $path)) {
            return false;
        }

        $segments = $path === $root
            ? []
            : explode('/', trim(substr($path, strlen($root) + 1), '/'));
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '..' || $seg === '.') {
                return false;
            }
        }

        return count($segments) <= self::MAX_DEPTH;
    }

    /**
     * Lit et parse l'ACL effective d'un répertoire. `null` si path refusé ou
     * `getfacl` en échec (dossier absent, droits, …).
     *
     * @return list<array{default: bool, type: string, qualifier: ?string, mode: string, raw: string}>|null
     */
    public function readAcl(string $path): ?array
    {
        if (! $this->validateInspectPath($path)) {
            Log::error('AclInspectionService: readAcl path invalide', ['path' => $path]);

            return null;
        }

        $cmd = sprintf('sudo getfacl -c -E -p %s', escapeshellarg($path));
        $r = Process::run($cmd);
        if (! $r->successful()) {
            Log::warning('AclInspectionService: getfacl en échec', [
                'path' => $path,
                'output' => trim($r->errorOutput() ?: $r->output()),
            ]);

            return null;
        }

        return AclFormat::parseEntries($r->output());
    }

    /**
     * Classe les entrées ACL d'un répertoire en trois seaux. Ignore les entrées
     * `default:` (héritage) : le modèle managé dérive ses propres défauts miroir
     * à la reconvergence — on n'importe QUE l'intention d'accès (entrées d'accès).
     *
     * @param  list<array{default: bool, type: string, qualifier: ?string, mode: string, raw: string}>  $entries
     * @return array{
     *   structural: list<array{raw: string, note: string}>,
     *   mappable: list<array{target_type: class-string, target_id: int, label: string, access: string, raw: string}>,
     *   unmappable: list<array{raw: string, reason: string}>,
     * }
     */
    public function classify(array $entries): array
    {
        $groupIndex = $this->buildGroupIndex();

        $structural = [];
        $mappable = [];
        $unmappable = [];

        foreach ($entries as $e) {
            // On n'importe pas l'héritage : le provisioning managé pose ses
            // propres `default:` miroir. Une entrée default est purement
            // structurelle du point de vue de l'import.
            if ($e['default']) {
                continue;
            }

            $type = $e['type'];
            $qualifier = $e['qualifier'];
            $mode = $e['mode'];

            // Entrées de base (owner/owning-group/other/mask) : structurelles.
            if ($qualifier === null || $type === 'other' || $type === 'mask') {
                $note = ($type === 'other' && $mode !== '---')
                    ? 'other:: accorde un accès (non représentable — le modèle force other:---)'
                    : 'entrée de base';
                $structural[] = ['raw' => $e['raw'], 'note' => $note];
                continue;
            }

            // `domain admins` = garde-fou admin canonique : structurel.
            if ($type === 'group' && in_array(strtolower($qualifier), self::STRUCTURAL_NAMED, true)) {
                $structural[] = ['raw' => $e['raw'], 'note' => 'garde-fou admin (canonique)'];
                continue;
            }

            $access = AclFormat::modeToAccess($mode);
            if ($access === null) {
                $unmappable[] = [
                    'raw' => $e['raw'],
                    'reason' => $mode === '---'
                        ? 'aucun accès effectif (entrée vide)'
                        : "mode « {$mode} » non représentable (ni ro ni rw)",
                ];
                continue;
            }

            if ($type === 'user') {
                $login = AclFormat::unescape($qualifier);
                $user = User::where('login', $login)->first();
                if ($user === null) {
                    $unmappable[] = ['raw' => $e['raw'], 'reason' => "utilisateur « {$login} » introuvable en base"];
                    continue;
                }
                $mappable[] = [
                    'target_type' => User::class,
                    'target_id' => (int) $user->id,
                    'label' => $login,
                    'access' => $access,
                    'raw' => $e['raw'],
                ];
                continue;
            }

            if ($type === 'group') {
                $unix = strtolower(AclFormat::unescape($qualifier));
                $group = $groupIndex[$unix] ?? null;
                if ($group === null) {
                    $unmappable[] = ['raw' => $e['raw'], 'reason' => "groupe « {$unix} » non rattaché à un UserGroup connu"];
                    continue;
                }
                $mappable[] = [
                    'target_type' => UserGroup::class,
                    'target_id' => (int) $group->id,
                    'label' => (string) ($group->display_name ?: $group->name),
                    'access' => $access,
                    'raw' => $e['raw'],
                ];
                continue;
            }

            $unmappable[] = ['raw' => $e['raw'], 'reason' => "type d'entrée « {$type} » non géré"];
        }

        return [
            'structural' => $structural,
            'mappable' => $mappable,
            'unmappable' => $unmappable,
        ];
    }

    /**
     * Inspecte un path : lit + classe en une passe. `null` si path illisible.
     *
     * @return array{
     *   structural: list<array{raw: string, note: string}>,
     *   mappable: list<array{target_type: class-string, target_id: int, label: string, access: string, raw: string}>,
     *   unmappable: list<array{raw: string, reason: string}>,
     * }|null
     */
    public function inspect(string $path): ?array
    {
        $entries = $this->readAcl($path);

        return $entries === null ? null : $this->classify($entries);
    }

    /**
     * Index INVERSE `nom Unix disque (lowercased) → UserGroup`, par
     * forward-projection de chaque groupe via
     * {@see PosixSubjectProjector::unixGroupForGroup()}. En cas de collision (deux
     * groupes projetant le même nom Unix), le PREMIER gagne et un warning est
     * tracé — situation anormale à investiguer, jamais un choix silencieux.
     *
     * @return array<string, UserGroup>
     */
    private function buildGroupIndex(): array
    {
        $index = [];
        foreach (UserGroup::query()->get() as $group) {
            $unix = $this->projector->unixGroupForGroup($group);
            if ($unix === null) {
                continue;
            }
            $key = strtolower($unix);
            if (isset($index[$key])) {
                Log::warning('AclInspectionService: collision de nom Unix dans l\'index inverse', [
                    'unix' => $key,
                    'kept_group_id' => $index[$key]->id,
                    'ignored_group_id' => $group->id,
                ]);
                continue;
            }
            $index[$key] = $group;
        }

        return $index;
    }
}

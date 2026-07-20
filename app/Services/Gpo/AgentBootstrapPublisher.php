<?php

declare(strict_types=1);

namespace App\Services\Gpo;

use App\Gpo\Services\GpoService;
use App\Gpo\Support\GpoTemplateRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Story 27.16 — Déploiement automatisé de la GPO-dispatcher figée d'amorçage
 * agent `SE_agent_bootstrap` (ex-`se4_agent_bootstrap`, 25.4).
 *
 * Automatise le Fork 2 manuel de la 25.4 en surmontant le blocage qui avait
 * justifié le choix manuel : **les droits SYSVOL**. Le user PHP-FPM
 * (`www-sambaedu`/`www-admin`) n'a que READ sur SYSVOL ; un `smbclient put` y
 * sort en exit 0 SANS rien écrire (faux succès — mémoires
 * `project_sysvol_wwwadmin_no_write_rights_and_silent_success`,
 * `project_sysvol_write_needs_wwwadmin_kinit`). Ce service :
 *
 *  1. **garde fail-soft** : `admin_passwd` absent ou DC injoignable → skip propre ;
 *  2. **stage** le template versionné `resources/gpo/SE_agent_bootstrap/` vers
 *     `templates_dir` (forme répertoire `sambaedu-gpo/<name>/`) ;
 *  3. établit un **contexte Kerberos Administrator**
 *     ({@see AdministratorKerberosContext}) ;
 *  4. **publie NATIVEMENT** via {@see NativeGpoPublisher} (port d'`import_gpo`) —
 *     Story 38.4 : plus AUCUN `require` du legacy `/var/www/sambaedu`. Le
 *     publisher CRÉE la GPC si absente ({@see GpoService::create}), écrit SYSVOL
 *     ({@see NativeGpoPublisher}), pose `gPCMachineExtensionNames` + `versionNumber`
 *     via LDAP et incrémente la version (GPT.INI CRLF) ;
 *  5. **vérifie l'écriture réelle** ({@see verifyRealWrite}) → un `ACCESS_DENIED`
 *     masqué en exit 0 devient un échec EXPLICITE ;
 *  6. **isole** : neutralise tout lien racine, bloque l'héritage sur l'OU
 *     computers de NOTRE établissement + lie `SE_agent_bootstrap` à cette même
 *     OU (JAMAIS la racine — AD mutualisé entre ~75 collèges) via {@see GpoService} ;
 *  7. **bloque symétriquement l'OU des comptes** ({@see blockUsersOuInheritance},
 *     fail-soft) : les deux moitiés d'une GPO (machine / utilisateur) héritent
 *     par des chemins distincts, si bien qu'un blocage côté postes laissait
 *     passer TOUTES les stratégies utilisateur des GPO de domaine (lecteurs
 *     réseau, redirections, imprimantes, scripts de logon), en concurrence avec
 *     les capacités natives de l'agent. Là encore : neutralisation CHEZ NOUS,
 *     jamais de modification de la GPO partagée.
 *
 * Garde-fou archi : ce service vit sous `App\Services\Gpo`, PAS `App\Gpo`. Les
 * écritures GPO natives (lien + héritage) passent exclusivement par
 * `GpoService` → `SambaToolRunner`. La publication SYSVOL + CSE passe par
 * {@see NativeGpoPublisher} (natif, smbclient sous ccache Administrator).
 *
 * Story 38.4 (Pb11/TD soldée) — la dépendance runtime au shim legacy
 * `import_gpo` (`../sambaedu/includes/gpo.inc.php`) est ÉLIMINÉE : le portage
 * natif prévu à l'extinction du legacy est réalisé ici.
 */
class AgentBootstrapPublisher
{
    /** Nom SE5 (= displayName GPT.INI = basename du template). */
    public const DISPLAY_NAME = 'SE_agent_bootstrap';

    private readonly AdministratorKerberosContext $kerberos;

    private readonly NativeGpoPublisher $nativePublisher;

    public function __construct(
        private readonly GpoService $gpoService,
        private readonly GpoTemplateRegistry $registry,
        ?AdministratorKerberosContext $kerberos = null,
        ?NativeGpoPublisher $nativePublisher = null,
    ) {
        // Résolution paresseuse pour ne pas casser les instanciations à 2 args
        // (tests unitaires 27.16 + sous-classe anonyme de test).
        $this->kerberos = $kerberos ?? app(AdministratorKerberosContext::class);
        $this->nativePublisher = $nativePublisher ?? new NativeGpoPublisher($gpoService);
    }

    /**
     * Déploie le bootstrap de bout en bout. Idempotent et fail-soft.
     *
     * @param  bool  $force    Republier même si la version SYSVOL est à jour.
     * @param  bool  $dryRun   Affiche ce qui serait fait, aucun side effect.
     * @return AgentBootstrapDeployResult
     */
    public function deploy(bool $force = false, bool $dryRun = false): AgentBootstrapDeployResult
    {
        $log = \App\Gpo\Support\GpoLogger::action('gpo.create', context: ['display_name' => self::DISPLAY_NAME, 'force' => $force, 'dry_run' => $dryRun]);
        $operationId = $log->operationId();

        try {
            // --- Garde 1 : creds Administrator disponibles ? -------------------
            if (! $this->kerberos->hasCredentials()) {
                $log->step('skip: admin_passwd absent (creds Administrator requis pour écrire SYSVOL)');
                $log->success(['outcome_kind' => 'skipped', 'reason' => 'missing_admin_password']);

                return AgentBootstrapDeployResult::skipped('admin_passwd absent — publication SYSVOL impossible sans creds Administrator.', $operationId);
            }

            // --- Garde 2 : DC joignable ? -------------------------------------
            if (! $this->isDomainControllerReachable($log)) {
                $log->step('skip: DC AD injoignable (samba-tool listall a échoué)');
                $log->success(['outcome_kind' => 'skipped', 'reason' => 'dc_unreachable']);

                return AgentBootstrapDeployResult::skipped('DC AD injoignable — publication reportée au prochain passage.', $operationId);
            }

            // --- Stage du template versionné vers templates_dir ----------------
            $staged = $this->stageTemplate($log, $dryRun);
            if (! $this->registry->isPublishable(self::DISPLAY_NAME)) {
                throw new RuntimeException(sprintf(
                    'Template %s introuvable/non publiable dans templates_dir (%s) après staging.',
                    self::DISPLAY_NAME,
                    $this->templatesDir(),
                ));
            }

            if ($dryRun) {
                $ouDn = $this->resolveTargetOuDn($log);
                $log->step('[dry-run] publication + blocage héritage + lien NON exécutés', [
                    'staged_to' => $staged,
                    'target_ou_dn' => $ouDn,
                ]);
                $log->success(['outcome_kind' => 'dry-run']);

                return AgentBootstrapDeployResult::dryRun($ouDn, $operationId);
            }

            // --- Publication SYSVOL native sous contexte Administrator ---------
            $this->publishWithAdministratorContext($force, $operationId, $log);

            // --- Vérification d'écriture réelle (anti faux-succès) -------------
            $this->verifyRealWrite($log);

            // --- Résolution du GUID de la GPO publiée --------------------------
            $guid = $this->resolveGpoGuid($log);

            // --- Isolation : neutralisation lien racine + blocage héritage +
            //     lien sur l'OU établissement (jamais la racine, AD mutualisé). --
            $ouDn = $this->resolveTargetOuDn($log);

            return $this->isolateToEstablishmentOu($guid, $ouDn, $operationId, $log);
        } catch (\Throwable $e) {
            $log->failure($e);

            return AgentBootstrapDeployResult::failed($e->getMessage(), $operationId);
        }
    }

    /**
     * Isole la GPO publiée : **(1)** neutralise tout lien racine, **(2)** bloque
     * l'héritage sur l'OU établissement, **(3)** lie la GPO à cette OU.
     *
     * **Pb1 (critique) — anti-lien-racine.** L'AD est mutualisé entre ~75
     * collèges → un lien racine pousserait l'agent SE5 sur TOUT le parc fédéré.
     * On retire donc **inconditionnellement et idempotemment** tout lien racine
     * APRÈS publication et AVANT le `setLink` sur l'OU établissement.
     *
     * Story 38.4 : le publisher natif ne pose PLUS AUCUN lien (contrairement au
     * legacy `import_gpo` qui liait la racine faute de section `[links]`) —
     * `removeRootLink` est conservé en **purge défensive** des liens racine
     * hérités d'anciennes publications legacy.
     *
     * Ordre sûr : publication → removeLink(racine) → setInheritance(OU, block) →
     * setLink(OU).
     */
    protected function isolateToEstablishmentOu(string $guid, ?string $ouDn, string $operationId, \App\Gpo\Support\GpoActionLog $log): AgentBootstrapDeployResult
    {
        // (1) Purge défensive de tout lien racine hérité (Pb1).
        $this->removeRootLink($guid, $operationId, $log);

        if ($ouDn === null) {
            $log->step('warn: aucune OU computers cible détectée — héritage/lien non appliqués (GPO publiée seule)');
            $log->success(['outcome_kind' => 'published_without_link', 'guid' => $guid]);

            return AgentBootstrapDeployResult::publishedWithoutLink($guid, $operationId);
        }

        // (2) Blocage d'héritage sur l'OU établissement (côté MACHINE).
        $blockLog = \App\Gpo\Support\GpoLogger::action('gpo.inheritance.set', $operationId, ['target_dn' => $ouDn]);
        $this->gpoService->setInheritance($ouDn, false); // false = block
        $blockLog->success(['blocked' => true]);

        // (2 bis) Blocage symétrique sur l'OU des COMPTES.
        //
        // Une GPO porte deux moitiés — Computer Configuration et User
        // Configuration — qui héritent par des chemins DISTINCTS. Bloquer l'OU
        // des postes ne neutralise que la première : les stratégies utilisateur
        // des GPO de domaine (lecteurs réseau, redirections, imprimantes,
        // scripts de logon…) continuaient de s'appliquer à CHAQUE ouverture de
        // session, en concurrence directe avec les capacités que l'agent pilote
        // désormais nativement — au point de fausser toute validation du travail
        // agent (une GPO peut faire passer pour fonctionnelle une capacité
        // cassée, ou écraser une capacité correcte).
        //
        // Comme pour les postes : on ne délie et ne vide JAMAIS la GPO partagée
        // (les collèges encore en SE4 la consomment) — on neutralise CHEZ NOUS.
        $this->blockUsersOuInheritance($operationId, $log);

        // (3) Lien sur l'OU établissement (JAMAIS la racine).
        $linkLog = \App\Gpo\Support\GpoLogger::action('gpo.link.add', $operationId, ['target_dn' => $ouDn, 'gpo_name' => $guid]);
        $this->gpoService->setLink($ouDn, $guid);
        $linkLog->success();

        $log->success(['outcome_kind' => 'deployed', 'guid' => $guid, 'target_ou_dn' => $ouDn]);

        return AgentBootstrapDeployResult::deployed($guid, $ouDn, $operationId);
    }

    /**
     * Retire (inconditionnel + idempotent + fail-soft) tout lien de la GPO sur
     * la RACINE du domaine (`ldap_base_dn`). Voir {@see isolateToEstablishmentOu}
     * (Pb1). Un échec « pas de lien à retirer » est toléré.
     */
    private function removeRootLink(string $guid, string $operationId, \App\Gpo\Support\GpoActionLog $log): void
    {
        $baseDn = (string) config('sambaedu.ldap_base_dn', '');
        if ($baseDn === '') {
            $log->step('removeLink racine ignoré : ldap_base_dn vide');

            return;
        }

        try {
            $this->gpoService->removeLink($baseDn, $guid);
            $log->step('lien racine neutralisé (anti-fédération-wide)', ['root_dn' => $baseDn, 'gpo_name' => $guid]);
        } catch (\Throwable $e) {
            $log->step('removeLink racine non bloquant (toléré)', ['root_dn' => $baseDn, 'error' => $this->kerberos->scrub($e->getMessage())]);
        }
    }

    // -----------------------------------------------------------------------
    // Garde-fous environnement
    // -----------------------------------------------------------------------

    /**
     * Le DC est-il joignable ? `samba-tool gpo listall` (lecture) via le runner
     * natif : exit 0 ⇒ joignable.
     *
     * Pb7 — cette garde ne valide que la LECTURE LDAP/AD ; l'écriture SYSVOL
     * est rattrapée en aval par {@see verifyRealWrite()}.
     */
    private function isDomainControllerReachable(\App\Gpo\Support\GpoActionLog $log): bool
    {
        try {
            $this->gpoService->list();

            return true;
        } catch (\Throwable $e) {
            $log->step('DC injoignable', ['error' => $e->getMessage()]);

            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Staging template
    // -----------------------------------------------------------------------

    /**
     * Dépose la source versionnée `resources/gpo/SE_agent_bootstrap/` sous
     * `templates_dir/sambaedu-gpo/SE_agent_bootstrap/` (forme répertoire
     * reconnue par {@see GpoTemplateRegistry} et {@see NativeGpoPublisher}).
     * Les placeholders sont spécialisés par le publisher natif lors de la
     * publication, PAS ici.
     *
     * @return string Chemin de staging.
     */
    private function stageTemplate(\App\Gpo\Support\GpoActionLog $log, bool $dryRun): string
    {
        $source = base_path('resources/gpo/' . self::DISPLAY_NAME);
        $destRoot = $this->templatesDir() . 'sambaedu-gpo';
        $dest = $destRoot . '/' . self::DISPLAY_NAME;

        if (! is_dir($source)) {
            throw new RuntimeException(sprintf('Source du template introuvable : %s', $source));
        }

        if ($dryRun) {
            $log->step('[dry-run] staging template', ['from' => $source, 'to' => $dest]);

            return $dest;
        }

        File::ensureDirectoryExists($destRoot);
        // Pb6 — purge préalable du dest : idempotence stricte.
        File::deleteDirectory($dest);
        File::copyDirectory($source, $dest);
        $log->step('template stagé vers templates_dir', ['to' => $dest]);

        return $dest;
    }

    private function templatesDir(): string
    {
        $dir = (string) config('sambaedu.gpo.templates_dir', '/usr/share/sambaedu/gpo/');

        return str_ends_with($dir, '/') ? $dir : $dir . '/';
    }

    // -----------------------------------------------------------------------
    // Publication SYSVOL native (contexte Administrator)
    // -----------------------------------------------------------------------

    /**
     * Publie via {@see NativeGpoPublisher} sous un ticket Kerberos Administrator
     * dédié ({@see AdministratorKerberosContext::withTicket}). Le publisher crée
     * la GPC si absente, écrit SYSVOL, pose `gPCMachineExtensionNames` et la
     * version. Le ticket est détruit en fin (finally interne au contexte).
     */
    private function publishWithAdministratorContext(bool $force, string $operationId, \App\Gpo\Support\GpoActionLog $log): void
    {
        $writeLog = \App\Gpo\Support\GpoLogger::action('gpo.sysvol.write', $operationId, ['display_name' => self::DISPLAY_NAME, 'force' => $force]);

        try {
            $this->kerberos->withTicket($writeLog, function (string $ccache) use ($force, $writeLog): void {
                $this->nativePublisher->publish(self::DISPLAY_NAME, $force, $ccache, $writeLog);
            });

            $writeLog->success(['published' => true]);
        } catch (\Throwable $e) {
            $writeLog->failure($e);
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Vérification d'écriture réelle (anti faux-succès SYSVOL)
    // -----------------------------------------------------------------------

    /**
     * Re-lit le `startup.cmd` déposé en SYSVOL via `smbclient` (contexte
     * Administrator) et compare sa taille à la **taille exacte attendue** du
     * fichier spécialisé. Un `ACCESS_DENIED` masqué en exit 0 produirait un
     * fichier absent/vide → on lève.
     */
    private function verifyRealWrite(\App\Gpo\Support\GpoActionLog $log): void
    {
        $verifyLog = \App\Gpo\Support\GpoLogger::action('gpo.sysvol.write', $log->operationId(), ['phase' => 'verify']);

        $this->kerberos->withTicket($verifyLog, function (string $ccache) use ($verifyLog): void {
            $guid = $this->resolveGpoGuid($verifyLog);
            $domain = (string) config('sambaedu.domain', '');
            $host = $this->kerberos->sysvolHost();
            if ($domain === '' || $host === '') {
                $verifyLog->step('vérification d\'écriture ignorée (domain/host indéterminés) — best effort');
                $verifyLog->success(['verified' => false, 'reason' => 'missing_domain_or_host']);

                return;
            }

            $remoteDir = sprintf('%s/Policies/%s/Machine/Scripts/Startup', $domain, $guid);
            $cmd = sprintf('cd "%s"; ls startup.cmd', $remoteDir);

            $result = Process::env(['KRB5CCNAME' => $ccache])->run([
                'smbclient', '//' . $host . '/sysvol',
                '--use-kerberos=required',
                '-c', $cmd,
            ]);

            $out = $result->output();
            if (! $result->successful() || ! preg_match('/startup\.cmd\s+\S+\s+(\d+)/i', $out, $m)) {
                throw new RuntimeException(sprintf(
                    'Vérification d\'écriture SYSVOL ÉCHOUÉE pour %s : startup.cmd absent en SYSVOL '
                    . '(exit=%d). Probable ACCESS_DENIED masqué (droits Administrator manquants). Sortie: %s',
                    self::DISPLAY_NAME,
                    $result->exitCode() ?? -1,
                    $this->kerberos->scrub(substr($out . $result->errorOutput(), 0, 400)),
                ));
            }

            $remoteSize = (int) $m[1];
            $expectedSize = $this->expectedStartupCmdSize();

            if ($expectedSize !== null) {
                if ($remoteSize !== $expectedSize) {
                    throw new RuntimeException(sprintf(
                        'Vérification d\'écriture SYSVOL ÉCHOUÉE pour %s : startup.cmd en SYSVOL fait %d octets, '
                        . '%d attendus (fichier spécialisé). Écriture de CE run non confirmée. Causes probables : '
                        . '(1) le contenu du template a changé sans incrément de GPT.INI [General] Version — '
                        . 'la publication (force=false) saute alors la republication SYSVOL et laisse le fichier périmé '
                        . '(remède : bumper Version dans resources/gpo/%s/GPT.INI, ou relancer avec --force) ; '
                        . '(2) faux-succès masquant un ACCESS_DENIED en re-run (droits Administrator / ACL SYSVOL).',
                        self::DISPLAY_NAME,
                        $remoteSize,
                        $expectedSize,
                        self::DISPLAY_NAME,
                    ));
                }
                $verifyLog->success(['verified' => true, 'remote_size' => $remoteSize, 'expected_size' => $expectedSize]);
            } else {
                if ($remoteSize <= 0) {
                    throw new RuntimeException(sprintf(
                        'Vérification d\'écriture SYSVOL ÉCHOUÉE pour %s : startup.cmd vide en SYSVOL.',
                        self::DISPLAY_NAME,
                    ));
                }
                $verifyLog->success(['verified' => 'presence_only', 'remote_size' => $remoteSize, 'reason' => 'expected_size_unavailable']);
            }
        });
    }

    // -----------------------------------------------------------------------
    // Résolution GPO / OU
    // -----------------------------------------------------------------------

    /** GUID `{...}` de la GPO publiée, résolu par displayName via le natif. */
    private function resolveGpoGuid(\App\Gpo\Support\GpoActionLog $log): string
    {
        $needle = mb_strtolower(self::DISPLAY_NAME);
        foreach ($this->gpoService->list() as $summary) {
            if (mb_strtolower(trim($summary->displayName)) === $needle) {
                return $summary->name;
            }
        }

        throw new RuntimeException(sprintf(
            'GPO %s introuvable dans l\'AD après publication (samba-tool gpo listall) — GUID non résolu.',
            self::DISPLAY_NAME,
        ));
    }

    /**
     * Bloque l'héritage sur l'OU des COMPTES de notre établissement.
     *
     * FAIL-SOFT par construction : l'amorçage de l'agent (publication + lien sur
     * l'OU des postes) est l'objectif critique de ce service et ne doit jamais
     * échouer parce que l'OU des comptes est introuvable ou en écriture refusée.
     * L'échec est journalisé, pas propagé.
     *
     * Idempotent : `setInheritance(dn, false)` sur une OU déjà bloquée est un
     * no-op côté AD.
     */
    protected function blockUsersOuInheritance(string $operationId, \App\Gpo\Support\GpoActionLog $log): void
    {
        $usersOuDn = $this->resolveTargetUsersOuDn($log);

        if ($usersOuDn === null) {
            $log->step('warn: aucune OU comptes détectée — héritage utilisateur NON bloqué (les stratégies utilisateur des GPO de domaine restent actives)');

            return;
        }

        $blockLog = \App\Gpo\Support\GpoLogger::action('gpo.inheritance.set', $operationId, [
            'target_dn' => $usersOuDn,
            'scope' => 'users',
        ]);

        try {
            $this->gpoService->setInheritance($usersOuDn, false); // false = block
            $blockLog->success(['blocked' => true, 'scope' => 'users']);
        } catch (\Throwable $e) {
            $blockLog->failure($e);
            $log->step('warn: blocage héritage OU comptes échoué (fail-soft) — l\'amorçage agent se poursuit', [
                'target_dn' => $usersOuDn,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dérive le DN de l'OU des comptes cible, pour les deux topologies :
     *  - couche établissement (prod/lab1) : `OU=<code>,<peopleRdn>,<base>` ;
     *  - plate (localdev /vm)              : `<peopleRdn>,<base>`.
     *
     * ⚠️ GARDE ANTI-FÉDÉRATION — asymétrie DÉLIBÉRÉE avec
     * {@see resolveTargetOuDn} : dès qu'un code établissement existe, SEULE la
     * couche établissement est acceptable. On ne retombe JAMAIS sur
     * `<peopleRdn>,<base>`, qui est le conteneur des comptes de TOUT le domaine —
     * y bloquer l'héritage neutraliserait les stratégies utilisateur des ~75
     * collèges d'un coup, alors que l'objectif est de neutraliser CHEZ NOUS.
     *
     * Le repli plat n'est donc autorisé que lorsqu'il n'y a AUCUN code
     * établissement — cas non fédéré (VM de dev), où ce conteneur EST celui de
     * l'instance. Dans le doute on ne bloque rien : ne pas neutraliser est un
     * défaut réparable, neutraliser les autres collèges ne l'est pas.
     *
     * Le RDN vient de la conf système (`peopleRdn`, ex. `ou=Utilisateurs`), pas
     * d'une constante : il diffère selon les installations. Fail-soft → null.
     */
    protected function resolveTargetUsersOuDn(\App\Gpo\Support\GpoActionLog $log): ?string
    {
        $baseDn = (string) config('sambaedu.ldap_base_dn', '');
        if ($baseDn === '') {
            $log->step('résolution OU comptes : ldap_base_dn vide — aucune OU dérivable');

            return null;
        }

        $peopleRdn = $this->peopleRdn();

        if ($peopleRdn === '') {
            $log->step('résolution OU comptes : peopleRdn indisponible — aucune OU dérivable');

            return null;
        }

        $peopleDn = $peopleRdn . ',' . $baseDn;
        $code = $this->establishmentCode();

        if ($code !== '') {
            $scopedDn = 'OU=' . $code . ',' . $peopleDn;

            if ($this->ouExists($scopedDn)) {
                $log->step('OU comptes cible résolue (couche établissement)', ['ou_dn' => $scopedDn]);

                return $scopedDn;
            }

            // Volontairement AUCUN repli : le conteneur partagé est hors limites.
            $log->step(
                'warn: OU comptes de l\'établissement absente — héritage utilisateur NON bloqué '
                . '(repli sur le conteneur partagé REFUSÉ : il neutraliserait les autres collèges)',
                ['expected_dn' => $scopedDn, 'shared_dn_refused' => $peopleDn],
            );

            return null;
        }

        // Aucun code établissement → instance non fédérée : le conteneur plat
        // est bien le nôtre.
        if ($this->ouExists($peopleDn)) {
            $log->step('OU comptes cible résolue (topologie plate, hors fédération)', ['ou_dn' => $peopleDn]);

            return $peopleDn;
        }

        $log->step('aucune OU comptes candidate n\'existe (fail-soft)', ['candidates' => [$peopleDn]]);

        return null;
    }

    /**
     * Dérive le DN de l'OU computers cible, en gérant les DEUX topologies :
     *  - couche établissement (prod/lab1) : `OU=<code>,OU=computers,<base>` ;
     *  - plate (localdev /vm)              : `OU=computers,<base>`.
     * Détection par LDAP read (existence). Fail-soft → null si aucune.
     */
    protected function resolveTargetOuDn(\App\Gpo\Support\GpoActionLog $log): ?string
    {
        $baseDn = (string) config('sambaedu.ldap_base_dn', '');
        if ($baseDn === '') {
            $log->step('résolution OU : ldap_base_dn vide — aucune OU dérivable');

            return null;
        }

        $computersDn = 'OU=computers,' . $baseDn;
        $code = $this->establishmentCode();

        $candidates = [];
        if ($code !== '') {
            $candidates[] = 'OU=' . $code . ',' . $computersDn;
        }
        $candidates[] = $computersDn;

        foreach ($candidates as $dn) {
            if ($this->ouExists($dn)) {
                $log->step('OU computers cible résolue', ['ou_dn' => $dn]);

                return $dn;
            }
        }

        $log->step('aucune OU computers candidate n\'existe (fail-soft)', ['candidates' => $candidates]);

        return null;
    }

    /**
     * RDN du conteneur des comptes (ex. `ou=Utilisateurs`), issu de la conf
     * système : il varie selon les installations, ce n'est pas une constante.
     * Chaîne vide si indisponible (→ résolution fail-soft).
     */
    protected function peopleRdn(): string
    {
        try {
            return (string) app(\App\Config\SambaEduConfig::class)->ldap()->peopleRdn;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Code établissement (UAI), dérivé de la config. Source canonique :
     * `getCurrentEstablishmentCode()` (`etab_ou`). Fallback : extraction du code
     * UAI depuis `se4fs_name` (`se4fs-0991229y` → `0991229y`).
     */
    protected function establishmentCode(): string
    {
        try {
            $code = (string) app(\App\Config\SambaEduConfig::class)->getCurrentEstablishmentCode();
        } catch (\Throwable) {
            $code = '';
        }
        if ($code !== '' && $code !== '0') {
            return $code;
        }

        $se4fsName = (string) config('sambaedu.se4fs_name', '');
        if (preg_match('/-(\d{7}[a-zA-Z])$/', $se4fsName, $m) === 1) {
            return strtolower($m[1]);
        }

        return '';
    }

    /** L'OU existe-t-elle dans l'AD (lecture LDAP) ? */
    protected function ouExists(string $dn): bool
    {
        try {
            $connection = \LdapRecord\Container::getDefaultConnection();
            $entry = $connection->query()->setDn($dn)->read()->first();

            return $entry !== null;
        } catch (\Throwable $e) {
            Log::channel('gpo')->warning('[AgentBootstrapPublisher] lecture OU échouée', [
                'dn' => $dn,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Taille EXACTE attendue du `startup.cmd` une fois spécialisé (Pb4).
     *
     * Reproduit fidèlement la substitution du legacy `specialise_gpo` : pour
     * chaque paramètre, remplace `###_<PARAM>_###` par `config('sambaedu.<param>')`
     * (substitution de chaîne simple — le startup.cmd est de l'ASCII). Lit la
     * source STAGÉE (placeholders intacts) et calcule la taille en octets après
     * substitution. Retourne `null` si la source est introuvable.
     */
    private function expectedStartupCmdSize(): ?int
    {
        $source = $this->templatesDir() . 'sambaedu-gpo/' . self::DISPLAY_NAME
            . '/Machine/Scripts/Startup/startup.cmd';

        if (! is_file($source)) {
            $source = base_path('resources/gpo/' . self::DISPLAY_NAME . '/Machine/Scripts/Startup/startup.cmd');
            if (! is_file($source)) {
                return null;
            }
        }

        $content = @file_get_contents($source);
        if ($content === false) {
            return null;
        }

        $params = [
            'domain', 'samba_domain', 'se4fs_name', 'se4ad_name',
            'se4install_name', 'ldap_base_dn', 'cloud_name',
        ];
        foreach ($params as $param) {
            $value = config('sambaedu.' . $param);
            if ($value === null) {
                continue;
            }
            $content = str_replace('###_' . strtoupper($param) . '_###', (string) $value, $content);
        }

        return strlen($content);
    }
}

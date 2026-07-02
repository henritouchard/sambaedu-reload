<?php

declare(strict_types=1);

namespace App\Services\Gpo;

use App\Gpo\Services\GpoService;
use App\Gpo\Support\GpoLogger;
use App\Gpo\Support\GpoTemplateRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Story 27.16 — Déploiement automatisé de la GPO-dispatcher figée d'amorçage
 * agent `SE_agent_bootstrap` (ex-`se4_agent_bootstrap`, 25.4).
 *
 * Automatise le Fork 2 manuel de la 25.4 (`docs/runbooks/gpo-se4-agent-bootstrap.md`)
 * en surmontant le blocage qui avait justifié le choix manuel : **les droits
 * SYSVOL**. Le user PHP-FPM (`www-sambaedu`/`www-admin`) n'a que READ sur SYSVOL ;
 * un `smbclient put` y sort en exit 0 SANS rien écrire (faux succès — mémoires
 * `project_sysvol_wwwadmin_no_write_rights_and_silent_success`,
 * `project_sysvol_write_needs_wwwadmin_kinit`). Ce service :
 *
 *  1. **garde fail-soft** : `admin_passwd` absent ou DC injoignable → skip propre
 *     (jamais d'exception qui ferait échouer install/update) ;
 *  2. **stage** le template versionné `resources/gpo/SE_agent_bootstrap/` vers
 *     `templates_dir` (forme répertoire `sambaedu-gpo/<name>/`) ;
 *  3. établit un **contexte Kerberos Administrator** (`kinit` avec `admin_passwd`
 *     dans un `KRB5CCNAME` dédié, purgé en fin) ;
 *  4. **publie** via le shim legacy `import_gpo` (chemin éprouvé du runbook
 *     « Option A ») — il CRÉE la GPC si absente (`gpocreate`), écrit SYSVOL
 *     (`sysvol_put`), pose l'attribut LDAP `gPCMachineExtensionNames` (SANS quoi
 *     le startup.cmd ne s'exécuterait jamais) et incrémente la version ;
 *  5. **vérifie l'écriture réelle** (re-lecture SYSVOL du `startup.cmd` déposé,
 *     comparaison de taille/hash avec la source) → un `ACCESS_DENIED` masqué en
 *     exit 0 devient un échec EXPLICITE ;
 *  6. **isole** : bloque l'héritage (`gPOptions=1`) sur l'OU computers de NOTRE
 *     établissement (DN dérivé de la config, deux topologies gérées) + lie
 *     `SE_agent_bootstrap` à cette même OU (JAMAIS la racine — l'AD est mutualisé
 *     entre ~75 collèges) via {@see GpoService} (16.5).
 *
 * Garde-fou archi : ce service vit sous `App\Services\Gpo`, PAS `App\Gpo`. Il
 * peut donc légitimement invoquer `Process` (kinit/smbclient/klist) et LdapRecord
 * (détection d'OU) — le test `GpoNamespaceTest` ne scanne que `app/Gpo/`. Les
 * écritures GPO natives (lien + héritage) passent exclusivement par `GpoService`
 * → `SambaToolRunner`. La publication SYSVOL + CSE passe par le shim legacy
 * `import_gpo` (frontière legacy, namespace `legacy/`, `exec` autorisé là).
 */
class AgentBootstrapPublisher
{
    /** Nom SE5 (= displayName GPT.INI = basename du template). */
    public const DISPLAY_NAME = 'SE_agent_bootstrap';

    /** Cache de la sonde `kinit --password-file` (Heimdal=true, MIT=false). */
    private ?bool $kinitSupportsPasswordFile = null;

    /** Handle /dev/null gardé vivant après détachement de fd 0 du TTY. */
    private $nullStdin = null;

    /** Garde : ne détacher stdin qu'une fois par instance. */
    private bool $stdinDetached = false;

    public function __construct(
        private readonly GpoService $gpoService,
        private readonly GpoTemplateRegistry $registry,
    ) {}

    /**
     * Déploie le bootstrap de bout en bout. Idempotent et fail-soft.
     *
     * @param  bool  $force    Republier même si la version SYSVOL est à jour.
     * @param  bool  $dryRun   Affiche ce qui serait fait, aucun side effect.
     * @return AgentBootstrapDeployResult
     */
    public function deploy(bool $force = false, bool $dryRun = false): AgentBootstrapDeployResult
    {
        $log = GpoLogger::action('gpo.create', context: ['display_name' => self::DISPLAY_NAME, 'force' => $force, 'dry_run' => $dryRun]);
        $operationId = $log->operationId();

        try {
            // --- Garde 1 : creds Administrator disponibles ? -------------------
            $adminPasswd = $this->adminPassword();
            if ($adminPasswd === '') {
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

            // --- Publication SYSVOL sous contexte Administrator ----------------
            $this->publishWithAdministratorContext($adminPasswd, $force, $operationId, $log);

            // --- Vérification d'écriture réelle (anti faux-succès) -------------
            $this->verifyRealWrite($adminPasswd, $log);

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
     * Isole la GPO publiée : **(1)** neutralise tout lien racine auto-créé par le
     * shim legacy `import_gpo`, **(2)** bloque l'héritage sur l'OU établissement,
     * **(3)** lie la GPO à cette OU.
     *
     * **Pb1 (critique) — anti-lien-racine.** `import_gpo` (gpo.inc.php:1035-1052),
     * faute de section `[links]` dans le GPT.INI de `SE_agent_bootstrap`, lie la
     * GPO à `$config['ldap_base_dn']` (la RACINE du domaine) via `gposetlink`.
     * L'AD est mutualisé entre ~75 collèges → un lien racine pousserait l'agent
     * SE5 sur TOUT le parc fédéré (scénario interdit par AC3). On retire donc
     * **inconditionnellement et idempotemment** ce lien racine APRÈS publication
     * et AVANT le `setLink` sur l'OU établissement. `removeLink` est déjà
     * idempotent côté {@see GpoService} (« lien absent » = succès silencieux) ;
     * on entoure malgré tout l'appel d'un fail-soft (un échec « pas de lien »
     * ne doit JAMAIS faire échouer le déploiement).
     *
     * Ordre sûr : import_gpo → removeLink(racine) → setInheritance(OU, block) →
     * setLink(OU).
     */
    protected function isolateToEstablishmentOu(string $guid, ?string $ouDn, string $operationId, \App\Gpo\Support\GpoActionLog $log): AgentBootstrapDeployResult
    {
        // (1) Neutraliser le lien racine auto-créé par import_gpo (Pb1).
        $this->removeRootLink($guid, $operationId, $log);

        if ($ouDn === null) {
            // Fail-soft : la GPO est publiée (et le lien racine neutralisé), mais
            // aucune OU cible détectée → ni héritage ni lien établissement appliqués.
            $log->step('warn: aucune OU computers cible détectée — héritage/lien non appliqués (GPO publiée seule)');
            $log->success(['outcome_kind' => 'published_without_link', 'guid' => $guid]);

            return AgentBootstrapDeployResult::publishedWithoutLink($guid, $operationId);
        }

        // (2) Blocage d'héritage sur l'OU établissement.
        $blockLog = GpoLogger::action('gpo.inheritance.set', $operationId, ['target_dn' => $ouDn]);
        $this->gpoService->setInheritance($ouDn, false); // false = block
        $blockLog->success(['blocked' => true]);

        // (3) Lien sur l'OU établissement (JAMAIS la racine).
        $linkLog = GpoLogger::action('gpo.link.add', $operationId, ['target_dn' => $ouDn, 'gpo_name' => $guid]);
        $this->gpoService->setLink($ouDn, $guid);
        $linkLog->success();

        $log->success(['outcome_kind' => 'deployed', 'guid' => $guid, 'target_ou_dn' => $ouDn]);

        return AgentBootstrapDeployResult::deployed($guid, $ouDn, $operationId);
    }

    /**
     * Retire (inconditionnel + idempotent + fail-soft) tout lien de la GPO sur la
     * RACINE du domaine (`ldap_base_dn`). Voir {@see isolateToEstablishmentOu}
     * (Pb1). Un échec « pas de lien à retirer » est toléré : il ne lève pas et ne
     * fait pas échouer le déploiement.
     */
    private function removeRootLink(string $guid, string $operationId, \App\Gpo\Support\GpoActionLog $log): void
    {
        $baseDn = (string) config('sambaedu.ldap_base_dn', '');
        if ($baseDn === '') {
            $log->step('removeLink racine ignoré : ldap_base_dn vide');

            return;
        }

        try {
            // removeLink est déjà idempotent côté GpoService (« lien absent » → OK).
            $this->gpoService->removeLink($baseDn, $guid);
            $log->step('lien racine neutralisé (anti-fédération-wide)', ['root_dn' => $baseDn, 'gpo_name' => $guid]);
        } catch (\Throwable $e) {
            // Fail-soft : un échec ici (ex. « pas de lien ») ne doit PAS interrompre
            // le déploiement ni l'isolation sur l'OU établissement.
            $log->step('removeLink racine non bloquant (toléré)', ['root_dn' => $baseDn, 'error' => $this->scrub($e->getMessage())]);
        }
    }

    // -----------------------------------------------------------------------
    // Garde-fous environnement
    // -----------------------------------------------------------------------

    /** Mot de passe Administrator (admin_passwd), jamais logué en clair. */
    private function adminPassword(): string
    {
        return (string) config('sambaedu.admin_passwd', '');
    }

    /**
     * Le DC est-il joignable ? On utilise un `samba-tool gpo listall` (lecture)
     * via le runner natif : exit 0 ⇒ joignable. C'est aussi un pré-requis de la
     * suite (résolution GUID, lien).
     *
     * Pb7 — cette garde ne valide que la **LECTURE** LDAP/AD : un DC joignable en
     * lecture peut très bien refuser l'**écriture** SYSVOL (droits Administrator
     * manquants, ACL READ-only). Ce cas n'est PAS attrapé ici ; il est rattrapé
     * en aval par {@see verifyRealWrite()} (re-lecture du startup.cmd déposé → si
     * absent/taille divergente, le déploiement passe en `failed`).
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
     * reconnue par {@see GpoTemplateRegistry}). Idempotent (re-copie). Les
     * placeholders (`###_SE4FS_NAME_###`/`###_DOMAIN_###`) sont spécialisés par
     * le legacy `specialise_gpo` lors de l'`import_gpo`, PAS ici.
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
        // Pb6 — purge préalable du dest : idempotence stricte (aucun résidu d'un
        // staging antérieur ne doit subsister sous le template publié).
        File::deleteDirectory($dest);
        // Copie récursive (préserve les octets CRLF/ASCII du startup.cmd).
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
    // Publication SYSVOL (shim legacy import_gpo, contexte Administrator)
    // -----------------------------------------------------------------------

    /**
     * Publie via le shim legacy `import_gpo` sous un ticket Kerberos
     * **Administrator** dédié. `import_gpo` crée la GPC si absente (`gpocreate`),
     * écrit SYSVOL (`sysvol_put` → `smbclient --use-kerberos=required`, qui lit
     * le `KRB5CCNAME` ambiant), pose `gPCMachineExtensionNames` et la version.
     *
     * Le ticket est isolé dans un ccache temporaire (`KRB5CCNAME`) et détruit en
     * fin (`finally`) — jamais de pollution du ccache www-admin ni de fuite.
     */
    private function publishWithAdministratorContext(string $adminPasswd, bool $force, string $operationId, \App\Gpo\Support\GpoActionLog $log): void
    {
        $writeLog = GpoLogger::action('gpo.sysvol.write', $operationId, ['display_name' => self::DISPLAY_NAME, 'force' => $force]);

        $ccache = $this->makeTempCcachePath();
        $previousCcache = getenv('KRB5CCNAME');

        // Les fonctions legacy (gpocreate → `samba-tool gpo create`, sysvol_put →
        // `smbclient`) sont lancées par `exec()` qui hérite du fd 0 du process PHP.
        // En CLI (artisan, ou via update.sh) c'est un TTY : si l'auth Kerberos SMB
        // vers SYSVOL échoue (ex. winbind WBC_ERR_DOMAIN_NOT_FOUND sur un DC mal
        // résolu), l'outil retombe sur un PROMPT mot de passe et bloque
        // INDÉFINIMENT — prompt invisible car la sortie est capturée par ob_start().
        // On détache fd 0 du TTY (→ /dev/null) : l'outil reçoit EOF et échoue vite
        // (fail-soft) au lieu de pendre. Sans effet sur le cas sain (pas de prompt).
        $this->detachStdinFromTty();

        try {
            $this->kinitAdministrator($adminPasswd, $ccache, $writeLog);

            // Le shim legacy lit KRB5CCNAME via l'env du process PHP courant.
            putenv('KRB5CCNAME=' . $ccache);

            $ok = $this->callLegacyImportGpo($force, $writeLog);
            if ($ok !== true) {
                throw new RuntimeException(sprintf(
                    'import_gpo(%s) a retourné false — publication SYSVOL échouée (voir logs legacy).',
                    self::DISPLAY_NAME,
                ));
            }

            $writeLog->success(['published' => true]);
        } catch (\Throwable $e) {
            $writeLog->failure($e);
            throw $e;
        } finally {
            // Restaurer/purger le ccache et détruire le ticket Administrator.
            if ($previousCcache === false) {
                putenv('KRB5CCNAME');
            } else {
                putenv('KRB5CCNAME=' . $previousCcache);
            }
            $this->destroyTicket($ccache);
        }
    }

    /**
     * `kinit Administrator` dans le ccache dédié.
     *
     * Pb3 — le mot de passe est fourni via **STDIN** (`Process::input`), jamais
     * en argv (`ps`) ni dans un fichier ni dans les logs. C'est le SEUL canal
     * portable entre implémentations :
     *  - MIT kinit (Debian `krb5-user`) n'a PAS d'option `--password-file` ; son
     *    prompter lit le mot de passe sur stdin dès que ce n'est pas un TTY.
     *  - Heimdal kinit exige explicitement `--password-file=STDIN` pour lire
     *    stdin (sinon il va sur /dev/tty → blocage).
     * On sonde donc la capacité `--password-file` une fois et on n'ajoute le flag
     * que si kinit le supporte. Un timeout borne le Process pour ne jamais pendre.
     */
    private function kinitAdministrator(string $adminPasswd, string $ccache, \App\Gpo\Support\GpoActionLog $log): void
    {
        $principal = $this->administratorPrincipal();
        $log->step('kinit Administrator (ccache dédié)', ['principal' => $principal]);

        $command = ['kinit'];
        if ($this->kinitSupportsPasswordFile()) {
            $command[] = '--password-file=STDIN';
        }
        $command[] = $principal;

        $result = Process::env(['KRB5CCNAME' => $ccache])
            ->input($adminPasswd)
            ->timeout(30)
            ->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                'kinit Administrator échoué (exit=%d) — impossible d\'établir le contexte d\'écriture SYSVOL. stderr: %s',
                $result->exitCode() ?? -1,
                $this->scrub($result->errorOutput()),
            ));
        }

        // Pb5 — durcir les perms du ccache (le ticket Administrator ne doit
        // être lisible que par le user courant).
        $ccachePath = preg_replace('/^FILE:/', '', $ccache) ?? $ccache;
        if (is_file($ccachePath)) {
            @chmod($ccachePath, 0600);
        }
    }

    /**
     * Sonde si le `kinit` du PATH supporte `--password-file` (Heimdal) ou non
     * (MIT). MIT comme Heimdal écrivent leur usage sur stderr ; on cherche le
     * flag dans la sortie combinée. Mémoïsé : la réponse ne change pas pendant
     * la vie du process.
     */
    private function kinitSupportsPasswordFile(): bool
    {
        if ($this->kinitSupportsPasswordFile !== null) {
            return $this->kinitSupportsPasswordFile;
        }

        $help = Process::timeout(5)->run(['kinit', '--help']);
        $text = $help->output() . $help->errorOutput();

        return $this->kinitSupportsPasswordFile = str_contains($text, '--password-file');
    }

    /**
     * Principal Kerberos Administrator : `Administrator@REALM`. Le realm est le
     * domaine AD en MAJUSCULES (`domain` de la config, ex. `LOCALDEV.FR`).
     */
    private function administratorPrincipal(): string
    {
        $adminName = (string) config('sambaedu.ldap_admin_name', 'Administrator');
        $realm = strtoupper((string) config('sambaedu.domain', ''));

        return $realm !== '' ? $adminName . '@' . $realm : $adminName;
    }

    /**
     * Invoque le shim legacy `import_gpo` après chargement idempotent du
     * bootstrap legacy. Frontière legacy assumée (le `exec(smbclient)` vit dans
     * `sambaedu/includes/gpo.inc.php`, namespace legacy — hors `app/Gpo`).
     *
     * TD / dette (Pb11) — DÉPENDANCE RUNTIME au shim legacy `legacy/bootstrap.php`
     * + `import_gpo` (`../sambaedu/includes/gpo.inc.php`). À l'extinction du legacy
     * (FR30 / story 27.14), `import_gpo` devra être PORTÉ NATIF (création GPC +
     * écriture SYSVOL + `gPCMachineExtensionNames` + version), sinon cette commande
     * — donc le « filet éternel » FR25 — devient INOPÉRANTE. Ne pas oublier de
     * neutraliser le lien racine auto (cf. {@see removeRootLink}) dans le portage.
     *
     * Pb5 — `import_gpo` émet des `echo` directs (potentiellement des chemins, des
     * messages SYSVOL) ; on les capture via `ob_start()`/`ob_get_clean()`, on les
     * scrub puis on les renvoie au log structuré — JAMAIS bruts sur stdout.
     *
     * @return bool Résultat brut de `import_gpo`.
     */
    private function callLegacyImportGpo(bool $force, \App\Gpo\Support\GpoActionLog $log): bool
    {
        require_once base_path('legacy/bootstrap.php');

        foreach (['get_config', 'import_gpo', 'search_ad'] as $fn) {
            if (! function_exists($fn)) {
                throw new RuntimeException(sprintf('Fonction legacy `%s` indisponible après bootstrap — environnement dégradé.', $fn));
            }
        }

        /** @var array<string,mixed> $config */
        $config = [];
        $config = \get_config($config);

        // PHP 8 — `import_gpo()` (legacy central /var/www/sambaedu, non porté)
        // fait `count($gpo)` sur le retour de `search_ad(...,'gpo')`. Ce retour
        // est `false` UNIQUEMENT quand le bind LDAP échoue (ldap_admin_passwd
        // absent/illisible dans /etc/sambaedu/sambaedu.conf, ou DC injoignable) —
        // et `count(false)` est une TypeError fatale en PHP 8.2 qui masque la
        // vraie cause. GPO absente => `search_ad` renvoie `[]` (count 0) et
        // import_gpo la crée nativement via sa branche `else` (gpocreate). On
        // sonde donc le bind AVANT pour convertir l'échec LDAP en message clair
        // sans toucher au legacy partagé.
        $this->assertGpoSearchUsable($config);

        $log->step('import_gpo (shim legacy) invoqué', ['display_name' => self::DISPLAY_NAME]);

        // `import_gpo($config, $displayname, $gpo_archive, $update=true, $force)` :
        // archive = nom NU (résolu sous templates_dir/sambaedu-gpo/<name>/ par unzip_gpo).
        ob_start();
        try {
            $ok = (bool) \import_gpo($config, self::DISPLAY_NAME, self::DISPLAY_NAME, true, $force);
        } finally {
            $echoed = (string) ob_get_clean();
        }

        if (trim($echoed) !== '') {
            // Capturé + scrubbé : aucun echo legacy ne fuit sur stdout / en clair.
            $log->step('import_gpo (sortie legacy capturée)', ['legacy_output' => $this->scrub(substr($echoed, 0, 1000))]);
        }

        return $ok;
    }

    /**
     * Sonde que `search_ad(...,'gpo')` est exploitable (bind LDAP OK) AVANT que
     * `import_gpo` ne fasse `count()` dessus.
     *
     * `search_ad` renvoie `false` quand le bind LDAP échoue — typiquement
     * `ldap_admin_passwd` absent/illisible dans /etc/sambaedu/sambaedu.conf, ou
     * DC injoignable. En PHP 8.2 le `count(false)` d'import_gpo serait une
     * TypeError opaque ; on lève ici une erreur explicite à la place. Un retour
     * `[]` (GPO absente) ou un array (présente) est valide : import_gpo gère les
     * deux nativement (création via `gpocreate` dans sa branche `else`).
     */
    private function assertGpoSearchUsable(array $config): void
    {
        // nocache=true : ne PAS relire un éventuel `false` mémoïsé (apc.enable_cli=On).
        $probe = \search_ad($config, self::DISPLAY_NAME, 'gpo', 'all', [], 'subtree', false, true);

        if ($probe === false) {
            throw new RuntimeException(
                'Recherche LDAP de la GPO a échoué (bind refusé). Vérifier `ldap_admin_passwd` '
                . 'dans /etc/sambaedu/sambaedu.conf (présent et lisible par www-admin) et la '
                . 'joignabilité du contrôleur de domaine.'
            );
        }
    }

    /**
     * Réattache fd 0 (STDIN) à /dev/null pour que les `exec()` legacy
     * (samba-tool/smbclient) n'héritent jamais d'un TTY → jamais de prompt mot de
     * passe bloquant. Idempotent, CLI uniquement. Le publisher ne lit pas stdin,
     * donc le détachement est sans effet de bord. Le handle /dev/null est conservé
     * en propriété pour éviter que le GC ne referme fd 0.
     */
    private function detachStdinFromTty(): void
    {
        if ($this->stdinDetached || PHP_SAPI !== 'cli') {
            return;
        }
        $this->stdinDetached = true;

        if (defined('STDIN') && is_resource(STDIN)) {
            @fclose(STDIN);
        }
        // fopen prend le plus bas descripteur libre (= fd 0 qu'on vient de fermer).
        $this->nullStdin = @fopen('/dev/null', 'r');
    }

    // -----------------------------------------------------------------------
    // Vérification d'écriture réelle (anti faux-succès SYSVOL)
    // -----------------------------------------------------------------------

    /**
     * Re-lit le `startup.cmd` déposé en SYSVOL via `smbclient` (contexte
     * Administrator) et compare sa taille à la **taille exacte attendue** du
     * fichier spécialisé. Un `ACCESS_DENIED` que smbclient masque en exit 0
     * produirait un fichier absent/vide → on lève.
     *
     * Pb4 — on ne se contente PLUS de `taille > 0` (aveugle au faux-succès en
     * re-run : le fichier préexiste, un échec d'écriture courant passerait
     * inaperçu). On reconstruit la taille **exacte** du startup.cmd après
     * substitution des placeholders ({@see expectedStartupCmdSize()}, mêmes
     * substitutions que le legacy `specialise_gpo`) et on exige l'égalité stricte.
     * Si la taille attendue n'est pas calculable (source absente), on retombe sur
     * une vérification de PRÉSENCE (taille > 0) explicitement loggée.
     *
     * Pb2 — l'hôte de vérification est aligné sur l'hôte de **publication** legacy
     * (`ad_url($config,'dns')` = `se4ad_name`, le FQDN du DC), et NON sur
     * `ldap()->getHosts()[0]` (qui peut être une IP → échec SASL/canonicalisation
     * cf. `project_ipxe_boot500_sasl_nocanon`, donc faux échec).
     */
    private function verifyRealWrite(string $adminPasswd, \App\Gpo\Support\GpoActionLog $log): void
    {
        $verifyLog = GpoLogger::action('gpo.sysvol.write', $log->operationId(), ['phase' => 'verify']);

        $ccache = $this->makeTempCcachePath();
        $previousCcache = getenv('KRB5CCNAME');

        try {
            $this->kinitAdministrator($adminPasswd, $ccache, $verifyLog);

            $guid = $this->resolveGpoGuid($verifyLog);
            $domain = (string) config('sambaedu.domain', '');
            $host = $this->sysvolHost();
            if ($domain === '' || $host === '') {
                $verifyLog->step('vérification d\'écriture ignorée (domain/host indéterminés) — best effort');
                $verifyLog->success(['verified' => false, 'reason' => 'missing_domain_or_host']);

                return;
            }

            // Récupère la taille du fichier distant : `ls` smbclient sur le chemin.
            $remoteDir = sprintf('%s/Policies/%s/Machine/Scripts/Startup', $domain, $guid);
            $cmd = sprintf('cd "%s"; ls startup.cmd', $remoteDir);

            $result = Process::env(['KRB5CCNAME' => $ccache])->run([
                'smbclient', '//' . $host . '/sysvol',
                '--use-kerberos=required',
                '-c', $cmd,
            ]);

            $out = $result->output();
            // smbclient `ls` affiche : `  startup.cmd   A   2048  Mon ...`.
            if (! $result->successful() || ! preg_match('/startup\.cmd\s+\S+\s+(\d+)/i', $out, $m)) {
                throw new RuntimeException(sprintf(
                    'Vérification d\'écriture SYSVOL ÉCHOUÉE pour %s : startup.cmd absent en SYSVOL '
                    . '(exit=%d). Probable ACCESS_DENIED masqué (droits Administrator manquants). Sortie: %s',
                    self::DISPLAY_NAME,
                    $result->exitCode() ?? -1,
                    $this->scrub(substr($out . $result->errorOutput(), 0, 400)),
                ));
            }

            $remoteSize = (int) $m[1];
            $expectedSize = $this->expectedStartupCmdSize();

            if ($expectedSize !== null) {
                // Pb4 — égalité stricte : un échec d'écriture en re-run (taille
                // divergente du fichier préexistant) devient un échec EXPLICITE.
                if ($remoteSize !== $expectedSize) {
                    throw new RuntimeException(sprintf(
                        'Vérification d\'écriture SYSVOL ÉCHOUÉE pour %s : startup.cmd en SYSVOL fait %d octets, '
                        . '%d attendus (fichier spécialisé). Écriture de CE run non confirmée. Causes probables : '
                        . '(1) le contenu du template a changé sans incrément de GPT.INI [General] Version — '
                        . 'import_gpo (force=false) saute alors la republication SYSVOL et laisse le fichier périmé '
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
                // Source non calculable → on RETOMBE explicitement sur une vérif
                // de PRÉSENCE (taille > 0). Moins fort qu'une vérif d'écriture du run.
                if ($remoteSize <= 0) {
                    throw new RuntimeException(sprintf(
                        'Vérification d\'écriture SYSVOL ÉCHOUÉE pour %s : startup.cmd vide en SYSVOL.',
                        self::DISPLAY_NAME,
                    ));
                }
                $verifyLog->success(['verified' => 'presence_only', 'remote_size' => $remoteSize, 'reason' => 'expected_size_unavailable']);
            }
        } finally {
            if ($previousCcache === false) {
                putenv('KRB5CCNAME');
            } else {
                putenv('KRB5CCNAME=' . $previousCcache);
            }
            $this->destroyTicket($ccache);
        }
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

        // Candidats par ordre de spécificité : OU établissement d'abord.
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

        // Fallback : UAI dans se4fs_name. Pb9 — ancré sur le SUFFIXE attendu
        // `se4fs-<uai>` (`/-(\d{7}[a-z])$/`, pas de `/i` : un faux positif au
        // milieu du nom casserait le DN). Les UAI dans les DN sont en MINUSCULES
        // (`OU=0991229y`) → on normalise en lowercase.
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

    // -----------------------------------------------------------------------
    // Helpers ccache / scrub
    // -----------------------------------------------------------------------

    private function makeTempCcachePath(): string
    {
        return 'FILE:' . sys_get_temp_dir() . '/krb5cc_se_bootstrap_' . bin2hex(random_bytes(6));
    }

    /** Détruit le ticket du ccache dédié (best effort). */
    private function destroyTicket(string $ccache): void
    {
        try {
            Process::env(['KRB5CCNAME' => $ccache])->run(['kdestroy', '-c', $ccache]);
        } catch (\Throwable) {
            // best effort — un ccache fichier résiduel est inerte (purge OS /tmp).
        }
        // Filet : supprimer aussi le fichier si présent.
        $path = preg_replace('/^FILE:/', '', $ccache) ?? $ccache;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Masque tout fragment ressemblant à un secret dans un message destiné aux
     * logs/sortie (défense en profondeur : argv/env ne contiennent jamais le
     * mot de passe, mais une stderr legacy pourrait le refléter).
     */
    private function scrub(string $text): string
    {
        $passwd = $this->adminPassword();
        if ($passwd !== '') {
            $text = str_replace($passwd, '***', $text);
        }

        return $text;
    }

    /**
     * Host SYSVOL pour la vérification d'écriture.
     *
     * Pb2 — ALIGNÉ sur l'hôte de publication legacy : `ad_url($config,'dns')`
     * retourne `se4ad_name` (le FQDN du DC). On NE réutilise PAS
     * `ldap()->getHosts()[0]` qui peut renvoyer une IP → échec
     * SASL/canonicalisation côté smbclient kerberos (faux échec de vérif alors que
     * l'écriture, faite via le FQDN, a réussi).
     */
    private function sysvolHost(): string
    {
        $name = (string) config('sambaedu.se4ad_name', '');
        if ($name !== '') {
            return $name;
        }

        // Fallback best-effort (si se4ad_name non configuré) : premier host LDAP.
        try {
            return app(\App\Config\SambaEduConfig::class)->ldap()->getHosts()[0] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Taille EXACTE attendue du `startup.cmd` une fois spécialisé (Pb4).
     *
     * Reproduit fidèlement la substitution du legacy `specialise_gpo`
     * (`../sambaedu/includes/gpo.inc.php:621`) : pour chaque paramètre, remplace
     * `###_<PARAM>_###` par `config('sambaedu.<param>')` (substitution de chaîne
     * simple, sans regex spéciale ni ré-encodage — le startup.cmd est de l'ASCII).
     * On lit le fichier source STAGÉ (placeholders intacts) et on calcule la
     * taille en octets après substitution. Retourne `null` si la source est
     * introuvable (→ la vérif retombe sur « présence »).
     */
    private function expectedStartupCmdSize(): ?int
    {
        $source = $this->templatesDir() . 'sambaedu-gpo/' . self::DISPLAY_NAME
            . '/Machine/Scripts/Startup/startup.cmd';

        if (! is_file($source)) {
            // Fallback : source versionnée (si le staging n'a pas (encore) eu lieu).
            $source = base_path('resources/gpo/' . self::DISPLAY_NAME . '/Machine/Scripts/Startup/startup.cmd');
            if (! is_file($source)) {
                return null;
            }
        }

        $content = @file_get_contents($source);
        if ($content === false) {
            return null;
        }

        // Mêmes paramètres que le legacy specialise_gpo (ceux pertinents pour un
        // fichier texte ; startup.cmd ne contient que ###_SE4FS_NAME_###).
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

<?php

declare(strict_types=1);

namespace Tests\Integration\Nextcloud;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 61.3 (AC8) — LE CANAL, VERROUILLÉ CONTRE L'INSTANCE RÉELLE.
 *
 * ---------------------------------------------------------------------------
 * **CE QUE CE TEST PROUVE, ET QU'AUCUN DOUBLE NE PEUT PROUVER.**
 *
 *  1. les SÉMANTIQUES du canal sont bien celles que les doubles rejouent — et si
 *     une version ultérieure de l'instance les change, c'est ici qu'on l'apprend,
 *     pas en production ;
 *  2. **POURQUOI LE GROUPE STRUCTUREL D'ADMINISTRATION EXISTE.** Un dossier
 *     d'équipe n'est PAS visible depuis l'espace de fichiers du compte
 *     d'administration tant que ce compte n'appartient pas à un groupe monté sur
 *     ce dossier : mesuré ici, `MKCOL` rend `409 Parent node does not exist`. Après
 *     inscription au groupe structurel, LE MÊME `MKCOL` rend `201`. C'est la
 *     décision n°3 des notes de la story, prise sans mesure et **confirmée ici** ;
 *  3. le MASQUE de clôture `31` n'est **pas coercé** par l'instance : il se relit
 *     tel qu'il a été écrit (décision n°2, également « à confirmer par l'AC8 ») ;
 *  4. la **perception EFFECTIVE d'un compte** : un membre d'un rôle CLOS obtient un
 *     refus sur le dossier fermé, et ce dossier DISPARAÎT de son listing. Aucune
 *     relecture de règle ne dit cela : une règle relue prouve une règle, pas une
 *     perception. C'est pourtant la seule chose qui compte pour l'élève.
 *
 * **LA SÉQUENCE EST CELLE DU BACKEND, DANS SON ORDRE** ({@see
 * \App\Services\Filesystem\Backend\Nextcloud\NextcloudFileBackend}) : dossier
 * d'équipe → groupe STRUCTUREL → interrupteur des permissions avancées →
 * arborescence → règles → **plafonds en dernier**. Un test d'intégration dont la
 * séquence diverge du code qu'il couvre ne prouve rien : le premier passage de ce
 * fichier omettait le groupe structurel et butait sur un `409` que le backend, lui,
 * ne rencontre jamais.
 *
 * **Le canal est appelé NU** (curl + XML montés ici), délibérément : ce fichier
 * mesure le PROTOCOLE, pas notre traduction de celui-ci. Le backend lui-même est
 * exercé de bout en bout par {@see NextcloudFileBackendConvergenceTest}, qui vit à
 * côté et lit le même environnement.
 *
 * ---------------------------------------------------------------------------
 * **SKIPPÉ PAR DÉFAUT.** Il exige `NC_SPIKE_URL`, `NC_SPIKE_ADMIN` et
 * `NC_SPIKE_PASSWORD`, et il vit hors de la suite par défaut
 * (`phpunit.integration.xml`). Il s'exécute depuis le checkout principal, jamais
 * depuis un worktree, et jamais par le développeur.
 *
 * **IL NETTOIE TOUT CE QU'IL CRÉE**, y compris quand il échoue (le nettoyage vit
 * dans `tearDown`, que PHPUnit appelle après un échec d'assertion), et il ne touche
 * JAMAIS l'état de sondage préexistant : dossiers d'équipe `1` et `2`, groupes et
 * comptes énumérés ci-dessous. Une garde défensive relit TOUTES les URL d'écriture
 * émises et le vérifie en fin de scénario.
 *
 * **Cas PHPUnit NU** : ni base, ni conteneur. Le canal est du HTTP et du XML ; le
 * brancher sur le cas de test applicatif le ferait buter sur la garde de base de
 * données du dépôt, pour une base dont il n'a aucun usage.
 */
class NextcloudTeamFolderBackendTest extends TestCase
{
    /** Les dossiers d'équipe de l'instance de sondage : ON N'Y TOUCHE PAS. */
    private const PROTECTED_FOLDER_IDS = [1, 2];

    /** Les groupes de l'état de sondage préexistant : ON N'Y TOUCHE PAS. */
    private const PROTECTED_GROUPS = ['classe3a', 'equipe3a', 'spike603classe'];

    /** Les comptes de l'état de sondage préexistant : ON N'Y TOUCHE PAS. */
    private const PROTECTED_ACCOUNTS = ['admin', 'eleve1', 'prof1', 'se5porteur', 'spike603eleve', 'spike603prof'];

    private const NS_NC = 'http://nextcloud.org/ns';

    /** Le mot de passe des comptes jetables — ils vivent le temps d'un scénario. */
    private const THROWAWAY_PASSWORD = 'Se5Integration!2026';

    /**
     * Les quatre bits que SE5 gouverne. La valeur est recopiée plutôt qu'importée :
     * ce fichier mesure ce que l'instance fait de la valeur, et une constante
     * partagée ferait mentir la mesure si les deux dérivaient ensemble.
     * ({@see \App\Services\Filesystem\Backend\Nextcloud\NextcloudPermissionBits::ALL_MODELLED})
     */
    private const ALL_MODELLED = 15;

    /** Le masque d'une règle de clôture — décision n°2, CONFIRMÉE ICI. */
    private const CLOSURE_MASK = 31;

    private string $url = '';

    private string $admin = '';

    private string $password = '';

    private string $mountPoint = '';

    /** Le groupe STRUCTUREL de ce scénario — l'homologue jetable de `se5_administration`. */
    private string $structuralGroup = '';

    private ?int $folderId = null;

    /** @var list<string> */
    private array $groups = [];

    /** @var list<string> */
    private array $accounts = [];

    /** @var list<string> le relevé BRUT, imprimé en fin de scénario */
    private array $log = [];

    /** @var list<string> toute URL d'écriture émise — la garde défensive s'y adosse */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->url = rtrim((string) (getenv('NC_SPIKE_URL') ?: ''), '/');
        $this->admin = (string) (getenv('NC_SPIKE_ADMIN') ?: '');
        $this->password = (string) (getenv('NC_SPIKE_PASSWORD') ?: '');

        if ($this->url === '' || $this->admin === '' || $this->password === '') {
            $this->markTestSkipped(
                'canal Nextcloud : nécessite NC_SPIKE_URL, NC_SPIKE_ADMIN et NC_SPIKE_PASSWORD '
                . '(instance de sondage, exécution manuelle depuis le checkout principal).'
            );
        }

        // Un suffixe par CAS DE TEST, pas par seconde : deux cas du même fichier
        // s'exécutent dans la même seconde et se marcheraient dessus.
        $suffix = substr((string) time(), -6) . bin2hex(random_bytes(2));

        $this->mountPoint = 'SE5_613_' . $suffix;
        $this->structuralGroup = 'se5it_admin_' . $suffix;
    }

    protected function tearDown(): void
    {
        // NETTOYAGE — dans l'ordre inverse de la création, et sans jamais rien
        // supposer : chaque suppression est tentée, aucune n'est exigée. Il vit ici
        // et pas en fin de scénario pour la seule raison qui compte : un échec
        // d'assertion interrompt le scénario, jamais `tearDown`.
        if ($this->folderId !== null && ! in_array($this->folderId, self::PROTECTED_FOLDER_IDS, true)) {
            $this->rest('DELETE', 'index.php/apps/groupfolders/folders/' . $this->folderId);
        }

        foreach ($this->accounts as $account) {
            if (in_array($account, self::PROTECTED_ACCOUNTS, true)) {
                continue;
            }
            $this->rest('DELETE', 'ocs/v1.php/cloud/users/' . rawurlencode($account));
        }

        foreach ($this->groups as $group) {
            if (in_array($group, self::PROTECTED_GROUPS, true)) {
                continue;
            }
            $this->rest('DELETE', 'ocs/v1.php/cloud/groups/' . rawurlencode($group));
        }

        if ($this->log !== []) {
            fwrite(STDERR, "\n=== RELEVÉ BRUT 61.3 ===\n" . implode("\n", $this->log) . "\n");
        }

        parent::tearDown();
    }

    // =========================================================================
    // LA PREUVE DU 409 — pourquoi le groupe structurel existe
    // =========================================================================

    /**
     * **SANS APPARTENANCE, LE COMPTE D'ADMINISTRATION NE VOIT PAS LE DOSSIER
     * D'ÉQUIPE — ET C'EST TOUTE LA RAISON D'ÊTRE DU GROUPE STRUCTUREL.**
     *
     * Les notes de la story posaient le risque en toutes lettres : « si l'AC8 montre
     * qu'un admin voit les dossiers d'équipe sans appartenance, ce groupe devient
     * inutile et se retire sans rien casser ». La mesure ci-dessous tranche dans
     * l'autre sens : il ne les voit pas. Le canal des règles passant par
     * `/remote.php/dav/files/<admin>/…`, sans ce groupe **aucun sous-dossier n'est
     * créable et aucune règle n'est posable** — le cloisonnement serait affiché et
     * inexistant.
     *
     * Le même `MKCOL` est joué DEUX FOIS, avant et après l'inscription : c'est la
     * seule forme d'assertion qui prouve la CAUSE et pas seulement le symptôme.
     */
    #[Test]
    public function the_admin_cannot_reach_a_team_folder_until_the_structural_group_mounts_it(): void
    {
        $created = $this->rest('POST', 'index.php/apps/groupfolders/folders', ['mountpoint' => $this->mountPoint]);
        $this->note('dossier ' . $this->mountPoint, $created);

        $this->folderId = $this->findFolderId($this->mountPoint);
        self::assertNotNull($this->folderId, 'le dossier doit figurer dans l\'inventaire RELU');
        self::assertNotContains($this->folderId, self::PROTECTED_FOLDER_IDS, 'garde : jamais les dossiers du spike');

        // --- AVANT : le dossier existe côté instance… et n'existe PAS côté admin --
        $unmounted = $this->dav('PROPFIND', $this->mountPoint);
        $this->note('PROPFIND racine SANS appartenance', $unmounted);
        self::assertSame(
            404,
            $unmounted['status'],
            'un dossier d\'équipe créé n\'est PAS dans l\'espace de fichiers du compte d\'administration : '
            . 'il n\'appartient à aucun groupe monté dessus',
        );

        $blocked = $this->dav('MKCOL', $this->mountPoint . '/_travail');
        $this->note('MKCOL SANS groupe structurel (attendu : 409)', $blocked);
        self::assertSame(
            409,
            $blocked['status'],
            'SANS APPARTENANCE, LE MKCOL DU PREMIER NIVEAU ÉCHOUE EN 409 « parent inexistant » — le parent '
            . 'manquant est le dossier d\'équipe LUI-MÊME, invisible depuis l\'espace de l\'admin',
        );
        self::assertStringContainsStringIgnoringCase('parent', $blocked['body'], 'la cause est nommée par l\'instance');

        // --- LA PARADE : le groupe STRUCTUREL, exactement comme le backend le pose -
        $this->ensureStructuralAccess();

        // --- APRÈS : le MÊME geste, et il aboutit ----------------------------
        $mounted = $this->dav('PROPFIND', $this->mountPoint);
        $this->note('PROPFIND racine AVEC appartenance', $mounted);
        self::assertSame(207, $mounted['status'], 'le dossier d\'équipe est désormais monté dans l\'espace de l\'admin');

        $allowed = $this->dav('MKCOL', $this->mountPoint . '/_travail');
        $this->note('MKCOL AVEC groupe structurel (attendu : 201)', $allowed);
        self::assertSame(
            201,
            $allowed['status'],
            'LE MÊME MKCOL ABOUTIT UNE FOIS LE COMPTE INSCRIT AU GROUPE STRUCTUREL : la parade du backend '
            . 'est justifiée PAR LA MESURE, elle n\'est pas une précaution de confort',
        );

        $this->assertNoWriteTouchedTheSpikeState();
    }

    // =========================================================================
    // Le scénario complet, dans l'ordre du backend
    // =========================================================================

    #[Test]
    public function the_measured_channel_still_behaves_as_the_fakes_replay_it(): void
    {
        $members = 'se5it_members_' . substr($this->structuralGroup, -10);
        $managers = 'se5it_managers_' . substr($this->structuralGroup, -10);
        $eleve = 'se5it_eleve_' . substr($this->structuralGroup, -10);
        $prof = 'se5it_prof_' . substr($this->structuralGroup, -10);

        // --- 0. Le décor : groupes et comptes jetables -----------------------
        foreach ([$members, $managers] as $group) {
            $created = $this->rest('POST', 'ocs/v1.php/cloud/groups', ['groupid' => $group]);
            $this->groups[] = $group;
            $this->note('groupe ' . $group, $created);
            self::assertContains($this->ocsCode($created), [100, 200, 102], 'création de groupe');
        }

        foreach ([[$eleve, $members], [$prof, $managers]] as [$login, $group]) {
            $created = $this->rest('POST', 'ocs/v1.php/cloud/users', [
                'userid' => $login,
                'password' => self::THROWAWAY_PASSWORD,
                'groups[]' => $group,
            ]);
            $this->accounts[] = $login;
            $this->note('compte ' . $login, $created);
            self::assertContains($this->ocsCode($created), [100, 200, 102], 'création de compte');
        }

        // --- 1. Le dossier d'équipe, créé PUIS relu -------------------------
        $created = $this->rest('POST', 'index.php/apps/groupfolders/folders', ['mountpoint' => $this->mountPoint]);
        $this->note('dossier ' . $this->mountPoint, $created);

        $this->folderId = $this->findFolderId($this->mountPoint);
        self::assertNotNull($this->folderId, 'le dossier doit figurer dans l\'inventaire RELU');
        self::assertNotContains($this->folderId, self::PROTECTED_FOLDER_IDS, 'garde : jamais les dossiers du spike');

        // ADOPTION AU REJEU : un second appel ne crée pas un second dossier au même
        // point de montage — ou, s'il le fait, la reconnaissance sur le point de
        // montage RELU doit le dire, et c'est ce qu'on mesure.
        $again = $this->findFolderId($this->mountPoint);
        self::assertSame($this->folderId, $again, 'la reconnaissance porte sur le point de montage RELU');

        // --- 2. Le groupe STRUCTUREL — l'étape 2 du backend ------------------
        $this->ensureStructuralAccess();

        $folder = $this->folder($this->folderId);
        self::assertSame(
            self::ALL_MODELLED,
            (int) ($folder['groups'][$this->structuralGroup] ?? -1),
            'le groupe structurel porte les quatre verbes — et pas le bit de re-partage',
        );

        // --- 3. L'INTERRUPTEUR des permissions avancées, AVANT tout plafond ---
        $toggled = $this->rest('POST', 'index.php/apps/groupfolders/folders/' . $this->folderId . '/acl', ['acl' => 1]);
        $this->note('acl=1', $toggled);
        self::assertTrue((bool) ($this->folder($this->folderId)['acl'] ?? false), 'sans cet interrupteur, les règles n\'ont AUCUN effet');

        // Ce que la route n'est PAS : lui passer un principal rend une erreur de
        // requête, jamais une règle.
        $misuse = $this->rest('POST', 'index.php/apps/groupfolders/folders/' . $this->folderId . '/acl', [
            'mappingType' => 'group', 'mappingId' => $members, 'permissions' => 0,
        ]);
        $this->note('acl avec un principal (attendu : refus)', $misuse);
        self::assertSame(400, $misuse['status'], 'cette route est un INTERRUPTEUR, jamais la pose d\'une règle');

        // --- 4. L'arborescence, un niveau à la fois --------------------------
        //
        // Le dossier d'équipe est MONTÉ (étape 2) : le `409` ci-dessous ne peut donc
        // avoir qu'une seule cause — ce protocole ne crée pas les parents. C'est
        // exactement ce que l'assertion prétend mesurer, et elle ne le mesurait pas
        // tant que la racine elle-même manquait.
        $deep = $this->dav('MKCOL', $this->mountPoint . '/_travail/devoirs');
        $this->note('MKCOL parent manquant', $deep);
        self::assertSame(409, $deep['status'], 'ce protocole NE CRÉE PAS les parents');

        self::assertSame(201, $this->dav('MKCOL', $this->mountPoint . '/_travail')['status']);
        self::assertSame(405, $this->dav('MKCOL', $this->mountPoint . '/_travail')['status'], 'rejeu = idempotence');
        self::assertSame(201, $this->dav('MKCOL', $this->mountPoint . '/_travail/devoirs')['status']);
        self::assertSame(201, $this->dav('MKCOL', $this->mountPoint . '/_profs')['status']);

        // --- 5. La CLÔTURE : posée, RELUE, et comparée SUR LE RELU ------------
        $empty = $this->propfindAcl($this->mountPoint . '/_profs');
        $this->note('acl-list avant pose', $empty);
        self::assertStringContainsString('404 Not Found', $empty['body'], '« aucune règle » se dit 404 DANS le multistatus');
        self::assertSame([], $this->aclRulesIn($empty['body'], 'acl-list'), 'et cela vaut ZÉRO règle, jamais une erreur');

        $wanted = [['type' => 'group', 'id' => $members, 'mask' => self::CLOSURE_MASK, 'permissions' => 0]];

        $posted = $this->proppatchAcl($this->mountPoint . '/_profs', $wanted);
        $this->note('PROPPATCH clôture (masque ' . self::CLOSURE_MASK . ')', $posted);

        self::assertSame(207, $posted['status'], 'l\'enveloppe est un 207 — elle ne conclut rien');
        self::assertMatchesRegularExpression(
            '#<d:status>HTTP/1\.1 200 OK</d:status>#',
            $posted['body'],
            'LE VERDICT EST LE STATUT PAR PROPRIÉTÉ, dans le corps',
        );

        $read = $this->propfindAcl($this->mountPoint . '/_profs');
        $this->note('acl-list relue', $read);

        self::assertStringContainsString(
            'acl-mapping-display-name',
            $read['body'],
            'LE SERVEUR AJOUTE UN CHAMP que personne n\'a écrit : la comparaison doit l\'ignorer',
        );

        // **LE MASQUE 31 N'EST PAS COERCÉ** (décision n°2, « à confirmer par l'AC8 »).
        // La comparaison porte sur la valeur RELUE, réduite aux QUATRE champs écrits :
        // le libellé ajouté par le serveur n'entre pas dans le tableau comparé, il ne
        // peut donc pas faire échouer le test — ni masquer une coercition.
        self::assertSame(
            $wanted,
            $this->aclRulesIn($read['body'], 'acl-list'),
            'LA RÈGLE SE RELIT À L\'IDENTIQUE, MASQUE COMPRIS : 31 n\'est pas rabattu par l\'instance, '
            . 'donc la comparaison d\'idempotence du backend ne produit aucune dérive',
        );

        // Et le rejeu ne dérive pas : deuxième écriture identique, même relecture.
        $this->proppatchAcl($this->mountPoint . '/_profs', $wanted);
        self::assertSame(
            $wanted,
            $this->aclRulesIn($this->propfindAcl($this->mountPoint . '/_profs')['body'], 'acl-list'),
            'rejeu d\'une règle identique : ZÉRO dérive sur le relu',
        );

        // L'HÉRITAGE est lisible dans sa propre propriété : c'est le vocabulaire
        // natif de la distinction posé-ici / descendu-d'en-haut.
        $inherited = $this->propfindAcl($this->mountPoint . '/_travail/devoirs');
        $this->note('inherited-acl-list', $inherited);
        self::assertStringContainsString('inherited-acl-list', $inherited['body']);

        // --- 6. LES PLAFONDS, EN DERNIER (le seul geste qui élargit) ----------
        foreach ([[$members, 1], [$managers, self::ALL_MODELLED]] as [$group, $permissions]) {
            $this->rest('POST', 'index.php/apps/groupfolders/folders/' . $this->folderId . '/groups', ['group' => $group]);
            $set = $this->rest(
                'POST',
                'index.php/apps/groupfolders/folders/' . $this->folderId . '/groups/' . rawurlencode($group),
                ['permissions' => $permissions],
            );
            $this->note('permissions ' . $group . ' = ' . $permissions, $set);
        }

        $folder = $this->folder($this->folderId);
        $this->note('carte des groupes relue', ['body' => json_encode($folder['groups'] ?? [])]);

        self::assertSame(1, (int) ($folder['groups'][$members] ?? -1), 'la lecture seule se relit EXACTEMENT');
        self::assertSame(
            self::ALL_MODELLED,
            (int) ($folder['groups'][$managers] ?? -1),
            'les quatre verbes valent 15 — aucune coercition, et le bit de re-partage n\'est pas accordé',
        );

        // --- 7. LA PREUVE : la perception EFFECTIVE d'un compte ---------------
        $eleveOnClosed = $this->davAs($eleve, self::THROWAWAY_PASSWORD, 'PROPFIND', $this->mountPoint . '/_profs');
        $this->note('élève sur le dossier CLOS', $eleveOnClosed);
        self::assertSame(404, $eleveOnClosed['status'], 'le dossier refermé est INATTEIGNABLE pour le rôle clos');

        $eleveListing = $this->davAs($eleve, self::THROWAWAY_PASSWORD, 'PROPFIND', $this->mountPoint, '1');
        $this->note('listing de l\'élève', $eleveListing);
        self::assertStringNotContainsString('_profs', $eleveListing['body'], 'il DISPARAÎT même de son listing');
        self::assertStringContainsString('_travail', $eleveListing['body'], 'le reste de la zone lui reste visible');

        $profOnClosed = $this->davAs($prof, self::THROWAWAY_PASSWORD, 'PROPFIND', $this->mountPoint . '/_profs');
        $this->note('enseignant sur le dossier clos', $profOnClosed);
        self::assertSame(207, $profOnClosed['status'], 'le rôle octroyé, lui, garde son accès');

        // --- 8. Les DEUX plafonds, chacun sur son objet -----------------------
        $this->rest('POST', 'index.php/apps/groupfolders/folders/' . $this->folderId . '/quota', ['quota' => 5368709120]);
        self::assertSame(5368709120, (int) ($this->folder($this->folderId)['quota'] ?? 0), 'plafond de ZONE relu');

        $this->rest('PUT', 'ocs/v2.php/cloud/users/' . rawurlencode($eleve), ['key' => 'quota', 'value' => '1073741824']);
        $account = $this->rest('GET', 'ocs/v2.php/cloud/users/' . rawurlencode($eleve));
        $this->note('plafond de PERSONNE relu', $account);
        self::assertSame(
            1073741824,
            (int) (json_decode($account['body'], true)['ocs']['data']['quota']['quota'] ?? 0),
            'plafond de PERSONNE relu — un autre objet, un autre canal (frontière D8)',
        );

        // --- 9. La RÉVOCATION : sans destruction ------------------------------
        $this->proppatchAcl($this->mountPoint . '/_profs', []);
        foreach ([$members, $managers] as $group) {
            $this->rest('DELETE', 'index.php/apps/groupfolders/folders/' . $this->folderId . '/groups/' . rawurlencode($group));
        }

        $folder = $this->folder($this->folderId);
        self::assertSame(
            [$this->structuralGroup],
            array_keys((array) ($folder['groups'] ?? [])),
            'les groupes du plan ont quitté la carte ; le groupe STRUCTUREL reste — sans lui, plus personne '
            . 'ne pourrait relire ni réparer la zone',
        );
        self::assertSame(207, $this->dav('PROPFIND', $this->mountPoint . '/_profs')['status'], 'le dossier SURVIT');

        // --- 10. LA GARDE DÉFENSIVE -------------------------------------------
        $this->assertNoWriteTouchedTheSpikeState();
    }

    // =========================================================================
    // La séquence du backend, reproduite à l'identique
    // =========================================================================

    /**
     * L'ÉTAPE 2 DU BACKEND, geste pour geste : assurer le groupe structurel, y
     * inscrire le compte d'administration, l'ajouter à la carte du dossier, et lui
     * poser les quatre verbes.
     *
     * Le nom est JETABLE (`se5it_admin_…`) plutôt que le `se5_administration` de
     * production : ce fichier ne doit ni créer ni détruire un objet dont un autre
     * chemin dépend. C'est la seule divergence assumée avec le backend, et elle ne
     * porte que sur le nom.
     */
    private function ensureStructuralAccess(): void
    {
        $group = $this->structuralGroup;

        $created = $this->rest('POST', 'ocs/v1.php/cloud/groups', ['groupid' => $group]);
        $this->groups[] = $group;
        $this->note('groupe STRUCTUREL ' . $group, $created);
        self::assertContains($this->ocsCode($created), [100, 200, 102], 'création du groupe structurel');

        $joined = $this->rest('POST', 'ocs/v1.php/cloud/users/' . rawurlencode($this->admin) . '/groups', [
            'groupid' => $group,
        ]);
        $this->note('compte d\'administration inscrit au groupe structurel', $joined);
        self::assertContains($this->ocsCode($joined), [100, 200, 102], 'inscription du compte d\'administration');

        self::assertNotNull($this->folderId);

        $attached = $this->rest(
            'POST',
            'index.php/apps/groupfolders/folders/' . $this->folderId . '/groups',
            ['group' => $group],
        );
        $this->note('groupe structurel ajouté au dossier', $attached);

        $permissions = $this->rest(
            'POST',
            'index.php/apps/groupfolders/folders/' . $this->folderId . '/groups/' . rawurlencode($group),
            ['permissions' => self::ALL_MODELLED],
        );
        $this->note('permissions du groupe structurel = ' . self::ALL_MODELLED, $permissions);
    }

    /**
     * LA GARDE DÉFENSIVE : aucune URL d'écriture émise ne vise l'état de sondage
     * préexistant — ni les deux dossiers d'équipe, ni les groupes, ni les comptes.
     */
    private function assertNoWriteTouchedTheSpikeState(): void
    {
        foreach ($this->writes as $write) {
            foreach (self::PROTECTED_FOLDER_IDS as $protected) {
                self::assertDoesNotMatchRegularExpression(
                    '#/folders/' . $protected . '(/|$)#',
                    $write,
                    'AUCUNE écriture ne doit viser un dossier d\'équipe préexistant du spike',
                );
            }

            foreach (self::PROTECTED_GROUPS as $protected) {
                self::assertDoesNotMatchRegularExpression(
                    '#/groups/' . preg_quote($protected, '#') . '(\?|$)#',
                    $write,
                    'AUCUNE écriture ne doit viser un groupe préexistant du spike',
                );
            }

            foreach (self::PROTECTED_ACCOUNTS as $protected) {
                if ($protected === $this->admin) {
                    // Le compte d'administration EST inscrit au groupe structurel :
                    // c'est la parade elle-même. Ce qui lui est interdit, c'est la
                    // suppression — mesurée sur le verbe, pas sur l'URL.
                    continue;
                }
                self::assertDoesNotMatchRegularExpression(
                    '#/users/' . preg_quote($protected, '#') . '(/|\?|$)#',
                    $write,
                    'AUCUNE écriture ne doit viser un compte préexistant du spike',
                );
            }
        }
    }

    // =========================================================================
    // Transport
    // =========================================================================

    /**
     * @param  array<string, mixed>  $form
     * @return array{status:int, body:string}
     */
    private function rest(string $method, string $path, array $form = []): array
    {
        $url = $this->url . '/' . ltrim($path, '/') . '?format=json';

        if ($method !== 'GET') {
            $this->writes[] = $url;
        }

        return $this->send($this->admin, $this->password, $method, $url, $form === [] ? null : http_build_query($form), [
            'OCS-APIRequest: true',
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    /** @return array{status:int, body:string} */
    private function dav(string $method, string $path, string $body = '', string $depth = '0'): array
    {
        return $this->davAs($this->admin, $this->password, $method, $path, $depth, $body);
    }

    /**
     * Un geste WebDAV sous l'espace d'un compte donné — c'est ainsi qu'on mesure la
     * PERCEPTION d'un utilisateur, que rien d'autre ne sait dire.
     *
     * @return array{status:int, body:string}
     */
    private function davAs(
        string $login,
        string $password,
        string $method,
        string $path,
        string $depth = '0',
        string $body = '',
    ): array {
        $segments = array_map(rawurlencode(...), array_values(array_filter(explode('/', trim($path, '/')))));
        $url = $this->url . '/remote.php/dav/files/' . rawurlencode($login)
            . ($segments === [] ? '' : '/' . implode('/', $segments));

        if (in_array($method, ['MKCOL', 'PROPPATCH', 'DELETE', 'PUT'], true)) {
            $this->writes[] = $url;
        }

        return $this->send($login, $password, $method, $url, $body === '' ? null : $body, [
            'Depth: ' . $depth,
            'Content-Type: application/xml; charset=UTF-8',
        ]);
    }

    /** @return array{status:int, body:string} */
    private function propfindAcl(string $path): array
    {
        return $this->dav('PROPFIND', $path, '<?xml version="1.0"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop>'
            . '<nc:acl-list/><nc:inherited-acl-list/><nc:acl-enabled/><nc:acl-can-manage/>'
            . '</d:prop></d:propfind>');
    }

    /**
     * @param  list<array{type:string,id:string,mask:int,permissions:int}>  $rules
     * @return array{status:int, body:string}
     */
    private function proppatchAcl(string $path, array $rules): array
    {
        $xml = '';
        foreach ($rules as $rule) {
            $xml .= '<nc:acl>'
                . '<nc:acl-mapping-type>' . $rule['type'] . '</nc:acl-mapping-type>'
                . '<nc:acl-mapping-id>' . $rule['id'] . '</nc:acl-mapping-id>'
                . '<nc:acl-mask>' . $rule['mask'] . '</nc:acl-mask>'
                . '<nc:acl-permissions>' . $rule['permissions'] . '</nc:acl-permissions>'
                . '</nc:acl>';
        }

        return $this->dav('PROPPATCH', $path, '<?xml version="1.0"?>'
            . '<d:propertyupdate xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
            . '<d:set><d:prop><nc:acl-list>' . $xml . '</nc:acl-list></d:prop></d:set>'
            . '</d:propertyupdate>');
    }

    /**
     * @param  list<string>  $headers
     * @return array{status:int, body:string}
     */
    private function send(string $login, string $password, string $method, string $url, ?string $body, array $headers): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $login . ':' . $password,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => (string) $raw];
    }

    // =========================================================================
    // Lecture
    // =========================================================================

    /**
     * Les règles portées par une propriété de liste, réduites AUX QUATRE CHAMPS
     * ÉCRITS.
     *
     * **Le libellé d'affichage que le serveur ajoute n'entre pas ici** — c'est la
     * même réduction que celle du canal de production, et c'est elle qui rend la
     * comparaison d'idempotence stable. Le corps brut, lui, reste assertable
     * séparément : on VÉRIFIE que le champ ajouté est bien là, et on l'ignore.
     *
     * @return list<array{type:string,id:string,mask:int,permissions:int}>
     */
    private function aclRulesIn(string $body, string $property): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($body, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $rules = [];

        foreach ($document->getElementsByTagNameNS(self::NS_NC, $property) as $list) {
            if (! $list instanceof DOMElement) {
                continue;
            }

            foreach ($list->getElementsByTagNameNS(self::NS_NC, 'acl') as $acl) {
                $rules[] = [
                    'type' => (string) $this->childText($acl, 'acl-mapping-type'),
                    'id' => (string) $this->childText($acl, 'acl-mapping-id'),
                    'mask' => (int) $this->childText($acl, 'acl-mask'),
                    'permissions' => (int) $this->childText($acl, 'acl-permissions'),
                ];
            }
        }

        return $rules;
    }

    private function childText(DOMElement $parent, string $local): ?string
    {
        $node = $parent->getElementsByTagNameNS(self::NS_NC, $local)->item(0);

        return $node === null ? null : trim($node->textContent);
    }

    /** @return array<string, mixed> */
    private function folder(int $id): array
    {
        foreach ($this->inventory() as $folder) {
            if ((int) ($folder['id'] ?? 0) === $id) {
                return $folder;
            }
        }

        return [];
    }

    private function findFolderId(string $mountPoint): ?int
    {
        foreach ($this->inventory() as $folder) {
            $relu = trim(trim((string) ($folder['mount_point'] ?? $folder['mountpoint'] ?? '')), '/');
            if ($relu === $mountPoint) {
                return (int) ($folder['id'] ?? 0);
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function inventory(): array
    {
        $response = $this->rest('GET', 'index.php/apps/groupfolders/folders');
        $decoded = json_decode($response['body'], true);
        $payload = $decoded['ocs']['data'] ?? $decoded;

        return array_values(array_filter(is_array($payload) ? $payload : [], 'is_array'));
    }

    /** @param array{status:int, body:string} $response */
    private function ocsCode(array $response): int
    {
        $decoded = json_decode($response['body'], true);

        return (int) ($decoded['ocs']['meta']['statuscode'] ?? 0);
    }

    /** @param array{status?:int, body?:string} $response */
    private function note(string $label, array $response): void
    {
        $this->log[] = sprintf(
            '%-46s HTTP %d %s',
            $label,
            (int) ($response['status'] ?? 0),
            preg_replace('/\s+/', ' ', mb_substr((string) ($response['body'] ?? ''), 0, 420)),
        );
    }
}

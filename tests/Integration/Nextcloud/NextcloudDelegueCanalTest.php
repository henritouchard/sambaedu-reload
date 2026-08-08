<?php

declare(strict_types=1);

namespace Tests\Integration\Nextcloud;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 61.2 — AC9 : LE CANAL DU MODE DÉLÉGUÉ, MESURÉ EN COMPTE ORDINAIRE.
 *
 * ---------------------------------------------------------------------------
 * **CE QUE CE TEST PROUVE, ET LUI SEUL.** Le sondage 60.0 a mesuré les partages
 * OCS **en compte admin** ; la production SE4 prouve le compte porteur **en PHP
 * legacy**. Mais ni la création d'arborescence (`MKCOL`) ni l'octroi par
 * UTILISATEUR **en compte ordinaire** n'avaient été mesurés par nous sur une
 * instance moderne — alors que la sonde du mode délégué et les textes de
 * dégradation affirment ce que ce compte sait faire. Les doubles de test ne
 * prouvent rien là-dessus : ils rejouent ce qu'on croit.
 *
 * Il relève, en compte ORDINAIRE :
 *   1. `PROPFIND` profondeur 0 sur l'espace du porteur — le code exact ;
 *   2. la lecture des capacités et le drapeau d'API de partage ;
 *   3. `MKCOL` d'un dossier, son REJEU (« existe déjà » attendu), et un `MKCOL`
 *      à parent manquant (pas de création récursive) ;
 *   4. l'octroi par UTILISATEUR émis comme propriétaire, et sa RÉÉMISSION
 *      (dédoublonnage vérifié par RELECTURE, jamais présumé) ;
 *   5. l'octroi par GROUPE — attendu en ÉCHEC : c'est précisément la raison pour
 *      laquelle le délégué octroie par utilisateur ;
 *   6. l'autocomplétion en compte ordinaire (la vérification d'identité de l'AC7) ;
 *   7. le REFUS des deux endpoints d'administration, avec leurs codes exacts —
 *      c'est ce relevé qui calibre les messages de la sonde et informe 61.3.
 *
 * **Les appels sont écrits en `Http::` NU, ici, dans le support de test.** Ils ne
 * sont PAS ajoutés au client de production : 61.2 déclare le mode, elle n'exécute
 * aucun plan, et une méthode d'écriture déléguée dans le namespace casserait la
 * garde d'architecture qui interdit à ce code de toucher aux partages. Précédent :
 * le squelette jetable de la story 60.3.
 *
 * **SKIPPÉ PAR DÉFAUT**, et jamais en intégration continue. Il exige
 * `NC_SPIKE_URL`, `NC_SPIKE_ADMIN`, `NC_SPIKE_PASSWORD` (le compte admin sert à
 * fabriquer et à détruire les comptes jetables) et, facultativement,
 * `NC_SPIKE_DELEGATE_USER` / `NC_SPIKE_DELEGATE_PASSWORD` pour réutiliser un compte
 * porteur existant au lieu d'en créer un.
 *
 * **Il laisse l'instance dans l'état où il l'a trouvée** : partages retirés, puis
 * dossiers, puis comptes jetables. Un compte porteur fourni par l'environnement
 * n'est jamais supprimé — on ne détruit pas ce qu'on n'a pas créé.
 *
 * **Exécution : depuis le checkout principal, par l'orchestrateur.** Jamais par le
 * dev, jamais depuis un worktree.
 * ---------------------------------------------------------------------------
 */
class NextcloudDelegueCanalTest extends TestCase
{
    private string $baseUrl = '';

    private string $adminUser = '';

    private string $adminPassword = '';

    private string $delegateUser = '';

    private string $delegatePassword = '';

    /** Comptes créés PAR ce test — les seuls qu'il détruira. */
    private array $throwawayLogins = [];

    /** Identifiant du second compte jetable : la cible de l'octroi. */
    private string $shareTarget = '';

    /** @var list<string> chemins WebDAV créés, dans l'ordre de création */
    private array $createdPaths = [];

    /** @var list<int|string> identifiants de partages émis */
    private array $createdShares = [];

    /**
     * Relevé BRUT des codes observés. Il est affiché en cas d'échec et repris dans
     * les notes de la story : c'est lui, et pas les assertions, qui informe 61.3.
     *
     * @var array<string, mixed>
     */
    private array $observed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUrl = rtrim((string) (getenv('NC_SPIKE_URL') ?: ''), '/');
        $this->adminUser = (string) (getenv('NC_SPIKE_ADMIN') ?: '');
        $this->adminPassword = (string) (getenv('NC_SPIKE_PASSWORD') ?: '');

        if ($this->baseUrl === '' || $this->adminUser === '' || $this->adminPassword === '') {
            $this->markTestSkipped(
                'canal délégué : nécessite NC_SPIKE_URL, NC_SPIKE_ADMIN et NC_SPIKE_PASSWORD (le compte '
                . 'admin fabrique et détruit les comptes jetables). Facultatif : NC_SPIKE_DELEGATE_USER et '
                . 'NC_SPIKE_DELEGATE_PASSWORD pour réutiliser un compte porteur existant. Prérequis '
                . 'd\'instance : le partage doit être activé. Exécution depuis le checkout principal ou '
                . 'par l\'orchestrateur, jamais depuis un worktree.'
            );
        }

        // Amorçage minimal de la façade `Http` : ce cas de test est NU (pas
        // d'application Laravel), et n'a besoin de rien d'autre.
        $container = new Container();
        $container->singleton(Factory::class, static fn (): Factory => new Factory());
        Facade::setFacadeApplication($container);

        $suffix = substr((string) time(), -6) . substr((string) random_int(100, 999), 0, 3);

        $envUser = (string) (getenv('NC_SPIKE_DELEGATE_USER') ?: '');
        $envPassword = (string) (getenv('NC_SPIKE_DELEGATE_PASSWORD') ?: '');

        if ($envUser !== '' && $envPassword !== '') {
            $this->delegateUser = $envUser;
            $this->delegatePassword = $envPassword;
        } else {
            $this->delegateUser = 'zz-se5-porteur-' . $suffix;
            $this->delegatePassword = 'Se5Delegue2026!' . $suffix;
            $this->createAccount($this->delegateUser, $this->delegatePassword);
        }

        $this->shareTarget = 'zz-se5-cible-' . $suffix;
        $this->createAccount($this->shareTarget, 'Se5Cible2026!' . $suffix);
    }

    protected function tearDown(): void
    {
        // L'ordre compte : les partages d'abord (un dossier supprimé laisserait des
        // partages orphelins), puis les dossiers, puis les comptes.
        foreach ($this->createdShares as $id) {
            $this->delegate()->delete($this->url('ocs/v2.php/apps/files_sharing/api/v1/shares/' . $id) . '?format=json');
        }

        foreach (array_reverse($this->createdPaths) as $path) {
            $this->delegate()->delete($this->davPath($path));
        }

        foreach ($this->throwawayLogins as $login) {
            $this->admin()->delete($this->url('ocs/v1.php/cloud/users/' . rawurlencode($login)) . '?format=json');
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    #[Test]
    public function the_delegated_channel_holds_against_a_real_instance(): void
    {
        // === 1. La sonde du mode : l'espace du porteur répond ==================
        $propfind = $this->delegate()
            ->withHeaders(['Depth' => '0'])
            ->send('PROPFIND', $this->davRoot());

        $this->observed['propfind_root'] = $propfind->status();

        self::assertSame(
            207,
            $propfind->status(),
            'la sonde attend un « 207 Multi-Status » — pas un 200. Relevé : ' . $this->dump(),
        );

        // === 2. Les capacités : le partage est-il activé ? ====================
        $capabilities = $this->delegate()->get($this->url('ocs/v1.php/cloud/capabilities') . '?format=json');
        $this->observed['capabilities_http'] = $capabilities->status();

        $sharing = $capabilities->json('ocs.data.capabilities.files_sharing');
        $this->observed['sharing_api_enabled'] = is_array($sharing) ? ($sharing['api_enabled'] ?? null) : null;

        self::assertSame(200, $capabilities->status(), 'un compte ordinaire doit pouvoir lire les capacités');
        self::assertTrue(
            $this->observed['sharing_api_enabled'] === true,
            'le drapeau que lit la sonde doit exister et être vrai sur une instance de partage : ' . $this->dump(),
        );

        // === 3. L'arborescence : MKCOL, rejeu, parent manquant =================
        $root = 'SE5_canal_' . substr((string) time(), -6);

        $create = $this->delegate()->send('MKCOL', $this->davPath($root));
        $this->observed['mkcol_create'] = $create->status();
        if ($create->successful()) {
            $this->createdPaths[] = $root;
        }
        self::assertSame(201, $create->status(), 'création de dossier en compte ordinaire : ' . $this->dump());

        $replay = $this->delegate()->send('MKCOL', $this->davPath($root));
        $this->observed['mkcol_replay'] = $replay->status();
        self::assertSame(
            405,
            $replay->status(),
            '« existe déjà » (405) est un état CONFORME, pas une erreur : c\'est la sémantique '
            . 'd\'idempotence de l\'arborescence déléguée. ' . $this->dump(),
        );

        $orphan = $this->delegate()->send('MKCOL', $this->davPath($root . '_absent/enfant'));
        $this->observed['mkcol_missing_parent'] = $orphan->status();
        self::assertSame(
            409,
            $orphan->status(),
            'PAS de création récursive : les parents se créent un par un, de la racine vers les feuilles. '
            . $this->dump(),
        );

        // Un enfant créé APRÈS son parent, lui, passe.
        $child = $this->delegate()->send('MKCOL', $this->davPath($root . '/eleves'));
        $this->observed['mkcol_child'] = $child->status();
        if ($child->successful()) {
            $this->createdPaths[] = $root . '/eleves';
        }
        self::assertSame(201, $child->status());

        // === 4. L'octroi par UTILISATEUR, émis comme propriétaire ==============
        $share = $this->delegate()->asForm()->post(
            $this->url('ocs/v2.php/apps/files_sharing/api/v1/shares') . '?format=json',
            ['path' => '/' . $root, 'shareType' => 0, 'shareWith' => $this->shareTarget, 'permissions' => 31],
        );

        $this->observed['share_user_http'] = $share->status();
        $this->observed['share_user_ocs'] = $share->json('ocs.meta.statuscode');

        self::assertTrue(
            $share->successful() && in_array((int) $share->json('ocs.meta.statuscode'), [100, 200], true),
            'l\'octroi par UTILISATEUR doit aboutir en compte ordinaire : ' . $this->dump(),
        );

        $shareId = $share->json('ocs.data.id');
        self::assertNotNull($shareId);
        $this->createdShares[] = $shareId;
        $this->observed['share_user_permissions'] = $share->json('ocs.data.permissions');
        $this->observed['share_user_type'] = $share->json('ocs.data.share_type');

        // …et la RÉÉMISSION à l'identique : on ne PRÉSUME pas le dédoublonnage, on
        // le VÉRIFIE par relecture. Un doublon silencieux serait invisible côté
        // SE5 et visible côté utilisateur.
        $reemit = $this->delegate()->asForm()->post(
            $this->url('ocs/v2.php/apps/files_sharing/api/v1/shares') . '?format=json',
            ['path' => '/' . $root, 'shareType' => 0, 'shareWith' => $this->shareTarget, 'permissions' => 31],
        );
        $this->observed['share_user_reemit_ocs'] = $reemit->json('ocs.meta.statuscode');

        $reemitId = $reemit->json('ocs.data.id');
        if ($reemitId !== null && (string) $reemitId !== (string) $shareId) {
            $this->createdShares[] = $reemitId;
        }

        $listing = $this->delegate()->get(
            $this->url('ocs/v2.php/apps/files_sharing/api/v1/shares') . '?format=json&path=/' . rawurlencode($root),
        );
        $this->observed['share_list_http'] = $listing->status();

        $mine = array_values(array_filter(
            (array) ($listing->json('ocs.data') ?? []),
            fn (array $entry): bool => (int) ($entry['share_type'] ?? -1) === 0
                && (string) ($entry['share_with'] ?? '') === $this->shareTarget,
        ));
        $this->observed['share_count_after_reemit'] = count($mine);

        self::assertCount(
            1,
            $mine,
            'la réémission ne doit pas produire un second partage — RELU, pas présumé : ' . $this->dump(),
        );

        // === 5. L'octroi par GROUPE : attendu en ÉCHEC =========================
        // C'est le fait qui justifie tout le modèle : en délégué, les groupes ne
        // sont pas supposés exister, et c'est pourquoi l'octroi est par utilisateur.
        $groupShare = $this->delegate()->asForm()->post(
            $this->url('ocs/v2.php/apps/files_sharing/api/v1/shares') . '?format=json',
            ['path' => '/' . $root, 'shareType' => 1, 'shareWith' => 'Classe_3A', 'permissions' => 31],
        );
        $this->observed['share_group_http'] = $groupShare->status();
        $this->observed['share_group_ocs'] = $groupShare->json('ocs.meta.statuscode');
        $this->observed['share_group_message'] = $groupShare->json('ocs.meta.message');

        self::assertNotContains(
            (int) $groupShare->json('ocs.meta.statuscode'),
            [100, 200],
            'un octroi par GROUPE ne doit PAS aboutir en compte porteur : ' . $this->dump(),
        );

        // === 6. L'autocomplétion, en compte ordinaire (AC7) ====================
        $autocomplete = $this->delegate()->get(
            $this->url('ocs/v2.php/core/autocomplete/get') . '?' . http_build_query([
                'format' => 'json',
                'search' => $this->shareTarget,
                'itemType' => ' ',
                'itemId' => ' ',
                'shareTypes' => [0],
            ]),
        );
        $this->observed['autocomplete_http'] = $autocomplete->status();
        $ids = array_column((array) ($autocomplete->json('ocs.data') ?? []), 'id');
        $this->observed['autocomplete_found_target'] = in_array($this->shareTarget, $ids, true);

        self::assertContains(
            $this->shareTarget,
            $ids,
            'la vérification d\'identité de l\'AC7 repose sur cette lecture en compte ordinaire : ' . $this->dump(),
        );

        // === 7. LES ENDPOINTS D'ADMINISTRATION SONT REFUSÉS ====================
        // Le relevé exact de ces refus est ce qui calibre les messages de la sonde
        // et fonde le gating de l'AC5.
        $storages = $this->delegate()->get($this->url('index.php/apps/files_external/globalstorages'));
        $this->observed['admin_globalstorages'] = $storages->status();

        $users = $this->delegate()->get($this->url('ocs/v2.php/cloud/users') . '?format=json');
        $this->observed['admin_cloud_users_http'] = $users->status();
        $this->observed['admin_cloud_users_ocs'] = $users->json('ocs.meta.statuscode');

        self::assertContains(
            $storages->status(),
            [401, 403],
            'un compte porteur ne doit PAS pouvoir lire les montages globaux : ' . $this->dump(),
        );
        self::assertFalse(
            $users->successful() && in_array((int) $users->json('ocs.meta.statuscode'), [100, 200], true),
            'un compte porteur ne doit PAS pouvoir lister les comptes : ' . $this->dump(),
        );

        // === 8. Le nettoyage est OBSERVABLE ===================================
        $unshare = $this->delegate()->delete(
            $this->url('ocs/v2.php/apps/files_sharing/api/v1/shares/' . $shareId) . '?format=json',
        );
        $this->observed['unshare_http'] = $unshare->status();
        if ($unshare->successful()) {
            $this->createdShares = array_values(array_filter(
                $this->createdShares,
                static fn ($id): bool => (string) $id !== (string) $shareId,
            ));
        }

        self::assertTrue($unshare->successful(), 'le retrait d\'un octroi doit aboutir : ' . $this->dump());

        // Le relevé complet part dans la sortie du test : c'est lui qu'on recopie
        // dans les notes de la story.
        fwrite(STDOUT, PHP_EOL . 'RELEVÉ CANAL DÉLÉGUÉ — ' . $this->dump() . PHP_EOL);
    }

    // =========================================================================
    // Support
    // =========================================================================

    private function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    private function davRoot(): string
    {
        return $this->url('remote.php/dav/files/' . rawurlencode($this->delegateUser) . '/');
    }

    private function davPath(string $relative): string
    {
        return $this->davRoot() . implode('/', array_map('rawurlencode', explode('/', trim($relative, '/'))));
    }

    private function admin(): PendingRequest
    {
        return $this->request($this->adminUser, $this->adminPassword);
    }

    private function delegate(): PendingRequest
    {
        return $this->request($this->delegateUser, $this->delegatePassword);
    }

    private function request(string $user, string $password): PendingRequest
    {
        return Http::withBasicAuth($user, $password)
            ->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
            ->withOptions(['verify' => false])
            ->timeout(20);
    }

    /** Crée un compte ORDINAIRE jetable — et le note pour la destruction. */
    private function createAccount(string $login, string $password): void
    {
        $response = $this->admin()->asForm()->post(
            $this->url('ocs/v1.php/cloud/users') . '?format=json',
            ['userid' => $login, 'password' => $password],
        );

        $code = (int) $response->json('ocs.meta.statuscode');

        self::assertContains(
            $code,
            [100, 102],
            sprintf('impossible de créer le compte jetable « %s » (OCS %d)', $login, $code),
        );

        if ($code === 100) {
            $this->throwawayLogins[] = $login;
        }
    }

    private function dump(): string
    {
        return (string) json_encode($this->observed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

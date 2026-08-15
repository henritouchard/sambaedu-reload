<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Enums\WorkstationEnvironment;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\AgentTtlResolver;
use App\Services\Agent\DesktopPathResolver;
use App\Services\Agent\Providers\ShellFoldersStateProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use App\Services\Agent\WorkstationEnvironmentResolver;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 58.1 — compilation BOUT-EN-BOUT du type `folders` par le
 * `StateCompiler` INCHANGÉ.
 *
 * Le test unitaire du provider prouve QUOI est émis ; celui-ci prouve que
 * l'item arrive bien sur le fil, dans la bonne portée, avec le hash attendu.
 * Un provider correct dont les items n'atteignent pas l'enveloppe serait la
 * même panne silencieuse que celle qu'on répare.
 */
class ShellFoldersCompilationTest extends TestCase
{
    use RefreshDatabase;

    /** Hash de l'item `folders` du golden `state.v1.json` (jumelage croisé PHP↔Go). */
    private const GOLDEN_ITEM_HASH = '4e7caf3747ee9b8f1edf1143dd022f3f8204b46d795de532e563d6dbf3890b73';

    private Workstation $ws;

    private WorkstationGroup $parc;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        $this->ws = Workstation::factory()->create();
        $this->parc = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach($this->parc->id);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function compileFolders(?User $user): array
    {
        $compiler = new StateCompiler(
            new StateHasher(),
            [new ShellFoldersStateProvider(new WorkstationEnvironmentResolver(), new DesktopPathResolver())],
            new AgentTtlResolver(),
        );
        $state = $compiler->compile(TargetContext::for($this->ws, $user));

        return array_values(array_filter(
            $state[StateContract::SCOPE_MACHINE_USER],
            fn ($i): bool => $i['type'] === 'folders',
        ));
    }

    #[Test]
    public function a_shared_local_park_compiles_the_network_desktop_redirection(): void
    {
        $this->parc->update(['environment' => WorkstationEnvironment::SharedLocal]);

        $items = $this->compileFolders($this->user);

        self::assertCount(1, $items);
        self::assertSame('exclusive', $items[0]['semantics']);
        self::assertSame(
            [
                'folder' => 'desktop',
                'path' => '\\\\<se4fs>\\users\\<user>\\Bureau\\',
                'quick_access' => 'pinned',
            ],
            $items[0]['payload'],
        );
        // Jumelage croisé : le hash compilé est BYTE-IDENTIQUE à celui de
        // l'item du golden — donc identique à celui que le hasher Go recalcule.
        self::assertSame(self::GOLDEN_ITEM_HASH, $items[0]['hash']);
    }

    #[Test]
    public function a_perdir_park_compiles_the_local_desktop_redirection(): void
    {
        $this->parc->update(['environment' => WorkstationEnvironment::PersonalLocal]);

        $items = $this->compileFolders($this->user);

        self::assertCount(1, $items, 'le Bureau local est ÉCRIT, jamais tu');
        self::assertSame('%USERPROFILE%\\Desktop\\', $items[0]['payload']['path']);
        // Le hash DIFFÈRE de celui du parc partagé : la bascule d'environnement
        // est bien un changement d'état, donc un ETag neuf pour l'agent.
        self::assertNotSame(self::GOLDEN_ITEM_HASH, $items[0]['hash']);
    }

    /**
     * **Story 63.2 — le test est RETOURNÉ, pas supprimé.**
     *
     * Il épinglait que couper le home déplaçait la redirection sur le Bureau
     * local. C'était la conflation : le home SMB porte à la fois les fichiers de
     * l'utilisateur (qui peuvent déménager) et l'infrastructure de l'agent
     * (Bureau redirigé, `.lnk`, profils applicatifs), qui reste. Déplacer
     * l'espace perso au cloud NE DÉPLACE PLUS la redirection — et le hash de
     * l'item est celui du golden, inchangé.
     */
    #[Test]
    public function putting_the_personal_space_in_the_cloud_leaves_the_redirection_on_the_network_desktop(): void
    {
        $this->parc->update(['environment' => WorkstationEnvironment::SharedLocal]);
        FileLocationService::set(FileLocations::make(
            FileBackendName::Nextcloud,
            FileBackendName::Nextcloud,
            ActiveCloud::Nextcloud,
        ));

        $items = $this->compileFolders($this->user);

        self::assertSame('\\\\<se4fs>\\users\\<user>\\Bureau\\', $items[0]['payload']['path']);
        self::assertSame(self::GOLDEN_ITEM_HASH, $items[0]['hash']);
    }

    #[Test]
    public function a_machine_only_target_carries_no_folders_item(): void
    {
        // Aucune session ⇒ aucune ruche utilisateur à écrire. Le type est
        // ABSENT de l'enveloppe (contrat §8 : l'agent ne touche à rien).
        self::assertSame([], $this->compileFolders(null));
    }
}

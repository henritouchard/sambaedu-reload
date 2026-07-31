<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

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
use App\Services\FilePolicyService;
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
    private const GOLDEN_ITEM_HASH = 'b45b37cc5f345ca8b079c26d5a77c3d26864b14450da6c66dc22e4a494f17bb7';

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
        FilePolicyService::setGlobal(true, true, false);

        $items = $this->compileFolders($this->user);

        self::assertCount(1, $items);
        self::assertSame('exclusive', $items[0]['semantics']);
        self::assertSame(
            ['folder' => 'desktop', 'path' => '\\\\<se4fs>\\users\\<user>\\Bureau\\'],
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
        FilePolicyService::setGlobal(true, true, false);

        $items = $this->compileFolders($this->user);

        self::assertCount(1, $items, 'le Bureau local est ÉCRIT, jamais tu');
        self::assertSame('%USERPROFILE%\\Desktop\\', $items[0]['payload']['path']);
        // Le hash DIFFÈRE de celui du parc partagé : la bascule d'environnement
        // est bien un changement d'état, donc un ETag neuf pour l'agent.
        self::assertNotSame(self::GOLDEN_ITEM_HASH, $items[0]['hash']);
    }

    #[Test]
    public function cutting_the_home_capability_moves_the_redirection_to_the_local_desktop(): void
    {
        $this->parc->update(['environment' => WorkstationEnvironment::SharedLocal]);
        // Home coupé : le Bureau réseau vit DANS le home — le laisser pointer
        // là serait rediriger vers un dossier que l'utilisateur ne peut plus
        // atteindre.
        FilePolicyService::setGlobal(false, true, false);

        $items = $this->compileFolders($this->user);

        self::assertSame('%USERPROFILE%\\Desktop\\', $items[0]['payload']['path']);
    }

    #[Test]
    public function a_machine_only_target_carries_no_folders_item(): void
    {
        // Aucune session ⇒ aucune ruche utilisateur à écrire. Le type est
        // ABSENT de l'enveloppe (contrat §8 : l'agent ne touche à rien).
        self::assertSame([], $this->compileFolders(null));
    }
}

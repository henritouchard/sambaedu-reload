<?php

declare(strict_types=1);

namespace Tests\Unit\Ldap;

use App\Gpo\Support\SambaToolRunner;
use App\Ldap\AdUserManager;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `AdUserManager` — Story 16.3b (correctifs post-review 2026-05-12).
 *
 * Mock complet de `SambaToolRunner` : aucun appel shell réel. Vérifie le
 * contrat (args passés, gestion erreurs, idempotence "already exists",
 * validation regex samAccountName).
 */
class AdUserManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeResult(int $exitCode, string $output = '', string $errorOutput = ''): ProcessResult
    {
        $mock = Mockery::mock(ProcessResult::class);
        $mock->shouldReceive('exitCode')->andReturn($exitCode);
        $mock->shouldReceive('output')->andReturn($output);
        $mock->shouldReceive('errorOutput')->andReturn($errorOutput);
        return $mock;
    }

    #[Test]
    public function exists_returns_true_when_user_in_list(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->with(['user', 'list'])
            ->andReturn($this->makeResult(0, "Administrator\nread.user\nguest\n"));

        $manager = new AdUserManager($runner);
        $this->assertTrue($manager->exists('read.user'));
    }

    #[Test]
    public function exists_returns_false_when_user_not_in_list(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldReceive('run')
            ->andReturn($this->makeResult(0, "Administrator\nguest\n"));

        $manager = new AdUserManager($runner);
        $this->assertFalse($manager->exists('read.user'));
    }

    #[Test]
    public function exists_returns_false_on_non_zero_exit(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldReceive('run')
            ->andReturn($this->makeResult(1, '', 'samba-tool: command failed'));

        $manager = new AdUserManager($runner);
        $this->assertFalse($manager->exists('read.user'));
    }

    #[Test]
    public function exists_rejects_invalid_samaccountname(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldNotReceive('run'); // pas d'appel shell !

        $manager = new AdUserManager($runner);
        $this->assertFalse($manager->exists('bad; rm -rf /'));
        $this->assertFalse($manager->exists(''));
    }

    #[Test]
    public function exists_strict_match_no_substring(): void
    {
        // Garantit que `read.user` ne matche pas `read.user-1234567a`.
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldReceive('run')
            ->andReturn($this->makeResult(0, "read.user-1234567a\n"));

        $manager = new AdUserManager($runner);
        $this->assertFalse($manager->exists('read.user'));
        $this->assertTrue($manager->exists('read.user-1234567a'));
    }

    #[Test]
    public function create_calls_samba_tool_with_correct_args(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(function (array $args): bool {
                    return $args[0] === 'user'
                        && $args[1] === 'create'
                        && $args[2] === 'read.user'
                        && $args[3] === 'Super-Secret-Pwd-1234'
                        && in_array('--use-username-as-cn', $args, true);
                })
            )
            ->andReturn($this->makeResult(0));

        $manager = new AdUserManager($runner);
        $this->assertTrue($manager->create('read.user', 'Super-Secret-Pwd-1234'));
    }

    #[Test]
    public function create_includes_description_attribute(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(function (array $args): bool {
                    return in_array('--description=compte service AD', $args, true);
                })
            )
            ->andReturn($this->makeResult(0));

        $manager = new AdUserManager($runner);
        $this->assertTrue($manager->create('read.user', 'Pwd-1234567890', [
            'description' => 'compte service AD',
        ]));
    }

    #[Test]
    public function create_is_idempotent_on_already_exists_error(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);

        // 1er appel = create() retourne "already exists" + non-zero.
        // 2ème appel (depuis exists() côté re-check) = user list contient le compte.
        $runner->shouldReceive('run')
            ->with(Mockery::on(fn(array $args): bool => $args[1] === 'create'))
            ->andReturn($this->makeResult(255, '', 'ERROR: User read.user already exists'));
        $runner->shouldReceive('run')
            ->with(Mockery::on(fn(array $args): bool => $args[1] === 'list'))
            ->andReturn($this->makeResult(0, "read.user\n"));

        $manager = new AdUserManager($runner);
        $this->assertTrue(
            $manager->create('read.user', 'Pwd-1234567890'),
            'already exists + re-check exists() → succès idempotent'
        );
    }

    #[Test]
    public function create_rejects_empty_password(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldNotReceive('run');

        $manager = new AdUserManager($runner);
        $this->assertFalse($manager->create('read.user', ''));
    }

    #[Test]
    public function create_rejects_invalid_samaccountname(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldNotReceive('run');

        $manager = new AdUserManager($runner);
        $this->assertFalse($manager->create('bad name with space', 'Pwd-12345678'));
        $this->assertFalse($manager->create('bad;injection', 'Pwd-12345678'));
    }

    #[Test]
    public function set_password_calls_samba_tool(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(function (array $args): bool {
                    return $args[0] === 'user'
                        && $args[1] === 'setpassword'
                        && $args[2] === 'read.user'
                        && in_array('--newpassword=NewPwd-1234567', $args, true);
                })
            )
            ->andReturn($this->makeResult(0));

        $manager = new AdUserManager($runner);
        $this->assertTrue($manager->setPassword('read.user', 'NewPwd-1234567'));
    }

    #[Test]
    public function set_password_returns_false_on_failure(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldReceive('run')
            ->andReturn($this->makeResult(1, '', 'password policy violation'));

        $manager = new AdUserManager($runner);
        $this->assertFalse($manager->setPassword('read.user', 'too-weak'));
    }

    #[Test]
    public function set_password_rejects_invalid_input(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldNotReceive('run');

        $manager = new AdUserManager($runner);
        $this->assertFalse($manager->setPassword('bad;injection', 'Pwd-12345678'));
        $this->assertFalse($manager->setPassword('read.user', ''));
    }

    #[Test]
    public function validate_password_rejects_invalid_samaccountname(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $manager = new AdUserManager($runner);

        $this->assertFalse($manager->validatePassword('bad;injection', 'pwd'));
        $this->assertFalse($manager->validatePassword('read.user', ''));
    }

    #[Test]
    public function exists_handles_runner_exception(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldReceive('run')->andThrow(new \RuntimeException('samba-tool not found'));

        $manager = new AdUserManager($runner);
        $this->assertFalse($manager->exists('read.user'));
    }

    #[Test]
    public function create_handles_runner_exception(): void
    {
        $runner = Mockery::mock(SambaToolRunner::class);
        $runner->shouldReceive('run')->andThrow(new \RuntimeException('samba-tool not found'));

        $manager = new AdUserManager($runner);
        $this->assertFalse($manager->create('read.user', 'Pwd-12345678'));
    }
}

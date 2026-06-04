<?php

declare(strict_types=1);

namespace Tests\Feature\E2e;

use App\Contracts\Ad\AdCredentialValidator;
use App\Contracts\Ad\AdDirectory;
use App\Ldap\Fakes\FakeAdDirectory;
use App\Ldap\Fakes\FakeAdRecorder;
use App\Ldap\Fakes\FakeE2eAdCredentialValidator;
use App\Ldap\Fakes\FakeSambaToolRunner;
use App\Ldap\Real\RealAdDirectory;
use App\Models\E2e\AdWriteLog;
use App\Models\User;
use App\Services\Auth\RealAdCredentialValidator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 21.2 — Tests host du fake AD (T7b/c/d).
 *
 * Stratégie iso-21.1 : on tourne sur le canal PHPUnit existant (SQLite
 * :memory:, APP_ENV=testing) et on n'effectue AUCUNE I/O réelle (pas de bind
 * LDAP, pas de process samba-tool). La table `e2e_ad_writes` (créée en e2e
 * uniquement par la migration) est recréée à la main ici pour permettre
 * l'assertion sur la capture.
 *
 * Couvre :
 *  - (b) le fake samba-tool CAPTURE une écriture sans exécuter de process ;
 *  - (c) l'auth fake VALIDE un user seedé sans bind LDAP (résolution + mdp) ;
 *  - (d) NON-RÉGRESSION : hors e2e, les bindings RÉELS sont en place.
 */
class FakeAdCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // La migration `e2e_ad_writes` no-op hors e2e → on crée la table à la
        // main pour les assertions de capture (SQLite :memory:).
        if (! Schema::hasTable('e2e_ad_writes')) {
            Schema::create('e2e_ad_writes', function (Blueprint $table): void {
                $table->id();
                $table->string('action_type');
                $table->string('target')->nullable();
                $table->string('fake_guid')->nullable();
                $table->json('payload')->nullable();
                $table->string('channel')->nullable();
                $table->timestamps();
            });
        }
    }

    #[Test]
    public function fake_samba_tool_capture_une_ecriture_sans_process(): void
    {
        // AC1/AC7 : aucune exécution de process réelle. Process::fake() permet
        // d'asserter qu'aucune commande n'a été lancée.
        Process::fake();

        $runner = new FakeSambaToolRunner(new FakeAdRecorder());

        $result = $runner->run(['user', 'create', 'jdoe', 'S3cret!']);

        // Réponse cohérente : succès (exit 0) → le parcours aboutit (AC2).
        $this->assertSame(0, $result->exitCode());

        // L'écriture est capturée dans le journal.
        $this->assertDatabaseHas('e2e_ad_writes', [
            'action_type' => 'ad.user.create',
            'target' => 'jdoe',
        ]);

        // GUID factice stable attribué.
        $entry = AdWriteLog::where('target', 'jdoe')->first();
        $this->assertNotNull($entry);
        $this->assertSame((new FakeAdRecorder())->guidFor('jdoe'), $entry->fake_guid);

        // Le mot de passe n'est JAMAIS persisté en clair.
        $this->assertStringNotContainsString('S3cret!', json_encode($entry->payload));

        // Aucun process samba-tool réel n'a été lancé.
        Process::assertNothingRan();
    }

    #[Test]
    public function fake_samba_tool_ne_journalise_pas_les_lectures(): void
    {
        Process::fake();
        $runner = new FakeSambaToolRunner(new FakeAdRecorder());

        $result = $runner->run(['user', 'list']);

        $this->assertSame(0, $result->exitCode());
        // `user list` est une lecture → aucune écriture journalisée.
        $this->assertDatabaseCount('e2e_ad_writes', 0);
        Process::assertNothingRan();
    }

    #[Test]
    public function auth_fake_valide_un_user_seede_sans_bind(): void
    {
        // (c) — Résolution + validation du mot de passe via les fakes, sans
        // aucun bind LDAP réel.
        config(['e2e.fake_ad_password' => 'e2e-pass']);

        // User seedé en Postgres (ici SQLite) — pas d'AD.
        User::create([
            'login' => 'alice',
            'fullname' => 'Alice Test',
            'role' => 'profs',
            'is_active' => true,
        ]);

        $directory = new FakeAdDirectory();
        $ldapUser = $directory->findUserByLogin('alice');

        // Résolution OK sans LDAP : DN canonique + login.
        $this->assertNotNull($ldapUser);
        $this->assertSame('alice', $ldapUser->getLogin());
        $expectedDn = FakeAdDirectory::dnForLogin('alice');
        $this->assertSame($expectedDn, $ldapUser->getDn());

        // Validation du mot de passe via le DN, sans bind réel.
        $validator = new FakeE2eAdCredentialValidator();
        $this->assertTrue($validator->attemptBind($expectedDn, 'e2e-pass'));
        $this->assertFalse($validator->attemptBind($expectedDn, 'wrong-pass'));
    }

    #[Test]
    public function auth_fake_refuse_un_login_inconnu(): void
    {
        $directory = new FakeAdDirectory();
        $this->assertNull($directory->findUserByLogin('ghost'));
    }

    #[Test]
    public function hors_e2e_les_bindings_ad_reels_sont_en_place(): void
    {
        // (d) NON-RÉGRESSION (AC5) : en `testing`, le container résout les
        // implémentations RÉELLES — JAMAIS les fakes.
        $this->assertSame('testing', app()->environment());

        $this->assertInstanceOf(
            RealAdCredentialValidator::class,
            app(AdCredentialValidator::class),
        );
        $this->assertInstanceOf(
            RealAdDirectory::class,
            app(AdDirectory::class),
        );

        // SambaToolRunner résout le type concret réel (pas la sous-classe fake).
        // Double assertion (review 21-2 P-10) : type attendu ET absence du fake
        // — un refactor de binding ne passerait pas inaperçu.
        $runner = app(\App\Gpo\Support\SambaToolRunner::class);
        $this->assertInstanceOf(\App\Gpo\Support\SambaToolRunner::class, $runner);
        $this->assertNotInstanceOf(FakeSambaToolRunner::class, $runner);
    }
}

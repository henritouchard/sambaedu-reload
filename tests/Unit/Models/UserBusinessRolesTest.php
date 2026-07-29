<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\SambaRole;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Story 54.3 (AC1, AR8, FR14) — `User::businessRoles()` : LA résolution
 * canonique du rôle métier, 100 % Postgres.
 *
 * Matrice complète de normalisation de `users.role` (singulier/pluriel/casse/
 * espaces), l'exclusivité Spatie `super-admin` pour le rôle `admin`, et la
 * preuve que la résolution n'émet AUCUNE requête LDAP (aucun mock LDAP requis
 * — l'absence même de mock est la preuve).
 */
class UserBusinessRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder())->run();
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function roleNormalizationProvider(): array
    {
        return [
            'prof singulier' => ['prof', ['prof']],
            'prof pluriel (auto-provisioning)' => ['profs', ['prof']],
            'eleve singulier' => ['eleve', ['eleve']],
            'eleve pluriel (auto-provisioning)' => ['eleves', ['eleve']],
            'administratif singulier' => ['administratif', ['administratif']],
            'administratif pluriel (auto-provisioning)' => ['administratifs', ['administratif']],
            "'admin' brut → administratif JAMAIS admin" => ['admin', ['administratif']],
            'autre' => ['autre', []],
            'federated' => ['federated', []],
            'vide' => ['', []],
            'casse et espaces : PROF ' => ['  PROF  ', ['prof']],
            'casse et espaces : Eleves' => [' Eleves ', ['eleve']],
        ];
    }

    #[Test]
    #[DataProvider('roleNormalizationProvider')]
    public function normalizes_users_role_without_spatie(string $rawRole, array $expected): void
    {
        $user = $this->makeUser($rawRole);

        $this->assertSame($expected, $user->businessRoles());
    }

    #[Test]
    public function super_admin_alone_yields_admin_only(): void
    {
        $user = $this->makeUser('autre');
        $user->assignRole(SambaRole::SuperAdmin->value);

        $this->assertSame(['admin'], $user->businessRoles());
    }

    #[Test]
    public function prof_delegated_super_admin_yields_both_roles(): void
    {
        $user = $this->makeUser('prof');
        $user->assignRole(SambaRole::SuperAdmin->value);

        $this->assertSame(['prof', 'admin'], $user->businessRoles());
    }

    #[Test]
    public function a_non_super_admin_spatie_role_never_yields_admin(): void
    {
        // Un rôle Spatie 'prof' matérialisé (hors sync réelle, cf. Dev Notes
        // 49.1 non implémentée) ne doit JAMAIS produire 'admin'.
        $user = $this->makeUser('autre');
        $user->assignRole(SambaRole::Prof->value);

        $this->assertSame([], $user->businessRoles());
    }

    #[Test]
    public function resolution_never_touches_ldap(): void
    {
        // ⚠️ L'argument « aucun mock LDAP n'est enregistré, donc un accès LDAP
        // ferait échouer ce test » était FAUX : `isProf()` s'écrit
        // `ldapBusinessObject()?->isProf() ?? ($this->role === 'prof')`, et
        // `LdapUser::findByLogin()` est un `->first()` qui peut renvoyer `null`
        // sans lever. Une implémentation LDAP-first serait retombée sur le
        // fallback et ce test serait resté VERT — exactement le défaut relevé
        // en review 54.2 (#4) : un commentaire décrivant une propriété que rien
        // ne verrouille, ici sur le modèle le plus transverse du dépôt.
        //
        // Preuve déterministe : TOUT chemin LDAP (`getLdapUser()`,
        // `ldapBusinessObject()`) pose une clé dans `self::$ldapCache`, même
        // quand le résultat est `null`. Un cache resté vide = aucun chemin LDAP
        // emprunté.
        $cache = new ReflectionProperty(User::class, 'ldapCache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);

        $user = $this->makeUser('prof');
        $user->assignRole(SambaRole::SuperAdmin->value);

        $this->assertSame(['prof', 'admin'], $user->businessRoles());

        $this->assertSame(
            [],
            $cache->getValue(),
            'businessRoles() ne doit emprunter AUCUN chemin LDAP (1 aller-retour par page vue, par utilisateur)',
        );
    }
}

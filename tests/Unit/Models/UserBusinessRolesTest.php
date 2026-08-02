<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\SambaRole;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
        // Story 54.3 — la preuve reposait sur le cache statique `User::$ldapCache` :
        // tout chemin LDAP y posait une clé (même pour un résultat `null`), un
        // cache resté vide prouvait donc qu'aucun n'avait été emprunté.
        //
        // Story 49.2 — ce cache et toute la chaîne LDAP-lazy du modèle
        // (`getLdapUser()`, `ldapBusinessObject()`, `isProf()`, `isEleve()`,
        // `isAdmin()`) ont été SUPPRIMÉS (FR-R3). La propriété est désormais
        // STRUCTURELLE : `App\Models\User` n'a plus aucun chemin vers l'annuaire.
        // On la verrouille en conséquence — si quelqu'un réintroduit un jour un
        // helper LDAP-first sur ce modèle, ce test le signale.
        $this->assertFalse(
            method_exists(User::class, 'ldapBusinessObject'),
            'Aucun chemin LDAP-lazy ne doit être réintroduit sur App\Models\User (FR-R3)',
        );
        $this->assertFalse(method_exists(User::class, 'getLdapUser'));
        foreach (['isProf', 'isEleve', 'isAdmin', 'getGroups'] as $removed) {
            $this->assertFalse(
                method_exists(User::class, $removed),
                "Le prédicat scolaire {$removed}() a été supprimé par la Story 49.2 (FR-R3) : "
                . 'il ne doit pas revenir, même réécrit en SQL.',
            );
        }
        $this->assertFalse(
            property_exists(User::class, 'ldapCache'),
            'Le cache LDAP request-scope a été supprimé avec la chaîne qu\'il servait',
        );

        $user = $this->makeUser('prof');
        $user->assignRole(SambaRole::SuperAdmin->value);

        $this->assertSame(['prof', 'admin'], $user->businessRoles());
    }
}

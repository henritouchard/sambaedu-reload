<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Federated;

use App\Auth\Federated\FederatedRoleMapper;
use App\Enums\SambaRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Story 20.3 — D-1 (pivot Henri 2026-06-03). Résolution du rôle asséré par
 * LOOKUP DIRECT dans les rôles EXISTANTS de l'instance (table Spatie `roles`,
 * guard `web`) : rôle existant → nom canonique renvoyé ; normalisation
 * casse/espaces ; rôle absent en base → null (→ 403) ; aucun wildcard/fallback ;
 * `super-admin` existant → renvoyé (modèle ouvert D-5). Plus AUCUNE lecture de
 * `config('federated_auth.role_map')` (table supprimée).
 */
class FederatedRoleMapperTest extends TestCase
{
    private FederatedRoleMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new FederatedRoleMapper();
        $this->ensureRolesTable();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureRolesTable(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }
    }

    private function seedRole(string $name, string $guard = 'web'): void
    {
        Role::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
    }

    #[Test]
    public function resolves_existing_role_to_its_canonical_name(): void
    {
        $this->seedRole(SambaRole::Technicien->value);

        $this->assertSame('technicien', $this->mapper->resolve('technicien'));
    }

    #[Test]
    public function resolution_is_case_insensitive(): void
    {
        $this->seedRole(SambaRole::Technicien->value);

        // Le nom CANONIQUE (tel qu'en base) est renvoyé, quelle que soit la casse asséré.
        $this->assertSame('technicien', $this->mapper->resolve('Technicien'));
        $this->assertSame('technicien', $this->mapper->resolve('TECHNICIEN'));
    }

    #[Test]
    public function resolution_trims_surrounding_whitespace(): void
    {
        $this->seedRole(SambaRole::Technicien->value);

        $this->assertSame('technicien', $this->mapper->resolve('  technicien  '));
        $this->assertSame('technicien', $this->mapper->resolve(" \tTECHNICIEN\n"));
    }

    #[Test]
    public function resolves_multiple_distinct_existing_roles(): void
    {
        $this->seedRole(SambaRole::Technicien->value);
        $this->seedRole(SambaRole::ReferentNumerique->value);
        $this->seedRole(SambaRole::ComputerAdmin->value);

        $this->assertSame('technicien', $this->mapper->resolve('technicien'));
        $this->assertSame('referent-numerique', $this->mapper->resolve('referent-numerique'));
        $this->assertSame('computer-admin', $this->mapper->resolve('computer-admin'));
    }

    #[Test]
    public function resolves_custom_role_not_in_enum_when_present_in_db(): void
    {
        // D-5 — modèle ouvert : un rôle créé hors enum (par controlHub) est
        // demandable s'il EXISTE en base.
        $this->seedRole('flotte-custom');

        $this->assertSame('flotte-custom', $this->mapper->resolve('Flotte-Custom'));
    }

    #[Test]
    public function returns_db_canonical_casing_not_the_normalized_lowercase(): void
    {
        // Invariant clé : le nom renvoyé (passé à syncRoles) doit être le nom
        // CANONIQUE tel que stocké en base, PAS la version normalisée en minuscules
        // — sinon syncRoles ne retrouverait pas le rôle / en créerait un mal nommé.
        $this->seedRole('Flotte-Custom');

        $this->assertSame('Flotte-Custom', $this->mapper->resolve('flotte-custom'));
        $this->assertSame('Flotte-Custom', $this->mapper->resolve('  FLOTTE-CUSTOM  '));
    }

    #[Test]
    public function role_absent_from_db_returns_null(): void
    {
        // Aucun rôle seedé : tout nom asséré est inconnu → null → 403.
        $this->assertNull($this->mapper->resolve('pirate-role'));

        // Un enum valide MAIS absent de la base reste null (aucune création).
        $this->assertNull($this->mapper->resolve(SambaRole::Technicien->value));
    }

    #[Test]
    public function empty_or_whitespace_role_returns_null(): void
    {
        $this->seedRole(SambaRole::Technicien->value);

        $this->assertNull($this->mapper->resolve(''));
        $this->assertNull($this->mapper->resolve('   '));
    }

    #[Test]
    public function no_wildcard_or_default_fallback(): void
    {
        // Même si des rôles existent, un nom asséré inconnu ne capture rien.
        $this->seedRole(SambaRole::Technicien->value);
        $this->seedRole(SambaRole::SuperAdmin->value);

        $this->assertNull($this->mapper->resolve('inconnu'));
        $this->assertNull($this->mapper->resolve('*'));
        $this->assertNull($this->mapper->resolve('default'));
    }

    #[Test]
    public function super_admin_resolves_when_it_exists(): void
    {
        // D-5 — super-admin n'est pas bloqué : s'il existe, il est demandable.
        $this->seedRole(SambaRole::SuperAdmin->value);

        $this->assertSame('super-admin', $this->mapper->resolve('super-admin'));
        $this->assertSame('super-admin', $this->mapper->resolve(' SUPER-ADMIN '));
    }

    #[Test]
    public function other_guard_role_is_not_matched(): void
    {
        // Seul le guard `web` est éligible (parité avec le reste de l'auth SE5).
        $this->seedRole(SambaRole::Technicien->value, 'api');

        $this->assertNull($this->mapper->resolve('technicien'));
    }

    #[Test]
    public function resolution_is_idempotent(): void
    {
        $this->seedRole(SambaRole::Technicien->value);

        $first = $this->mapper->resolve('Technicien');
        $second = $this->mapper->resolve('technicien');

        $this->assertSame($first, $second);
        $this->assertSame('technicien', $first);
    }
}

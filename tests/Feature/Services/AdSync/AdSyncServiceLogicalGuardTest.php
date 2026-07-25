<?php

declare(strict_types=1);

namespace Tests\Feature\Services\AdSync;

use App\Models\WorkstationGroup;
use App\Services\AdSync\AdSyncService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 38.7 / AC3 — défense en profondeur : les méthodes d'écriture AD de
 * {@see AdSyncService} refusent un groupe LOGIQUE (`is_physical = false`) AVANT
 * toute requête LDAP. `OU=Parcs` est en lecture seule ; le chemin normal ne doit
 * jamais atteindre ces méthodes avec un groupe logique (l'observer filtre en
 * amont), mais la garde le prouve par appel direct — et le fait SANS contacter
 * l'annuaire (les tests tournent sur HÔTE, sans AD).
 */
class AdSyncServiceLogicalGuardTest extends TestCase
{
    private function service(): AdSyncService
    {
        return app(AdSyncService::class);
    }

    private function logical(string $name = 'parc-logique'): WorkstationGroup
    {
        // Instance non persistée : les gardes ne lisent que name / is_physical.
        return new WorkstationGroup(['name' => $name, 'is_physical' => false]);
    }

    #[Test]
    public function create_refuses_a_logical_group_without_ldap(): void
    {
        $result = $this->service()->createWorkstationGroup($this->logical());

        $this->assertFalse($result['success']);
        $this->assertNull($result['guid']);
        $this->assertNull($result['dn']);
        $this->assertStringContainsString('lecture seule', $result['error']);
    }

    #[Test]
    public function rename_refuses_a_logical_group_without_ldap(): void
    {
        $result = $this->service()->renameWorkstationGroup($this->logical(), 'ancien', 'nouveau');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('lecture seule', $result['error']);
    }

    #[Test]
    public function delete_refuses_when_not_physical_without_ldap(): void
    {
        $result = $this->service()->deleteWorkstationGroupByName('parc-logique', null, isPhysical: false);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('lecture seule', $result['error']);
    }

    /**
     * Défaut n°3 du contexte de la story : jadis `moveWorkstationGroup()` sur un
     * groupe logique atteignait `findSalleOu()` (null → « OU salle non trouvée »
     * → throw en queue sync → exception dans la requête HTTP). La garde 38.7 le
     * refuse d'abord — plus aucune requête LDAP, plus d'exception traversante.
     */
    #[Test]
    public function move_refuses_a_logical_group_before_reaching_ldap(): void
    {
        $result = $this->service()->moveWorkstationGroup($this->logical(), null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('lecture seule', $result['error']);
        // Le message n'est PLUS « OU salle non trouvée » : preuve que findSalleOu()
        // (donc le LDAP) n'est jamais atteint.
        $this->assertStringNotContainsString('non trouvée', $result['error']);
    }
}

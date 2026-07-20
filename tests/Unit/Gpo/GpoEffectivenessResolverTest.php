<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Services\Gpo\GpoEffectivenessResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cœur pur de {@see GpoEffectivenessResolver} — aucun annuaire requis.
 *
 * Le contresens corrigé par cette classe : l'ancien listing badgeait « Active »
 * sur `versionNumber > 0`, c'est-à-dire « éditée au moins une fois ». Sur un parc
 * neutralisé par blocage d'héritage, 14 GPO totalement inertes s'affichaient donc
 * en vert. Ces tests verrouillent la sémantique réelle : un lien ACTIF qui ATTEINT
 * le périmètre.
 */
class GpoEffectivenessResolverTest extends TestCase
{
    private GpoEffectivenessResolver $resolver;

    /** Chaîne type production : OU des postes → OU établissement → racine. */
    private const PERIMETER = 'ou=computers,ou=0950001a,dc=localdev,dc=fr';

    private const ETAB = 'ou=0950001a,dc=localdev,dc=fr';

    private const ROOT = 'dc=localdev,dc=fr';

    private const CHAIN = [self::PERIMETER, self::ETAB, self::ROOT];

    private const GUID = '{AE623DCE-6333-4936-97FB-6FBD30D7D024}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new GpoEffectivenessResolver();
    }

    /**
     * @param  array<string, bool>  $blocks
     * @param  array<string, array<string, int>>  $chainLinks
     * @param  array<string, array<string, int>>  $belowLinks
     * @return array<string, mixed>
     */
    private function classify(array $blocks, array $chainLinks, array $belowLinks = []): array
    {
        return $this->resolver->classify(
            ['guid' => self::GUID, 'displayName' => 'wpkg', 'versionNumber' => 42],
            self::CHAIN,
            $blocks,
            $chainLinks,
            $belowLinks,
        );
    }

    #[Test]
    public function a_root_linked_gpo_is_neutralized_when_the_perimeter_blocks_inheritance(): void
    {
        // Cas nominal SE5 : GPO de domaine partagée + gPOptions=1 côté collège.
        $result = $this->classify(
            [self::PERIMETER => true, self::ETAB => false, self::ROOT => false],
            [self::ROOT => [self::GUID => 0]],
        );

        self::assertSame(GpoEffectivenessResolver::STATUS_NEUTRALIZED, $result['status']);
        self::assertStringContainsString(self::PERIMETER, $result['detail']);
    }

    #[Test]
    public function a_root_linked_gpo_is_effective_when_nothing_blocks_inheritance(): void
    {
        $result = $this->classify(
            [self::PERIMETER => false, self::ETAB => false, self::ROOT => false],
            [self::ROOT => [self::GUID => 0]],
        );

        self::assertSame(GpoEffectivenessResolver::STATUS_EFFECTIVE, $result['status']);
    }

    #[Test]
    public function an_enforced_link_traverses_inheritance_blocking(): void
    {
        // Le piège que ratait l'ancienne page « Vue par OU » : ENFORCED (bit 2)
        // franchit TOUS les blocages en aval.
        $result = $this->classify(
            [self::PERIMETER => true, self::ETAB => true, self::ROOT => false],
            [self::ROOT => [self::GUID => 2]],
        );

        self::assertSame(GpoEffectivenessResolver::STATUS_EFFECTIVE, $result['status']);
        self::assertTrue($result['enforced']);
        self::assertStringContainsString('ENFORCED', $result['detail']);
    }

    #[Test]
    public function a_disabled_link_never_applies_even_without_blocking(): void
    {
        $result = $this->classify(
            [self::PERIMETER => false, self::ETAB => false, self::ROOT => false],
            [self::ROOT => [self::GUID => 1]],
        );

        self::assertSame(GpoEffectivenessResolver::STATUS_LINK_DISABLED, $result['status']);
    }

    #[Test]
    public function a_disabled_and_enforced_link_stays_disabled(): void
    {
        // flags = 3 : bit 1 (disabled) l'emporte sur bit 2 (enforced).
        $result = $this->classify(
            [self::PERIMETER => true, self::ETAB => false, self::ROOT => false],
            [self::ROOT => [self::GUID => 3]],
        );

        self::assertSame(GpoEffectivenessResolver::STATUS_LINK_DISABLED, $result['status']);
    }

    #[Test]
    public function a_link_on_the_perimeter_itself_applies_despite_its_own_block(): void
    {
        // gPOptions=1 sur l'OU des postes bloque l'HÉRITAGE, pas les liens posés
        // SUR cette OU — c'est ainsi que SE_agent_bootstrap reste effective.
        $result = $this->classify(
            [self::PERIMETER => true, self::ETAB => false, self::ROOT => false],
            [self::PERIMETER => [self::GUID => 0]],
        );

        self::assertSame(GpoEffectivenessResolver::STATUS_EFFECTIVE, $result['status']);
        self::assertSame(self::PERIMETER, $result['holderDn']);
    }

    #[Test]
    public function an_intermediate_block_stops_a_root_link(): void
    {
        $result = $this->classify(
            [self::PERIMETER => false, self::ETAB => true, self::ROOT => false],
            [self::ROOT => [self::GUID => 0]],
        );

        self::assertSame(GpoEffectivenessResolver::STATUS_NEUTRALIZED, $result['status']);
        self::assertStringContainsString(self::ETAB, $result['detail']);
    }

    #[Test]
    public function a_link_below_the_perimeter_applies(): void
    {
        // Le cas que la remontée seule raterait — d'où la recherche en sous-arbre.
        $subOu = 'ou=salle-a,' . self::PERIMETER;

        $result = $this->classify(
            [self::PERIMETER => true, self::ETAB => false, self::ROOT => false],
            [],
            [$subOu => [self::GUID => 0]],
        );

        self::assertSame(GpoEffectivenessResolver::STATUS_EFFECTIVE, $result['status']);
        self::assertSame($subOu, $result['holderDn']);
    }

    #[Test]
    public function a_gpo_linked_nowhere_on_our_chain_is_out_of_scope_not_orphaned(): void
    {
        // AD fédéré : elle peut très bien être liée chez un autre collège. On ne
        // prétend pas à une vérité domaine, on dit ce qu'on sait.
        $result = $this->classify(
            [self::PERIMETER => true, self::ETAB => false, self::ROOT => false],
            [self::ROOT => ['{11111111-1111-1111-1111-111111111111}' => 0]],
        );

        self::assertSame(GpoEffectivenessResolver::STATUS_OUT_OF_SCOPE, $result['status']);
        self::assertNull($result['holderDn']);
    }

    #[Test]
    public function an_effective_link_wins_over_a_neutralized_one(): void
    {
        // Liée deux fois : neutralisée à la racine, mais active sur le périmètre.
        $result = $this->classify(
            [self::PERIMETER => true, self::ETAB => false, self::ROOT => false],
            [
                self::ROOT => [self::GUID => 0],
                self::PERIMETER => [self::GUID => 0],
            ],
        );

        self::assertSame(GpoEffectivenessResolver::STATUS_EFFECTIVE, $result['status']);
    }

    #[Test]
    public function it_decodes_gplink_flags_for_every_linked_gpo(): void
    {
        $a = '{AE623DCE-6333-4936-97FB-6FBD30D7D024}';
        $b = '{D3ADC05B-719F-4982-8C51-7D213E14FAC3}';

        $links = $this->resolver->parseGplink(
            "[LDAP://CN={$a},CN=Policies,CN=System,DC=localdev,DC=fr;0]"
            . "[LDAP://CN={$b},CN=Policies,CN=System,DC=localdev,DC=fr;2]",
        );

        self::assertSame([$a => 0, $b => 2], $links);
    }

    #[Test]
    public function it_returns_no_links_for_an_empty_gplink(): void
    {
        self::assertSame([], $this->resolver->parseGplink(''));
        self::assertSame([], $this->resolver->parseGplink('   '));
    }

    #[Test]
    public function it_builds_the_dn_chain_from_perimeter_up_to_base(): void
    {
        $chain = $this->resolver->chainToBase(
            'OU=computers,OU=0950001A, DC=localdev,DC=fr',
            'DC=localdev,DC=fr',
        );

        // Normalisé minuscules, espaces après virgules retirés, périmètre en tête.
        self::assertSame(self::CHAIN, $chain);
    }

    #[Test]
    public function the_chain_is_two_nodes_when_no_establishment_prefix_applies(): void
    {
        // Cas VM de dev : etabCode '0' → pas de préfixe UAI.
        $chain = $this->resolver->chainToBase('ou=computers,dc=localdev,dc=fr', 'dc=localdev,dc=fr');

        self::assertSame(['ou=computers,dc=localdev,dc=fr', 'dc=localdev,dc=fr'], $chain);
    }
}

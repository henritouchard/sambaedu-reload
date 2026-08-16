<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ad;

use App\LdapModels\LdapUser;
use App\Services\Ad\AdImmutableKeyOutcome;
use App\Services\Ad\AdImmutableKeyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LA CONVERSION DE BOUTISME EST LE SUJET DE CE FICHIER.
 *
 * Les valeurs de référence ci-dessous ne sont pas inventées : ce sont des
 * `objectGUID` RÉELS relevés le 2026-08-14 sur l'AD `LOCALDEV.FR`, avec la forme
 * texte que l'annuaire et Nextcloud leur donnent. Un test bâti sur des octets
 * fabriqués se validerait lui-même — c'est exactement le piège que la campagne de
 * mesure existe pour éviter.
 *
 * Ce qu'ils verrouillent : les TROIS PREMIERS champs sont renversés, les DEUX
 * DERNIERS ne le sont pas. Une conversion gros-boutiste intégrale produit une chaîne
 * qui ressemble assez à la bonne pour passer une relecture à l'œil, et assez peu
 * pour qu'aucun compte ne se retrouve.
 */
final class AdImmutableKeyServiceTest extends TestCase
{
    private AdImmutableKeyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdImmutableKeyService();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function realGuids(): array
    {
        return [
            // nc.probe1 — l'uid que Nextcloud rend pour ce même compte.
            'nc.probe1' => ['6efaf9f79cae0b479408e2dc3f88810f', 'f7f9fa6e-ae9c-470b-9408-e2dc3f88810f'],
            'oc.probe2' => ['b82b4c18a935b744a353fa435bbd0ad1', '184c2bb8-35a9-44b7-a353-fa435bbd0ad1'],
            'techtech' => ['29b427f099de2444a4b9da09c4fa13ed', 'f027b429-de99-4424-a4b9-da09c4fa13ed'],
            'johnrambo' => ['5fb9577d71cef64583f0813f60bfa80a', '7d57b95f-ce71-45f6-83f0-813f60bfa80a'],
        ];
    }

    #[Test]
    #[DataProvider('realGuids')]
    public function il_rend_la_forme_microsoft_depuis_les_octets_bruts(string $hex, string $expected): void
    {
        $this->assertSame($expected, $this->service->canonicalFromRaw(hex2bin($hex)));
    }

    #[Test]
    #[DataProvider('realGuids')]
    public function il_rend_la_forme_microsoft_depuis_le_ad_guid_stocke_en_sql(string $hex, string $expected): void
    {
        // `users.ad_guid` porte le `bin2hex` des octets bruts.
        $this->assertSame($expected, $this->service->canonicalFromHex($hex));
    }

    #[Test]
    public function il_n_est_pas_une_conversion_gros_boutiste(): void
    {
        // Le piège nommé dans le docblock : la lecture naïve donnerait ceci.
        $naive = '6efaf9f7-9cae-0b47-9408-e2dc3f88810f';

        $this->assertNotSame($naive, $this->service->canonicalFromHex('6efaf9f79cae0b479408e2dc3f88810f'));
    }

    #[Test]
    public function les_huit_derniers_octets_ne_sont_PAS_renverses(): void
    {
        $key = $this->service->canonicalFromHex('000102030405060708090a0b0c0d0e0f');

        $this->assertSame('03020100-0504-0706-0809-0a0b0c0d0e0f', $key);
    }

    #[Test]
    public function il_accepte_l_hexadecimal_en_majuscules_et_rend_en_minuscules(): void
    {
        $this->assertSame(
            'f7f9fa6e-ae9c-470b-9408-e2dc3f88810f',
            $this->service->canonicalFromHex('6EFAF9F79CAE0B479408E2DC3F88810F'),
        );
    }

    /**
     * Poser une clé TRONQUÉE serait pire que ne rien poser : deux comptes pourraient
     * y répondre, et l'annuaire ne dirait rien.
     */
    #[Test]
    public function il_refuse_toute_entree_qui_ne_fait_pas_seize_octets(): void
    {
        $this->assertNull($this->service->canonicalFromRaw(''));
        $this->assertNull($this->service->canonicalFromRaw(hex2bin('6efaf9f7')));
        $this->assertNull($this->service->canonicalFromRaw(hex2bin('6efaf9f79cae0b479408e2dc3f88810f00')));
        $this->assertNull($this->service->canonicalFromHex('pas-de-l-hexadecimal-du-tout-1234'));
        $this->assertNull($this->service->canonicalFromHex('6efaf9f7'));
    }

    #[Test]
    public function l_attribut_porteur_vient_de_la_configuration_et_est_en_minuscules(): void
    {
        config()->set('ad_identity.attribute', 'EmployeeType');

        $this->assertSame('employeetype', (new AdImmutableKeyService())->attribute());
    }

    #[Test]
    public function une_entree_deja_conforme_n_ecrit_rien(): void
    {
        $entry = $this->entry('6efaf9f79cae0b479408e2dc3f88810f', 'f7f9fa6e-ae9c-470b-9408-e2dc3f88810f');

        $this->assertSame(AdImmutableKeyOutcome::Conforme, $this->service->ensure($entry));
    }

    #[Test]
    public function une_entree_sans_object_guid_rend_un_doute_et_pas_un_echec(): void
    {
        $entry = new LdapUser();
        $entry->setAttribute('samaccountname', 'sans.guid');

        $this->assertSame(AdImmutableKeyOutcome::Unresolved, $this->service->ensure($entry));
    }

    #[Test]
    public function la_simulation_annonce_une_ecriture_sans_toucher_a_l_annuaire(): void
    {
        $entry = $this->entry('6efaf9f79cae0b479408e2dc3f88810f', null);

        $this->assertSame(AdImmutableKeyOutcome::Written, $this->service->ensure($entry, dryRun: true));
        $this->assertNull($this->service->currentFor($entry));
    }

    /**
     * Le cœur de la contrainte « aucune destruction de donnée d'annuaire » : l'inventaire
     * du code garantit que l'attribut est libre de NOTRE fait, pas qu'un outil tiers
     * (connecteur d'ENT, script académique, inventaire de parc) n'y a rien rangé.
     */
    #[Test]
    public function une_valeur_divergente_non_vide_n_est_PAS_ecrasee_par_defaut(): void
    {
        $entry = $this->entry('6efaf9f79cae0b479408e2dc3f88810f', 'MATRICULE-4412');

        $this->assertSame(AdImmutableKeyOutcome::Divergent, $this->service->ensure($entry));
        $this->assertSame('MATRICULE-4412', $this->service->currentFor($entry));
    }

    #[Test]
    public function une_valeur_divergente_n_est_ecrasee_qu_avec_force(): void
    {
        $entry = $this->entry('6efaf9f79cae0b479408e2dc3f88810f', 'MATRICULE-4412');

        $this->assertSame(
            AdImmutableKeyOutcome::Written,
            $this->service->ensure($entry, dryRun: true, force: true),
        );
    }

    /**
     * Une clé posée par une conversion fautive (gros-boutiste) est une valeur
     * divergente comme une autre : elle se montre avant de s'écraser.
     */
    #[Test]
    public function une_cle_au_mauvais_boutisme_est_divergente_pas_silencieusement_reecrite(): void
    {
        $entry = $this->entry('6efaf9f79cae0b479408e2dc3f88810f', '6efaf9f7-9cae-0b47-9408-e2dc3f88810f');

        $this->assertSame(AdImmutableKeyOutcome::Divergent, $this->service->ensure($entry));
    }

    /**
     * Le produit distant prend la chaîne TELLE QUELLE comme identifiant de compte :
     * un espace parasite fabrique une identité différente. Déclarer « conforme »
     * après nettoyage ferait dire « tout est en règle » à un rapport sur un compte
     * dont l'identité cloud ne correspond à aucun octroi calculé.
     */
    #[Test]
    public function une_cle_a_l_espace_pres_n_est_PAS_conforme(): void
    {
        $entry = $this->entry('6efaf9f79cae0b479408e2dc3f88810f', ' f7f9fa6e-ae9c-470b-9408-e2dc3f88810f');

        $this->assertNotSame(AdImmutableKeyOutcome::Conforme, $this->service->ensure($entry));
        $this->assertSame(AdImmutableKeyOutcome::Divergent, $this->service->ensure($entry));
    }

    /**
     * La sélection explicite EST le mécanisme d'idempotence — `LdapUser::$columns` est
     * déclaratif et LdapRecord ne le lit pas. Si l'attribut porteur sortait de cette
     * liste, la clé serait relue `null` et réécrite à chaque passage, en silence.
     */
    #[Test]
    public function la_selection_explicite_contient_l_attribut_porteur_et_de_quoi_calculer(): void
    {
        $select = $this->service->selectFor();

        $this->assertContains($this->service->attribute(), $select);
        $this->assertContains('objectguid', $select);
        $this->assertContains('cn', $select);
    }

    private function entry(string $guidHex, ?string $currentKey): LdapUser
    {
        $entry = new LdapUser();
        $entry->setAttribute('samaccountname', 'sonde');
        $entry->setAttribute('objectguid', hex2bin($guidHex));

        if ($currentKey !== null) {
            $entry->setAttribute('employeetype', $currentKey);
        }

        return $entry;
    }
}

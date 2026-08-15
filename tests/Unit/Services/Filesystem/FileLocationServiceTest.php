<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\FileLocationException;
use App\Models\SystemSetting;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.1 — AC2/AC3 : le service qui lit et écrit UNE ligne de réglage.
 *
 * Régime applicatif (`Tests\TestCase` + `RefreshDatabase`, sqlite
 * `:memory:`) : `SystemSetting` a besoin d'une base.
 */
class FileLocationServiceTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // AC2 — les défauts, exactement l'état historique home✓ shares✓ nextcloud✗ opencloud✗
    // =========================================================================

    #[Test]
    public function defaults_egale_posix_posix_aucun(): void
    {
        self::assertSame(
            ['espace_perso.autorite' => 'posix', 'espace_partage.autorite' => 'posix', 'cloud.actif' => 'aucun'],
            FileLocationService::defaults()->toArray(),
        );
    }

    #[Test]
    public function current_rend_les_defauts_quand_la_cle_est_absente(): void
    {
        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
        self::assertSame(FileLocationService::defaults()->toArray(), FileLocationService::current()->toArray());
        self::assertFalse(FileLocationService::isDecided());
    }

    #[Test]
    public function la_cle_est_distincte_de_files_policy(): void
    {
        self::assertSame('files.locations', FileLocationService::SETTING_KEY);
        self::assertNotSame(FilePolicyService::SETTING_KEY, FileLocationService::SETTING_KEY);
    }

    // =========================================================================
    // AC2 — aller-retour, une seule écriture
    // =========================================================================

    #[Test]
    public function set_puis_current_rend_exactement_ce_qui_a_ete_ecrit(): void
    {
        $locations = FileLocations::make(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud);
        FileLocationService::set($locations);

        self::assertTrue(FileLocationService::isDecided());
        self::assertSame($locations->toArray(), FileLocationService::current()->toArray());
    }

    #[Test]
    public function set_ecrit_les_trois_cles_en_une_seule_ligne(): void
    {
        FileLocationService::set(FileLocations::make(FileBackendName::Posix, FileBackendName::OpenCloud, ActiveCloud::OpenCloud));

        self::assertSame(1, SystemSetting::query()->where('key', FileLocationService::SETTING_KEY)->count());

        $stored = SystemSetting::get(FileLocationService::SETTING_KEY);
        self::assertSame(
            ['espace_perso.autorite', 'espace_partage.autorite', 'cloud.actif'],
            array_keys($stored),
        );
    }

    #[Test]
    public function forget_puis_current_rend_les_defauts_sans_lever(): void
    {
        FileLocationService::set(FileLocations::make(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud));
        SystemSetting::forget(FileLocationService::SETTING_KEY);

        self::assertSame(FileLocationService::defaults()->toArray(), FileLocationService::current()->toArray());
        self::assertFalse(FileLocationService::isDecided());
    }

    // =========================================================================
    // AC3 — aucune valeur nulle, aucun repli silencieux
    // =========================================================================

    #[Test]
    public function une_cle_amputee_refuse_en_nommant_l_objet_manquant(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => 'posix',
            'cloud.actif' => 'aucun',
            // espace_partage.autorite manquant
        ]);

        try {
            FileLocationService::current();
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('espace partagé', $e->getMessage());
        }
    }

    #[Test]
    public function un_payload_a_une_seule_cle_sur_trois_refuse_en_nommant_ce_qui_manque(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, ['espace_perso.autorite' => 'posix']);

        $this->expectException(FileLocationException::class);
        $this->expectExceptionMessage('L\'espace partagé est absent du réglage des emplacements');
        FileLocationService::current();
    }

    #[Test]
    public function un_cloud_actif_absent_a_lui_seul_refuse_en_le_nommant(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => 'posix',
            'espace_partage.autorite' => 'posix',
            // cloud.actif manquant : les deux autorités sont pourtant lisibles.
        ]);

        $this->expectException(FileLocationException::class);
        $this->expectExceptionMessage('Le cloud actif est absent du réglage des emplacements');
        FileLocationService::current();
    }

    /**
     * AC3 — l'ABSENCE de ligne rend les défauts ; une ligne PRÉSENTE mais
     * illisible REFUSE en nommant le type lu. Retomber ici sur les défauts
     * inventerait une décision que personne n'a prise : c'est le repli
     * silencieux que l'AC3 interdit explicitement.
     */
    #[Test]
    public function un_payload_non_tableau_refuse_en_nommant_le_type_lu(): void
    {
        SystemSetting::query()->create(['key' => FileLocationService::SETTING_KEY, 'value' => 'oops']);

        // La ligne existe : c'est une décision illisible, pas une décision absente.
        self::assertTrue(FileLocationService::isDecided());

        try {
            FileLocationService::current();
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('n\'est pas un objet à trois clés', $e->getMessage());
            self::assertStringContainsString('de type string', $e->getMessage());
        }
    }

    #[Test]
    public function un_payload_numerique_refuse_aussi_en_nommant_son_type(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, 42);

        $this->expectException(FileLocationException::class);
        $this->expectExceptionMessage('de type int');
        FileLocationService::current();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valeursHorsVocabulaireProvider(): iterable
    {
        yield 'chaine vide' => [''];
        yield 'null' => [null];
        yield 'entier' => [42];
        yield 'smb' => ['smb'];
        yield 'nextcloud_delegue' => ['nextcloud_delegue'];
        yield 'les_deux' => ['les_deux'];
    }

    #[Test]
    #[DataProvider('valeursHorsVocabulaireProvider')]
    public function une_valeur_hors_vocabulaire_pour_espace_perso_refuse(mixed $raw): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => $raw,
            'espace_partage.autorite' => 'posix',
            'cloud.actif' => 'aucun',
        ]);

        try {
            FileLocationService::current();
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('espace perso', $e->getMessage());
            // Le refus annonce le vocabulaire RÉELLEMENT acceptable — et donc
            // jamais l'aperçu, qui serait refusé au tour suivant.
            self::assertStringContainsString('vocabulaire attendu : posix|nextcloud|opencloud', $e->getMessage());
            self::assertStringNotContainsString('preview', $e->getMessage());
        }
    }

    #[Test]
    #[DataProvider('valeursHorsVocabulaireProvider')]
    public function une_valeur_hors_vocabulaire_pour_espace_partage_refuse(mixed $raw): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => 'posix',
            'espace_partage.autorite' => $raw,
            'cloud.actif' => 'aucun',
        ]);

        try {
            FileLocationService::current();
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('espace partagé', $e->getMessage());
            self::assertStringContainsString('vocabulaire attendu : posix|nextcloud|opencloud', $e->getMessage());
        }
    }

    #[Test]
    #[DataProvider('valeursHorsVocabulaireProvider')]
    public function une_valeur_hors_vocabulaire_pour_cloud_actif_refuse(mixed $raw): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => 'posix',
            'espace_partage.autorite' => 'posix',
            'cloud.actif' => $raw,
        ]);

        try {
            FileLocationService::current();
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('Le cloud actif', $e->getMessage());
            self::assertStringContainsString('vocabulaire attendu : aucun|nextcloud|opencloud', $e->getMessage());
        }
    }

    /**
     * `preview` APPARTIENT au vocabulaire {@see FileBackendName} : la lecture
     * du VOCABULAIRE réussit, mais {@see FileLocations::make()} le refuse
     * ensuite comme emplacement — c'est la garde n°1 de l'AC4, rejouée à la
     * lecture.
     */
    #[Test]
    public function preview_comme_espace_perso_est_refuse_a_la_lecture(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => 'preview',
            'espace_partage.autorite' => 'posix',
            'cloud.actif' => 'aucun',
        ]);

        try {
            FileLocationService::current();
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('aperçu', $e->getMessage());
        }
    }

    /**
     * Piège n°7 du cadrage : le trim et la casse. `'POSIX '` n'est pas
     * `posix` — trim() seulement, comparaison stricte sensible à la casse.
     */
    #[Test]
    public function le_trim_ne_normalise_pas_la_casse(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => 'POSIX ',
            'espace_partage.autorite' => 'posix',
            'cloud.actif' => 'aucun',
        ]);

        try {
            FileLocationService::current();
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            // La valeur LUE est épinglée dans le message : sans elle, l'exploitant
            // ne sait pas laquelle des trois valeurs corriger, ni pourquoi une
            // valeur qui « ressemble » au vocabulaire a été refusée.
            self::assertStringContainsString('POSIX', $e->getMessage());
            self::assertStringContainsString('espace perso', $e->getMessage());
        }
    }

    #[Test]
    public function les_espaces_autour_d_une_valeur_valide_sont_tolerees_par_trim(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => '  posix  ',
            'espace_partage.autorite' => 'posix',
            'cloud.actif' => 'aucun',
        ]);

        self::assertSame(FileBackendName::Posix, FileLocationService::current()->espacePerso);
    }

    /**
     * AC4 — un payload forgé DIRECTEMENT en base (contournant `set()`) est
     * refusé à la LECTURE aussi : une garde qui ne vit que dans l'écriture
     * protège l'étourderie, pas la requête forgée.
     */
    #[Test]
    public function un_payload_force_directement_en_base_est_refuse_a_la_lecture(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => 'nextcloud',
            'espace_partage.autorite' => 'posix',
            'cloud.actif' => 'aucun',
        ]);

        try {
            FileLocationService::current();
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('espace perso', $e->getMessage());
            self::assertStringContainsString('nextcloud', $e->getMessage());
        }
    }
}

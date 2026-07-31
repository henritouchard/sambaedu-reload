<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 57.4 / AR12 — **LES ANCIENNES URL BBB NE MÈNENT PLUS NULLE PART.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER FERME, ET QUE LA SEULE SUPPRESSION LAISSAIT OUVERT
 *
 *  Supprimer `legacy/modules/bbb/` ne suffisait pas : le catchall, ne trouvant
 *  plus de module local, serait simplement passé à l'étape suivante — le proxy
 *  vers le système de fichiers SE4 (`/var/www/sambaedu/bbb/…`). Sur toute
 *  instance où l'extinction de l'Epic 38 n'a pas encore été jouée, l'interface
 *  legacy d'origine serait donc REVENUE, avec ses mots de passe en champs
 *  cachés et sa vérification TLS désactivée.
 *
 *  « L'accès à la visioconférence passe exclusivement par la tuile » interdit
 *  ce chemin. D'où deux entrées dans `blocked_legacy_routes`, évaluées à
 *  l'étape 2 du catchall, AVANT toute résolution legacy.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ Ce test lit la configuration RÉELLE (`config/sambaedu.php`). Il ne pose
 * aucune route bloquée de son cru : ce qu'il vérifie est précisément que la
 * configuration livrée porte ces redirections.
 */
class LegacyBbbRoutesRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Le mécanisme est actif par défaut ; on l'affirme plutôt que de le
        // supposer, un test qui passerait parce qu'il est débranché ne
        // prouverait rien.
        Config::set('sambaedu.block_migrated_routes', true);
        Config::set('sambaedu.etab_ou', '');
    }

    public static function legacyBbbPaths(): array
    {
        return [
            'écran de configuration des serveurs' => ['/bbb/config.php'],
            'formulaire de création' => ['/bbb/create.php'],
            'liste des salons' => ['/bbb/join.php'],
            'lancement réel du meeting' => ['/bbb/launch.php'],
            'enregistrements' => ['/bbb/records.php'],
            'rafraîchissement du cache' => ['/bbb/refresh.php'],
            'répertoire nu' => ['/bbb/'],
            'page publique invité' => ['/visio'],
            'page publique invité avec slash' => ['/visio/'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyBbbPaths')]
    #[Test]
    public function every_legacy_bbb_url_lands_back_on_the_se5_home(string $path): void
    {
        $this->get($path)
            ->assertStatus(302)
            ->assertRedirect('/');
    }

    #[Test]
    public function the_guest_url_of_the_legacy_still_redirects_with_its_query_string(): void
    {
        // L'URL que SE4 distribuait aux parents : `https://<hôte>/visio/?salon=<login>`.
        // Elle ne doit plus servir une page — ni la sienne, ni celle du système
        // de fichiers SE4.
        $this->get('/visio/?salon=prof.martin')
            ->assertStatus(302)
            ->assertRedirect('/');
    }

    #[Test]
    public function the_uai_prefixed_form_is_redirected_too(): void
    {
        // Le legacy fabriquait ses URL avec le préfixe UAI. Le catchall le
        // retire avant d'évaluer les routes bloquées : la redirection vaut donc
        // pour les deux formes — et c'est ce que ce test empêche de casser.
        Config::set('sambaedu.etab_ou', '0991229y');

        $this->get('/0991229y/bbb/create.php')
            ->assertStatus(302)
            ->assertRedirect('/');

        $this->get('/0991229y/visio/')
            ->assertStatus(302)
            ->assertRedirect('/');
    }

    #[Test]
    public function a_path_that_merely_starts_with_the_same_letters_is_not_swept_along(): void
    {
        // CONTRÔLE NÉGATIF : les motifs exigent une frontière (`/` ou fin de
        // chemin). Sans elle, ils avaleraient n'importe quel chemin commençant
        // par ces lettres — le genre d'élargissement silencieux qu'un « ^bbb »
        // nu aurait produit.
        $blocked = config('sambaedu.blocked_legacy_routes');

        foreach (['bbbtest/page.php', 'visionneuse/index.php', 'provisioning/x.php'] as $path) {
            foreach (['^bbb(/|$)', '^visio(/|$)'] as $pattern) {
                self::assertSame(
                    0,
                    preg_match('~' . $pattern . '~', $path),
                    sprintf('le motif « %s » ne doit pas capturer « %s »', $pattern, $path),
                );
            }
        }

        // Et les deux motifs sont bien ceux de la configuration livrée.
        self::assertArrayHasKey('^bbb(/|$)', $blocked);
        self::assertArrayHasKey('^visio(/|$)', $blocked);
        self::assertSame('/', $blocked['^bbb(/|$)']);
        self::assertSame('/', $blocked['^visio(/|$)']);
    }
}

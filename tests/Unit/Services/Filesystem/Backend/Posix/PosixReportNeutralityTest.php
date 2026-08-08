<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\PlanNodeNature;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixDiagnostic;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\PlanNeutralityMarkers;

/**
 * Story 60.4 — LA COUPE TIENT AUSSI QUAND ÇA ÉCHOUE.
 *
 * La garde de neutralité des rapports existe depuis la story 60.3, mais elle
 * n'avait jamais été exercée contre un backend qui EXÉCUTE. C'est la différence
 * qui compte : le seul texte libre d'un rapport est son `detail`, et un backend
 * réel a une source de texte que le backend d'aperçu n'a pas — la sortie d'erreur
 * du système, qui contient précisément un chemin absolu, un nom de commande, un
 * mode de permission.
 *
 * Une garde qui ne s'exercerait que sur le chemin heureux (où aucun geste
 * n'échoue, donc où aucun texte système ne remonte) serait vraie exactement là où
 * elle ne sert à rien. Ces tests font donc ÉCHOUER de vrais gestes avec de vraies
 * sorties d'erreur.
 *
 * La liste de marqueurs est celle des stories précédentes, RÉUTILISÉE : deux
 * listes qui divergeraient seraient pires qu'une seule.
 */
class PosixReportNeutralityTest extends TestCase
{
    use PlanNeutralityMarkers;
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/se5-neutral-' . uniqid();
        @mkdir($this->tempRoot . '/proj', 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot . '/proj');
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    /** Texte BRUT d'un rapport sérialisé : clés et valeurs scalaires bout à bout. */
    private function plainTextOfArray(array $data): string
    {
        $parts = [];
        $walk = static function (array $data) use (&$walk, &$parts): void {
            foreach ($data as $key => $value) {
                $parts[] = (string) $key;
                if (is_array($value)) {
                    $walk($value);
                } elseif ($value !== null && ! is_bool($value)) {
                    $parts[] = (string) $value;
                }
            }
        };
        $walk($data);

        return implode("\n", $parts);
    }

    /** @return list<string> étiquettes des marqueurs trouvés */
    private function markersIn(string $haystack): array
    {
        $found = [];
        foreach (self::forbiddenMarkers() as $label => $marker) {
            if (stripos($haystack, $marker) !== false) {
                $found[] = $label;
            }
        }

        return $found;
    }

    private function plan(array $grants = []): FilePlan
    {
        return new FilePlan('@partage', 'proj', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre, $grants),
        ]);
    }

    #[Test]
    public function a_successful_pass_carries_no_marker_of_the_layer_below(): void
    {
        Process::fake();
        $alice = User::factory()->create(['login' => 'alice']);
        $groupe = UserGroup::create(['name' => 'Direction', 'type' => 'custom']);

        $plan = $this->plan([
            new PlanGrant('@assignation', PlanSubject::user((int) $alice->id), PlanGrant::VERBS),
            new PlanGrant('@assignation', PlanSubject::group((int) $groupe->id), [PlanGrant::VERB_LIRE]),
        ]);

        $backend = app(PosixFileBackend::class);

        foreach ([$backend->provision($plan)->toArray(), $backend->inspect($plan)->toArray(), $backend->quota($plan)->toArray()] as $data) {
            self::assertSame([], $this->markersIn($this->plainTextOfArray($data)));
        }
    }

    /**
     * LE CAS QUI COMPTE : les gestes échouent, et la sortie du système est un
     * message réel — chemin absolu, nom de commande, entrée de droits comprise.
     */
    #[Test]
    public function a_failing_pass_carries_no_marker_either_even_with_a_real_system_error(): void
    {
        $realError = "setfacl: /var/sambaedu/Partages/proj: Invalid argument\n"
            . "setfacl: cannot apply group:domain\\040admins:rwx (default:mask::rwx)";

        Process::fake([
            'sudo setfacl *' => Process::result(output: '', errorOutput: $realError, exitCode: 1),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $report = app(PosixFileBackend::class)->provision($this->plan());

        $text = $this->plainTextOfArray($report->toArray());

        self::assertSame([], $this->markersIn($text), 'la sortie système a franchi la ligne : ' . $text);
        // …tout en restant EXPLOITABLE : la phrase du système survit.
        self::assertStringContainsString('Invalid argument', $text);
    }

    #[Test]
    public function a_failing_read_carries_no_marker_either(): void
    {
        Process::fake([
            'sudo getfacl *' => Process::result(
                output: '',
                errorOutput: 'getfacl: /var/sambaedu/Partages/proj: Permission denied',
                exitCode: 1,
            ),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $report = app(PosixFileBackend::class)->inspect($this->plan());

        self::assertSame([], $this->markersIn($this->plainTextOfArray($report->toArray())));
    }

    #[Test]
    public function no_value_of_a_posix_report_is_an_absolute_path(): void
    {
        Process::fake();

        $backend = app(PosixFileBackend::class);
        $plan = $this->plan();

        foreach ([$backend->provision($plan)->toArray(), $backend->inspect($plan)->toArray()] as $data) {
            foreach (explode("\n", $this->plainTextOfArray($data)) as $value) {
                self::assertStringStartsNotWith('/', $value, 'chemin absolu dans un rapport : ' . $value);
            }
        }
    }

    /**
     * Story 62.4 — LA GARDE S'ÉTEND AUX TEXTES NOUVEAUX : les phrases d'un déclin
     * par limite de modèle.
     *
     * Ce sont du texte LIBRE, écrit à la main, et il traverse la ligne de coupe
     * jusqu'à l'écran. La tentation d'y écrire le mécanisme (« il faudrait un
     * drapeau sur le dossier », « le même bit sert aux deux ») est forte, parce
     * qu'elle est plus précise. Elle ferait pourtant remonter le vocabulaire du
     * serveur de fichiers à un administrateur qui ne le lira jamais dans une
     * interface de plan — et qui, demain, verra le même écran alimenté par un
     * backend distant où ce vocabulaire n'a aucun sens.
     */
    #[Test]
    public function the_new_model_limit_details_carry_no_marker_of_the_layer_below(): void
    {
        Process::fake();
        $classe = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $subject = PlanSubject::group((int) $classe->id);

        // Les deux façons dont ce backend décline par limite de modèle.
        $plans = [
            'supprimer sans créer' => $this->plan([
                new PlanGrant('classe', $subject, [PlanGrant::VERB_LIRE, PlanGrant::VERB_SUPPRIMER]),
            ]),
            'nœud mixte' => $this->plan([
                new PlanGrant('classe', $subject, [PlanGrant::VERB_LIRE, PlanGrant::VERB_CREER]),
                new PlanGrant('equipe', PlanSubject::group(
                    (int) UserGroup::create(['name' => 'equipe_3emeA', 'type' => 'equipe'])->id,
                ), PlanGrant::VERBS),
            ]),
        ];

        foreach ($plans as $case => $plan) {
            $report = app(PosixFileBackend::class)->provision($plan);
            $text = $this->plainTextOfArray($report->toArray());

            self::assertSame([], $this->markersIn($text), "{$case} : la ligne de coupe a été franchie — " . $text);

            // …et le texte reste EXPLOITABLE : il nomme le rôle et les verbes.
            self::assertStringContainsString('classe', $text);
            self::assertStringContainsString('suppression', $text);

            // Le mot du mécanisme, nommément interdit : c'est le seul que la
            // liste partagée de marqueurs ne connaît pas encore, parce qu'il
            // n'existait pas avant cette story.
            foreach (['sticky', 'drapeau', 'chmod', '+t'] as $mechanism) {
                self::assertStringNotContainsStringIgnoringCase($mechanism, $text, $case . ' / ' . $mechanism);
            }
        }
    }

    /**
     * Story 62.5 — LA GARDE S'ÉTEND AUX DEUX TEXTES DU COULOIR DÉRIVÉ.
     *
     * Ils sont écrits à la main, ils traversent la ligne de coupe, et ils parlent
     * d'un mécanisme dont le vocabulaire naturel est précisément celui qui est
     * interdit ici (un mode, une lettre, un bit d'exécution). La phrase doit donc
     * dire qu'un PASSAGE manque, vers quels dossiers et pour quels rôles — jamais
     * comment ce passage s'écrit sur le disque.
     */
    #[Test]
    public function the_derived_corridor_texts_carry_no_marker_of_the_layer_below(): void
    {
        // Un groupe que le système ne résout pas : le couloir ne peut pas s'ouvrir,
        // et le nœud PORTEUR le dit. C'est le texte de la compilation.
        Process::fake([
            'getent group *' => Process::result(output: '', exitCode: 2),
            'sudo getfacl *' => Process::result(output: implode("\n", self::BASE_ON_DISK), exitCode: 0),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $profs = UserGroup::create(['name' => 'Profs', 'type' => 'custom']);
        $plan = new FilePlan('@arbre', 'proj', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::Partagee),
            new PlanNode('_profond', 'Dossier profond', PlanNodeNature::Partagee, [
                new PlanGrant('profs', PlanSubject::group((int) $profs->id), PlanGrant::VERBS),
            ]),
        ]);

        $backend = app(PosixFileBackend::class);

        $provision = $this->plainTextOfArray($backend->provision($plan)->toArray());
        self::assertSame([], $this->markersIn($provision), 'texte du couloir refusé : ' . $provision);
        self::assertStringContainsString('couloir', $provision);
        self::assertStringContainsString('_profond', $provision);

        // Et le texte de la RELECTURE : le couloir attendu n'est pas sur le disque.
        $inspection = $this->plainTextOfArray($backend->inspect($plan)->toArray());
        self::assertSame([], $this->markersIn($inspection), 'texte du couloir manquant : ' . $inspection);
        self::assertStringContainsString('couloir', $inspection);

        // Le vocabulaire du mécanisme, nommément interdit dans les deux textes.
        foreach ([$provision, $inspection] as $text) {
            foreach (['traversée du système', 'exécution', 'bit', '--x', 'setfacl'] as $mechanism) {
                self::assertStringNotContainsStringIgnoringCase($mechanism, $text, $mechanism);
            }
        }

        @rmdir($this->tempRoot . '/proj/_profond');
    }

    /** L'état de disque MINIMAL d'un répertoire géré, pour une relecture crédible. */
    private const BASE_ON_DISK = [
        'user::rwx',
        'group::---',
        'group:domain\\040admins:rwx',
        'mask::rwx',
        'other::---',
    ];

    /**
     * MÉTA-TEST — la neutralisation VOIT chacune des formes qu'elle prétend
     * effacer, et laisse passer une phrase honnête.
     */
    #[Test]
    public function the_neutraliser_sees_every_form_it_claims_to_erase(): void
    {
        foreach ([
            'setfacl: /var/sambaedu/Partages/x: Invalid argument',
            'getfacl: /var/sambaedu/Classes/Classe_3a: Permission denied',
            'chown: changing ownership of /srv/data: Operation not permitted',
            'cannot set group:domain\\040admins:rwx',
            'sudo: a password is required',
            'mode rwx refused',
            'default:mask::rwx is invalid',
        ] as $raw) {
            $neutral = PosixDiagnostic::neutralize($raw);

            self::assertSame(
                [],
                $this->markersIn($neutral),
                sprintf('la neutralisation laisse passer un marqueur : « %s » → « %s »', $raw, $neutral),
            );
            self::assertNotSame('', $neutral);
        }

        // Contrôle NÉGATIF : une phrase honnête traverse intacte, sinon la cause
        // deviendrait illisible et le `detail` inutile.
        self::assertStringContainsString('Invalid argument', PosixDiagnostic::neutralize('Invalid argument'));
        self::assertStringContainsString(
            'aucune sortie',
            PosixDiagnostic::neutralize('   '),
        );
    }
}

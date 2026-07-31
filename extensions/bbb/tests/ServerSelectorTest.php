<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Bbb\LoadResult;
use SambaEdu\ExtBbb\Bbb\ServerCandidate;
use SambaEdu\ExtBbb\Bbb\ServerSelector;
use SambaEdu\ExtBbb\Store;
use SambaEdu\ExtBbb\Tests\Support\RecordingBbbApiClient;

/**
 * Story 57.4 — **LA MATRICE DU CHOIX DE SERVEUR (AC1), SANS RÉSEAU.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER FIGE
 *
 *  L'AC dit « le moins chargé, ou délégué à Scalelite, comportement iso-SE4 ».
 *  Trois propriétés en découlent, et chacune est une décision qu'on ne veut pas
 *  voir dériver :
 *
 *   1. la charge d'un serveur normal est MESURÉE, celle d'un Scalelite est
 *      CONFIGURÉE — et un Scalelite n'est JAMAIS sondé ;
 *   2. « déléguer à Scalelite » n'est pas une branche du code : c'est le même
 *      minimum, gagné par le seuil quand les serveurs mesurés le dépassent ;
 *   3. un serveur qui ne répond pas est écarté en silence ; un serveur qui
 *      refuse le secret est écarté ET signalé.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Une base SQLite réelle, un client enregistreur : la matrice entière s'exerce
 * sur l'hôte de développement, sans le moindre serveur BigBlueButton.
 */
final class ServerSelectorTest extends TestCase
{
    private string $directory = '';

    private Store $store;

    private RecordingBbbApiClient $api;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ext-bbb-selector-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);

        $this->store = new Store($this->directory . '/database.sqlite');
        $this->api = new RecordingBbbApiClient();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    private function selector(): ServerSelector
    {
        return new ServerSelector($this->store, $this->api);
    }

    private function url(int $n): string
    {
        return 'https://bbb' . $n . '.example.test/bigbluebutton/api';
    }

    /** @return list<int> Les identifiants retenus, dans l'ordre du choix. */
    private function chosenIds(): array
    {
        return array_map(
            static fn (ServerCandidate $candidate): int => $candidate->id,
            $this->selector()->select()->candidates,
        );
    }

    // =====================================================================
    // Le moins chargé gagne
    // =====================================================================

    #[Test]
    public function the_least_loaded_server_wins(): void
    {
        $first = $this->store->addServer($this->url(1), 'secret-1');
        $second = $this->store->addServer($this->url(2), 'secret-2');
        $third = $this->store->addServer($this->url(3), 'secret-3');

        $this->api->loadByServer = [
            $this->url(1) => LoadResult::ok(42),
            $this->url(2) => LoadResult::ok(3),
            $this->url(3) => LoadResult::ok(17),
        ];

        $selection = $this->selector()->select();

        self::assertSame($second, $selection->best()?->id);
        self::assertSame(3, $selection->best()?->load);

        // ⚠️ La liste est ORDONNÉE, et pas seulement « le vainqueur d'abord » :
        // c'est elle qui rend la bascule possible.
        self::assertSame([$second, $third, $first], $this->chosenIds());
    }

    #[Test]
    public function an_equal_load_is_broken_by_the_smallest_identifier(): void
    {
        // Un parc au repos, c'est TROIS serveurs à zéro. Sans départage
        // déterministe, deux démarrages successifs partiraient au hasard — et
        // aucun test ne pourrait plus rien affirmer.
        $first = $this->store->addServer($this->url(1), 'secret-1');
        $second = $this->store->addServer($this->url(2), 'secret-2');

        $this->api->loadResult = LoadResult::ok(7);

        self::assertSame([$first, $second], $this->chosenIds());
    }

    #[Test]
    public function a_success_without_any_meeting_is_a_load_of_zero_and_the_best_candidate_there_is(): void
    {
        // Contrôle POSITIF du piège SimpleXML : un serveur vide n'est pas un
        // serveur en panne, c'est le meilleur hôte possible.
        $empty = $this->store->addServer($this->url(1), 'secret-1');
        $this->store->addServer($this->url(2), 'secret-2');

        $this->api->loadByServer = [
            $this->url(1) => LoadResult::ok(0),
            $this->url(2) => LoadResult::ok(1),
        ];

        $selection = $this->selector()->select();

        self::assertSame($empty, $selection->best()?->id);
        self::assertSame(0, $selection->best()?->load);
        self::assertFalse($selection->isEmpty());
    }

    // =====================================================================
    // Scalelite : seuil fixe configuré, JAMAIS sondé
    // =====================================================================

    #[Test]
    public function a_scalelite_server_is_never_probed_because_its_threshold_is_not_a_measurement(): void
    {
        // LE test qui porte la sémantique posée en 57.1 (`Store::addServer`) et
        // reprise du legacy : `load_server_bbb()` n'émettait AUCUN appel dès que
        // `scalelite != 0`.
        $this->store->addServer($this->url(1), 'secret-1');
        $this->store->addServer($this->url(9), 'secret-9', 50);

        $this->api->loadResult = LoadResult::ok(1);

        $this->selector()->select();

        self::assertSame([$this->url(1)], $this->api->probedUrls());
        self::assertCount(1, $this->api->measured, 'le Scalelite ne se sonde pas');
    }

    #[Test]
    public function a_measured_server_below_the_threshold_keeps_the_meeting(): void
    {
        $small = $this->store->addServer($this->url(1), 'secret-1');
        $this->store->addServer($this->url(2), 'secret-2');
        $this->store->addServer($this->url(9), 'secret-9', 50);

        $this->api->loadByServer = [
            $this->url(1) => LoadResult::ok(10),
            $this->url(2) => LoadResult::ok(80),
        ];

        self::assertSame($small, $this->selector()->select()->best()?->id);
    }

    #[Test]
    public function scalelite_wins_when_every_measured_server_is_above_its_threshold(): void
    {
        // « Ou délégué à Scalelite » n'est PAS une branche du code : c'est le
        // même minimum, gagné par le seuil. Le seuil est donc bien ce que le
        // legacy en faisait — un point de délégation choisi par l'admin.
        $this->store->addServer($this->url(1), 'secret-1');
        $this->store->addServer($this->url(2), 'secret-2');
        $scalelite = $this->store->addServer($this->url(9), 'secret-9', 50);

        $this->api->loadByServer = [
            $this->url(1) => LoadResult::ok(60),
            $this->url(2) => LoadResult::ok(80),
        ];

        $best = $this->selector()->select()->best();

        self::assertSame($scalelite, $best?->id);
        self::assertTrue($best?->delegated, 'sa charge est un seuil, pas une mesure');
        self::assertSame(50, $best?->load);
    }

    #[Test]
    public function a_scalelite_alone_is_chosen_without_a_single_outgoing_call(): void
    {
        $scalelite = $this->store->addServer($this->url(9), 'secret-9', 50);

        $selection = $this->selector()->select();

        self::assertSame($scalelite, $selection->best()?->id);
        self::assertSame(0, $this->api->outboundCalls(), 'un parc 100 % Scalelite ne sonde rien du tout');
    }

    // =====================================================================
    // Pannes : écarter, et savoir quoi en dire
    // =====================================================================

    #[Test]
    public function an_unreachable_server_is_excluded_and_the_others_carry_on(): void
    {
        $this->store->addServer($this->url(1), 'secret-1');
        $alive = $this->store->addServer($this->url(2), 'secret-2');

        $this->api->loadByServer = [
            $this->url(1) => LoadResult::unreachable(),
            $this->url(2) => LoadResult::ok(99),
        ];

        $selection = $this->selector()->select();

        self::assertSame([$alive], $this->chosenIds());
        self::assertSame('', $selection->message, 'l\'exclusion est SILENCIEUSE tant qu\'un serveur répond');
        self::assertFalse($selection->secretRefused);
    }

    #[Test]
    public function an_unexpected_response_excludes_the_server_too(): void
    {
        $this->store->addServer($this->url(1), 'secret-1');
        $alive = $this->store->addServer($this->url(2), 'secret-2');

        $this->api->loadByServer = [
            $this->url(1) => LoadResult::invalidResponse('unsupportedRequest'),
            $this->url(2) => LoadResult::ok(5),
        ];

        self::assertSame([$alive], $this->chosenIds());
    }

    #[Test]
    public function a_refused_secret_excludes_the_server_and_is_reported_distinctly(): void
    {
        // Un secret refusé n'est PAS une panne : c'est une configuration à
        // corriger, et personne d'autre que nous ne le dira à l'administrateur.
        $this->store->addServer($this->url(1), 'secret-perime');
        $alive = $this->store->addServer($this->url(2), 'secret-2');

        $this->api->loadByServer = [
            $this->url(1) => LoadResult::invalidSecret(),
            $this->url(2) => LoadResult::ok(5),
        ];

        $selection = $this->selector()->select();

        self::assertSame([$alive], $this->chosenIds());
        self::assertTrue($selection->secretRefused, 'exclu, mais signalé');
    }

    #[Test]
    public function every_server_unreachable_yields_an_empty_list_and_its_own_message(): void
    {
        $this->store->addServer($this->url(1), 'secret-1');
        $this->store->addServer($this->url(2), 'secret-2');

        $this->api->loadResult = LoadResult::unreachable();

        $selection = $this->selector()->select();

        self::assertTrue($selection->isEmpty());
        self::assertNull($selection->best());
        self::assertSame(ServerSelector::NONE_REACHABLE, $selection->message);
        self::assertStringContainsString('joignable', $selection->message);
    }

    #[Test]
    public function no_server_at_all_is_a_different_message_because_it_is_a_different_remedy(): void
    {
        // Deux causes, deux remèdes : l'une se règle sur la page
        // d'administration, l'autre en allumant une machine. Les confondre
        // enverrait l'administrateur au mauvais endroit.
        $selection = $this->selector()->select();

        self::assertTrue($selection->isEmpty());
        self::assertSame(ServerSelector::NO_SERVER_CONFIGURED, $selection->message);
        self::assertNotSame(ServerSelector::NONE_REACHABLE, $selection->message);
        self::assertSame(0, $this->api->outboundCalls(), 'sans serveur, rien à sonder');
    }

    #[Test]
    public function a_refused_secret_wins_over_an_unreachable_park_because_it_is_the_actionable_one(): void
    {
        // Review 57.4 #3 — le cas MIXTE, que les tests ne couvraient qu'en
        // versions pures. Trois serveurs : un au secret refusé, deux éteints.
        // Des deux causes, une seule est actionnable tout de suite, et une
        // seule ne se répare pas toute seule — un parc éteint se rallume, un
        // secret faux reste faux tant que personne ne le sait.
        //
        // Ce test ne dit pas que c'est la seule priorité défendable ; il dit
        // que c'est CELLE-CI qui est choisie, et qu'elle ne changera pas par
        // accident.
        $this->store->addServer($this->url(1), 'secret-perime');
        $this->store->addServer($this->url(2), 'secret-2');
        $this->store->addServer($this->url(3), 'secret-3');

        $this->api->loadByServer = [
            $this->url(1) => LoadResult::invalidSecret(),
            $this->url(2) => LoadResult::unreachable(),
            $this->url(3) => LoadResult::unreachable(),
        ];

        $selection = $this->selector()->select();

        self::assertTrue($selection->isEmpty());
        self::assertTrue($selection->secretRefused);
        self::assertSame(ServerSelector::SECRET_REFUSED, $selection->message);
        self::assertNotSame(
            ServerSelector::NONE_REACHABLE,
            $selection->message,
            'la cause actionnable prime, même minoritaire',
        );
    }

    #[Test]
    public function only_refused_secrets_left_says_so_rather_than_blaming_the_network(): void
    {
        $this->store->addServer($this->url(1), 'secret-perime');
        $this->store->addServer($this->url(2), 'secret-perime-aussi');

        $this->api->loadResult = LoadResult::invalidSecret();

        $selection = $this->selector()->select();

        self::assertTrue($selection->isEmpty());
        self::assertSame(ServerSelector::SECRET_REFUSED, $selection->message);
        self::assertTrue($selection->secretRefused);
        self::assertStringContainsString('secret', $selection->message);
    }

    // =====================================================================
    // Serveur désactivé : ni sondé, ni candidat
    // =====================================================================

    #[Test]
    public function a_disabled_server_is_neither_probed_nor_a_candidate(): void
    {
        $this->store->addServer($this->url(1), 'secret-1', 0, false);
        $enabled = $this->store->addServer($this->url(2), 'secret-2');

        // Un Scalelite désactivé non plus : il n'est jamais sondé, mais il ne
        // doit pas pour autant devenir un candidat par la petite porte.
        $this->store->addServer($this->url(9), 'secret-9', 50, false);

        self::assertSame([$enabled], $this->chosenIds());
        self::assertSame([$this->url(2)], $this->api->probedUrls());
    }

    #[Test]
    public function the_probe_carries_the_credentials_of_the_server_it_measures(): void
    {
        // Contrôle POSITIF des tests d'exclusion : sans lui, ils passeraient
        // aussi bien si le sélecteur sondait n'importe quoi avec n'importe quel
        // secret.
        $this->store->addServer($this->url(1), 'secret-1');
        $this->store->addServer($this->url(2), 'secret-2');

        $this->selector()->select();

        self::assertSame(
            [
                ['url' => $this->url(1), 'secret' => 'secret-1'],
                ['url' => $this->url(2), 'secret' => 'secret-2'],
            ],
            $this->api->measured,
        );
    }
}

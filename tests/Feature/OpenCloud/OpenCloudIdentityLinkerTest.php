<?php

declare(strict_types=1);

namespace Tests\Feature\OpenCloud;

use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudIdentityLinker;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LE CACHE D'IDENTITÉ ET SES DEUX GARDES.
 *
 * Ce n'est pas un test de confort. Le backend traduit un sujet de plan en compte
 * distant **par ce cache et par rien d'autre** : ce qui s'y écrit décide de qui
 * ouvre le dossier personnel d'un élève. Les deux gardes vérifiées ici sont donc
 * des protections de sécurité, pas des validations de formulaire.
 */
class OpenCloudIdentityLinkerTest extends TestCase
{
    use RefreshDatabase;

    /** L'annuaire RELU, dans la forme mesurée le 2026-08-13. */
    private const DIRECTORY = ['value' => [
        [
            'accountEnabled' => true,
            'displayName' => 'Mesure Eleve',
            'id' => '1f150734-d517-4361-b175-7c535027f72f',
            'onPremisesSamAccountName' => 'mesure_eleve',
            'userType' => 'Member',
        ],
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        FilePolicyService::setGlobal(
            true, true, false, '', null, null, null,
            true, 'https://nuage.exemple.fr', 'admin', true,
        );
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, 'secret');
    }

    private function user(string $login, ?string $identity = null): User
    {
        $user = User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true, 'source' => 'ad']);
        $user->opencloud_user_id = $identity;
        $user->saveQuietly();

        return $user->fresh();
    }

    private function linker(): OpenCloudIdentityLinker
    {
        return app(OpenCloudIdentityLinker::class);
    }

    /**
     * **L'IDENTIFIANT DE CONNEXION SUFFIT À LA SAISIE — mais c'est l'IDENTIFIANT
     * INTERNE RELU qui est écrit.** Le détour est imposé par la mesure : l'API
     * refuse de filtrer sur l'identifiant de connexion.
     */
    #[Test]
    public function a_login_is_accepted_but_the_stored_value_is_the_one_the_instance_returned(): void
    {
        Http::fake(['*' => Http::response(self::DIRECTORY, 200)]);

        $user = $this->user('alice');
        $result = $this->linker()->link($user, 'mesure_eleve');

        self::assertFalse($result->isFailure(), $result->message);
        self::assertSame('1f150734-d517-4361-b175-7c535027f72f', $user->fresh()->opencloud_user_id);
    }

    /**
     * **UNE IDENTITÉ QUE L'INSTANCE NE CONFIRME PAS N'EST JAMAIS ÉCRITE.** Un
     * identifiant saisi de travers rattacherait l'utilisateur à rien — ou pire, à
     * quelqu'un.
     */
    #[Test]
    public function an_identity_the_instance_does_not_confirm_is_never_written(): void
    {
        Http::fake(['*' => Http::response(self::DIRECTORY, 200)]);

        $user = $this->user('alice');
        $result = $this->linker()->link($user, 'compte-qui-nexiste-pas');

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('compte-qui-nexiste-pas', $result->message);
        self::assertNull($user->fresh()->opencloud_user_id);
    }

    /**
     * **LA GARDE D'UNICITÉ, ET ELLE NOMME LE DÉTENTEUR.** Sans elle, deux logins
     * SE5 désigneraient le même compte distant, et un octroi nominatif
     * atterrirait chez un tiers. Le refus dit QUI détient, pas « impossible ».
     */
    #[Test]
    public function an_identity_already_held_by_someone_else_is_refused_by_naming_the_holder(): void
    {
        Http::fake(['*' => Http::response(self::DIRECTORY, 200)]);

        $this->user('bruno', '1f150734-d517-4361-b175-7c535027f72f');
        $alice = $this->user('alice');

        $result = $this->linker()->link($alice, 'mesure_eleve');

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('bruno', $result->message);
        self::assertNull($alice->fresh()->opencloud_user_id);
    }

    /** Rejouer un rattachement identique est un no-op qui n'émet AUCUN appel. */
    #[Test]
    public function relinking_the_same_identity_emits_no_call_at_all(): void
    {
        Http::fake(['*' => Http::response(self::DIRECTORY, 200)]);

        $user = $this->user('alice', '1f150734-d517-4361-b175-7c535027f72f');
        $result = $this->linker()->link($user, '1f150734-d517-4361-b175-7c535027f72f');

        self::assertTrue($result->alreadyConforming);
        Http::assertNothingSent();
    }

    /** **DÉTACHER N'EST JAMAIS DESTRUCTEUR** : le cache seulement, aucun appel émis. */
    #[Test]
    public function unlinking_clears_the_cache_and_touches_nothing_on_the_instance(): void
    {
        Http::fake();

        $user = $this->user('alice', '1f150734-d517-4361-b175-7c535027f72f');
        $result = $this->linker()->unlink($user);

        self::assertFalse($result->isFailure());
        self::assertStringContainsString('Rien n\'a été supprimé', $result->message);
        self::assertNull($user->fresh()->opencloud_user_id);
        Http::assertNothingSent();
    }

    /** Capacité éteinte ⇒ refus NOMMÉ, et aucun appel émis. */
    #[Test]
    public function a_disabled_capability_refuses_before_any_call(): void
    {
        FilePolicyService::setGlobal(true, true, false, '', null, null, null, false);
        Http::fake();

        $result = $this->linker()->link($this->user('alice'), 'mesure_eleve');

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('Accès OpenCloud', $result->message);
        Http::assertNothingSent();
    }

    /**
     * **L'INDEX UNIQUE EST LA DÉFENSE EN PROFONDEUR**, et il laisse passer
     * plusieurs `NULL` — indispensable, puisque l'écrasante majorité des comptes
     * n'a aucune identité en cache.
     */
    #[Test]
    public function the_database_index_is_the_second_line_and_tolerates_many_nulls(): void
    {
        $this->user('a');
        $this->user('b');
        $this->user('c');

        self::assertSame(3, User::query()->whereNull('opencloud_user_id')->count());

        $this->user('d', 'identite-unique');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $duplicate = $this->user('e');
        $duplicate->opencloud_user_id = 'identite-unique';
        $duplicate->saveQuietly();
    }
}

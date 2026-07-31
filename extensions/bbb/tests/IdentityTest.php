<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Http\ArraySessionStore;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Oidc\ErrorCodes;
use SambaEdu\ExtBbb\Oidc\OidcException;

/**
 * Story 57.1 — **LE VOCABULAIRE FERMÉ DU RÔLE, ET LE REFUS EXPLICITE.**
 *
 * Contrat 55.2, gelé : `role` est un scalaire de vocabulaire fermé
 * `prof|eleve|administratif|admin`, et « non résoluble ⇒ clé ABSENTE » — jamais
 * `null`, `""`, ni `"autre"`. Il n'existe donc AUCUNE valeur hors vocabulaire
 * légitime, et donc aucun repli défendable.
 */
final class IdentityTest extends TestCase
{
    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function claims(array $overrides = []): array
    {
        return array_merge([
            'sub' => 'prof.dupont',
            'name' => 'Professeur Dupont',
            'role' => 'prof',
            'groups' => ['3A', '4B'],
        ], $overrides);
    }

    #[Test]
    public function each_role_of_the_closed_vocabulary_is_accepted(): void
    {
        // CONTRÔLE POSITIF : sans lui, les refus ci-dessous prouveraient
        // seulement que tout est refusé.
        foreach (Identity::ROLES as $role) {
            $identity = Identity::fromClaims($this->claims(['role' => $role]));

            self::assertSame($role, $identity->role);
        }

        self::assertSame(['prof', 'eleve', 'administratif', 'admin'], Identity::ROLES);
    }

    #[Test]
    public function a_role_outside_the_vocabulary_is_refused_without_any_fallback(): void
    {
        foreach (['autre', 'super-admin', 'Prof', 'ADMIN', '', 'null'] as $role) {
            try {
                Identity::fromClaims($this->claims(['role' => $role]));
                self::fail('rôle accepté à tort : « ' . $role . ' »');
            } catch (OidcException $e) {
                self::assertSame(ErrorCodes::ROLE_UNSUPPORTED, $e->errorCode);
            }
        }
    }

    #[Test]
    public function a_missing_role_claim_is_refused(): void
    {
        // C'est LE cas nominal du contrat : rôle non résoluble ⇒ clé absente.
        $claims = $this->claims();
        unset($claims['role']);

        $this->expectException(OidcException::class);
        Identity::fromClaims($claims);
    }

    #[Test]
    public function a_null_role_is_refused_as_much_as_an_unknown_one(): void
    {
        $this->expectException(OidcException::class);
        Identity::fromClaims($this->claims(['role' => null]));
    }

    #[Test]
    public function a_missing_sub_is_refused(): void
    {
        $claims = $this->claims();
        unset($claims['sub']);

        try {
            Identity::fromClaims($claims);
            self::fail('identité sans sujet acceptée');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::MISSING_CLAIM, $e->errorCode);
        }
    }

    #[Test]
    public function only_admin_is_admin(): void
    {
        self::assertTrue(Identity::fromClaims($this->claims(['role' => 'admin']))->isAdmin());

        foreach (['prof', 'eleve', 'administratif'] as $role) {
            self::assertFalse(Identity::fromClaims($this->claims(['role' => $role]))->isAdmin());
        }
    }

    #[Test]
    public function groups_are_bare_names_deduplicated_and_cleaned(): void
    {
        $identity = Identity::fromClaims($this->claims([
            'groups' => ['3A', ' 3A ', '', 'Équipe SVT', 42, null],
        ]));

        self::assertSame(['3A', 'Équipe SVT'], $identity->groups);
    }

    #[Test]
    public function an_absent_name_falls_back_to_the_login_never_to_nothing(): void
    {
        $claims = $this->claims();
        unset($claims['name']);

        self::assertSame('prof.dupont', Identity::fromClaims($claims)->name);
    }

    #[Test]
    public function the_identity_survives_a_round_trip_through_the_session(): void
    {
        $session = new ArraySessionStore();
        Identity::fromClaims($this->claims())->storeIn($session);

        $restored = Identity::fromSessionStore($session);

        self::assertNotNull($restored);
        self::assertSame('prof.dupont', $restored->sub);
        self::assertSame('prof', $restored->role);
        self::assertSame(['3A', '4B'], $restored->groups);

        Identity::clear($session);
        self::assertNull(Identity::fromSessionStore($session));
    }

    #[Test]
    public function a_tampered_session_role_yields_no_identity_at_all(): void
    {
        // Le contrôle du vocabulaire se REJOUE à chaque requête : il n'est pas
        // acquis une fois pour toutes à la connexion.
        //
        // ⚠️ L'horodatage est posé FRAIS exprès : sans lui, ce test passerait
        // pour la mauvaise raison (identité périmée) et ne prouverait plus rien
        // du vocabulaire.
        $session = new ArraySessionStore();
        $session->put('identity', ['sub' => 'x', 'name' => 'X', 'role' => 'root', 'groups' => []]);
        $session->put('identity.authenticated_at', time());

        self::assertNull(Identity::fromSessionStore($session));
    }

    // ── Péremption de l'identité locale (review 57.1 #2) ──────────────────────
    //
    // L'extension n'appelle ni `/userinfo` ni l'API et n'a pas de refresh token :
    // elle ne peut PAS apprendre qu'un rôle a changé. Sans borne, un compte
    // rétrogradé garderait ses droits — pour `admin`, la page des serveurs BBB
    // et ses secrets — jusqu'à la fermeture du navigateur.

    #[Test]
    public function a_fresh_identity_is_accepted(): void
    {
        // Contrôle POSITIF : sans lui, les tests de péremption ci-dessous
        // passeraient même si `fromSessionStore` refusait tout.
        $session = new ArraySessionStore();
        $now = 1_800_000_000;
        Identity::fromClaims($this->claims())->storeIn($session, $now);

        self::assertNotNull(Identity::fromSessionStore($session, $now + Identity::MAX_AGE - 1));
    }

    #[Test]
    public function an_identity_older_than_the_max_age_is_refused(): void
    {
        $session = new ArraySessionStore();
        $now = 1_800_000_000;
        Identity::fromClaims($this->claims())->storeIn($session, $now);

        self::assertNull(Identity::fromSessionStore($session, $now + Identity::MAX_AGE));
    }

    #[Test]
    public function an_expired_identity_is_wiped_from_the_session(): void
    {
        // Elle ne doit pas rester à traîner : l'état est effacé au passage.
        $session = new ArraySessionStore();
        $now = 1_800_000_000;
        Identity::fromClaims($this->claims())->storeIn($session, $now);

        Identity::fromSessionStore($session, $now + Identity::MAX_AGE);

        self::assertFalse($session->has('identity'));
        self::assertFalse($session->has('identity.authenticated_at'));
    }

    #[Test]
    public function an_identity_without_a_timestamp_is_refused(): void
    {
        // État d'une version antérieure, ou fabriqué : on ne peut pas affirmer
        // qu'il est frais, donc on ne l'accepte pas.
        $session = new ArraySessionStore();
        $session->put('identity', ['sub' => 'x', 'name' => 'X', 'role' => 'prof', 'groups' => []]);

        self::assertNull(Identity::fromSessionStore($session));
    }

    #[Test]
    public function an_identity_stamped_in_the_future_is_refused(): void
    {
        // Un horodatage postérieur à maintenant ne peut venir que d'un état
        // fabriqué ou d'une horloge qui a reculé : dans les deux cas on
        // redemande plutôt que d'accorder une fraîcheur qu'on ne constate pas.
        $session = new ArraySessionStore();
        $now = 1_800_000_000;
        Identity::fromClaims($this->claims())->storeIn($session, $now + 60);

        self::assertNull(Identity::fromSessionStore($session, $now));
    }
}

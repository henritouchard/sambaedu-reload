<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nextcloud;

use App\Services\Nextcloud\ExternalStorageDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 61.1 — LA SIGNATURE CANONIQUE, et le piège qu'elle referme.
 *
 * Les corps de réponse employés ici sont ceux MESURÉS sur l'instance de sondage le
 * 2026-08-08, **slash initial du point de montage compris** — c'est tout l'objet
 * de ce fichier : un double de test qui rejouerait ce que SE5 ENVOIE au lieu de ce
 * que Nextcloud STOCKE laisserait passer une divergence permanente, et resterait
 * vert pendant que la production « mettrait à jour » le même montage à chaque
 * passage.
 */
class ExternalStorageDefinitionTest extends TestCase
{
    /** Réponse RÉELLE d'un montage « Documents » créé sur `nc-spike`. */
    private static function measuredRemote(
        string $mountPoint,
        string $share,
        string $root,
        ?string $domain = null,
    ): array {
        return [
            'id' => 3,
            'mountPoint' => '/'.$mountPoint,
            'backend' => 'smb',
            'authMechanism' => 'password::sessioncredentials',
            // `domain` est ABSENT par défaut : c'est la forme qu'ont les montages
            // posés avant que SE5 le déclare, et c'est cette forme-là qu'il faut
            // savoir reconnaître ET réparer.
            'backendOptions' => ['host' => 'se4fs', 'share' => $share, 'root' => $root]
                + ($domain === null ? [] : ['domain' => $domain]),
            'priority' => 100,
            'mountOptions' => ['enable_sharing' => false],
            // Avec `sessioncredentials`, le statut est INÉVALUABLE hors session :
            // il figure ici parce que l'instance le rend, et nulle part ailleurs
            // dans le code — ce n'est pas un critère de succès.
            'status' => 4,
            'statusMessage' => 'Storage unauthorized. Session unavailable',
            'userProvided' => false,
            'type' => 'system',
        ];
    }

    #[Test]
    public function the_canonical_set_is_partages_then_documents_with_the_user_placeholder(): void
    {
        $set = ExternalStorageDefinition::canonicalSet('se4fs');

        self::assertCount(2, $set);
        self::assertSame('Partages', $set[0]->mountPoint);
        self::assertSame('partages', $set[0]->share);
        self::assertSame('', $set[0]->root);
        self::assertSame('Documents', $set[1]->mountPoint);
        self::assertSame('users', $set[1]->share);
        self::assertSame('$user', $set[1]->root);
    }

    // =========================================================================
    // LE DOMAINE SMB — le réglage dont l'absence faisait échouer les deux
    // montages sur toute instance en conteneur (mesuré le 2026-08-17).
    // =========================================================================

    #[Test]
    public function the_canonical_set_carries_the_domain_on_both_mounts(): void
    {
        $set = ExternalStorageDefinition::canonicalSet('se4fs', 'localdev');

        self::assertSame('localdev', $set[0]->domain);
        self::assertSame('localdev', $set[1]->domain);
    }

    /**
     * Le domaine PART dans la charge utile. Sans lui, le client SMB du conteneur
     * présente le `workgroup` par défaut de sa distribution et l'annuaire refuse
     * l'ouverture de session.
     */
    #[Test]
    public function the_domain_is_written_in_the_payload(): void
    {
        $payload = (new ExternalStorageDefinition('Partages', 'se4fs', 'partages', '', 'localdev'))->toPayload();

        self::assertSame('localdev', $payload['backendOptions']['domain']);
    }

    /**
     * Déclaré MÊME VIDE : une carte asymétrique laisserait une instance réglée à
     * la main garder un domaine que SE5 ne déclare plus.
     */
    #[Test]
    public function an_empty_domain_is_still_written(): void
    {
        $payload = ExternalStorageDefinition::canonicalSet('se4fs')[0]->toPayload();

        self::assertArrayHasKey('domain', $payload['backendOptions']);
        self::assertSame('', $payload['backendOptions']['domain']);
    }

    /**
     * **LE DOMAINE N'ENTRE PAS DANS LA SIGNATURE D'IDENTITÉ.** Un montage dont
     * seul le domaine change reste LE MÊME montage : s'il y entrait, le passage
     * suivant ne reconnaîtrait plus l'existant et en poserait un SECOND, à côté
     * de celui qui ne marche pas.
     */
    #[Test]
    public function a_mount_lacking_the_domain_keeps_the_same_identity(): void
    {
        $definition = new ExternalStorageDefinition('Partages', 'se4fs', 'partages', '', 'localdev');
        $remoteWithoutDomain = self::measuredRemote('Partages', 'partages', '');

        self::assertSame(
            $definition->signature(),
            ExternalStorageDefinition::signatureOf($remoteWithoutDomain),
        );
    }

    /**
     * …ET IL EST RÉPARÉ. C'est cette ligne qui profite aux instances déjà
     * provisionnées : sans elle, la correction ne vaudrait que pour les neuves,
     * et les montages en place resteraient inutilisables indéfiniment.
     */
    #[Test]
    public function a_mount_lacking_the_domain_is_a_divergence_to_correct(): void
    {
        $definition = new ExternalStorageDefinition('Partages', 'se4fs', 'partages', '', 'localdev');

        self::assertSame(
            ['domain'],
            $definition->divergences(self::measuredRemote('Partages', 'partages', '')),
        );
    }

    #[Test]
    public function a_mount_carrying_the_wrong_domain_is_a_divergence(): void
    {
        $definition = new ExternalStorageDefinition('Partages', 'se4fs', 'partages', '', 'localdev');

        self::assertSame(
            ['domain'],
            $definition->divergences(self::measuredRemote('Partages', 'partages', '', 'WORKGROUP')),
        );
    }

    #[Test]
    public function a_mount_carrying_the_expected_domain_is_conforming(): void
    {
        $definition = new ExternalStorageDefinition('Partages', 'se4fs', 'partages', '', 'localdev');

        self::assertSame(
            [],
            $definition->divergences(self::measuredRemote('Partages', 'partages', '', 'localdev')),
        );
    }

    /** Sans domaine déclaré, un montage sans domaine reste conforme. */
    #[Test]
    public function an_instance_without_a_domain_is_not_permanently_diverging(): void
    {
        $definition = ExternalStorageDefinition::canonicalSet('se4fs')[0];

        self::assertSame([], $definition->divergences(self::measuredRemote('Partages', 'partages', '')));
    }

    #[Test]
    public function a_measured_response_matches_the_definition_that_produced_it(): void
    {
        [$partages, $documents] = ExternalStorageDefinition::canonicalSet('se4fs');

        self::assertSame(
            $partages->signature(),
            ExternalStorageDefinition::signatureOf(self::measuredRemote('Partages', 'partages', '')),
        );
        self::assertSame(
            $documents->signature(),
            ExternalStorageDefinition::signatureOf(self::measuredRemote('Documents', 'users', '$user')),
        );
    }

    /**
     * **LE PIÈGE.** Nextcloud relit le point de montage avec un slash initial. Sans
     * normalisation, chaque passage verrait une divergence, mettrait à jour, et
     * l'idempotence de l'AC3 serait fausse.
     */
    #[Test]
    public function the_leading_slash_added_by_the_instance_is_not_a_divergence(): void
    {
        [$partages] = ExternalStorageDefinition::canonicalSet('se4fs');

        self::assertSame([], $partages->divergences(self::measuredRemote('Partages', 'partages', '')));
    }

    #[Test]
    public function a_renamed_mount_point_is_a_divergence_to_correct_not_a_second_mount(): void
    {
        [$partages] = ExternalStorageDefinition::canonicalSet('se4fs');
        $remote = self::measuredRemote('Ancien nom', 'partages', '');

        self::assertSame($partages->signature(), ExternalStorageDefinition::signatureOf($remote));
        self::assertSame(['mountPoint'], $partages->divergences($remote));
    }

    /**
     * Une restriction d'applicabilité apparue côté Nextcloud est un SECOND plan de
     * permissions sur la zone : SE5 a déclaré « applicable à tous », et c'est la
     * déclaration qui fait foi.
     */
    #[Test]
    public function an_applicability_restriction_is_a_divergence(): void
    {
        [$partages] = ExternalStorageDefinition::canonicalSet('se4fs');
        $remote = self::measuredRemote('Partages', 'partages', '') + [];
        $remote['applicableGroups'] = ['profs'];

        self::assertSame(['applicable'], $partages->divergences($remote));
    }

    /** Un montage étranger n'entre dans aucune comparaison — SE5 ne le gouverne pas. */
    #[Test]
    public function a_foreign_mount_has_no_comparable_signature(): void
    {
        self::assertNull(ExternalStorageDefinition::signatureOf([
            'id' => 9,
            'mountPoint' => '/Sauvegardes',
            'backend' => 'dav',
            'authMechanism' => 'password::password',
            'backendOptions' => ['host' => 'ailleurs'],
        ]));

        // Même backend SMB, mais avec un COMPTE DE SERVICE : ce n'est pas le
        // mécanisme de la story, et l'adopter dupliquerait l'autorité d'accès.
        self::assertNull(ExternalStorageDefinition::signatureOf([
            'id' => 10,
            'mountPoint' => '/Commun',
            'backend' => 'smb',
            'authMechanism' => 'password::password',
            'backendOptions' => ['host' => 'se4fs', 'share' => 'partages', 'root' => ''],
        ]));
    }

    /** Certaines versions rendent l'identifiant sous forme d'objet. */
    #[Test]
    public function the_backend_identifier_is_read_in_both_shapes(): void
    {
        [$partages] = ExternalStorageDefinition::canonicalSet('se4fs');

        self::assertSame($partages->signature(), ExternalStorageDefinition::signatureOf([
            'mountPoint' => '/Partages',
            'backend' => ['identifier' => 'smb'],
            'authMechanism' => ['identifier' => 'password::sessioncredentials'],
            'backendOptions' => ['host' => 'SE4FS', 'share' => 'partages', 'root' => ''],
        ]));
    }
}

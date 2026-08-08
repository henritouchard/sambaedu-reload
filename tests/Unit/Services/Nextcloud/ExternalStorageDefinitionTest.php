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
    private static function measuredRemote(string $mountPoint, string $share, string $root): array
    {
        return [
            'id' => 3,
            'mountPoint' => '/' . $mountPoint,
            'backend' => 'smb',
            'authMechanism' => 'password::sessioncredentials',
            'backendOptions' => ['host' => 'se4fs', 'share' => $share, 'root' => $root],
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

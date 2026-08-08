<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Nextcloud;

use App\Services\Filesystem\Backend\Nextcloud\NextcloudAclRule;
use App\Services\Filesystem\Backend\Nextcloud\NextcloudDavClient;
use App\Services\Filesystem\Backend\Nextcloud\NextcloudPermissionBits;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.3 — LE CANAL DES RÈGLES, ÉPROUVÉ SUR SES CORPS RÉELS.
 *
 * Chaque double de ce fichier rejoue un corps MESURÉ contre l'instance de sondage
 * le 2026-08-08. Aucune sémantique n'y est inventée : ce sont les réponses que le
 * canal produit, y compris — et surtout — celles qui ressemblent à des erreurs sans
 * en être, et celles qui ressemblent à des succès sans en être.
 */
class NextcloudDavChannelTest extends TestCase
{
    private function client(): NextcloudDavClient
    {
        return new NextcloudDavClient(NextcloudConnectionConfig::fromValues(
            'https://nuage.exemple.fr',
            'admin',
            'secret-app-password',
            '',
        ));
    }

    /**
     * Le corps de relecture MESURÉ : la règle revient telle qu'écrite, augmentée
     * d'un libellé d'affichage que le SERVEUR ajoute.
     */
    private static function readBody(int $mask = 31, int $permissions = 0): string
    {
        return <<<XML
        <?xml version="1.0"?>
        <d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:nc="http://nextcloud.org/ns">
          <d:response>
            <d:href>/remote.php/dav/files/admin/Classe_3A/_profs/</d:href>
            <d:propstat>
              <d:prop>
                <nc:acl-list>
                  <nc:acl>
                    <nc:acl-mapping-type>group</nc:acl-mapping-type>
                    <nc:acl-mapping-id>se5_3a_member</nc:acl-mapping-id>
                    <nc:acl-mapping-display-name>Classe 3A (membres)</nc:acl-mapping-display-name>
                    <nc:acl-mask>{$mask}</nc:acl-mask>
                    <nc:acl-permissions>{$permissions}</nc:acl-permissions>
                  </nc:acl>
                </nc:acl-list>
                <nc:acl-enabled>1</nc:acl-enabled>
                <nc:acl-can-manage>1</nc:acl-can-manage>
              </d:prop>
              <d:status>HTTP/1.1 200 OK</d:status>
            </d:propstat>
            <d:propstat>
              <d:prop><nc:inherited-acl-list/></d:prop>
              <d:status>HTTP/1.1 404 Not Found</d:status>
            </d:propstat>
          </d:response>
        </d:multistatus>
        XML;
    }

    /** Le corps d'écriture MESURÉ : `207` d'enveloppe, verdict porté par la propriété. */
    private static function writeBody(string $status = 'HTTP/1.1 200 OK'): string
    {
        return <<<XML
        <?xml version="1.0"?>
        <d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
          <d:response>
            <d:href>/remote.php/dav/files/admin/Classe_3A/_profs</d:href>
            <d:propstat>
              <d:prop><nc:acl-list/></d:prop>
              <d:status>{$status}</d:status>
            </d:propstat>
          </d:response>
        </d:multistatus>
        XML;
    }

    // =========================================================================
    // Relecture
    // =========================================================================

    /**
     * **LE PIÈGE N°3 DE L'EPIC, ÉPINGLÉ** : le serveur AJOUTE un champ à la
     * relecture. Le lire comme une différence produirait une réécriture à chaque
     * passage — un drift permanent avec tous les doubles verts.
     */
    #[Test]
    public function a_rule_read_back_ignores_the_display_name_the_server_adds(): void
    {
        Http::fake(['*' => Http::response(self::readBody(), 207)]);

        $state = $this->client()->readAcl('Classe_3A/_profs');

        self::assertTrue($state->readable);
        self::assertTrue($state->exists);
        self::assertCount(1, $state->rules);

        $written = NextcloudAclRule::forGroup('se5_3a_member', 31, 0);

        self::assertTrue(
            $state->carriesExactly([$written]),
            'la règle relue DOIT être reconnue identique à celle écrite : le libellé d\'affichage ajouté '
            . 'par le serveur n\'est pas un champ de la règle',
        );
    }

    /**
     * **`404` DANS LE CORPS = « AUCUNE RÈGLE », JAMAIS UNE ERREUR.** Même famille
     * que le refus de méthode d'une création rejouée et que le statut « existe
     * déjà » du canal applicatif.
     */
    #[Test]
    public function an_absent_rule_list_reads_as_no_rule_and_never_as_a_failure(): void
    {
        Http::fake(['*' => Http::response(<<<'XML'
        <?xml version="1.0"?>
        <d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
          <d:response>
            <d:href>/remote.php/dav/files/admin/Classe_3A/_travail/</d:href>
            <d:propstat>
              <d:prop><nc:acl-enabled>1</nc:acl-enabled></d:prop>
              <d:status>HTTP/1.1 200 OK</d:status>
            </d:propstat>
            <d:propstat>
              <d:prop><nc:acl-list/><nc:inherited-acl-list/></d:prop>
              <d:status>HTTP/1.1 404 Not Found</d:status>
            </d:propstat>
          </d:response>
        </d:multistatus>
        XML, 207)]);

        $state = $this->client()->readAcl('Classe_3A/_travail');

        self::assertTrue($state->readable, '« aucune règle » est une RÉPONSE, pas une lecture ratée');
        self::assertTrue($state->exists);
        self::assertSame([], $state->rules);
        self::assertSame([], $state->inherited);
        self::assertTrue($state->aclEnabled, 'la sonde d\'activation répond, elle, en 200');
        self::assertTrue($state->carriesExactly([]), 'un nœud sans règle voulue est déjà conforme');
    }

    /** L'héritage est LISIBLE — c'est le vocabulaire natif du problème que la clôture résout. */
    #[Test]
    public function the_ancestor_propagation_is_read_as_its_own_list(): void
    {
        Http::fake(['*' => Http::response(<<<'XML'
        <?xml version="1.0"?>
        <d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
          <d:response>
            <d:href>/remote.php/dav/files/admin/Classe_3A/_travail/devoirs/</d:href>
            <d:propstat>
              <d:prop>
                <nc:inherited-acl-list>
                  <nc:acl>
                    <nc:acl-mapping-type>group</nc:acl-mapping-type>
                    <nc:acl-mapping-id>se5_3a_member</nc:acl-mapping-id>
                    <nc:acl-mask>31</nc:acl-mask>
                    <nc:acl-permissions>1</nc:acl-permissions>
                  </nc:acl>
                </nc:inherited-acl-list>
              </d:prop>
              <d:status>HTTP/1.1 200 OK</d:status>
            </d:propstat>
            <d:propstat>
              <d:prop><nc:acl-list/></d:prop>
              <d:status>HTTP/1.1 404 Not Found</d:status>
            </d:propstat>
          </d:response>
        </d:multistatus>
        XML, 207)]);

        $state = $this->client()->readAcl('Classe_3A/_travail/devoirs');

        self::assertSame([], $state->rules, 'rien n\'est posé ICI');
        self::assertCount(1, $state->inherited, 'mais l\'ancêtre, lui, descend');
        self::assertSame(1, $state->inheritedRuleFor('group:se5_3a_member')?->permissions);
    }

    /** Un chemin absent est un FAIT constaté, pas une lecture ratée. */
    #[Test]
    public function a_missing_path_reads_as_absent_not_as_unreadable(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $state = $this->client()->readAcl('Classe_3A/_inexistant');

        self::assertTrue($state->readable);
        self::assertFalse($state->exists);
    }

    /**
     * **CE QU'ON N'A PAS PU LIRE N'EST JAMAIS « CONFORME ».** Un refus autre que
     * « absent » rend une lecture non exploitable, jamais une liste vide — sans quoi
     * un nœud illisible passerait pour un nœud sans règle.
     */
    #[Test]
    public function a_refused_read_never_degrades_into_an_empty_rule_list(): void
    {
        Http::fake(['*' => Http::response('', 403)]);

        $state = $this->client()->readAcl('Classe_3A/_profs');

        self::assertFalse($state->readable);
        self::assertNotNull($state->error);
        self::assertSame([], $state->rules);
    }

    // =========================================================================
    // Écriture
    // =========================================================================

    /** Le chemin heureux, dans sa forme mesurée : `207` + statut de propriété `200`. */
    #[Test]
    public function a_rule_is_written_as_xml_and_the_property_status_says_it_took(): void
    {
        Http::fake(['*' => Http::response(self::writeBody(), 207)]);

        $outcome = $this->client()->writeAcl('Classe_3A/_profs', [
            NextcloudAclRule::forGroup('se5_3a_member', NextcloudPermissionBits::CLOSURE_MASK, 0),
        ]);

        self::assertTrue($outcome->ok);

        Http::assertSent(static function (Request $request): bool {
            $body = $request->body();

            return $request->method() === 'PROPPATCH'
                && str_contains($request->url(), '/remote.php/dav/files/admin/Classe_3A/_profs')
                && str_contains($body, '<nc:acl-mapping-type>group</nc:acl-mapping-type>')
                && str_contains($body, '<nc:acl-mapping-id>se5_3a_member</nc:acl-mapping-id>')
                && str_contains($body, '<nc:acl-mask>31</nc:acl-mask>')
                && str_contains($body, '<nc:acl-permissions>0</nc:acl-permissions>')
                && str_contains($body, 'xmlns:nc="http://nextcloud.org/ns"');
        });
    }

    /**
     * **L'ENVELOPPE NE CONCLUT RIEN — c'est le piège structurel du protocole.**
     *
     * Un `207` peut envelopper un échec. Lire l'enveloppe rapporterait « appliqué »
     * sur un cloisonnement qui n'existe pas : la signature de défaut exacte que cet
     * epic traque depuis les Epics 56/57.
     */
    #[Test]
    public function a_207_envelope_wrapping_a_property_failure_is_a_failure(): void
    {
        Http::fake(['*' => Http::response(self::writeBody('HTTP/1.1 403 Forbidden'), 207)]);

        $outcome = $this->client()->writeAcl('Classe_3A/_profs', [
            NextcloudAclRule::forGroup('se5_3a_member', 31, 0),
        ]);

        self::assertFalse($outcome->ok, 'le verdict est le statut PAR PROPRIÉTÉ, jamais le code d\'enveloppe');
        self::assertStringContainsString('403', (string) $outcome->error);
    }

    /**
     * Une réponse qui ne parle PAS de ce qu'on a écrit ne prouve rien. On refuse de
     * conclure plutôt que de supposer un succès.
     */
    #[Test]
    public function a_207_that_says_nothing_about_the_written_property_proves_nothing(): void
    {
        Http::fake(['*' => Http::response(<<<'XML'
        <?xml version="1.0"?>
        <d:multistatus xmlns:d="DAV:"><d:response><d:href>/x</d:href></d:response></d:multistatus>
        XML, 207)]);

        $outcome = $this->client()->writeAcl('Classe_3A/_profs', []);

        self::assertFalse($outcome->ok);
        self::assertStringContainsString('ne conclut rien', (string) $outcome->error);
    }

    /** Une liste VIDE est une écriture licite : c'est ainsi que la révocation retire tout. */
    #[Test]
    public function an_empty_rule_list_is_a_legitimate_write(): void
    {
        Http::fake(['*' => Http::response(self::writeBody(), 207)]);

        self::assertTrue($this->client()->writeAcl('Classe_3A/_profs', [])->ok);

        Http::assertSent(static fn (Request $r): bool => str_contains($r->body(), '<nc:acl-list></nc:acl-list>'));
    }

    // =========================================================================
    // Structure
    // =========================================================================

    /** `201` = créé, `405` (rejeu) = déjà là. Deux succès, un seul état. */
    #[Test]
    public function creating_a_collection_normalises_its_two_success_shapes(): void
    {
        Http::fakeSequence()->push('', 201)->push('', 405);

        $created = $this->client()->makeCollection('Classe_3A/_profs');
        self::assertTrue($created->ok);
        self::assertFalse($created->alreadyThere);

        $replayed = $this->client()->makeCollection('Classe_3A/_profs');
        self::assertTrue($replayed->ok, 'un rejeu est une IDEMPOTENCE, pas un échec');
        self::assertTrue($replayed->alreadyThere);
    }

    /**
     * `409` = le parent manque. Ce N'EST PAS une idempotence : ce protocole ne crée
     * pas les parents, donc c'est un défaut d'ORDRE de notre côté. L'avaler ferait
     * disparaître un dossier du plan sans un mot.
     */
    #[Test]
    public function a_missing_parent_is_never_swallowed_as_an_idempotence(): void
    {
        Http::fake(['*' => Http::response('', 409)]);

        $outcome = $this->client()->makeCollection('Classe_3A/_travail/devoirs');

        self::assertFalse($outcome->ok);
        self::assertTrue($outcome->orderFault);
        self::assertStringContainsString('un par un', (string) $outcome->error);
    }

    /** Chaque segment est encodé séparément : ni séparateur écrasé, ni espace laissé. */
    #[Test]
    public function each_path_segment_is_encoded_on_its_own(): void
    {
        self::assertSame(
            'https://nuage.exemple.fr/remote.php/dav/files/admin/Classe_3A/Espace%20partag%C3%A9',
            $this->client()->url('Classe_3A/Espace partagé'),
        );
    }
}

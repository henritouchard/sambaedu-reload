<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Http\NativeSessionStore;

/**
 * Story 57.1, review #1 — **D'OÙ VIENT LE FLAG `Secure` DU COOKIE DE SESSION.**
 *
 * Le piège que ces tests verrouillent : la manière « évidente » de décider
 * (`$_SERVER['HTTPS']`, puis `X-Forwarded-Proto`) ne pose JAMAIS `Secure` dans
 * la topologie réelle de SE5 —
 *
 *  - le backend est derrière `ProxyPass "…" "http://127.0.0.1:<port>/"`, donc
 *    `HTTPS` n'est pas posé, quel que soit le protocole vu par le navigateur ;
 *  - le fragment généré par le helper root ne pose que `X-Forwarded-Prefix`, et
 *    le vhost qui l'inclut est en `*:80`.
 *
 * La source de vérité est donc le **schéma de l'issuer** : l'extension est
 * servie sous `/ext/bbb` de la même origine que SE5, et `SE5_OIDC_ISSUER` est
 * la seule URL de SE5 qu'elle connaisse (contrat §5).
 */
final class SessionCookieSecurityTest extends TestCase
{
    #[Test]
    public function an_https_issuer_alone_sets_the_secure_flag(): void
    {
        // LE cas de production : aucun des deux en-têtes n'arrive au backend.
        self::assertTrue(NativeSessionStore::secureFlagFor('https://se5.etab.fr', []));
    }

    #[Test]
    public function an_http_issuer_alone_does_not_set_the_secure_flag(): void
    {
        // Contrôle négatif : sans lui, le test ci-dessus passerait même si la
        // méthode rendait `true` inconditionnellement. Poser `Secure` sur une
        // instance servie en clair rendrait le cookie tout simplement ignoré.
        self::assertFalse(NativeSessionStore::secureFlagFor('http://se5.etab.fr', []));
    }

    #[Test]
    public function an_issuer_served_under_a_sub_path_is_still_recognised(): void
    {
        // Instance servie sous un sous-chemin par un reverse-proxy amont
        // (topologie lab1) : c'est le schéma qui compte, pas le chemin.
        self::assertTrue(NativeSessionStore::secureFlagFor('https://lab1.sambaedu.org/0991229y', []));
    }

    #[Test]
    public function the_scheme_comparison_is_case_insensitive(): void
    {
        self::assertTrue(NativeSessionStore::secureFlagFor('HTTPS://se5.etab.fr', []));
    }

    #[Test]
    public function a_malformed_issuer_does_not_silently_grant_the_flag(): void
    {
        foreach (['', 'se5.etab.fr', 'ftp://se5.etab.fr', 'https:/se5.etab.fr', ' https://se5.etab.fr'] as $issuer) {
            self::assertFalse(
                NativeSessionStore::secureFlagFor($issuer, []),
                sprintf('un issuer « %s » ne doit pas valoir HTTPS', $issuer),
            );
        }
    }

    #[Test]
    public function the_headers_can_still_confirm_but_never_infirm(): void
    {
        // Les deux en-têtes restent des signaux ADDITIONNELS : ils élèvent, ils
        // ne rabaissent pas.
        self::assertTrue(NativeSessionStore::secureFlagFor('http://se5.etab.fr', ['HTTPS' => 'on']));
        self::assertTrue(NativeSessionStore::secureFlagFor('', ['HTTP_X_FORWARDED_PROTO' => 'https']));
        self::assertTrue(NativeSessionStore::secureFlagFor('', ['HTTP_X_FORWARDED_PROTO' => 'HTTPS']));

        // Un issuer HTTPS l'emporte sur un en-tête qui dirait le contraire :
        // l'en-tête vient du réseau, l'issuer vient de l'installation.
        self::assertTrue(NativeSessionStore::secureFlagFor(
            'https://se5.etab.fr',
            ['HTTP_X_FORWARDED_PROTO' => 'http'],
        ));
    }

    #[Test]
    public function an_empty_https_variable_is_not_a_yes(): void
    {
        // Apache pose `HTTPS=off` — ou rien du tout — hors TLS.
        self::assertFalse(NativeSessionStore::secureFlagFor('http://se5.etab.fr', ['HTTPS' => '']));
        self::assertFalse(NativeSessionStore::secureFlagFor('http://se5.etab.fr', ['HTTP_X_FORWARDED_PROTO' => 'http']));
    }
}

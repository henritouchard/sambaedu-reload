<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Http\Controllers;

use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @legacy-port path="sambaedu/wpkg/profiles_xml_out.php"
 * @see _bmad-output/implementation-artifacts/15-2-generators-xml-ini-par-poste.md
 *
 * Story 15.2 / AC1.2-AC1.3 — Endpoint HTTP `profiles.xml?poste={hostname}`.
 *
 * Parité legacy stricte :
 *   - hostname inconnu → profile vide silencieux (pas de 404 — décision user #2).
 *   - postes désactivés → XML normal, pas de filtrage (décision user #1).
 *   - pas d'auth (décision user #3).
 *
 * Délègue le calcul des packages au `WorkstationPackagesResolver` (cache-aside
 * 1000s, équivalent legacy `info_poste_applications` + APCu).
 */
final class ProfilesXmlController
{
    public function __invoke(Request $request, WorkstationPackagesResolver $resolver): Response
    {
        $hostname = (string) ($request->query('poste') ?? '');

        if ($hostname === '') {
            return response('Erreur! Pas de machine déclarée.', 400, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $packageIds = $resolver->resolve($hostname);

        $xml = new \DOMDocument('1.0');
        $xml->formatOutput = true;
        $xml->preserveWhiteSpace = false;
        $xml->encoding = 'UTF-8';

        $root = $xml->createElement('profiles');
        $xml->appendChild($root);
        $root->appendChild($xml->createComment(' Fichier genere par SambaEdu. Ne pas modifier.'));

        $profile = $xml->createElement('profile');
        $profile->setAttribute('id', $hostname);
        $root->appendChild($profile);

        foreach ($packageIds as $packageId) {
            $package = $xml->createElement('package');
            $package->setAttribute('package-id', (string) $packageId);
            $profile->appendChild($package);
        }

        return response($xml->saveXML(), 200, [
            'Content-Type' => 'text/xml; charset=UTF-8',
        ]);
    }
}

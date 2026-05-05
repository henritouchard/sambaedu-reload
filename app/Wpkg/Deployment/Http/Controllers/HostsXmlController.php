<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @legacy-port path="sambaedu/wpkg/hosts_xml_out.php"
 * @see _bmad-output/implementation-artifacts/15-2-generators-xml-ini-par-poste.md
 *
 * Story 15.2 / AC1.1 — Endpoint HTTP `hosts.xml?poste={hostname}`.
 *
 * Parité legacy stricte : le legacy ne consulte pas la BDD, il renvoie toujours
 * `<wpkg><host name=HOSTNAME profile-id=HOSTNAME/></wpkg>`. Pas d'auth (parité
 * confiance LAN, décision user #3). `profile-id == HOSTNAME`, bijectif.
 */
final class HostsXmlController
{
    public function __invoke(Request $request): Response
    {
        $hostname = (string) ($request->query('poste') ?? '');

        if ($hostname === '') {
            return response('Erreur! Pas de machine déclarée.', 400, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $xml = new \DOMDocument('1.0');
        $xml->formatOutput = true;
        $xml->preserveWhiteSpace = false;
        $xml->encoding = 'UTF-8';

        $root = $xml->createElement('wpkg');
        $xml->appendChild($root);
        $root->appendChild($xml->createComment(' Fichier genere par SambaEdu. Ne pas modifier.'));

        $host = $xml->createElement('host');
        $host->setAttribute('name', $hostname);
        $host->setAttribute('profile-id', $hostname);
        $root->appendChild($host);

        return response($xml->saveXML(), 200, [
            'Content-Type' => 'text/xml; charset=UTF-8',
        ]);
    }
}

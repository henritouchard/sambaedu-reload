<?php

namespace App\Http\Controllers;

use App\Models\LegacyCatchallLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Story 38.2 — Tombstones natifs du canal client legacy.
 *
 * Chaque route encore appelée par un poste SE4 (crochet logon cmd/bash, démon
 * iPXE, machine à états d'install) reçoit une réponse **terminale, typée et
 * inerte** — jamais une réimplémentation, jamais du HTML d'erreur sur un
 * endpoint dont le corps est EXÉCUTÉ côté poste (`curl > x.cmd && call x.cmd`,
 * `eval` du corps par le démon autorun). Servir du code exécutable sur un canal
 * non authentifié serait structurellement un C2 : les corps sont donc des
 * MESSAGES FIXES (jamais d'écho d'un paramètre de requête — la réflexion dans un
 * corps CALLé/eval'é est un vecteur d'injection). Le nettoyage des crochets côté
 * poste est la story 38.3 (agent), pas celle-ci.
 *
 * Observabilité (D3) : chaque hit est journalisé en DB (`source='tombstone'`) +
 * channel `legacylog` (`legacy.tombstone.hit`) — c'est le critère GO de la 38.6.
 *
 * Voisin de {@see LegacyCatchallController} (même famille) ; le catchall n'est
 * PAS modifié par cette story (38.1 l'a déjà traité).
 */
class LegacyTombstoneController extends Controller
{
    /**
     * Message inerte FIXE servi dans les corps script/commentaire. Aucun
     * paramètre de requête n'est jamais réfléchi (corps exécuté = injection).
     */
    private const MESSAGE = 'SE5 : canal client legacy neutralise (tombstone 38.2)';

    /**
     * Script no-op générique : commentaire `REM …\r\n` (cmd Windows, défaut) ou
     * `# …\n` (bash) si `os=linux`.
     */
    public function script(Request $request): Response
    {
        $this->logHit($request);

        return $this->scriptResponse($request);
    }

    /**
     * Commentaire bash STRICT (`# …\n`), quel que soit `os`. Utilisé par
     * `/ipxe/linux/action.php` : le démon `autorun` fait `eval` du corps en
     * boucle — un commentaire cmd (`REM`) y serait une commande inconnue.
     */
    public function bashScript(Request $request): Response
    {
        $this->logHit($request);

        return new Response(
            '# ' . self::MESSAGE . "\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /**
     * `gpo/applications.php` — inerte à TOUTE combinaison de paramètres
     * (action logon/logoff/startup/shutdown, user, machine, ret=0, context, os
     * absent…) SAUF l'exception bornée Q4 : `os=linux` (paramètre explicite) →
     * PASSTHROUGH vers le catchall (le canal Linux reste vivant, cf. mesure
     * lab1). Le catchall logge lui-même le hit — PAS de log tombstone ici.
     */
    public function applications(Request $request): mixed
    {
        if ($request->input('os') === 'linux') {
            return app(LegacyCatchallController::class)->handle($request, $request->path());
        }

        $this->logHit($request);

        return $this->scriptResponse($request);
    }

    /**
     * `gpo/shortcuts_out.php` — `action=file|icon` (téléchargement d'un
     * fichier/icône) → 204 No Content ; sinon script no-op REM/# selon `os`.
     */
    public function shortcuts(Request $request): Response
    {
        $this->logHit($request);

        $action = $request->input('action');
        if ($action === 'file' || $action === 'icon') {
            return $this->noContentResponse();
        }

        return $this->scriptResponse($request);
    }

    /**
     * 204 No Content (legacy servait `image/*` : wallpaper_out, shortcuts_out
     * en mode fichier/icône).
     */
    public function noContent(Request $request): Response
    {
        $this->logHit($request);

        return $this->noContentResponse();
    }

    /**
     * JSON vide valide `{}` (firefox/thunderbird/veyon/associations — iso
     * legacy Content-Type `application/json`).
     */
    public function json(Request $request): Response
    {
        $this->logHit($request);

        return new Response(
            '{}',
            200,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * 200 corps vide `text/plain` (endpoint puits de logs `wpkg/wpkg_log.php`).
     */
    public function emptyBody(Request $request): Response
    {
        $this->logHit($request);

        return new Response(
            '',
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /**
     * XML vide valide : déclaration + élément racine conforme au format legacy
     * (`<wpkg/>`, `<profiles/>`, `<packages/>`, `<unattend/>`). L'élément racine
     * est fourni par la route via `->defaults('element', '<wpkg/>')` — jamais un
     * paramètre de requête (corps fixe).
     */
    public function xml(Request $request): Response
    {
        $this->logHit($request);

        $element = $request->route()?->defaults['element'] ?? '<root/>';
        $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $element . "\n";

        return new Response(
            $body,
            200,
            ['Content-Type' => 'text/xml; charset=UTF-8'],
        );
    }

    /**
     * Corps script no-op : commentaire cmd (`REM …\r\n`) par défaut, bash
     * (`# …\n`) si `os=linux`. Message FIXE (aucun écho de paramètre).
     */
    private function scriptResponse(Request $request): Response
    {
        $isLinux = $request->input('os') === 'linux';
        $comment = $isLinux ? '#' : 'REM';
        $eol = $isLinux ? "\n" : "\r\n";

        return new Response(
            $comment . ' ' . self::MESSAGE . $eol,
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    private function noContentResponse(): Response
    {
        return new Response('', 204);
    }

    /**
     * Journalise un hit tombstone : ligne DB `source='tombstone'` (valeurs
     * TRONQUÉES à la largeur colonne — entrées non authentifiées, SQLite ne
     * borne pas les varchar) + channel `legacylog`. `machine`/`user_login` sont
     * extraits des paramètres d'appel (`machine`/`poste`, `user`).
     */
    private function logHit(Request $request): void
    {
        $path = $request->path();

        $machineRaw = $request->input('machine');
        if (! is_string($machineRaw) || $machineRaw === '') {
            $machineRaw = $request->input('poste');
        }
        $machine = $this->truncate($machineRaw, 255);
        $userLogin = $this->truncate($request->input('user'), 255);

        $data = [
            'source'       => 'tombstone',
            'method'       => $this->truncate($request->method(), 10),
            'path'         => $this->truncate($path, 2048),
            'ip'           => $this->truncate($request->ip(), 45),
            'machine'      => $machine,
            'user_login'   => $userLogin,
            'query_string' => $request->getQueryString() ?: null,
            'referer'      => $request->header('referer') ?: null,
            'created_at'   => now(),
        ];

        try {
            LegacyCatchallLog::create($data);
        } catch (\Exception $e) {
            Log::channel('legacylog')->error('Impossible d\'enregistrer le hit tombstone en DB : ' . $e->getMessage());
        }

        Log::channel('legacylog')->info('legacy.tombstone.hit', [
            'path'    => $path,
            'method'  => $request->method(),
            'ip'      => $request->ip(),
            'machine' => $machine,
            'user'    => $userLogin,
        ]);
    }

    /**
     * Tronque une valeur à `$max` caractères. Une valeur absente/non-scalaire
     * (ou une chaîne vide) devient null. Indispensable en SQLite (varchar non
     * appliqué, `project_sqlite_tests_no_varchar_enforcement`).
     */
    private function truncate(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gpo;

use App\Gpo\Services\ReadUserManager;
use App\Gpo\Services\VeyonConfigGenerator;
use App\Gpo\Services\WorkstationConfigContextResolver;
use App\Http\Controllers\Controller;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint legacy iso-contrat `/gpo/veyon_out.php` — config JSON Veyon
 * consommée par le client Veyon installé sur les postes (bind LDAP, ACL,
 * groupes autorisés).
 *
 * Story 16.3b. Pattern iso 4.8 (`AppPolicyController`).
 *
 * Sous-action `licence=1` : renvoie le contenu raw de
 * `/etc/sambaedu/applications/veyon/licence.vlf` si présent
 * (`application/octet-stream`).
 *
 * Side effect critique : si `read_ldap_password` est vide en config, on crée
 * un compte AD `read.user{suffix}` (`ReadUserManager::ensurePassword`).
 *
 * @legacy-port path="sambaedu/gpo/veyon_out.php"
 */
class VeyonOutController extends Controller
{
    private const LICENCE_PATH = '/etc/sambaedu/applications/veyon/licence.vlf';

    public function __construct(
        private readonly AppContextRepository $contextRepository,
        private readonly VeyonConfigGenerator $generator,
        private readonly ReadUserManager $readUser,
    ) {}

    public function legacyOut(Request $request): Response
    {
        // AC2.2 — Sous-action `licence=1` : sortie raw du fichier licence Veyon.
        $licence = (string) $request->input('licence', '');
        if ($licence === '1') {
            return $this->serveLicence();
        }

        $id = (string) $request->input('id', '');

        // AC4.3 — Validation md5 strict AVANT tout accès APCu/AD.
        if (! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return $this->emptyOk();
        }

        $context = $this->contextRepository->findById($id);
        if ($context === null) {
            // #M4 (review fixes) : debug, pas info (300 logs/min en boot de masse).
            Log::debug('[VeyonOutController] context expired', ['id' => $id]);
            return $this->emptyOk();
        }

        // Iso-legacy `veyon_out.php:25-27` : nom_poste vide → exit() body vide.
        if ($context->machineName === '') {
            return $this->emptyOk();
        }

        // AC2.5/2.6 — Création AD `read.user{suffix}` si absent. Décision Henri
        // 2026-05-12 (option B) : si échec → renvoyer la config JSON sans
        // `BindPassword` (client Veyon retry au prochain logon, pas de 503).
        try {
            $readPassword = $this->readUser->ensurePassword();
        } catch (\Throwable $e) {
            Log::error('[VeyonOutController] read.user resolution threw', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            $readPassword = null;
        }

        try {
            $json = $this->generator->generate($context, $readPassword ?? '');
        } catch (\Throwable $e) {
            Log::error('[VeyonOutController] generate failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyOk();
        }

        if ($readPassword === null) {
            // Option B Henri : strip BindPassword pour signaler explicitement
            // au client Veyon que le bind échouera (retry au prochain logon).
            if (isset($json['LDAP']) && is_array($json['LDAP'])) {
                unset($json['LDAP']['BindPassword']);
            }
            Log::error('[VeyonOutController] serving JSON without BindPassword (read.user creation failed)', [
                'id' => $id,
            ]);
        }

        $body = (string) json_encode($json, JSON_PRETTY_PRINT);

        return response($body, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Story 16.13 — endpoint natif `GET /api/v1/workstation-config/veyon`.
     *
     * Pattern iso 16.12 strict : `workstation_uuid` extrait EXCLUSIVEMENT
     * du JWT via `$request->attributes->get('auth_v1.workstation_uuid')`.
     *
     * Sous-action `licence=1` : sert le fichier licence raw (parité
     * `serveLicence()`). Sinon : reconstruit le contexte via le resolver
     * et délègue à `VeyonConfigGenerator::generate()`.
     *
     * Iso-fonctionnel avec `legacyOut()` : même Content-Type
     * (`application/json; charset=utf-8` ou `application/octet-stream`
     * pour licence), mêmes status. Déviation D5 : 404 explicite si
     * `workstation_uuid` JWT inconnu en DB.
     */
    public function apiV1(Request $request, WorkstationConfigContextResolver $resolver): Response
    {
        // Sous-action `licence=1` — parité legacy (pas de JWT requis côté
        // logique métier mais auth JWT déjà appliquée par le middleware).
        $licence = (string) $request->input('licence', '');
        if ($licence === '1') {
            return $this->serveLicence();
        }

        $workstationUuid = (string) $request->attributes->get('auth_v1.workstation_uuid', '');
        $userLogin = (string) $request->input('user', '');
        $os = (string) $request->input('os', 'linux');
        $userProfile = (string) $request->input('userprofile', '');

        $context = $resolver->toAppContext($workstationUuid, $os, $userLogin, $userProfile);
        if ($context === null) {
            Log::channel('auth-v1')->warning('[VeyonOutController] workstation not found', [
                'action_type' => 'agent.v1.config.workstation_not_found',
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'endpoint' => '/api/v1/workstation-config/veyon',
            ]);
            // Format JSON unifié post-review (Henri Q2).
            return response()->json(['error' => 'workstation_not_found'], 404);
        }

        if ($context->machineName === '') {
            return $this->emptyOk();
        }

        try {
            $readPassword = $this->readUser->ensurePassword();
        } catch (\Throwable $e) {
            Log::error('[VeyonOutController] read.user resolution threw (apiV1)', [
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'error' => $e->getMessage(),
            ]);
            $readPassword = null;
        }

        try {
            $json = $this->generator->generate($context, $readPassword ?? '');
        } catch (\Throwable $e) {
            Log::error('[VeyonOutController] generate failed (apiV1)', [
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'error' => $e->getMessage(),
            ]);
            return $this->emptyOk();
        }

        if ($readPassword === null) {
            if (isset($json['LDAP']) && is_array($json['LDAP'])) {
                unset($json['LDAP']['BindPassword']);
            }
            Log::error('[VeyonOutController] serving JSON without BindPassword (apiV1)', [
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
            ]);
        }

        $body = (string) json_encode($json, JSON_PRETTY_PRINT);

        return response($body, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Sert le fichier licence Veyon raw (parité legacy ligne 14-19).
     *
     * Chemin codé en dur (pas de paramètre `path`) — pas de path traversal possible.
     *
     * #M3 (review fixes) : `Cache-Control: no-store, no-cache, must-revalidate`
     * sur les deux returns fallback (fichier absent / illisible) pour éviter
     * qu'un proxy intermédiaire cache la réponse vide jusqu'à expiration
     * (admin qui installe la licence après le 1er appel = postes derrière le
     * cache reçoivent vide jusqu'à la TTL).
     *
     * @legacy-port path="sambaedu/gpo/veyon_out.php:13-20"
     */
    private function serveLicence(): Response
    {
        if (! is_file(self::LICENCE_PATH) || ! is_readable(self::LICENCE_PATH)) {
            // Iso-legacy : `exit()` sans body si fichier absent — mais on ajoute
            // explicitement no-store pour ne pas cacher du vide en cas de
            // déploiement licence ultérieur.
            return response('', 200, [
                'Content-Type' => 'application/octet-stream',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]);
        }
        $contents = file_get_contents(self::LICENCE_PATH);
        if ($contents === false) {
            return response('', 200, [
                'Content-Type' => 'application/octet-stream',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]);
        }
        return response($contents, 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Réponse vide iso-legacy : `200 body=""` (pas 204) — décision Henri
     * 2026-05-12 post-review (#4). Le legacy PHP `exit()` fallthrough produit
     * un 200 body vide. Content-Type retiré (#5) : un body vide n'a pas besoin
     * de Content-Type — Veyon client ignore le payload de toute façon.
     */
    private function emptyOk(): Response
    {
        return response('', 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Auth\V1\Pki;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service PKI locale — génère et persiste le CA root, le cert serveur HTTPS,
 * et la paire RS256 dédiée à la signature JWT (Story 16.10, AC1.1, D1 + D3).
 *
 * **Outillage** (D1) :
 *
 *  - Fonctions natives PHP `openssl_pkey_new()`, `openssl_csr_new()`,
 *    `openssl_csr_sign()`, `openssl_x509_export()`, `openssl_pkey_export()`
 *    sont utilisées en **priorité**. C'est le cas pour :
 *       - génération clé RSA (CA root, cert serveur, JWT)
 *       - génération CSR
 *       - export pubkey/cert au format PEM
 *  - Fallback `Process::run(['openssl', ...])` mode array est utilisé
 *    **uniquement** quand l'API native PHP est insuffisante — typiquement
 *    pour appliquer des extensions x509v3 (SAN, basicConstraints CA:true,
 *    keyUsage critical, extendedKeyUsage). PHP <8.3 ne permet pas de passer
 *    ces extensions facilement via `openssl_csr_sign()` (le tableau `$configargs`
 *    accepte `config` + `digest_alg` mais pas un v3 mapping fiable sur tous
 *    les Debian/PHP). Le fallback CLI reste **dans cette classe uniquement**
 *    (whitelist test archi `AuthV1NamespaceTest`).
 *  - Mode array obligatoire pour `Process::run([...])` (jamais string shell —
 *    audit Epic 16 §6.F).
 *
 * **Idempotence** (AC1.1) :
 *
 *  - `initIfMissing()` : si CA root + cert serveur + paire JWT déjà présents
 *    (3 fichiers cert + 3 fichiers clés), retourne sans rien faire.
 *  - `forceRegen()` : régénère tout (CA, server, JWT). Détruit les anciens.
 *  - `regenerateServerOnly()` : régénère uniquement la paire serveur (signée
 *    par le CA existant). N'altère ni CA ni JWT keys.
 *
 * **Permissions** (D3) :
 *
 *  - Clés privées chmod **0600** (CA, server, JWT private)
 *  - Certs + clés publiques chmod **0644**
 *  - Dossiers `storage/keys/pki/` et `storage/keys/jwt/` créés en 0700
 *
 * **Logging** : channel `auth-v1` (cf. README), `action_type`
 *   `auth.ca.init.start` / `auth.ca.init.success` / `auth.ca.init.skipped` /
 *   `auth.ca.server.regenerated`. Aucun secret n'est jamais loggé.
 */
class CaInitializer
{
    /** Mode des dossiers contenant des clés privées (rwx propriétaire seul). */
    private const DIR_MODE = 0700;

    /** Mode des fichiers de clés privées. */
    private const PRIVATE_KEY_MODE = 0600;

    /** Mode des fichiers publics (cert + pubkey). */
    private const PUBLIC_MODE = 0644;

    /** Hash de signature (RFC 5280 — sha256WithRSAEncryption). */
    private const SIGN_DIGEST = 'sha256';

    /**
     * Chemins surchargés (injection de dépendance pour tests). Si null, les
     * valeurs viennent de `config('auth_v1.pki')` au moment de l'usage.
     *
     * @var array<string, string|int|null>
     */
    private array $config;

    /** @param array<string, string|int> $overrides */
    public function __construct(array $overrides = [])
    {
        $this->config = $overrides;
    }

    /**
     * Initialise la PKI si elle est absente. Idempotent.
     *
     * @return array<string, mixed> Rapport : `{status, files, server_url_block, regenerated}`.
     *
     * @throws RuntimeException Si une des étapes openssl échoue.
     */
    public function initIfMissing(): array
    {
        $this->logEvent('auth.ca.init.start', ['mode' => 'init_if_missing']);

        if ($this->isAlreadyInitialized()) {
            $report = [
                'status' => 'already_initialized',
                'regenerated' => [],
                'files' => $this->listManagedFiles(),
                'server_url_block' => $this->renderServerBlocks(),
            ];
            $this->logEvent('auth.ca.init.skipped', ['reason' => 'already_initialized']);

            return $report;
        }

        $regenerated = [];
        $this->ensureDirectoriesExist();

        // CA root (peut déjà exister si un appel partiel a planté avant
        // la fin) — on ne ré-émet le CA que si absent.
        $caKey = $this->path('ca_root_key');
        $caCrt = $this->path('ca_root_crt');
        if (! is_file($caKey) || ! is_file($caCrt)) {
            $this->generateCaRoot();
            $regenerated[] = 'ca-root';
        }

        // Cert serveur — toujours régénérer si absent.
        $serverKey = $this->path('server_key');
        $serverCrt = $this->path('server_crt');
        if (! is_file($serverKey) || ! is_file($serverCrt)) {
            $this->generateServerCert();
            $regenerated[] = 'server';
        }

        // JWT keypair.
        $jwtPriv = (string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.private', '');
        $jwtPub = (string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.public', '');
        if (! is_file($jwtPriv) || ! is_file($jwtPub)) {
            $this->generateJwtKeypair();
            $regenerated[] = 'jwt-keys';
        }

        $report = [
            'status' => 'initialized',
            'regenerated' => $regenerated,
            'files' => $this->listManagedFiles(),
            'server_url_block' => $this->renderServerBlocks(),
        ];

        $this->logEvent('auth.ca.init.success', [
            'regenerated' => $regenerated,
        ]);

        return $report;
    }

    /**
     * Force la régénération complète (CA, server, JWT). Détruit les anciens
     * fichiers (gardés en backup `.bak-YYYYMMDDHHMMSS`).
     *
     * @return array<string, mixed>
     */
    public function forceRegen(): array
    {
        $this->logEvent('auth.ca.init.start', ['mode' => 'force']);

        $stamp = date('YmdHis');
        $this->backupIfExists($this->path('ca_root_key'), $stamp);
        $this->backupIfExists($this->path('ca_root_crt'), $stamp);
        $this->backupIfExists($this->path('server_key'), $stamp);
        $this->backupIfExists($this->path('server_crt'), $stamp);

        $jwtPriv = (string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.private', '');
        $jwtPub = (string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.public', '');
        $this->backupIfExists($jwtPriv, $stamp);
        $this->backupIfExists($jwtPub, $stamp);

        $this->ensureDirectoriesExist();
        $this->generateCaRoot();
        $this->generateServerCert();
        $this->generateJwtKeypair();

        $report = [
            'status' => 'force_regenerated',
            'regenerated' => ['ca-root', 'server', 'jwt-keys'],
            'files' => $this->listManagedFiles(),
            'server_url_block' => $this->renderServerBlocks(),
        ];
        $this->logEvent('auth.ca.init.success', [
            'mode' => 'force',
            'regenerated' => $report['regenerated'],
        ]);

        return $report;
    }

    /**
     * Régénère uniquement le cert serveur (signé par le CA existant), sans
     * toucher au CA ni aux JWT keys. Utile pour rotation cert HTTPS.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException Si le CA root n'est pas présent (prérequis).
     */
    public function regenerateServerOnly(): array
    {
        $this->logEvent('auth.ca.init.start', ['mode' => 'regenerate_server_only']);

        $caKey = $this->path('ca_root_key');
        $caCrt = $this->path('ca_root_crt');
        if (! is_file($caKey) || ! is_file($caCrt)) {
            throw new RuntimeException(
                'Cannot regenerate server cert : CA root absent ('.$caKey.' / '.$caCrt.'). '
                .'Run with --force to regenerate the full chain.'
            );
        }

        $stamp = date('YmdHis');
        $this->backupIfExists($this->path('server_key'), $stamp);
        $this->backupIfExists($this->path('server_crt'), $stamp);

        $this->ensureDirectoriesExist();
        $this->generateServerCert();

        $report = [
            'status' => 'server_regenerated',
            'regenerated' => ['server'],
            'files' => $this->listManagedFiles(),
            'server_url_block' => $this->renderServerBlocks(),
        ];
        $this->logEvent('auth.ca.server.regenerated', []);

        return $report;
    }

    /**
     * Retourne le contenu PEM du CA root (utile pour `EnrollController` qui
     * renvoie `ca_cert_pem` au poste).
     *
     * @throws RuntimeException Si le CA root n'est pas présent.
     */
    public function getCaCertPem(): string
    {
        $crt = $this->path('ca_root_crt');
        if (! is_file($crt) || ! is_readable($crt)) {
            throw new RuntimeException('CA root cert not initialized — run php artisan auth:ca:init');
        }
        $contents = file_get_contents($crt);
        if ($contents === false) {
            throw new RuntimeException('Cannot read CA root cert : '.$crt);
        }

        return $contents;
    }

    /**
     * Indique si la PKI est entièrement initialisée (6 fichiers attendus
     * présents).
     */
    public function isAlreadyInitialized(): bool
    {
        foreach (['ca_root_key', 'ca_root_crt', 'server_key', 'server_crt'] as $key) {
            if (! is_file($this->path($key))) {
                return false;
            }
        }

        $jwtPriv = (string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.private', '');
        $jwtPub = (string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.public', '');
        if (! is_file($jwtPriv) || ! is_file($jwtPub)) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // ÉTAPES INTERNES — Génération individuelle
    // =========================================================================

    /**
     * Génère le CA root RSA (validité = `ca_validity_days`). Auto-signé.
     * Configuration x509v3 : `basicConstraints=critical,CA:TRUE,pathlen:0`,
     * `keyUsage=critical,keyCertSign,cRLSign`, `subjectKeyIdentifier=hash`.
     */
    private function generateCaRoot(): void
    {
        $bits = (int) $this->getConfig('ca_key_bits', 4096);
        $days = (int) $this->getConfig('ca_validity_days', 1825);
        $org = (string) $this->getConfig('subject_organization', 'SambaEdu');
        $country = (string) $this->getConfig('subject_country', 'FR');

        $se4fs = $this->se4fsName();
        $dn = [
            'C' => $country,
            'O' => $org,
            'OU' => 'SambaEdu Local PKI',
            'CN' => sprintf('SambaEdu Local CA — %s', $se4fs ?: 'sambaedu-local'),
        ];

        $caConfPath = $this->writeOpensslConfig('ca_root', [
            'extensions' => [
                'basicConstraints' => 'critical,CA:TRUE,pathlen:0',
                'keyUsage' => 'critical,keyCertSign,cRLSign',
                'subjectKeyIdentifier' => 'hash',
            ],
        ]);

        try {
            $privateKey = openssl_pkey_new([
                'private_key_bits' => $bits,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => $caConfPath,
            ]);
            if ($privateKey === false) {
                throw new RuntimeException('openssl_pkey_new failed (CA root) : ' . $this->lastOpensslError());
            }

            $csr = openssl_csr_new(
                $dn,
                $privateKey,
                [
                    'digest_alg' => self::SIGN_DIGEST,
                    'config' => $caConfPath,
                ]
            );
            if ($csr === false) {
                throw new RuntimeException('openssl_csr_new failed (CA root) : ' . $this->lastOpensslError());
            }

            $cert = openssl_csr_sign(
                $csr,
                null, // self-signed
                $privateKey,
                $days,
                [
                    'digest_alg' => self::SIGN_DIGEST,
                    'x509_extensions' => 'v3_ca',
                    'config' => $caConfPath,
                ]
            );
            if ($cert === false) {
                throw new RuntimeException('openssl_csr_sign failed (CA root) : ' . $this->lastOpensslError());
            }

            // Export PEM
            $keyPem = '';
            if (! openssl_pkey_export($privateKey, $keyPem, null, ['config' => $caConfPath])) {
                throw new RuntimeException('openssl_pkey_export failed (CA root) : ' . $this->lastOpensslError());
            }
            $crtPem = '';
            if (! openssl_x509_export($cert, $crtPem)) {
                throw new RuntimeException('openssl_x509_export failed (CA root) : ' . $this->lastOpensslError());
            }

            $this->writeSecureFile($this->path('ca_root_key'), $keyPem, self::PRIVATE_KEY_MODE);
            $this->writeSecureFile($this->path('ca_root_crt'), $crtPem, self::PUBLIC_MODE);
            // ca-root.crt est lu par PHP/Apache (renvoyé dans la réponse enroll
            // `ca_cert_pem`). ca-root.key reste root-only — jamais besoin par le web.
            $this->applyWebOwnership($this->path('ca_root_crt'));
        } finally {
            @unlink($caConfPath);
        }
    }

    /**
     * Génère le cert serveur HTTPS (signé par le CA root). CN = hostname FQDN
     * `se4fs-<UAI>.<domaine>`. SAN inclut le FQDN + hostname court +
     * `localhost` + `127.0.0.1` (utile pour smoke tests locaux).
     *
     * Extensions x509v3 : `basicConstraints=CA:FALSE`, `keyUsage=critical,digitalSignature,keyEncipherment`,
     * `extendedKeyUsage=serverAuth`, `subjectAltName=DNS:<fqdn>,DNS:<short>,DNS:localhost,IP:127.0.0.1`.
     */
    private function generateServerCert(): void
    {
        $bits = (int) $this->getConfig('server_key_bits', 2048);
        $days = (int) $this->getConfig('server_validity_days', 365);
        $org = (string) $this->getConfig('subject_organization', 'SambaEdu');
        $country = (string) $this->getConfig('subject_country', 'FR');

        $fqdn = $this->serverFqdn();
        $shortName = $this->se4fsName() ?: 'sambaedu-local';

        $dn = [
            'C' => $country,
            'O' => $org,
            'OU' => 'SambaEdu Local HTTPS',
            'CN' => $fqdn,
        ];

        $sanEntries = array_values(array_filter(array_unique([
            'DNS:' . $fqdn,
            'DNS:' . $shortName,
            'DNS:localhost',
            'IP:127.0.0.1',
        ])));

        $serverConfPath = $this->writeOpensslConfig('server', [
            'extensions' => [
                'basicConstraints' => 'CA:FALSE',
                'keyUsage' => 'critical,digitalSignature,keyEncipherment',
                'extendedKeyUsage' => 'serverAuth',
                'subjectAltName' => implode(',', $sanEntries),
                'subjectKeyIdentifier' => 'hash',
            ],
        ]);

        $caKeyContents = file_get_contents($this->path('ca_root_key'));
        $caCrtContents = file_get_contents($this->path('ca_root_crt'));
        if ($caKeyContents === false || $caCrtContents === false) {
            throw new RuntimeException('Cannot read CA root materials');
        }
        $caKey = openssl_pkey_get_private($caKeyContents);
        $caCrt = openssl_x509_read($caCrtContents);
        if ($caKey === false || $caCrt === false) {
            throw new RuntimeException('CA root unreadable (corrupted ?) : ' . $this->lastOpensslError());
        }

        try {
            $privateKey = openssl_pkey_new([
                'private_key_bits' => $bits,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => $serverConfPath,
            ]);
            if ($privateKey === false) {
                throw new RuntimeException('openssl_pkey_new failed (server) : ' . $this->lastOpensslError());
            }

            $csr = openssl_csr_new(
                $dn,
                $privateKey,
                [
                    'digest_alg' => self::SIGN_DIGEST,
                    'config' => $serverConfPath,
                ]
            );
            if ($csr === false) {
                throw new RuntimeException('openssl_csr_new failed (server) : ' . $this->lastOpensslError());
            }

            $cert = openssl_csr_sign(
                $csr,
                $caCrt,
                $caKey,
                $days,
                [
                    'digest_alg' => self::SIGN_DIGEST,
                    'x509_extensions' => 'v3_req',
                    'config' => $serverConfPath,
                ],
                random_int(1, PHP_INT_MAX) // serial number unique
            );
            if ($cert === false) {
                throw new RuntimeException('openssl_csr_sign failed (server) : ' . $this->lastOpensslError());
            }

            $keyPem = '';
            if (! openssl_pkey_export($privateKey, $keyPem, null, ['config' => $serverConfPath])) {
                throw new RuntimeException('openssl_pkey_export failed (server) : ' . $this->lastOpensslError());
            }
            $crtPem = '';
            if (! openssl_x509_export($cert, $crtPem)) {
                throw new RuntimeException('openssl_x509_export failed (server) : ' . $this->lastOpensslError());
            }

            $this->writeSecureFile($this->path('server_key'), $keyPem, self::PRIVATE_KEY_MODE);
            $this->writeSecureFile($this->path('server_crt'), $crtPem, self::PUBLIC_MODE);
            // server.key + server.crt lus par Apache HTTPS vhost.
            $this->applyWebOwnership($this->path('server_key'));
            $this->applyWebOwnership($this->path('server_crt'));
        } finally {
            @unlink($serverConfPath);
        }
    }

    /**
     * Génère la paire JWT RS256 (utilisée par WorkstationJwtIssuer).
     */
    private function generateJwtKeypair(): void
    {
        $bits = (int) $this->getConfig('jwt_key_bits', 2048);

        $privKey = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($privKey === false) {
            throw new RuntimeException('openssl_pkey_new failed (JWT) : ' . $this->lastOpensslError());
        }

        $privPem = '';
        if (! openssl_pkey_export($privKey, $privPem)) {
            throw new RuntimeException('openssl_pkey_export failed (JWT) : ' . $this->lastOpensslError());
        }
        $details = openssl_pkey_get_details($privKey);
        if ($details === false || ! isset($details['key'])) {
            throw new RuntimeException('openssl_pkey_get_details failed (JWT) : ' . $this->lastOpensslError());
        }
        $pubPem = (string) $details['key'];

        $jwtPriv = (string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.private', '');
        $jwtPub = (string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.public', '');
        if ($jwtPriv === '' || $jwtPub === '') {
            throw new RuntimeException('JWT key paths not configured for active kid : ' . $this->activeKid());
        }

        // Le dossier de la JWT key peut différer du PKI (storage/keys/jwt/).
        $jwtDir = dirname($jwtPriv);
        if (! is_dir($jwtDir)) {
            if (! @mkdir($jwtDir, self::DIR_MODE, true) && ! is_dir($jwtDir)) {
                throw new RuntimeException('Cannot mkdir JWT dir : ' . $jwtDir);
            }
        }

        $this->writeSecureFile($jwtPriv, $privPem, self::PRIVATE_KEY_MODE);
        $this->writeSecureFile($jwtPub, $pubPem, self::PUBLIC_MODE);
        // JWT keypair lue par WorkstationJwtIssuer/Validator (process PHP/Apache).
        $this->applyWebOwnership($jwtPriv);
        $this->applyWebOwnership($jwtPub);
        $this->applyWebOwnership($jwtDir);
    }

    // =========================================================================
    // HELPERS — Configuration, paths, logging
    // =========================================================================

    /**
     * Résout un chemin via override constructeur > config('auth_v1.pki.*').
     */
    private function path(string $key): string
    {
        if (isset($this->config[$key]) && is_string($this->config[$key])) {
            return $this->config[$key];
        }

        return (string) config('auth_v1.pki.' . $key, '');
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        return config('auth_v1.pki.' . $key, $default);
    }

    private function activeKid(): string
    {
        $kid = (string) config('auth_v1.jwt.active_kid', '');
        if ($kid === '') {
            throw new RuntimeException('auth_v1.jwt.active_kid not configured');
        }

        return $kid;
    }

    private function se4fsName(): string
    {
        return (string) config('sambaedu.se4fs_name', '');
    }

    /**
     * Calcule le hostname FQDN du serveur pour CN du cert serveur.
     * Format : `<se4fs_name>.<host_suffix>` ou fallback `<se4fs_name>` si
     * suffixe vide. Si aucune valeur n'est positionnée, fallback `localhost`.
     */
    private function serverFqdn(): string
    {
        $short = $this->se4fsName();
        $suffix = (string) config('auth_v1.server.host_suffix', '');

        if ($short === '') {
            return 'localhost';
        }
        if ($suffix === '') {
            return $short;
        }

        return $short . '.' . ltrim($suffix, '.');
    }

    /**
     * Crée les dossiers de stockage avec permissions 0700.
     */
    private function ensureDirectoriesExist(): void
    {
        $dirs = array_unique(array_filter([
            dirname($this->path('ca_root_key')),
            dirname($this->path('ca_root_crt')),
            dirname($this->path('server_key')),
            dirname($this->path('server_crt')),
            dirname((string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.private', '')),
            dirname((string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.public', '')),
        ]));

        foreach ($dirs as $dir) {
            if ($dir === '' || $dir === '.' || $dir === '/') {
                continue;
            }
            if (! is_dir($dir)) {
                if (! @mkdir($dir, self::DIR_MODE, true) && ! is_dir($dir)) {
                    throw new RuntimeException('Cannot create directory : ' . $dir);
                }
            }
            @chmod($dir, self::DIR_MODE);
            // Les dossiers contiennent des fichiers que PHP/Apache doit lire —
            // ils doivent être traversables par le runtime web. Le chown ici
            // garantit que la traversée 0700 ne bloque pas le web user.
            $this->applyWebOwnership($dir);
        }
    }

    /**
     * Aligne la propriété du fichier/dossier sur le user runtime du serveur web
     * (PHP-FPM / Apache mod_php) pour qu'il puisse lire les certs et clés JWT.
     *
     * Sambaedu utilise un pool PHP-FPM custom `www-admin`, distinct du défaut
     * Debian `www-data`. Sans ce chown, `auth:ca:init` (lancé en root via
     * `update.sh`) produit des fichiers `root:root 0600` illisibles par le web.
     *
     * No-op si `auth_v1.pki.web_owner` est vide, si posix n'est pas dispo (Mac
     * dev / Win), ou si l'user n'existe pas (log warning).
     */
    private function applyWebOwnership(string $path): void
    {
        $webOwner = (string) config('auth_v1.pki.web_owner', '');
        if ($webOwner === '' || ! function_exists('posix_getpwnam')) {
            return;
        }
        $info = @posix_getpwnam($webOwner);
        if ($info === false) {
            Log::channel('auth-v1')->warning('[CaInitializer] web_owner unknown — skip chown', [
                'web_owner' => $webOwner,
                'path' => $path,
            ]);
            return;
        }
        if (! @chown($path, $info['uid']) || ! @chgrp($path, $info['gid'])) {
            Log::channel('auth-v1')->warning('[CaInitializer] chown to web_owner failed', [
                'web_owner' => $webOwner,
                'uid' => $info['uid'],
                'gid' => $info['gid'],
                'path' => $path,
                'errno' => error_get_last()['message'] ?? null,
            ]);
        }
    }

    private function writeSecureFile(string $path, string $contents, int $mode): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (! @mkdir($dir, self::DIR_MODE, true) && ! is_dir($dir)) {
                throw new RuntimeException('Cannot mkdir : ' . $dir);
            }
        }

        // Pour les fichiers privés (0600), on force umask 0077 AVANT file_put_contents
        // pour garantir que le fichier soit créé directement avec les bonnes perms
        // — pas de fenêtre exploitable entre create et chmod (sinon clé privée
        // lisible quelques µs par d'autres process locaux).
        $isPrivate = ($mode & 0077) === 0;
        $previousUmask = $isPrivate ? umask(0077) : null;
        try {
            $bytes = file_put_contents($path, $contents);
            if ($bytes === false) {
                throw new RuntimeException('Cannot write : ' . $path);
            }
            if (! @chmod($path, $mode)) {
                Log::channel('auth-v1')->warning('[CaInitializer] chmod failed', [
                    'path' => $path,
                    'mode' => sprintf('%04o', $mode),
                ]);
            }
        } finally {
            if ($previousUmask !== null) {
                umask($previousUmask);
            }
        }
    }

    private function backupIfExists(string $path, string $stamp): void
    {
        if ($path === '' || ! is_file($path)) {
            return;
        }
        $backup = $path . '.bak-' . $stamp;
        if (@rename($path, $backup)) {
            Log::channel('auth-v1')->info('[CaInitializer] backed up before regen', [
                'original' => $path,
                'backup' => $backup,
            ]);
        }
    }

    /**
     * Écrit un fichier de config openssl minimaliste pour permettre
     * `openssl_csr_new`/`openssl_csr_sign` de produire des extensions v3.
     *
     * @param array{extensions: array<string, string>} $opts
     */
    private function writeOpensslConfig(string $label, array $opts): string
    {
        $extLabel = $label === 'ca_root' ? 'v3_ca' : 'v3_req';
        $extensionsLines = '';
        foreach ($opts['extensions'] ?? [] as $key => $value) {
            $extensionsLines .= $key . ' = ' . $value . "\n";
        }

        $content = <<<INI
[ req ]
default_bits = 2048
default_md = sha256
distinguished_name = req_distinguished_name
prompt = no
req_extensions = {$extLabel}

[ req_distinguished_name ]

[ {$extLabel} ]
{$extensionsLines}
INI;

        $tmp = tempnam(sys_get_temp_dir(), 'auth_v1_pki_' . $label . '_');
        if ($tmp === false) {
            throw new RuntimeException('tempnam failed for openssl config');
        }
        if (file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            throw new RuntimeException('Cannot write openssl config : ' . $tmp);
        }

        return $tmp;
    }

    private function lastOpensslError(): string
    {
        $errors = [];
        while (($e = openssl_error_string()) !== false) {
            $errors[] = $e;
        }

        return implode(' | ', $errors) ?: 'unknown openssl error';
    }

    /**
     * @return array<string, string>
     */
    private function listManagedFiles(): array
    {
        $jwtPriv = (string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.private', '');
        $jwtPub = (string) config('auth_v1.jwt.keys.' . $this->activeKid() . '.public', '');

        return [
            'ca_root_key' => $this->path('ca_root_key'),
            'ca_root_crt' => $this->path('ca_root_crt'),
            'server_key' => $this->path('server_key'),
            'server_crt' => $this->path('server_crt'),
            'jwt_private' => $jwtPriv,
            'jwt_public' => $jwtPub,
        ];
    }

    /**
     * Rend les blocs Apache/nginx à intégrer dans le vhost HTTPS local.
     * AC1.2 — la commande Artisan détecte le serveur web actif via T0.5
     * et affiche le bloc pertinent. Ici on renvoie les deux pour permettre
     * à l'admin de choisir.
     *
     * @return array<string, string>
     */
    public function renderServerBlocks(): array
    {
        $crt = $this->path('server_crt');
        $key = $this->path('server_key');

        $apacheBlock = <<<APACHE
# Apache vhost HTTPS local (Story 16.10 — à intégrer manuellement)
SSLEngine               on
SSLCertificateFile      {$crt}
SSLCertificateKeyFile   {$key}
SSLProtocol             TLSv1.2 TLSv1.3
SSLHonorCipherOrder     on
APACHE;

        $nginxBlock = <<<NGINX
# nginx server block HTTPS local (Story 16.10 — à intégrer manuellement)
ssl_certificate     {$crt};
ssl_certificate_key {$key};
ssl_protocols       TLSv1.2 TLSv1.3;
ssl_prefer_server_ciphers on;
NGINX;

        return [
            'apache' => $apacheBlock,
            'nginx' => $nginxBlock,
        ];
    }

    /**
     * Logger interne — toujours channel `auth-v1`, jamais de secret.
     *
     * @param array<string, mixed> $context
     */
    private function logEvent(string $actionType, array $context): void
    {
        Log::channel('auth-v1')->info('[CaInitializer] ' . $actionType, array_merge([
            'action_type' => $actionType,
        ], $context));
    }
}

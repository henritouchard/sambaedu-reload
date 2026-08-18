<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

/**
 * Story 61.1 — LA DÉFINITION D'UN MONTAGE, ET SA SIGNATURE CANONIQUE.
 *
 * Deux montages, et deux seulement (l'arbre de classe attendra que la zone
 * correspondante soit exportée en SMB) :
 *  - **« Partages »** — le stanza `[partages]` de `scripts/config/smb-partages.conf`,
 *    racine `/var/sambaedu/Partages`, sans sous-chemin ;
 *  - **« Documents »** — le partage `users` avec le sous-chemin `$user`, soit
 *    exactement la cible du lecteur K: (`\\<se4fs>\users\<user>\`). `$user` est la
 *    substitution NATIVE de `files_external` : c'est Nextcloud qui la résout, pas
 *    SE5 — un montage par utilisateur serait un plan de permissions déguisé.
 *
 * **Le mécanisme d'authentification est le cœur de la story.**
 * `password::sessioncredentials` relaie à Samba les identifiants de l'utilisateur
 * connecté : Nextcloud devient un client SMB parmi d'autres, et c'est l'ACL POSIX
 * du kernel qui tranche. Un compte de service SMB global aurait fait de chaque
 * visiteur web ce compte-là — l'autorité d'accès aurait été DUPLIQUÉE, et
 * l'applicabilité Nextcloud serait devenue le vrai filtre. C'est précisément ce
 * que le garde-fou d'epic « une seule autorité d'écriture par zone » interdit.
 *
 * **Applicable à TOUS, et c'est délibéré.** Restreindre par groupe Nextcloud
 * serait un second plan de permissions sur la même zone, et exigerait des groupes
 * que rien ne provisionne. Samba montre déjà à chacun ce qu'il doit voir
 * (`hide unreadable = Yes`, posé en `[global]`).
 *
 * **La signature canonique** est ce qui rend le rejeu sans doublon possible. Le
 * mode de défaut classique de `files_external` est d'accumuler des entrées
 * identiques dans l'écran d'administration, parce que rien côté Nextcloud ne
 * dédoublonne. La signature ne retient QUE ce qui identifie la cible — type, hôte,
 * partage, sous-chemin, mécanisme d'auth — et surtout PAS le point de montage :
 * renommer « Partages » en « Fichiers partagés » côté SE5 doit MODIFIER le montage
 * existant, pas en créer un second.
 *
 * ---------------------------------------------------------------------------
 * **ON COMPARE LA VALEUR RELUE, JAMAIS CELLE ENVOYÉE** (mesuré sur `nc-spike`,
 * 2026-08-08). Nextcloud NORMALISE ce qu'on lui envoie : `mountPoint`
 * `ZZ_probe_smb` est relu `/ZZ_probe_smb` (slash initial ajouté), et une valeur
 * booléenne envoyée en formulaire est relue coercée. Comparer l'envoyé au relu
 * produirait une divergence PERMANENTE : chaque passage « mettrait à jour » le
 * même montage, l'idempotence de l'AC3 serait fausse — et tous les tests
 * `Http::fake()` resteraient verts, puisqu'ils rejoueraient ce qu'on envoie.
 * D'où {@see self::normalizeMountPoint()}, et des doubles de test qui rejouent
 * les corps RÉELS, slash compris.
 * ---------------------------------------------------------------------------
 */
final class ExternalStorageDefinition
{
    /** Identifiant du backend SMB/CIFS de `files_external`. */
    public const BACKEND_SMB = 'smb';

    /** « Identifiants de connexion, enregistrés en session ». */
    public const AUTH_SESSION_CREDENTIALS = 'password::sessioncredentials';

    /** Partage SMB des répertoires réseau gérés (Epic 34). */
    public const SHARE_PARTAGES = 'partages';

    /** Partage SMB des répertoires personnels (cible du lecteur K:). */
    public const SHARE_USERS = 'users';

    /**
     * Substitution native de `files_external` : Nextcloud remplace ce jeton par le
     * login de l'utilisateur au moment du montage.
     */
    public const USER_PLACEHOLDER = '$user';

    public function __construct(
        /** Point de montage tel qu'il apparaît dans « Fichiers » (nom du dossier racine). */
        public readonly string $mountPoint,
        public readonly string $host,
        public readonly string $share,
        /** Sous-chemin dans le partage. Vide = racine du partage. */
        public readonly string $root = '',
        /**
         * DOMAINE SMB — le domaine court de l'annuaire (`localdev`), et non son nom
         * DNS.
         *
         * Sans lui, les deux montages échouent à l'authentification depuis une
         * instance en conteneur : son client SMB présente alors le `workgroup` par
         * défaut de sa distribution. Le symptôme est coûteux à diagnostiquer — les
         * dossiers APPARAISSENT dans « Fichiers » et refusent de s'ouvrir, le journal
         * ne nomme ni le domaine ni l'authentification, et l'instance MÉMORISE
         * l'indisponibilité : corriger la configuration ne suffit pas tant que son
         * cache n'a pas expiré.
         *
         * Il n'entre PAS dans la signature d'identité ({@see signature()}) : un
         * montage dont seul le domaine change reste LE MÊME montage, à corriger et
         * non à dupliquer. Il entre en revanche dans {@see divergences()}.
         *
         * Vide est un état valide (annuaire sans domaine court configuré) : on
         * l'écrit alors explicitement, plutôt que de laisser l'instance conserver
         * une valeur que SE5 ne déclare plus.
         */
        public readonly string $domain = '',
    ) {}

    /**
     * Les deux montages canoniques de la story, pour un serveur de fichiers donné.
     *
     * **Le montage « Documents » ne dépend PAS de la capacité `home`.** Décision de
     * la story : `home` gouverne ce que l'AGENT monte sur le poste (la lettre K:),
     * pas le chemin d'accès WEB. Les capacités sont indépendantes depuis la
     * décision Henri du 2026-07-17 — les conditionner l'une à l'autre réintroduirait
     * par la porte de service le mode exclusif qui a été explicitement refusé, et
     * priverait d'accès web à ses propres documents une instance qui a justement
     * choisi de ne plus monter de lecteur.
     *
     * @return list<self>
     */
    public static function canonicalSet(string $host, string $domain = ''): array
    {
        return [
            new self('Partages', $host, self::SHARE_PARTAGES, '', $domain),
            new self('Documents', $host, self::SHARE_USERS, self::USER_PLACEHOLDER, $domain),
        ];
    }

    /**
     * Signature d'identité — ce qui fait que deux montages sont LE MÊME montage.
     * Le point de montage en est volontairement absent (voir docblock de classe).
     */
    public function signature(): string
    {
        return implode('|', [
            self::BACKEND_SMB,
            mb_strtolower($this->host),
            mb_strtolower($this->share),
            trim($this->root, '/'),
            self::AUTH_SESSION_CREDENTIALS,
        ]);
    }

    /**
     * Signature d'une entrée telle que l'instance la rend. Rend `null` quand
     * l'entrée n'est pas un montage SMB en identifiants de session : elle n'entre
     * alors dans aucune comparaison — SE5 ne gouverne que ce qu'il a déclaré.
     *
     * @param  array<string, mixed>  $remote
     */
    public static function signatureOf(array $remote): ?string
    {
        $backend = self::backendIdentifier($remote);
        $auth = self::authIdentifier($remote);

        if ($backend !== self::BACKEND_SMB || $auth !== self::AUTH_SESSION_CREDENTIALS) {
            return null;
        }

        $options = is_array($remote['backendOptions'] ?? null) ? $remote['backendOptions'] : [];

        return implode('|', [
            self::BACKEND_SMB,
            mb_strtolower((string) ($options['host'] ?? '')),
            mb_strtolower((string) ($options['share'] ?? '')),
            trim((string) ($options['root'] ?? ''), '/'),
            self::AUTH_SESSION_CREDENTIALS,
        ]);
    }

    /**
     * Ce qui, sur une entrée reconnue, DIVERGE de la définition SE5. Rend la liste
     * des champs divergents — vide = déjà conforme, rien à écrire.
     *
     * Périmètre volontairement étroit : le point de montage et l'applicabilité.
     * Les autres réglages (priorité, options de montage) ne sont pas déclarés par
     * SE5 ; les réécrire reviendrait à gouverner ce qu'on n'a pas décrit, et la
     * doctrine drift STRICT dit le contraire — hors du plan, hors du geste.
     *
     * @param  array<string, mixed>  $remote
     * @return list<string>
     */
    public function divergences(array $remote): array
    {
        $divergences = [];

        if (self::normalizeMountPoint((string) ($remote['mountPoint'] ?? '')) !== $this->mountPoint) {
            $divergences[] = 'mountPoint';
        }

        // Le domaine est dans le périmètre : un montage qui ne le porte pas reste
        // reconnu par sa signature, et se trouve corrigé au passage suivant plutôt
        // que dupliqué.
        $options = is_array($remote['backendOptions'] ?? null) ? $remote['backendOptions'] : [];

        if ((string) ($options['domain'] ?? '') !== $this->domain) {
            $divergences[] = 'domain';
        }

        $users = is_array($remote['applicableUsers'] ?? null) ? $remote['applicableUsers'] : [];
        $groups = is_array($remote['applicableGroups'] ?? null) ? $remote['applicableGroups'] : [];

        if ($users !== [] || $groups !== []) {
            // Une restriction d'applicabilité apparue côté Nextcloud est un second
            // plan de permissions sur la zone : on la retire, parce que SE5 a
            // DÉCLARÉ « applicable à tous » et que c'est la déclaration qui fait foi.
            $divergences[] = 'applicable';
        }

        return $divergences;
    }

    /**
     * Point de montage tel que Nextcloud le RELIT : il y ajoute un slash initial.
     * Normaliser des deux côtés est ce qui empêche la divergence permanente.
     */
    public static function normalizeMountPoint(string $mountPoint): string
    {
        return ltrim(trim($mountPoint), '/');
    }

    /**
     * Charge utile de l'endpoint d'administration des montages globaux.
     *
     * **Émise en `application/x-www-form-urlencoded`** — mesuré le 2026-08-08 :
     * c'est la forme que l'endpoint accepte (`backendOptions[host]=…`).
     *
     * Trois conséquences, toutes délibérées :
     *
     *  1. **Les deux listes d'applicabilité sont VIDES, et l'encodage de
     *     formulaire les fait disparaître du corps.** C'est exactement le
     *     résultat voulu : côté Nextcloud, une applicabilité absente signifie
     *     « tous les utilisateurs ». On les déclare quand même ICI parce que
     *     l'intention doit être lisible et testable dans le code — c'est
     *     l'invariant de la story (aucune restriction côté Nextcloud, Samba
     *     tranche seul), pas un oubli.
     *  2. **`mountOptions` n'est PAS déclaré.** L'instance applique son défaut
     *     (`enable_sharing: false`, mesuré). Le déclarer ferait gouverner à SE5
     *     un réglage qu'il n'a pas décrit — drift STRICT : hors du plan, hors du
     *     geste.
     *  3. **Aucune valeur BOOLÉENNE n'est envoyée.** En formulaire, `false`
     *     s'encode en chaîne et l'instance la relit comme vraie (mesuré sur un
     *     montage de contrôle : `secure=false` envoyé, `"secure":true` relu). Un
     *     booléen envoyé ici serait donc un réglage qu'on croit poser et qui vaut
     *     l'inverse.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'mountPoint' => $this->mountPoint,
            'backend' => self::BACKEND_SMB,
            'authMechanism' => self::AUTH_SESSION_CREDENTIALS,
            'backendOptions' => [
                'host' => $this->host,
                'share' => $this->share,
                'root' => $this->root,
                // Déclaré MÊME VIDE : sinon une instance réglée à la main garderait
                // en silence un domaine que la définition ne porte plus.
                'domain' => $this->domain,
            ],
            'applicableUsers' => [],
            'applicableGroups' => [],
            'priority' => 100,
        ];
    }

    /** Libellé court pour le rapport d'exploitation. */
    public function label(): string
    {
        return $this->mountPoint;
    }

    /**
     * L'instance rend le backend tantôt en chaîne, tantôt en objet `{identifier:…}`
     * selon la version. On lit les deux plutôt que d'en présumer une.
     *
     * @param  array<string, mixed>  $remote
     */
    private static function backendIdentifier(array $remote): string
    {
        return self::identifier($remote['backend'] ?? null);
    }

    /** @param array<string, mixed> $remote */
    private static function authIdentifier(array $remote): string
    {
        return self::identifier($remote['authMechanism'] ?? null);
    }

    private static function identifier(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) && is_string($value['identifier'] ?? null)) {
            return $value['identifier'];
        }

        return '';
    }
}

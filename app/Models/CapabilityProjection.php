<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Epic 27 — Projection d'une {@see Capability} : comment l'intention se
 * matérialise sur un `os` via un `mechanism` (= type du contrat desired-state).
 *
 * La `spec` est interprétée par le provider/compilateur du mécanisme concerné.
 * Le `mechanism` est un identifiant FIGÉ (NFR12) aligné sur
 * `StateContract::RESOURCE_TYPES` : `registry` est déjà publié ; un nouveau
 * mécanisme (firewall, localgroup…) implique un ajout au contrat + handler agent.
 *
 * @property int $id
 * @property int $capability_id
 * @property string $os windows | linux
 * @property string $mechanism registry | firewall | localgroup | …
 * @property array<string,mixed> $spec
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class CapabilityProjection extends Model
{
    use HasFactory;

    protected $table = 'capability_projections';

    /** Mécanisme registre — déjà publié au contrat (gratuit). */
    public const MECHANISM_REGISTRY = 'registry';

    /**
     * Mécanisme liste registre à sous-valeurs indexées `\1..\N` (Story 35.2,
     * contrat §7.6 — type `registry_list`). La `spec` porte des CONTENEURS
     * `{hive, path, entry_type, values}` : l'agent possède les valeurs au nom
     * numérique de la clé-conteneur (réconciliation D3). Une capacité peut
     * porter registry ET registry_list sur le même OS (bi-projection D5 —
     * l'unique `(capability_id, os, mechanism)` le permet).
     */
    public const MECHANISM_REGISTRY_LIST = 'registry_list';

    /**
     * Mécanisme HORS-REGISTRE `fs_acl` (Story 36.1, contrat §7.7 — type
     * `fs_acl`) : ACE NTFS gérées sur le poste, portée **Machine** (service
     * SYSTEM seul). La `spec` porte des ACE `{path, trustee, ace_type, rights,
     * applies_to, ensure?}` — l'agent POSSÈDE ses ACE explicites (store
     * dernier-appliqué), jamais la DACL entière (chirurgie SetNamedSecurityInfo,
     * D4). Le serveur n'émet que des NOMS (`Eleves`, `Domain Users`) ; la
     * résolution SID est côté POSTE (LSA, D5) — provider Postgres pur.
     */
    public const MECHANISM_FS_ACL = 'fs_acl';

    /**
     * Mécanisme HORS-REGISTRE `firewall` (Story 36.2, contrat §7.8 — type
     * `firewall`) : règles pare-feu Windows possédées PAR GROUPE, portée
     * **Machine** (service SYSTEM seul). La `spec` porte des règles
     * `{rule_id, direction, action, remote_scope, protocol, ensure?, ports?,
     * remote_addresses?}` — l'agent POSSÈDE le conteneur de règles
     * `SambaEdu-Agent` EN ENTIER (le champ `Grouping` de la règle EST le
     * marqueur de propriété — PAS de store, contrairement à `fs_acl` D4) : il le
     * réconcilie (iso `registry_list`, désirées présentes+conformes, toute règle
     * du groupe hors état désiré SUPPRIMÉE). Le serveur n'émet que des MOTS
     * MÉTIER (enums fermés) ; la traduction `remote_scope: internet` en plages
     * inverses-RFC1918 vit dans le HANDLER (D6). La politique par défaut, les
     * profils et le service MpsSvc ne sont JAMAIS touchés — provider Postgres pur.
     */
    public const MECHANISM_FIREWALL = 'firewall';

    /**
     * Mécanisme HORS-REGISTRE `privilege` (Story 35.6, contrat §7.9 — type
     * `privilege`) : droits de logon LSA `SeDeny*` gérés sur le poste, portée
     * **Machine** (service SYSTEM seul). La `spec` porte
     * `{privilege, accounts}` — `privilege` ∈ enum FERMÉ des 5 droits SeDeny*
     * ({@see \App\Services\Agent\Providers\PrivilegeAuthoringGuard::ALLOWED_PRIVILEGES},
     * tout droit *grant* est REFUSÉ : une convergence « possède la liste
     * entière » sur un grant VERROUILLERAIT la machine), `accounts` = liste OU
     * map valeur-capacité de NOMS Windows (jetons d'audience `@eleves|@profs|
     * @personnels` résolus à l'expansion). L'agent possède la liste de
     * titulaires du privilège EN ENTIER et la réconcilie — CONTENEUR SANS
     * store (les titulaires sont énumérables via LSA, iso `firewall`, PAS
     * `fs_acl`). Le serveur n'émet que des NOMS ; la résolution SID est côté
     * POSTE (LSA, D5) — provider Postgres pur.
     */
    public const MECHANISM_PRIVILEGE = 'privilege';

    /**
     * Mécanisme `legacy_cleanup` (Story 38.3, contrat §7.10 — type
     * `legacy_cleanup`) : nettoyage des crochets clients legacy SE4 du poste,
     * portée **Machine** (service SYSTEM seul). La `spec` porte `{mozilla}` —
     * enum FERMÉ (`vanilla` seule valeur v1, décision Q5-a : suppression des
     * paires profiles.ini/installs.ini référençant `sambaedu.default`, AUCUN
     * profil forcé posé). Le CATALOGUE d'artefacts (blobs applications-*,
     * tâches WPKG, scripts GPO locale, helpers, autologon se4install) est
     * versionné DANS l'agent (D3 : connaissance legacy figée, pas du
     * paramétrage métier) — le serveur ne fait que GATER. Réconciliation par
     * SCAN sans store (iso `firewall`/`privilege`, PAS `fs_acl`).
     */
    public const MECHANISM_LEGACY_CLEANUP = 'legacy_cleanup';

    /** Mécanisme membership de groupe local — slice C (idem). */
    public const MECHANISM_LOCALGROUP = 'localgroup';

    /**
     * Ruche machine (portée machine / service SYSTEM) — clé de `spec.keys[].hive`
     * du mécanisme `registry`. Foyer canonique de la constante depuis le rewrite
     * capability-first (le modèle `RegistrySetting` est superseded — Story 27.12).
     */
    public const HIVE_MACHINE = 'HKLM';

    /** Ruche utilisateur (portée session / compagnon) — idem. */
    public const HIVE_USER = 'HKCU';

    /**
     * Ruche des profils utilisateurs HKEY_USERS (Story 35.3) — TROISIÈME valeur
     * admise de `spec.keys[].hive` du mécanisme `registry`, PORTÉE MACHINE :
     * émise par le provider Machine uniquement ({@see \App\Services\Agent\Providers\RegistryMachineCapabilityProvider}),
     * appliquée par le service SYSTEM qui FAN-OUT la clé vers `HKU\.DEFAULT`
     * (écran de logon) + chaque ruche utilisateur chargée (`HKU\<SID>`), à
     * chaque cycle. Jamais émise en portée Session ; non admise en
     * `registry_list` (garde-fou d'authoring). Pas de ciblage par utilisateur
     * (structurel : le service fetch son state sans `?user`).
     */
    public const HIVE_USERS = 'HKU';

    protected $fillable = [
        'capability_id',
        'os',
        'mechanism',
        'spec',
    ];

    protected $casts = [
        'spec' => 'array',
    ];

    /**
     * @return BelongsTo<Capability, CapabilityProjection>
     */
    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }
}

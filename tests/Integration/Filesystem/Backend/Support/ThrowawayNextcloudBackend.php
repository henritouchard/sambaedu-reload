<?php

declare(strict_types=1);

namespace Tests\Integration\Filesystem\Backend\Support;

use App\Enums\FileBackendName;
use App\Services\Filesystem\Backend\FileBackend;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Backend\ReconciliationReport;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 60.3 — SQUELETTE JETABLE : un adaptateur distant écrit CONTRE L'INTERFACE
 * RÉELLE, pour vérifier que les signatures sont IMPLÉMENTABLES.
 *
 * **Pourquoi il existe.** Le sondage d'ouverture d'epic a validé des CONCEPTS, en
 * lignes de commande. Il n'a jamais prouvé qu'une classe PHP pouvait honorer ces
 * cinq signatures contre une instance réelle — et le backend d'aperçu ne le prouve
 * pas non plus, puisque n'exécutant rien il satisferait n'importe quel contrat.
 * Ce fichier comble exactement ce trou, et rien d'autre.
 *
 * **Il est JETABLE.** Il n'est ni enregistré au conteneur, ni sélectionnable par
 * une valeur de colonne, ni atteignable depuis l'interface — et il ne PEUT pas
 * l'être : le vocabulaire de noms de backend n'a aucune case pour lui, ce qu'un
 * test épingle. Le vrai adaptateur est l'affaire de l'Epic 61 ; celui-ci ne sera
 * pas repris, il sera relu puis remplacé.
 *
 * **Périmètre : le cas ÉTROIT du sondage, rien de plus.** Créer les nœuds, poser
 * un octroi dérivé d'un rôle, relire, constater que la clôture n'est pas
 * refermable avec ce mécanisme d'octroi, décliner le plafond. Aucune gestion de
 * reprise, aucune pagination, aucun cache, aucune reprojection d'identité
 * automatique — la table des principaux lui est FOURNIE, parce que la reprojection
 * est justement le savoir que chaque backend porte à sa façon.
 *
 * **Sur son nom** : il emprunte un nom du vocabulaire fermé, faute d'en avoir un.
 * C'est un CONSTAT de l'exercice, consigné dans le verdict : la signature `name()`
 * interdit structurellement à une implémentation hors arbre de se nommer — ce qui
 * est la propriété voulue (aucun backend ne s'ajoute par configuration), mais qui
 * mérite d'être dit plutôt que découvert.
 */
final class ThrowawayNextcloudBackend implements FileBackend
{
    /**
     * @param  string  $baseUrl  ex. `http://192.168.122.50:8090`
     * @param  array<string, array{type:string,name:string}>  $principals  clé de tri de sujet => principal distant
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $admin,
        private readonly string $password,
        private readonly array $principals,
    ) {}

    public function name(): FileBackendName
    {
        return FileBackendName::Preview;
    }

    // =========================================================================
    // provision
    // =========================================================================

    public function provision(FilePlan $plan): ReconciliationReport
    {
        $entries = [];

        foreach ($plan->nodes as $node) {
            $entries[] = $this->reconcile($plan, $node);
        }

        return ReconciliationReport::covering($this->name(), $plan, $entries);
    }

    private function reconcile(FilePlan $plan, PlanNode $node): NodeReconciliation
    {
        $remotePath = $this->remotePathOf($plan, $node->path);

        // 1. La structure. Rejouée, la création d'un dossier existant rend un
        //    refus de méthode : c'est une IDEMPOTENCE, pas un échec. On la
        //    normalise ici — au-dessus, il n'y a qu'un état.
        $mkcol = $this->dav('MKCOL', $remotePath);
        $created = $mkcol['status'] === 201;
        if (! $created && $mkcol['status'] !== 405) {
            return NodeReconciliation::echec(
                $node->path,
                'création du dossier refusée par le backend distant.',
            );
        }

        // 2. Les octrois. Un partage identique réémis rend un succès avec le même
        //    identifiant : idempotence, encore.
        foreach ($node->activeGrants() as $grant) {
            $principal = $this->principals[$grant->subject->sortKey()] ?? null;
            if ($principal === null) {
                return NodeReconciliation::echec(
                    $node->path,
                    'aucun principal distant connu pour un sujet du plan : la reprojection d\'identité '
                    . 'est un savoir de backend, et ce squelette ne l\'apprend pas tout seul.',
                );
            }

            $share = $this->share($remotePath, $principal, $grant->access);
            if ($share === null) {
                return NodeReconciliation::echec(
                    $node->path,
                    'octroi refusé par le backend distant (cible inconnue ou chemin inexistant).',
                );
            }
        }

        // 3. La CLÔTURE. C'est ici que ça casse, et le mode de rupture est pire
        //    qu'un échec : l'instruction de retrait est ACCEPTÉE, sans effet, et
        //    la relecture rend ensuite un accès là où on demandait zéro.
        $unclosable = $this->unclosableRoles($plan, $node, $remotePath);
        if ($unclosable !== []) {
            return NodeReconciliation::nonExprimable(
                $node->path,
                sprintf(
                    'octroi hérité non refermable pour le rôle « %s » : ce mécanisme d\'octroi propage '
                    . 'depuis le dossier parent et accepte l\'instruction de retrait sans effet.',
                    implode(', ', $unclosable),
                ),
            );
        }

        return $created
            ? NodeReconciliation::applique($node->path)
            : NodeReconciliation::conforme($node->path);
    }

    /**
     * Les rôles clos ici dont l'accès subsiste malgré l'instruction de retrait.
     *
     * C'est une CONSTATATION, pas une supposition : on émet le retrait, puis on
     * relit. Le rôle n'est déclaré non refermable que si la relecture le voit
     * encore.
     *
     * @return list<string>
     */
    private function unclosableRoles(FilePlan $plan, PlanNode $node, string $remotePath): array
    {
        $unclosable = [];

        foreach ($node->closure as $role) {
            foreach ($plan->roles[$role] ?? [] as $subject) {
                $principal = $this->principals[$subject->sortKey()] ?? null;
                if ($principal === null) {
                    continue;
                }

                // L'instruction de retrait, telle que mesurée : un octroi à zéro
                // permission sur le nœud à refermer.
                $this->share($remotePath, $principal, null);

                foreach ($this->sharesOf($remotePath) as $observed) {
                    if ($observed['name'] === $principal['name'] && $observed['type'] === $principal['type']) {
                        $unclosable[$role] = true;
                    }
                }
            }
        }

        return array_keys($unclosable);
    }

    // =========================================================================
    // deprovision
    // =========================================================================

    public function deprovision(FilePlan $plan): ReconciliationReport
    {
        $entries = [];

        foreach ($plan->nodes as $node) {
            $remotePath = $this->remotePathOf($plan, $node->path);
            $removed = 0;

            foreach ($this->sharesOf($remotePath) as $share) {
                $this->ocs('DELETE', '/ocs/v2.php/apps/files_sharing/api/v1/shares/' . $share['id']);
                $removed++;
            }

            $entries[] = $removed > 0
                ? NodeReconciliation::applique($node->path, 'Octrois révoqués ; le dossier et son contenu restent.')
                : NodeReconciliation::conforme($node->path, 'Aucun octroi à révoquer.');
        }

        return ReconciliationReport::covering($this->name(), $plan, $entries);
    }

    // =========================================================================
    // inspect — BALAYAGE, racine comprise
    // =========================================================================

    /**
     * Un appel PAR NŒUD. C'est le piège mesuré : une lecture unique du sous-arbre
     * rend les sous-chemins mais PAS la racine, donc elle est structurellement
     * incomplète. La signature impose le balayage, et l'implémentation n'a même
     * pas la tentation de l'éviter.
     */
    public function inspect(FilePlan $plan): InspectionReport
    {
        $observations = [];

        foreach ($plan->nodes as $node) {
            $remotePath = $this->remotePathOf($plan, $node->path);

            if ($this->dav('PROPFIND', $remotePath)['status'] === 404) {
                $observations[] = NodeObservation::absent($node->path);

                continue;
            }

            $grants = [];
            foreach ($this->sharesOf($remotePath) as $share) {
                $subject = $this->subjectFor($share);
                if ($subject === null) {
                    continue;
                }
                $grants[] = new ObservedGrant($subject, $share['access']);
            }

            $observations[] = NodeObservation::observed(
                $node->path,
                $grants,
                // Le plafond n'est pas regardé : ce modèle n'a pas de plafond de
                // DOSSIER. On ne prétend pas l'avoir lu.
                null,
                false,
            );
        }

        return InspectionReport::covering($this->name(), $plan, $observations);
    }

    // =========================================================================
    // quota — déclin PERMANENT
    // =========================================================================

    public function quota(FilePlan $plan): ReconciliationReport
    {
        return ReconciliationReport::coveringCapped(
            $this->name(),
            $plan,
            array_map(
                static fn (string $path): NodeReconciliation => NodeReconciliation::nonExprimable(
                    $path,
                    'le quota de ce backend est une propriété de l\'UTILISATEUR, jamais du dossier : '
                    . 'un plafond de zone n\'est pas exprimable dans son modèle.',
                ),
                $plan->cappedNodePaths(),
            ),
        );
    }

    // =========================================================================
    // Administration du décor (hors contrat — utilitaires du test)
    // =========================================================================

    /** Crée un groupe distant. Un groupe existant est une idempotence, pas un échec. */
    public function ensureGroup(string $name): void
    {
        $this->ocs('POST', '/ocs/v2.php/cloud/groups', ['groupid' => $name]);
    }

    /** Crée un utilisateur distant et le place dans un groupe. */
    public function ensureUser(string $login, string $password, ?string $group = null): void
    {
        $form = ['userid' => $login, 'password' => $password];
        if ($group !== null) {
            $form['groups[]'] = $group;
        }
        $this->ocs('POST', '/ocs/v2.php/cloud/users', $form);
    }

    /** Le quota d'un utilisateur — pour CONSTATER qu'il est par personne. */
    public function userQuota(string $login): mixed
    {
        $response = $this->ocs('GET', '/ocs/v2.php/cloud/users/' . rawurlencode($login));

        return $response['body']['ocs']['data']['quota']['quota'] ?? null;
    }

    public function removeFolder(string $path): void
    {
        $this->dav('DELETE', $path);
    }

    // =========================================================================
    // Transport
    // =========================================================================

    private function remotePathOf(FilePlan $plan, string $nodePath): string
    {
        return $nodePath === PlanNode::ROOT_PATH
            ? $plan->rootPath
            : $plan->rootPath . '/' . $nodePath;
    }

    /**
     * Pose (ou réémet) un octroi. `$access === null` = l'instruction de RETRAIT.
     *
     * @param  array{type:string,name:string}  $principal
     * @return array<string,mixed>|null `null` si le backend a refusé net
     */
    private function share(string $path, array $principal, ?string $access): ?array
    {
        $permissions = match ($access) {
            PlanGrant::ACCESS_RW => 31,
            PlanGrant::ACCESS_RO => 1,
            default => 0,
        };

        $response = $this->ocs('POST', '/ocs/v2.php/apps/files_sharing/api/v1/shares', [
            'path' => '/' . ltrim($path, '/'),
            'shareType' => $principal['type'] === 'group' ? 1 : 0,
            'shareWith' => $principal['name'],
            'permissions' => $permissions,
        ]);

        // Rejeu d'un octroi identique : MESURÉ pendant cette story — succès avec
        // le MÊME identifiant, exactement comme au sondage. Aucune branche
        // spéciale n'est donc nécessaire ici, et il n'y en a pas : une branche
        // qui n'a jamais été atteinte est une garantie qu'on croit tenir.
        $data = $response['body']['ocs']['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /**
     * Les octrois RELUS d'un chemin, en vocabulaire de transport.
     *
     * @return list<array{id:string,name:string,type:string,access:string}>
     */
    private function sharesOf(string $path): array
    {
        $response = $this->ocs('GET', '/ocs/v2.php/apps/files_sharing/api/v1/shares?path='
            . rawurlencode('/' . ltrim($path, '/')));

        $rows = $response['body']['ocs']['data'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = ((int) ($row['share_type'] ?? -1)) === 1 ? 'group' : 'user';
            $permissions = (int) ($row['permissions'] ?? 0);
            if ($permissions === 0) {
                continue;
            }
            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['share_with'] ?? ''),
                'type' => $type,
                'access' => ($permissions & 2) === 2 ? PlanGrant::ACCESS_RW : PlanGrant::ACCESS_RO,
            ];
        }

        return $out;
    }

    /**
     * Reprojection INVERSE : du principal distant vers l'identité interne. C'est
     * le savoir de backend dont parle le contrat, réduit ici à une table fournie.
     *
     * @param  array{name:string,type:string}  $share
     */
    private function subjectFor(array $share): ?PlanSubject
    {
        foreach ($this->principals as $sortKey => $principal) {
            if ($principal['name'] === $share['name'] && $principal['type'] === $share['type']) {
                [$type, $id] = explode("\0", $sortKey);

                return $type === PlanSubject::TYPE_USER
                    ? PlanSubject::user((int) $id)
                    : PlanSubject::group((int) $id);
            }
        }

        return null;
    }

    /** @return array{status:int,body:mixed} */
    private function ocs(string $method, string $path, array $form = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->admin . ':' . $this->password,
            CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($form !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => json_decode((string) $raw, true)];
    }

    /** @return array{status:int,body:string} */
    private function dav(string $method, string $path): array
    {
        $url = $this->baseUrl . '/remote.php/dav/files/' . rawurlencode($this->admin) . '/'
            . implode('/', array_map(rawurlencode(...), explode('/', trim($path, '/'))));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->admin . ':' . $this->password,
            CURLOPT_HTTPHEADER => ['Depth: 0'],
            CURLOPT_TIMEOUT => 20,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $raw];
    }

    /** Story 60.5 — emplacement d'affichage : ce double n'écrit sur aucun disque. */
    public function location(FilePlan $plan): ?string
    {
        return null;
    }
}

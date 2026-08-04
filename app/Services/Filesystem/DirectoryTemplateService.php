<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Models\DirectoryTemplate;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Story 34.3 — matérialisation d'un « template de répertoire » en
 * {@see NetworkShare} + ses assignations par maille.
 *
 * **Couche de PRÉFABRICATION par-dessus 34.1/34.2 — socle figé INTOUCHÉ.** Un
 * template = une RECETTE (lue depuis la table `directory_templates`, Q3 option B)
 * qui produit des `NetworkShare` standards : ce service ne réinvente NI le
 * provisioning ({@see NetworkShareService::provision()}), NI la projection agent,
 * NI la validation prédictive ({@see NetworkShareValidator}). Il ASSIGNE le bon
 * `UserGroup`/`User` avec le bon `access` ; le socle mappe au groupe Unix à
 * `provision()` (piège #3 — on NE redérive AUCUN nom de groupe Unix ici).
 *
 * **Invariant WG-montage-seul.** Une recette ne porte JAMAIS de maille
 * `WorkstationGroup` (POSIX ne sait pas exprimer « les users de la machine X »).
 * Le service refuse toute maille hors `User`/`UserGroup` (defense-in-depth).
 *
 * **Patron transaction + collision (review 34.2 #1).** La collision de lettre
 * n'est calculable qu'une fois l'audience peuplée : on crée le share + ses
 * assignations DANS une `DB::transaction`, on invoque
 * {@see NetworkShareValidator::assertNoLetterCollision()} AVANT commit, et toute
 * collision ROLLBACK l'ensemble (aucune écriture partielle). Le format
 * `directory_name` et la lettre réservée sont validés AVANT toute écriture.
 */
class DirectoryTemplateService
{
    public function __construct(
        private readonly NetworkShareService $shareService,
        private readonly NetworkShareValidator $validator,
    ) {
    }

    /**
     * Matérialise un template en un répertoire réseau + ses assignations.
     *
     * **Story 60.4 — deux régimes de provisionnement, choisis par l'APPELANT.**
     * Appelée depuis un écran, la matérialisation ENFILE la pose des droits
     * (`$deferProvisioning = true`) : le cycle d'une requête n'est pas le bon
     * endroit pour un geste dont le coût est quadratique. Appelée hors requête,
     * elle l'exécute en direct. Le résultat DIT lequel des deux a eu lieu — il
     * n'affirme jamais un provisionnement accompli qui ne l'est pas.
     *
     * @param  array{name?:string,directory_name?:string,label?:string|null,letter?:string|null,roles?:array<string,list<int>>}  $params
     *
     * @throws InvalidArgumentException                            format / lettre réservée / rôles invalides (AVANT écriture)
     * @throws \App\Exceptions\Filesystem\NetworkShareLetterCollisionException  collision de lettre (rollback transactionnel)
     */
    public function materialize(
        DirectoryTemplate $template,
        array $params,
        ?string $performedBy = null,
        bool $deferProvisioning = false,
    ): TemplateMaterializationResult {
        $name = trim((string) ($params['name'] ?? ''));
        $directoryName = trim((string) ($params['directory_name'] ?? ''));
        $label = isset($params['label']) ? trim((string) $params['label']) : '';
        $letter = $this->normalizedLetter($params['letter'] ?? null);

        // --- Validation AVANT toute écriture (AC2) --------------------------
        if ($name === '') {
            throw new InvalidArgumentException('Le nom du répertoire est requis.');
        }
        if (! $this->shareService->isValidDirectoryName($directoryName)) {
            throw new InvalidArgumentException(
                'Le nom de répertoire ne peut contenir que des lettres, chiffres, « . », « _ » et « - » '
                .'(sans espace), et ne peut pas commencer par « . ».'
            );
        }
        // Unicité du `directory_name` (one-shot, Q4) : pré-check explicite pour que
        // tout appelant — UI *et* appel direct (future commande/API) — obtienne
        // l'InvalidArgumentException documentée plutôt qu'une QueryException brute
        // sur la contrainte `unique` DB (le rollback transactionnel resterait sûr,
        // mais le contrat du PHPDoc serait fuyant).
        if (NetworkShare::where('directory_name', $directoryName)->exists()) {
            throw new InvalidArgumentException(
                'Ce nom de répertoire est déjà utilisé. Éditez le répertoire existant depuis sa page.'
            );
        }
        if ($this->validator->isReservedLetter($letter)) {
            throw new InvalidArgumentException(
                'Cette lettre est réservée par le système (A-D, H, I, K, L). '
                .'Choisissez une autre lettre ou laissez le champ vide (attribution automatique).'
            );
        }

        // --- Plan d'assignations dérivé de la recette (Q6 : rôles du pattern) -
        $plan = $this->buildAssignmentPlan($template, $params['roles'] ?? []);

        // --- Transaction : share + pivot + assertNoLetterCollision AVANT commit
        $share = DB::transaction(function () use ($name, $directoryName, $label, $letter, $plan): NetworkShare {
            $share = NetworkShare::create([
                'name' => $name,
                'directory_name' => $directoryName,
                'label' => $label !== '' ? $label : null,
                'letter' => $letter,
                'created_by_user_id' => $this->currentUserId(),
            ]);

            foreach ($plan as $row) {
                NetworkShareAssignable::create([
                    'network_share_id' => $share->id,
                    'assignable_type' => $row['type'],
                    'assignable_id' => $row['id'],
                    'access' => $row['access'],
                ]);
            }

            // L'audience est désormais peuplée : la collision est calculable.
            // Une collision lève l'exception → rollback de TOUT (share + pivot).
            $this->validator->assertNoLetterCollision($share->fresh());

            return $share;
        });

        // --- Provisionnement APRÈS commit, direct ou enfilé (60.4) -----------
        if ($deferProvisioning) {
            $queued = $this->shareService->queueReconciliation($share, $performedBy);
            $state = $queued
                ? TemplateMaterializationResult::PROVISIONING_QUEUED
                : TemplateMaterializationResult::PROVISIONING_FAILED;
        } else {
            $state = $this->shareService->provision($share, $performedBy)
                ? TemplateMaterializationResult::PROVISIONING_APPLIED
                : TemplateMaterializationResult::PROVISIONING_FAILED;
        }

        return new TemplateMaterializationResult(
            share: $share,
            warnings: $this->validator->warnings($share),
            provisioning: $state,
        );
    }

    /**
     * Construit le plan d'assignations {type, id, access} à partir de la recette
     * et des cibles sélectionnées. Valide cardinalité, maille autorisée,
     * existence/typage des cibles, et l'absence de doublon de cible.
     *
     * @param  array<string,list<int>>  $rolesParams
     * @return list<array{type:class-string,id:int,access:string}>
     *
     * @throws InvalidArgumentException
     */
    private function buildAssignmentPlan(DirectoryTemplate $template, array $rolesParams): array
    {
        if (! $template->respectsMountOnlyInvariant()) {
            // Garde-fou : une recette corrompue (maille WG) ne doit jamais octroyer
            // d'ACL — on échoue plutôt que de matérialiser un montage-seul masqué.
            throw new InvalidArgumentException(
                'Recette invalide : une assignation porte une maille non autorisée (parc).'
            );
        }

        $plan = [];
        $seen = [];

        foreach ($template->roles() as $role) {
            $roleKey = (string) ($role['key'] ?? '');
            $maille = (string) ($role['maille'] ?? '');
            $access = ($role['access'] ?? 'ro') === 'rw' ? 'rw' : 'ro';
            $cardinality = ($role['cardinality'] ?? 'one') === 'many' ? 'many' : 'one';
            $groupType = $role['group_type'] ?? null;
            $roleLabel = (string) ($role['label'] ?? $roleKey);

            if (! in_array($maille, DirectoryTemplate::ALLOWED_ROLE_MAILLES, true)) {
                throw new InvalidArgumentException("Maille non autorisée pour le rôle « {$roleLabel} ».");
            }

            $ids = array_values(array_unique(array_map(
                static fn ($v): int => (int) $v,
                $rolesParams[$roleKey] ?? [],
            )));
            $ids = array_filter($ids, static fn (int $id): bool => $id > 0);

            if ($cardinality === 'one' && count($ids) !== 1) {
                throw new InvalidArgumentException("Le rôle « {$roleLabel} » attend exactement une cible.");
            }
            if ($cardinality === 'many' && count($ids) < 1) {
                throw new InvalidArgumentException("Le rôle « {$roleLabel} » attend au moins une cible.");
            }

            foreach ($ids as $id) {
                $this->assertTargetValid($maille, $id, $groupType, $roleLabel);

                $dedupKey = $maille.'#'.$id;
                if (isset($seen[$dedupKey])) {
                    throw new InvalidArgumentException(
                        'Une même cible ne peut pas être sélectionnée deux fois dans le même répertoire.'
                    );
                }
                $seen[$dedupKey] = true;

                $plan[] = ['type' => $maille, 'id' => $id, 'access' => $access];
            }
        }

        if ($plan === []) {
            throw new InvalidArgumentException('Aucune cible à assigner : sélectionnez les destinataires du template.');
        }

        return $plan;
    }

    /**
     * Vérifie qu'une cible existe (Postgres, zéro AD) et — pour un `UserGroup`
     * typé par la recette — qu'elle est bien du `group_type` attendu.
     *
     * @throws InvalidArgumentException
     */
    private function assertTargetValid(string $maille, int $id, ?string $groupType, string $roleLabel): void
    {
        if ($maille === User::class) {
            if (! User::whereKey($id)->exists()) {
                throw new InvalidArgumentException("Utilisateur introuvable pour le rôle « {$roleLabel} ».");
            }

            return;
        }

        // UserGroup
        $group = UserGroup::find($id);
        if ($group === null) {
            throw new InvalidArgumentException("Groupe introuvable pour le rôle « {$roleLabel} ».");
        }
        if ($groupType !== null && $group->type !== $groupType) {
            throw new InvalidArgumentException(
                "Le rôle « {$roleLabel} » attend un groupe de type « {$groupType} »."
            );
        }
    }

    /**
     * Normalise une lettre saisie (`'P:'`, `'p'`, ` P `) en `'P:'`, ou `null` si
     * vide (= attribution automatique par le provider). Iso UI 34.2.
     */
    private function normalizedLetter(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return strtoupper($trimmed[0]).':';
    }

    private function currentUserId(): ?int
    {
        $user = auth()->user();

        return $user instanceof User ? $user->id : null;
    }
}

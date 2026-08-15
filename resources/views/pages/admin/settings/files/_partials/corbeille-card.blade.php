<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\Filesystem\FileLocationException;
use App\Models\SystemSetting;
use App\Services\Filesystem\FileLocationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Story 63.4 — LA CARTE « CORBEILLE DES RÉPERTOIRES PERSONNELS », dans le bloc
 * « Réglages » de l'onglet des emplacements.
 *
 * ---------------------------------------------------------------------------
 * **CE QU'ELLE EST VRAIMENT, ET POURQUOI LE LIBELLÉ COMPTE.** Ce n'est **pas**
 * une corbeille d'utilisateur. Ce que la corbeille contient, c'est le
 * RÉPERTOIRE PERSONNEL D'UN COMPTE DÉSACTIVÉ : il y est déplacé au moment de la
 * désactivation, il en ressort à la réactivation, et la purge le supprime
 * définitivement passé le délai. Laisser un administrateur croire qu'il règle la
 * rétention des fichiers supprimés par ses élèves serait exactement le défaut
 * que la carte des quotas ferme par ailleurs : un champ qui ment.
 *
 * **CETTE CARTE N'INVENTE RIEN.** Le réglage, le service, la commande de purge
 * et sa planification quotidienne existaient déjà ; seule l'interface avait
 * disparu avec l'onglet qui la portait. C'est un REBRANCHEMENT — le mécanisme
 * est à zéro diff.
 *
 * **ELLE NE PRÉTEND RIEN GOUVERNER DU CLOUD.** Si l'espace personnel ne vit plus
 * sur le serveur de fichiers, la carte le DIT et n'offre rien de plus : la
 * corbeille d'une instance cloud se règle dans l'instance, et préparer ici un
 * réglage qui n'existe pas serait une promesse sans répondant.
 * ---------------------------------------------------------------------------
 *
 * Composant enfant (nested) — double garde `server.admin` au montage ET à chaque
 * écriture, et **racine stable**.
 */
new class extends Component {
    use WithToasts;

    /** Clé de réglage — inchangée : la commande de purge et le cron la lisent. */
    public const SETTING_KEY = 'quota.trash';

    /** Littéral figé — CE QUE LA PURGE SUPPRIME, dit sans détour. */
    public const WHAT_IS_PURGED = 'Les répertoires personnels archivés lors de la désactivation d\'un '
        .'compte. Passé ce délai, ils sont supprimés définitivement et la réactivation du compte ne les '
        .'retrouve plus.';

    /** Littéral figé — la corbeille du cloud n'est pas la nôtre. */
    public const CLOUD_TRASH_ELSEWHERE = 'La corbeille de l\'instance cloud est réglée dans l\'instance '
        .'elle-même.';

    /** Durée de conservation, en jours (1-365). */
    public int $ttlDays = 30;

    /** La purge automatique de 02h00 — le planificateur relit ce booléen à chaque tour. */
    public bool $purgeAuto = false;

    /**
     * L'espace personnel vit-il encore sur le serveur de fichiers ?
     *
     * `null` = on ne sait pas (la ligne d'emplacements ne se relit pas) : la carte
     * n'ajoute alors AUCUNE phrase, plutôt que d'en inventer une.
     */
    public ?bool $espacePersoSurSmb = null;

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $stored = SystemSetting::get(self::SETTING_KEY, null);

        if (is_array($stored)) {
            $this->ttlDays = (int) ($stored['ttl_days'] ?? 30);
            $this->purgeAuto = (bool) ($stored['purge_auto'] ?? false);
        }

        try {
            $this->espacePersoSurSmb = FileLocationService::current()->espacePersoSurSmb();
        } catch (FileLocationException) {
            // La décision d'emplacement est illisible — c'est le sujet d'un autre
            // bloc de cet écran, qui le dit déjà. Ici, on se tait.
            $this->espacePersoSurSmb = null;
        }
    }

    /** La corbeille du cloud est ailleurs — phrase ajoutée, et rien d'autre. */
    public function cloudNotice(): ?string
    {
        return $this->espacePersoSurSmb === false ? self::CLOUD_TRASH_ELSEWHERE : null;
    }

    public function save(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        try {
            $this->validate([
                'ttlDays' => ['required', 'integer', 'min:1', 'max:365'],
                'purgeAuto' => ['required', 'boolean'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toastError('Durée de conservation invalide (entre 1 et 365 jours).');

            throw $e;
        }

        try {
            SystemSetting::set(self::SETTING_KEY, [
                'ttl_days' => (int) $this->ttlDays,
                'purge_auto' => (bool) $this->purgeAuto,
            ]);

            $this->toastSuccess('Réglage de la corbeille enregistré.');
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec enregistrement du réglage de corbeille', [
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Impossible d\'enregistrer le réglage de la corbeille. Consultez les logs.');
        }
    }

    /**
     * « Purger maintenant » — le même traitement que le cron, joué à la demande.
     *
     * Le pré-contrôle de la durée n'est pas de la coquetterie : sans lui, la
     * commande rend « 0 dossier supprimé » avec un code de succès, et l'écran
     * afficherait un message VERT alors qu'aucune purge n'a eu lieu.
     */
    public function purgeNow(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $stored = SystemSetting::get(self::SETTING_KEY, null);
        $ttl = is_array($stored) ? (int) ($stored['ttl_days'] ?? 0) : 0;

        if ($ttl <= 0) {
            $this->toastError(
                'Corbeille non purgée — la durée de conservation n\'est pas enregistrée. '
                .'Saisissez-la et enregistrez-la d\'abord.',
            );

            return;
        }

        try {
            $performedBy = auth()->user()?->login ?? 'admin';

            $exitCode = Artisan::call('trash:purge', ['--performed-by' => 'ui:'.$performedBy]);
            $output = Artisan::output();

            $count = preg_match('/Purgé\s*:\s*(\d+)/u', $output, $m) ? (int) $m[1] : 0;
            $errors = preg_match('/Erreurs\s*:\s*(\d+)/u', $output, $me) ? (int) $me[1] : 0;

            if ($exitCode !== 0) {
                $this->toastError('Échec de la purge — consultez les logs.');

                return;
            }

            if ($errors > 0) {
                $this->toastInfo(sprintf(
                    'Corbeille purgée — %d répertoire(s) supprimé(s), %d erreur(s). Consultez les logs.',
                    $count,
                    $errors,
                ));

                return;
            }

            $this->toastSuccess(sprintf('Corbeille purgée — %d répertoire(s) supprimé(s).', $count));
        } catch (\Throwable $e) {
            Log::error('QuotaService: purge manuelle de la corbeille échouée', [
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Échec de la purge — consultez les logs.');
        }
    }
};
?>

{{-- RACINE STABLE : un seul élément au premier niveau, sans condition. --}}
<div class="card bg-base-100 border border-base-300 w-full lg:max-w-xl" data-testid="corbeille-card">
    <div class="card-body p-5 gap-4">
        <div>
            <h4 class="font-medium">
                <i class="fa-solid fa-trash mr-1"></i>
                Corbeille des répertoires personnels
            </h4>
            <p class="text-xs text-base-content/60 mt-1" data-testid="corbeille-what-is-purged">
                Les répertoires personnels archivés lors de la désactivation d'un compte. Passé ce délai,
                ils sont supprimés définitivement et la réactivation du compte ne les retrouve plus.
            </p>
        </div>

        @if ($this->cloudNotice() !== null)
            <p class="text-xs text-base-content/60" data-testid="corbeille-cloud-notice">
                <i class="fa-solid fa-circle-info"></i>
                La corbeille de l'instance cloud est réglée dans l'instance elle-même.
            </p>
        @endif

        <div class="flex flex-col w-full">
            <label class="label w-full" for="corbeille-ttl">
                <span class="label-text text-xs font-medium">
                    Durée de conservation (jours) <span class="text-error">*</span>
                </span>
            </label>
            <input id="corbeille-ttl" type="number" min="1" max="365"
                class="input input-bordered input-sm w-full"
                wire:model="ttlDays" data-testid="corbeille-ttl" />
            @error('ttlDays')
                <span class="text-xs text-error mt-1">{{ $message }}</span>
            @enderror
        </div>

        <label class="label cursor-pointer justify-start gap-3 w-full">
            <input type="checkbox" class="toggle toggle-primary toggle-sm"
                wire:model="purgeAuto" data-testid="corbeille-purge-auto" />
            <span class="label-text text-xs">Purge automatique quotidienne (02 h 00)</span>
        </label>

        <div class="flex flex-wrap items-center gap-3">
            <button type="button" class="btn btn-primary btn-sm"
                wire:click="save" wire:loading.attr="disabled" wire:target="save"
                data-testid="corbeille-save">
                <i class="fa-solid fa-floppy-disk"></i>
                Enregistrer
            </button>

            <button type="button" class="btn btn-outline btn-warning btn-sm"
                wire:click="purgeNow"
                wire:confirm="Purger maintenant la corbeille ? Les répertoires personnels archivés depuis plus longtemps que la durée de conservation seront supprimés définitivement."
                wire:loading.attr="disabled" wire:target="purgeNow"
                data-testid="corbeille-purge-now">
                <i class="fa-solid fa-broom"></i>
                Purger maintenant
            </button>
        </div>
    </div>
</div>

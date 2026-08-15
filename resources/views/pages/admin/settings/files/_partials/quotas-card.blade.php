<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\Filesystem\QuotaPartitionUnavailableException;
use App\Models\QuotaRule;
use App\Models\QuotaSetting;
use App\Models\SystemSetting;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Story 63.4 — LA CARTE « QUOTAS DES ESPACES PERSONNELS », dans le bloc
 * « Réglages » de l'onglet des emplacements.
 *
 * ---------------------------------------------------------------------------
 * **CE QU'ELLE RÉPARE.** L'écran d'avant portait une grille de « quotas par
 * défaut par profil » — quatre publics × deux partitions — qui écrivait une clé
 * de réglage que **la résolution ne lisait pas**. Elle répondait « Réglages
 * enregistrés » et n'appliquait rien à personne ; pendant ce temps, la
 * résolution cherchait des lignes qu'aucun écran n'écrivait, si bien que sur une
 * instance qui n'avait pas joué l'import legacy, tout le monde était illimité en
 * silence.
 *
 * Ici, **un seul plafond par partition**, écrit LÀ OÙ LA RÉSOLUTION LIT
 * ({@see XfsQuotaService::setQuotaRule()} avec {@see QuotaRule::TYPE_DEFAULT}) —
 * donc par le même chemin d'audit et d'application que les règles par
 * utilisateur et par groupe. Il n'y a plus de public : le défaut est d'INSTANCE,
 * et un budget plus large se pose en règle de groupe, où il se voit.
 *
 * **LA PÉRIODE DE GRÂCE VIT ICI**, à côté du plafond souple : elle ne qualifie
 * que lui (le délai laissé avant que l'écriture ne soit bloquée), et l'exiler
 * dans une carte séparée obligerait à comprendre deux écrans pour régler une
 * seule chose.
 *
 * **UN PLAFOND NON POSABLE EST UN CHAMP FERMÉ AVEC SON MOTIF.** La disponibilité
 * est lue UNE FOIS par rendu — deux partitions, deux appels système au plus,
 * jamais dans une boucle de gabarit — et distingue trois issues : appliqué,
 * éteint, **non mesurable** (le motif dit alors « je ne sais pas », jamais « il
 * n'y a pas de quota » : un code de retour non nul ne prouve pas l'absence). Un
 * champ ouvert qui accepte une valeur sans effet est le même défaut que la grille
 * qu'on retire.
 *
 * ⚠️ **Un espace qui ne vit plus sur le serveur de fichiers échappe à cette
 * fermeture** : le plafond y gouverne le compte sur l'instance cloud, et laisser
 * un système de fichiers local hors sujet fermer ce champ fermerait le seul écran
 * où se règle le plafond du cloud.
 *
 * **LE COÛT DE L'APPLICATION est lu au même moment**, et seulement quand un
 * plafond existe et que la partition est exploitable : c'est UNE lecture système
 * de plus par partition (le relevé d'occupation de tout le monde d'un coup, jamais
 * un appel par compte). Sans elle, le bouton d'application serait un clic à
 * l'aveugle.
 *
 * **LA GARDE EST REJOUÉE CÔTÉ SERVICE.** Une garde qui ne vit que dans l'écran
 * protège l'étourderie, pas la requête forgée : {@see XfsQuotaService} refuse
 * en nommant, et n'écrit alors ni règle ni ligne d'audit.
 *
 * **ENREGISTRER N'EST PAS APPLIQUER, ET LA CARTE LE DIT** (correction de revue).
 * Écrire la règle ne touche AUCUN plafond système : les plafonds ne bougent
 * qu'au geste d'écriture suivant sur chaque compte. Porter le plafond à toute la
 * population est donc un SECOND geste, explicite, qui annonce avant d'agir
 * combien de comptes il concerne et combien basculeraient en dépassement
 * immédiat. Aucune application en masse sans clic — et aucun clic à l'aveugle.
 *
 * **LE REGROUPEMENT DES ANCIENS PLAFONDS EST ANNONCÉ TANT QU'IL N'A PAS ÉTÉ
 * CONFIRMÉ.** La migration de bascule ne rétrécit jamais un plafond : elle retient
 * la valeur LA PLUS LARGE. Élargir ne bloque personne et ne consomme aucun disque
 * — un plafond plafonne, il n'alloue pas — mais ce n'est pas anodin pour autant :
 * tant que l'administrateur n'a pas enregistré une valeur lui-même, la carte
 * affiche ce qui a été regroupé et avec quelles valeurs. L'avertissement disparaît
 * au premier enregistrement.
 * ---------------------------------------------------------------------------
 *
 * Composant enfant (nested) — double garde `server.admin` au montage ET à chaque
 * écriture, et **racine stable** : aucune condition au premier niveau du
 * gabarit, sous peine d'erreur au re-rendu du parent.
 */
new class extends Component {
    use WithToasts;

    /** Plancher du plafond souple sur l'espace personnel — `0` reste « illimité ». */
    public const HOME_FLOOR_MB = 10;

    /** @var array{home:int, sambaedu:int} Plafond souple (Mo). `0` = illimité. */
    public array $soft = ['home' => 0, 'sambaedu' => 0];

    /** @var array{home:int, sambaedu:int} Dépassement toléré (%), borné 0-100. */
    public array $overage = ['home' => 20, 'sambaedu' => 20];

    /** @var array{home:int, sambaedu:int} Période de grâce (jours), bornée 0-30. */
    public array $grace = ['home' => 7, 'sambaedu' => 7];

    /**
     * La clé de réglage où la migration de bascule DÉPOSE ce qu'elle a regroupé.
     * Elle est lue ici, et effacée au premier enregistrement manuel.
     *
     * ⚠️ Ce n'est PAS la clé de l'ancienne grille : celle-là est morte avec elle, et
     * la ressusciter sous une autre casquette serait exactement le défaut que cette
     * story ferme. Celle-ci ne porte aucun plafond — seulement de quoi expliquer une
     * fois ce qui a été regroupé.
     */
    public const COLLAPSE_NOTICE_KEY = 'quota.profils_regroupes';

    /**
     * La disponibilité du quota de chaque partition, LUE AU MONTAGE et pas une
     * fois de plus : c'est un appel système par partition.
     *
     * @var array<string, array{available: bool, reason: string|null}>
     */
    public array $availability = [];

    /**
     * L'espace de cette partition est-il encore servi par le serveur de fichiers ?
     * S'il ne l'est plus, le plafond ne gouverne QUE le cloud, et un système de
     * fichiers local hors sujet n'a pas à fermer le champ.
     *
     * @var array<string, bool>
     */
    public array $servedOverSmb = [];

    /**
     * Ce que l'application à tous les comptes couverts coûterait, PAR PARTITION —
     * annoncé avant le clic, jamais après.
     *
     * @var array<string, array{couverts: int, depassements: int, mesure: bool}>
     */
    public array $coverage = [];

    /**
     * Ce que la migration de bascule a regroupé, ou `null` si l'administrateur a
     * déjà enregistré une valeur lui-même.
     *
     * @var array<string, mixed>|null
     */
    public ?array $collapseNotice = null;

    private XfsQuotaService $quotaService;

    public function boot(XfsQuotaService $quotaService): void
    {
        $this->quotaService = $quotaService;
    }

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $this->collapseNotice = $this->readCollapseNotice();

        foreach ($this->partitions() as $key => $partition) {
            $this->refreshPartition($key, $partition);
        }
    }

    /**
     * Tout ce qu'une partition doit relire : sa règle, sa grâce, sa disponibilité
     * et son coût d'application. Appelée au montage et après chaque écriture —
     * jamais dans une boucle de gabarit.
     */
    private function refreshPartition(string $key, string $partition): void
    {
        $rule = QuotaRule::query()
            ->where('type', QuotaRule::TYPE_DEFAULT)
            ->whereNull('target')
            ->where('partition', $partition)
            ->first();

        $this->soft[$key] = (int) ($rule->quota_soft_mb ?? 0);
        $this->overage[$key] = $rule === null ? 20 : $rule->getOveragePercent();
        $this->grace[$key] = (int) QuotaSetting::forPartition($partition)->grace_period_days;

        $this->servedOverSmb[$key] = $this->quotaService->partitionIsServedOverSmb($partition);

        // Un espace qui ne vit plus sur le serveur de fichiers n'a pas de système
        // de fichiers local à interroger : le plafond y gouverne le cloud, et la
        // sonde de partition n'a rien à en dire.
        $this->availability[$key] = $this->servedOverSmb[$key]
            ? $this->quotaService->partitionQuotaAvailability($partition)
            : ['available' => true, 'reason' => null];

        $this->coverage[$key] = $rule !== null && $this->servedOverSmb[$key] && $this->isAvailable($key)
            ? $this->quotaService->instanceDefaultCoverage($partition)
            : ['couverts' => 0, 'depassements' => 0, 'mesure' => false];
    }

    /** @return array<string, mixed>|null */
    private function readCollapseNotice(): ?array
    {
        try {
            $stored = SystemSetting::get(self::COLLAPSE_NOTICE_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_array($stored) && $stored !== [] ? $stored : null;
    }

    /**
     * Le regroupement est CONFIRMÉ dès que l'administrateur enregistre lui-même une
     * valeur : à partir de là, le plafond en vigueur est le sien, et rappeler d'où
     * il vient n'apprend plus rien.
     */
    private function acknowledgeCollapseNotice(): void
    {
        if ($this->collapseNotice === null) {
            return;
        }

        try {
            SystemSetting::query()->where('key', self::COLLAPSE_NOTICE_KEY)->delete();
        } catch (\Throwable $e) {
            Log::warning('QuotaService: avertissement de regroupement non effacé', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->collapseNotice = null;
    }

    /**
     * Les valeurs regroupées, en littéraux — jamais « des réglages historiques ».
     *
     * @return list<string>
     */
    public function collapsedValues(): array
    {
        $values = $this->collapseNotice['fondus'] ?? [];

        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }

    /**
     * Les deux partitions, `clé d'écran => partition`. La clé d'écran est courte
     * parce qu'elle voyage dans les noms de propriété du gabarit.
     *
     * @return array<string, string>
     */
    public function partitions(): array
    {
        return [
            'home' => QuotaRule::PARTITION_HOME,
            'sambaedu' => QuotaRule::PARTITION_SAMBAEDU,
        ];
    }

    /** Le libellé humain d'une partition — jamais un public, jamais un profil. */
    public function partitionLabel(string $key): string
    {
        return $key === 'home' ? 'Espace personnel' : 'Partages communs';
    }

    public function isAvailable(string $key): bool
    {
        return ($this->availability[$key]['available'] ?? false) === true;
    }

    public function unavailableReason(string $key): ?string
    {
        return $this->availability[$key]['reason'] ?? null;
    }

    /** Le plafond dur, DÉRIVÉ — il ne se saisit pas, il se lit. */
    public function calculateHard(int $softMb, int $overagePercent): int
    {
        if ($softMb === 0) {
            return 0;
        }

        return (int) round($softMb * (1 + $overagePercent / 100));
    }

    /**
     * L'enregistrement d'UNE partition — plafond puis période de grâce.
     *
     * Le geste est par partition parce que le refus l'est aussi : une partition
     * qui ne porte pas de quota n'a pas de bouton, et rien de ce qui la concerne
     * ne doit pouvoir partir dans le geste de l'autre.
     */
    public function save(string $key): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $partitions = $this->partitions();

        if (! array_key_exists($key, $partitions)) {
            $this->toastError('Partition inconnue.');

            return;
        }

        $partition = $partitions[$key];

        // La garde d'écran. Celle du service est rejouée derrière : celle-ci
        // épargne un aller-retour, elle ne protège rien à elle seule.
        if (! $this->isAvailable($key)) {
            $this->toastError((string) $this->unavailableReason($key));

            return;
        }

        $soft = max(0, (int) ($this->soft[$key] ?? 0));
        $overage = max(0, min(100, (int) ($this->overage[$key] ?? 20)));
        $graceDays = (int) ($this->grace[$key] ?? 0);

        $this->soft[$key] = $soft;
        $this->overage[$key] = $overage;

        try {
            $this->validate([
                'soft.'.$key => ['required', 'integer', 'min:0'],
                'overage.'.$key => ['required', 'integer', 'min:0', 'max:100'],
                'grace.'.$key => ['required', 'integer', 'min:0', 'max:30'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toastError('Valeurs invalides — corrigez les champs en rouge.');

            throw $e;
        }

        // Plancher de l'espace personnel, et de lui SEUL : un plafond de quelques
        // méga-octets sur un répertoire personnel rend la session inutilisable
        // avant même d'avoir ouvert un fichier.
        if ($key === 'home' && $soft > 0 && $soft < self::HOME_FLOOR_MB) {
            $this->addError('soft.home', sprintf(
                'Le plafond de l\'espace personnel doit être d\'au moins %d Mo (ou 0 pour illimité).',
                self::HOME_FLOOR_MB,
            ));
            $this->toastError(sprintf(
                'Le plafond de l\'espace personnel doit être d\'au moins %d Mo (ou 0 pour illimité).',
                self::HOME_FLOOR_MB,
            ));

            return;
        }

        $performedBy = auth()->user()?->login ?? 'system';

        try {
            $this->quotaService->setQuotaRule(
                QuotaRule::TYPE_DEFAULT,
                null,
                $partition,
                $soft,
                $this->calculateHard($soft, $overage),
                $performedBy,
                applyImmediately: false,
            );
        } catch (QuotaPartitionUnavailableException $e) {
            // La partition a changé d'état entre le montage et le clic : on relit,
            // on ferme le champ, et on dit pourquoi.
            $this->availability[$key] = $this->quotaService->partitionQuotaAvailability($partition);
            $this->toastError($e->getMessage());

            return;
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec enregistrement du défaut d\'instance', [
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Impossible d\'enregistrer le plafond. Consultez les logs.');

            return;
        }

        $this->persistGrace($partition, $graceDays, $performedBy);

        // L'administrateur vient d'enregistrer une valeur LUI-MÊME : l'avertissement
        // de regroupement a fini son office.
        $this->acknowledgeCollapseNotice();

        $this->refreshPartition($key, $partition);
    }

    /**
     * **LE SECOND GESTE : PORTER LE PLAFOND À TOUS LES COMPTES COUVERTS.**
     *
     * ---------------------------------------------------------------------------
     * **POURQUOI IL EXISTE, ET POURQUOI IL EST SÉPARÉ.** Enregistrer le plafond
     * l'écrit en base ; il n'atteint AUCUN compte tant que rien ne le porte sur le
     * système de fichiers. Le porter d'un coup à toute la population est légitime —
     * c'est même la seule façon de rendre un plafond effectif sur un parc existant
     * — mais c'est une application EN MASSE, et la doctrine de cette story est
     * qu'aucune ne se fait sans clic. D'où deux boutons, et une annonce chiffrée
     * entre les deux.
     *
     * Les règles de groupe restent respectées : ce qui est mis en file est le quota
     * EFFECTIF de chaque compte, jamais le défaut appliqué à l'aveugle.
     * ---------------------------------------------------------------------------
     */
    public function applyToCovered(string $key): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $partitions = $this->partitions();

        if (! array_key_exists($key, $partitions)) {
            $this->toastError('Partition inconnue.');

            return;
        }

        $partition = $partitions[$key];

        if (! $this->isAvailable($key) || ! ($this->servedOverSmb[$key] ?? true)) {
            $this->toastError(
                'Le plafond ne peut pas être porté sur cette partition : elle n\'est pas servie par le '
                .'serveur de fichiers, ou son quota n\'est pas exploitable.',
            );

            return;
        }

        $rule = QuotaRule::query()
            ->where('type', QuotaRule::TYPE_DEFAULT)
            ->whereNull('target')
            ->where('partition', $partition)
            ->first();

        if ($rule === null) {
            $this->toastError('Enregistrez d\'abord un plafond pour cette partition.');

            return;
        }

        try {
            $dispatched = $this->quotaService->applyInstanceDefault(
                $partition,
                auth()->user()?->login ?? 'system',
            );
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec de l\'application du défaut d\'instance', [
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Impossible de porter le plafond. Consultez les logs.');

            return;
        }

        $this->refreshPartition($key, $partition);

        $this->toastSuccess(sprintf(
            '%d compte(s) mis en file. Les plafonds seront posés au fil du traitement.',
            $dispatched,
        ));
    }

    /**
     * LA GRÂCE ÉCHOUE MOLLEMENT, ET C'EST VOULU (comportement conservé à
     * l'identique) : la valeur est persistée en base **même si** le geste système
     * échoue, et l'écran le DIT. Le corriger est un autre sujet ; le changer sans
     * le dire serait une régression invisible.
     */
    private function persistGrace(string $partition, int $days, string $performedBy): void
    {
        try {
            $setting = QuotaSetting::forPartition($partition);
            $setting->grace_period_days = $days;
            $setting->save();

            $applied = false;

            try {
                $applied = (bool) ($this->quotaService->setGracePeriod($partition, $days, $performedBy)['success'] ?? false);
            } catch (\Throwable $e) {
                Log::warning('QuotaService: période de grâce non appliquée (valeur persistée)', [
                    'partition' => $partition,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($applied) {
                $this->toastSuccess('Plafond et période de grâce enregistrés.');
            } else {
                $this->toastInfo(
                    'Plafond enregistré. La période de grâce est enregistrée mais n\'a pas pu être posée '
                    .'sur le serveur — consultez les logs.',
                );
            }
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec enregistrement de la période de grâce', [
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
            $this->toastInfo('Plafond enregistré, période de grâce non enregistrée — consultez les logs.');
        }
    }
};
?>

{{-- RACINE STABLE : un seul élément au premier niveau, sans condition. --}}
<div class="card bg-base-100 border border-base-300 w-full" data-testid="quotas-card">
    <div class="card-body p-5 gap-4">
        <div>
            <h4 class="font-medium">
                <i class="fa-solid fa-hard-drive mr-1"></i>
                Quotas des espaces personnels
            </h4>
            <p class="text-xs text-base-content/60 mt-1">
                Le plafond qui s'applique à tout compte qu'aucune règle personnelle ni règle de groupe ne
                couvre. Un budget plus large pour une population donnée se pose en règle de groupe.
            </p>
        </div>

        @if ($collapseNotice !== null)
            {{-- LE REGROUPEMENT, ANNONCÉ TANT QU'IL N'A PAS ÉTÉ CONFIRMÉ. Élargir un
                 plafond ne bloque personne et ne consomme aucun disque — un plafond
                 plafonne, il n'alloue pas — mais ce n'est pas anodin, et le taire
                 laisserait l'exploitant devant une valeur qu'il n'a pas posée. --}}
            <div class="alert alert-warning" data-testid="quota-collapse-notice">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div class="text-sm">
                    <p>
                        Les {{ count($this->collapsedValues()) }} plafonds par défaut de cette instance ont
                        été regroupés en un seul, et c'est <strong>la valeur la plus large</strong> qui a été
                        retenue : personne n'a perdu de place.
                    </p>
                    <p class="mt-1">
                        Valeurs regroupées : {{ implode(' ; ', $this->collapsedValues()) }}.
                    </p>
                    <p class="mt-1">
                        Resserrez ce plafond si votre établissement l'exige — vous verrez alors qui bascule.
                        Cet avertissement disparaît dès que vous enregistrez une valeur.
                    </p>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @foreach ($this->partitions() as $key => $partition)
                @php
                    // ⚠️ Jamais `$soft` / `$overage` : ce sont les propriétés du
                    // composant, et les réécrire ici viderait la seconde partition.
                    $softMb = (int) ($soft[$key] ?? 0);
                    $overagePct = (int) ($overage[$key] ?? 20);
                    $hardMb = $this->calculateHard($softMb, $overagePct);
                    $available = $this->isAvailable($key);
                    $reason = $this->unavailableReason($key);
                    $surSmb = (bool) ($servedOverSmb[$key] ?? true);
                    $couverts = (int) ($coverage[$key]['couverts'] ?? 0);
                    $depassements = (int) ($coverage[$key]['depassements'] ?? 0);
                    $mesure = (bool) ($coverage[$key]['mesure'] ?? false);
                @endphp

                <div class="rounded-lg border border-base-300 p-4 flex flex-col gap-3"
                    data-testid="quota-partition-{{ $key }}">

                    <div class="flex items-center gap-2 border-b border-base-300 pb-2">
                        <i class="fa-solid {{ $key === 'home' ? 'fa-house' : 'fa-server' }} text-primary"></i>
                        <span class="text-sm font-medium">{{ $this->partitionLabel($key) }}</span>
                        <code class="text-xs opacity-60 ml-auto">{{ $partition }}</code>
                    </div>

                    @unless ($surSmb)
                        {{-- L'espace ne vit plus sur le serveur de fichiers : ce
                             plafond gouverne alors le compte sur l'instance cloud,
                             et le système de fichiers local n'a rien à en dire. --}}
                        <p class="text-xs text-info" data-testid="quota-off-smb-{{ $key }}">
                            <i class="fa-solid fa-circle-info"></i>
                            Cet espace ne vit plus sur le serveur de fichiers : ce plafond gouverne le
                            plafond du compte sur l'instance cloud, appliqué au prochain balayage de
                            provisionnement.
                        </p>
                    @endunless

                    @unless ($available)
                        {{-- CHAMP FERMÉ AVEC SON MOTIF — jamais un champ ouvert qui
                             accepte une valeur sans effet, jamais un motif tu. --}}
                        <p class="text-xs text-warning" data-testid="quota-unavailable-{{ $key }}">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            {{ $reason }}
                        </p>
                    @endunless

                    <div class="flex flex-col w-full">
                        <label class="label w-full" for="quota-soft-{{ $key }}">
                            <span class="label-text text-xs font-medium">
                                Plafond (Mo) <span class="text-error">*</span>
                            </span>
                        </label>
                        <input id="quota-soft-{{ $key }}" type="number" min="0"
                            class="input input-bordered input-sm w-full"
                            wire:model="soft.{{ $key }}" @disabled(! $available)
                            data-testid="quota-soft-{{ $key }}" />
                        <span class="text-xs text-base-content/60 mt-1">0 = illimité</span>
                        @error('soft.'.$key)
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="flex flex-col w-full">
                        <label class="label w-full" for="quota-overage-{{ $key }}">
                            <span class="label-text text-xs font-medium">
                                Dépassement toléré (%) <span class="text-error">*</span>
                            </span>
                        </label>
                        <input id="quota-overage-{{ $key }}" type="number" min="0" max="100"
                            class="input input-bordered input-sm w-full"
                            wire:model="overage.{{ $key }}" @disabled(! $available || $softMb === 0)
                            data-testid="quota-overage-{{ $key }}" />
                        @error('overage.'.$key)
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="flex flex-col w-full">
                        <span class="label-text text-xs font-medium">Blocage de l'écriture</span>
                        <div class="mt-1 h-9 flex items-center justify-center rounded border border-base-300 bg-base-200 text-sm font-semibold"
                            data-testid="quota-hard-{{ $key }}">
                            {{ $softMb === 0 ? 'Aucun' : number_format($hardMb, 0, ',', ' ') . ' Mo' }}
                        </div>
                        <span class="text-xs text-base-content/60 mt-1">Calculé — il ne se saisit pas.</span>
                    </div>

                    <div class="flex flex-col w-full">
                        <label class="label w-full" for="quota-grace-{{ $key }}">
                            <span class="label-text text-xs font-medium">
                                Période de grâce (jours) <span class="text-error">*</span>
                            </span>
                        </label>
                        <input id="quota-grace-{{ $key }}" type="number" min="0" max="30"
                            class="input input-bordered input-sm w-full"
                            wire:model="grace.{{ $key }}" @disabled(! $available)
                            data-testid="quota-grace-{{ $key }}" />
                        <span class="text-xs text-base-content/60 mt-1">
                            Délai laissé après dépassement du plafond avant que l'écriture ne soit bloquée.
                        </span>
                        @error('grace.'.$key)
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    @if ($available)
                        <button type="button" class="btn btn-primary btn-sm self-start"
                            wire:click="save('{{ $key }}')"
                            wire:loading.attr="disabled" wire:target="save('{{ $key }}')"
                            data-testid="quota-save-{{ $key }}">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Enregistrer
                        </button>
                    @endif

                    @if ($available && $surSmb && $couverts > 0)
                        {{-- ENREGISTRER N'EST PAS APPLIQUER. La règle écrite n'atteint
                             aucun compte tant que rien ne la porte sur le système de
                             fichiers ; le geste qui la porte est explicite, et il
                             annonce ce qu'il fera AVANT de le faire. --}}
                        <div class="border-t border-base-300 pt-3 flex flex-col gap-2"
                            data-testid="quota-apply-block-{{ $key }}">
                            <p class="text-xs text-base-content/60">
                                Enregistrer n'applique rien : les plafonds système ne bougent qu'au geste
                                suivant sur chaque compte.
                            </p>
                            <p class="text-xs" data-testid="quota-coverage-{{ $key }}">
                                <strong>{{ $couverts }}</strong> compte(s) sont couverts par ce plafond.
                                @if ($mesure)
                                    <span class="{{ $depassements > 0 ? 'text-warning' : '' }}">
                                        <strong>{{ $depassements }}</strong> passerait(ent) immédiatement
                                        en dépassement.
                                    </span>
                                @else
                                    <span class="text-warning">
                                        Le nombre de comptes qui basculeraient en dépassement n'a pas pu
                                        être mesuré.
                                    </span>
                                @endif
                            </p>
                            <button type="button" class="btn btn-outline btn-sm self-start"
                                wire:click="applyToCovered('{{ $key }}')"
                                wire:loading.attr="disabled" wire:target="applyToCovered('{{ $key }}')"
                                wire:confirm="Porter ce plafond à {{ $couverts }} compte(s) ?{{ $mesure ? ' '.$depassements.' passerait(ent) immédiatement en dépassement.' : ' Le nombre de comptes qui basculeraient en dépassement n\'a pas pu être mesuré.' }}"
                                data-testid="quota-apply-{{ $key }}">
                                <i class="fa-solid fa-arrows-rotate"></i>
                                Appliquer à tous les comptes couverts
                            </button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>

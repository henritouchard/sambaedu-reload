@php
    use App\Services\QuotaService;
    use App\Models\QuotaRule;

    $quotaService = app(QuotaService::class);
    $groupName = $group['cn'] ?? '';

    // Récupérer les règles de quota existantes pour ce groupe
    $homeRule = QuotaRule::where('type', QuotaRule::TYPE_GROUP)
        ->where('target', $groupName)
        ->where('partition', QuotaRule::PARTITION_HOME)
        ->first();

    $sambaeduRule = QuotaRule::where('type', QuotaRule::TYPE_GROUP)
        ->where('target', $groupName)
        ->where('partition', QuotaRule::PARTITION_SAMBAEDU)
        ->first();

    $formatQuota = function ($rule) {
        if (!$rule) {
            return ['label' => 'Hérité (défaut)', 'class' => 'badge-ghost'];
        }
        if ($rule->quota_soft_mb === 0 && $rule->quota_hard_mb === 0) {
            return ['label' => 'Illimité', 'class' => 'badge-success'];
        }
        $soft =
            $rule->quota_soft_mb >= 1024 ? round($rule->quota_soft_mb / 1024, 1) . ' Go' : $rule->quota_soft_mb . ' Mo';
        $overage = $rule->getOveragePercent();
        return [
            'label' => $soft . ($overage > 0 ? " (+{$overage}%)" : ''),
            'class' => 'badge-primary',
        ];
    };

    $homeQuota = $formatQuota($homeRule);
    $sambaeduQuota = $formatQuota($sambaeduRule);
@endphp

<div class="card bg-base-100 shadow-sm" x-data="{
    showEditModal: false,
    editPartition: '',
    editSoftMb: 0,
    editOveragePercent: 20,
    isUnlimited: false,
    isInherited: true,

    openEdit(partition, rule) {
        this.editPartition = partition;
        if (rule) {
            this.isInherited = false;
            this.isUnlimited = rule.quota_soft_mb === 0 && rule.quota_hard_mb === 0;
            this.editSoftMb = rule.quota_soft_mb;
            this.editOveragePercent = rule.quota_soft_mb > 0 ?
                Math.round((rule.quota_hard_mb - rule.quota_soft_mb) / rule.quota_soft_mb * 100) :
                20;
        } else {
            this.isInherited = true;
            this.isUnlimited = false;
            this.editSoftMb = 500;
            this.editOveragePercent = 20;
        }
        this.showEditModal = true;
    },

    closeEdit() {
        this.showEditModal = false;
    }
}">
    <div class="card-body">
        <h3 class="card-title text-lg mb-4">
            <i class="fa-solid fa-hard-drive mr-2"></i>
            Quotas disque du groupe
            <span class="tooltip tooltip-right"
                data-tip="Les quotas de groupe s'appliquent à tous les membres. Le quota effectif d'un utilisateur est le plus grand parmi ses groupes d'appartenance.">
                <i class="fa-solid fa-circle-info text-base-content/50 text-sm cursor-help"></i>
            </span>
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Partition /home --}}
            <div class="bg-base-200 rounded-lg p-4">
                <div class="flex items-center justify-between mb-2">
                    <div>
                        <span class="font-medium">Espace personnel (K:)</span>
                        <code class="text-xs opacity-70 ml-2">/home</code>
                    </div>
                    @can('manage-quotas')
                        <button type="button" class="btn btn-xs btn-ghost"
                            @click="openEdit('/home', {{ $homeRule ? json_encode($homeRule->toArray()) : 'null' }})">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                    @endcan
                </div>
                <div class="text-xl font-bold">
                    <span class="badge {{ $homeQuota['class'] }}">{{ $homeQuota['label'] }}</span>
                </div>
                @if ($homeRule)
                    <p class="text-xs opacity-70 mt-2">
                        Quota explicite pour ce groupe
                    </p>
                @else
                    <p class="text-xs opacity-70 mt-2">
                        Les membres héritent de leur politique par défaut
                    </p>
                @endif
            </div>

            {{-- Partition /var/sambaedu --}}
            <div class="bg-base-200 rounded-lg p-4">
                <div class="flex items-center justify-between mb-2">
                    <div>
                        <span class="font-medium">Partages (Classes/Docs)</span>
                        <code class="text-xs opacity-70 ml-2">/var/sambaedu</code>
                    </div>
                    @can('manage-quotas')
                        <button type="button" class="btn btn-xs btn-ghost"
                            @click="openEdit('/var/sambaedu', {{ $sambaeduRule ? json_encode($sambaeduRule->toArray()) : 'null' }})">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                    @endcan
                </div>
                <div class="text-xl font-bold">
                    <span class="badge {{ $sambaeduQuota['class'] }}">{{ $sambaeduQuota['label'] }}</span>
                </div>
                @if ($sambaeduRule)
                    <p class="text-xs opacity-70 mt-2">
                        Quota explicite pour ce groupe
                    </p>
                @else
                    <p class="text-xs opacity-70 mt-2">
                        Les membres héritent de leur politique par défaut
                    </p>
                @endif
            </div>
        </div>


        {{-- Modal d'édition --}}
        <template x-if="showEditModal">
            <div class="modal modal-open">
                <div class="modal-box">
                    <h3 class="font-bold text-lg mb-4">
                        <i class="fa-solid fa-hard-drive mr-2"></i>
                        Modifier le quota
                        <span class="text-sm font-normal opacity-70" x-text="editPartition"></span>
                    </h3>

                    <form action="{{ route('app.users.groups.quota.update', ['groupCn' => $groupName]) }}"
                        method="POST">
                        @csrf
                        <input type="hidden" name="partition" x-bind:value="editPartition">
                        <input type="hidden" name="group" value="{{ $groupName }}">

                        <div class="space-y-4">
                            {{-- Type de quota --}}
                            <div class="form-control">
                                <label class="label cursor-pointer justify-start gap-3">
                                    <input type="radio" name="quota_type" value="inherited"
                                        class="radio radio-primary" x-model="isInherited" x-bind:value="true"
                                        @change="isInherited = true">
                                    <span class="label-text">Hérité (supprimer le quota explicite)</span>
                                </label>
                                <label class="label cursor-pointer justify-start gap-3">
                                    <input type="radio" name="quota_type" value="unlimited"
                                        class="radio radio-primary" x-model="isUnlimited"
                                        x-bind:checked="!isInherited && isUnlimited"
                                        @change="isInherited = false; isUnlimited = true">
                                    <span class="label-text">Illimité</span>
                                </label>
                                <label class="label cursor-pointer justify-start gap-3">
                                    <input type="radio" name="quota_type" value="custom" class="radio radio-primary"
                                        x-bind:checked="!isInherited && !isUnlimited"
                                        @change="isInherited = false; isUnlimited = false">
                                    <span class="label-text">Quota personnalisé</span>
                                </label>
                            </div>

                            {{-- Champs quota personnalisé --}}
                            <div x-show="!isInherited && !isUnlimited" x-transition class="space-y-3">
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">Quota (Mo)</span>
                                    </label>
                                    <input type="number" name="quota_soft_mb" x-model="editSoftMb"
                                        class="input input-bordered" min="10" placeholder="500">
                                </div>
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">Dépassement temporaire autorisé (%)</span>
                                    </label>
                                    <input type="number" name="overage_percent" x-model="editOveragePercent"
                                        class="input input-bordered" min="0" max="100" placeholder="20">
                                </div>
                            </div>
                        </div>

                        <div class="modal-action">
                            <button type="button" @click="closeEdit()" class="btn">Annuler</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-check mr-2"></i>
                                Enregistrer
                            </button>
                        </div>
                    </form>
                </div>
                <div class="modal-backdrop" @click="closeEdit()"></div>
            </div>
        </template>
    </div>
</div>

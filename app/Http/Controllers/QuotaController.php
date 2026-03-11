<?php

namespace App\Http\Controllers;

use App\Models\QuotaRule;
use App\Services\QuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Contrôleur pour la gestion des quotas via formulaires classiques
 */
class QuotaController extends Controller
{
    public function __construct(
        private QuotaService $quotaService
    ) {}

    /**
     * Met à jour le quota d'un groupe
     */
    public function updateGroupQuota(Request $request, string $groupCn)
    {
        $validated = $request->validate([
            'partition' => 'required|in:/home,/var/sambaedu',
            'quota_type' => 'required|in:inherited,unlimited,custom',
            'quota_soft_mb' => 'nullable|integer|min:0',
            'overage_percent' => 'nullable|integer|min:0|max:100',
        ]);

        $partition = $validated['partition'];
        $quotaType = $validated['quota_type'];
        $performedBy = auth()->user()?->name ?? session('login') ?? 'system';

        try {
            if ($quotaType === 'inherited') {
                // Supprimer le quota explicite du groupe
                $rule = QuotaRule::where('type', QuotaRule::TYPE_GROUP)
                    ->where('target', $groupCn)
                    ->where('partition', $partition)
                    ->first();

                if ($rule) {
                    $this->quotaService->deleteQuotaRule($rule, $performedBy);
                    Log::info('QuotaController: Quota groupe supprimé', [
                        'group' => $groupCn,
                        'partition' => $partition,
                        'performed_by' => $performedBy,
                    ]);
                }

                return redirect()
                    ->back()
                    ->with('success', "Quota supprimé pour le groupe {$groupCn}. Les membres héritent maintenant de leur politique par défaut.");

            } elseif ($quotaType === 'unlimited') {
                // Quota illimité (soft = 0, hard = 0)
                $this->quotaService->setQuotaRule(
                    QuotaRule::TYPE_GROUP,
                    $groupCn,
                    $partition,
                    0,
                    0,
                    $performedBy
                );

                Log::info('QuotaController: Quota groupe illimité', [
                    'group' => $groupCn,
                    'partition' => $partition,
                    'performed_by' => $performedBy,
                ]);

                return redirect()
                    ->back()
                    ->with('success', "Quota illimité défini pour le groupe {$groupCn} sur {$partition}.");

            } else {
                // Quota personnalisé
                $softMb = (int) ($validated['quota_soft_mb'] ?? 500);
                $overagePercent = (int) ($validated['overage_percent'] ?? 20);
                $hardMb = (int) round($softMb * (1 + $overagePercent / 100));

                // Validation minimale pour /home
                if ($partition === '/home' && $softMb > 0 && $softMb < 10) {
                    return redirect()
                        ->back()
                        ->withErrors(['quota_soft_mb' => 'Le quota sur /home doit être d\'au moins 10 Mo.']);
                }

                $this->quotaService->setQuotaRule(
                    QuotaRule::TYPE_GROUP,
                    $groupCn,
                    $partition,
                    $softMb,
                    $hardMb,
                    $performedBy
                );

                Log::info('QuotaController: Quota groupe défini', [
                    'group' => $groupCn,
                    'partition' => $partition,
                    'soft_mb' => $softMb,
                    'hard_mb' => $hardMb,
                    'performed_by' => $performedBy,
                ]);

                $label = $softMb >= 1024 ? round($softMb / 1024, 1) . ' Go' : $softMb . ' Mo';
                return redirect()
                    ->back()
                    ->with('success', "Quota de {$label} (+{$overagePercent}%) défini pour le groupe {$groupCn} sur {$partition}.");
            }

        } catch (\Throwable $e) {
            Log::error('QuotaController: Erreur mise à jour quota groupe', [
                'group' => $groupCn,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->withErrors(['error' => 'Erreur lors de la mise à jour du quota: ' . $e->getMessage()]);
        }
    }

    /**
     * Met à jour le quota d'un utilisateur
     */
    public function updateUserQuota(Request $request, string $login)
    {
        $validated = $request->validate([
            'partition' => 'required|in:/home,/var/sambaedu',
            'quota_type' => 'required|in:inherited,unlimited,custom',
            'quota_soft_mb' => 'nullable|integer|min:0',
            'overage_percent' => 'nullable|integer|min:0|max:100',
        ]);

        $partition = $validated['partition'];
        $quotaType = $validated['quota_type'];
        $performedBy = auth()->user()?->name ?? session('login') ?? 'system';

        try {
            if ($quotaType === 'inherited') {
                $rule = QuotaRule::where('type', QuotaRule::TYPE_USER)
                    ->where('target', $login)
                    ->where('partition', $partition)
                    ->first();

                if ($rule) {
                    $this->quotaService->deleteQuotaRule($rule, $performedBy);
                }

                return redirect()
                    ->back()
                    ->with('success', "Quota supprimé pour {$login}. L'utilisateur hérite maintenant de ses groupes.");

            } elseif ($quotaType === 'unlimited') {
                $this->quotaService->setQuotaRule(
                    QuotaRule::TYPE_USER,
                    $login,
                    $partition,
                    0,
                    0,
                    $performedBy
                );

                return redirect()
                    ->back()
                    ->with('success', "Quota illimité défini pour {$login} sur {$partition}.");

            } else {
                $softMb = (int) ($validated['quota_soft_mb'] ?? 500);
                $overagePercent = (int) ($validated['overage_percent'] ?? 20);
                $hardMb = (int) round($softMb * (1 + $overagePercent / 100));

                if ($partition === '/home' && $softMb > 0 && $softMb < 10) {
                    return redirect()
                        ->back()
                        ->withErrors(['quota_soft_mb' => 'Le quota sur /home doit être d\'au moins 10 Mo.']);
                }

                $this->quotaService->setQuotaRule(
                    QuotaRule::TYPE_USER,
                    $login,
                    $partition,
                    $softMb,
                    $hardMb,
                    $performedBy
                );

                $label = $softMb >= 1024 ? round($softMb / 1024, 1) . ' Go' : $softMb . ' Mo';
                return redirect()
                    ->back()
                    ->with('success', "Quota de {$label} (+{$overagePercent}%) défini pour {$login} sur {$partition}.");
            }

        } catch (\Throwable $e) {
            Log::error('QuotaController: Erreur mise à jour quota utilisateur', [
                'login' => $login,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->withErrors(['error' => 'Erreur lors de la mise à jour du quota: ' . $e->getMessage()]);
        }
    }
}

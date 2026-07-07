<?php

use App\Components\Traits\WithToasts;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /admin/settings/security — Réglages « Sécurité & session ».
 *
 * Déconnexion automatique sur inactivité : pilote la durée de vie de la
 * session serveur (`config('session.lifetime')`, en rolling / idle-based).
 * Persisté via SystemSetting::set('security.session_idle', [...]) et appliqué
 * au runtime par App\Providers\AppServiceProvider::boot().
 *
 * Rappel : la session admin est une session Laravel classique (guard web
 * `driver => session`), PAS un JWT. Le JWT fédéré ne borne que la fenêtre de
 * login (story 20.1), pas la session vivante.
 *
 * Sécurité : middleware can:server.admin sur la route + double guard sur mount()
 * et save(). Toasts génériques (jamais $e->getMessage() exposé).
 */
new #[Title('Sécurité & session')] class extends Component {
    use WithToasts;

    /** Défaut legacy : 24h (aligné config/session.php `lifetime` = 1440). */
    public const DEFAULT_MINUTES = 1440;

    /**
     * @var array{enabled:bool, minutes:int}
     */
    public array $session = ['enabled' => false, 'minutes' => self::DEFAULT_MINUTES];

    public array $originalSession = [];

    public function mount(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        $this->loadSession();
        $this->originalSession = $this->session;
    }

    private function loadSession(): void
    {
        $stored = SystemSetting::get('security.session_idle', null);
        $stored = is_array($stored) ? $stored : [];

        $this->session = [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'minutes' => (int) ($stored['minutes'] ?? self::DEFAULT_MINUTES),
        ];
    }

    public function getSessionDirtyProperty(): bool
    {
        return [
            (bool) ($this->session['enabled'] ?? false),
            (int) ($this->session['minutes'] ?? 0),
        ] != [
            (bool) ($this->originalSession['enabled'] ?? false),
            (int) ($this->originalSession['minutes'] ?? 0),
        ];
    }

    public function saveSession(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        try {
            $this->validate([
                'session.enabled' => ['required', 'boolean'],
                // 5 min à 24h : borne basse pour éviter de se verrouiller dehors.
                'session.minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toastError('Configuration invalide (délai entre 5 et 1440 minutes).');
            throw $e;
        }

        try {
            $payload = [
                'enabled' => (bool) $this->session['enabled'],
                'minutes' => (int) $this->session['minutes'],
            ];
            SystemSetting::set('security.session_idle', $payload);
            $this->session = $payload;
            $this->originalSession = $payload;

            $this->toastSuccess('Réglage de déconnexion automatique enregistré.');
        } catch (\Throwable $e) {
            Log::error('SecuritySettings: échec save session_idle', ['error' => $e->getMessage()]);
            $this->toastError('Impossible d\'enregistrer le réglage. Consultez les logs.');
        }
    }
};
?>

<x-organisms.page title="Sécurité & session"
    icon="fa-solid fa-user-clock"
    description="Déconnexion automatique de l'interface sur inactivité.">

    <div class="flex flex-col gap-6 pt-4 max-w-2xl">

        <div class="card bg-base-100 shadow-sm border border-base-300">
            <div class="card-body">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="card-title text-lg">
                        <i class="fa-solid fa-user-clock mr-2"></i>
                        Déconnexion automatique sur inactivité
                    </h3>
                </div>
                <p class="text-sm opacity-70 mb-4">
                    Ferme la session serveur après une période d'inactivité. Le délai est
                    remis à zéro à chaque action ; passé ce délai sans activité, l'utilisateur
                    est déconnecté et devra se reconnecter. Désactivé, la session suit la
                    valeur par défaut du serveur (24&nbsp;h).
                </p>

                <form wire:submit.prevent="saveSession" class="space-y-4">
                    <div class="form-control">
                        <label class="label cursor-pointer max-w-xs justify-start gap-3">
                            <input type="checkbox" wire:model.live="session.enabled" class="toggle toggle-primary" />
                            <span class="label-text">Activer la déconnexion automatique</span>
                        </label>
                    </div>

                    <div class="form-control" @if (!$session['enabled']) style="opacity:.5" @endif>
                        <label class="label">
                            <span class="label-text">Délai d'inactivité avant déconnexion (minutes)</span>
                        </label>
                        <input type="number" min="5" max="1440"
                            wire:model.live.debounce.500ms="session.minutes"
                            @disabled(!$session['enabled'])
                            class="input input-bordered max-w-xs" />
                        @error('session.minutes')
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                        <span class="text-xs opacity-60 mt-1">Entre 5 minutes et 1440 minutes (24&nbsp;h).</span>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary"
                            @disabled(!$this->sessionDirty)
                            wire:loading.attr="disabled" wire:target="saveSession">
                            <span wire:loading wire:target="saveSession" class="loading loading-spinner loading-xs"></span>
                            <i wire:loading.remove wire:target="saveSession" class="fa-solid fa-save"></i>
                            Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</x-organisms.page>

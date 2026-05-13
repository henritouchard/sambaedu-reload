<?php

use App\Components\Traits\WithToasts;
use App\Services\Network\DhcpImportService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Import CSV — Réservations DHCP')] class extends Component {
    use WithFileUploads, WithToasts;

    public $csvFile = null;

    public function mount(): void
    {
        if (Gate::denies('manage-dhcp')) {
            abort(403);
        }
    }

    public function import()
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }

        $this->validate([
            'csvFile' => 'required|file|max:2048|mimes:csv,txt',
        ]);

        try {
            // TemporaryUploadedFile (Livewire) hérite de \Illuminate\Http\UploadedFile.
            $report = app(DhcpImportService::class)->importFromCsv($this->csvFile);

            $this->toastSuccessWithActions(
                sprintf('%d réservation(s) importée(s), %d mise(s) à jour, %d erreur(s), %d ligne(s) ignorée(s).',
                    $report->ok, $report->updated, $report->errors, $report->skipped),
                [['label' => 'Voir le rapport', 'url' => route('app.network.dhcp.import.report', ['uuid' => $report->uuid])]],
                sticky: true,
            );

            return $this->redirect(route('app.network.dhcp.import.report', ['uuid' => $report->uuid]));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('network')->error('Import CSV DHCP — exception', ['error' => $e->getMessage()]);
            $this->toastError("Erreur d'import : " . $e->getMessage());
        }
    }
};
?>

<x-organisms.page :backUrl="route('app.network.dhcp')" title="Importer des réservations DHCP"
    backText="Retour à la liste">

    <div class="max-w-2xl space-y-6">
        <div class="alert alert-info">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <h3 class="font-bold">Format CSV attendu</h3>
                <p class="text-sm">
                    Header obligatoire <code>name,mac,ip,description</code> (description optionnelle).
                    Séparateur : virgule. Encodage : UTF-8.
                </p>
                <pre class="text-xs bg-base-200 rounded p-2 mt-2 font-mono">name,mac,ip,description
posteSalle1,00:11:22:33:44:55,10.0.0.50,Salle informatique poste #1
imprimanteCDI,AA:BB:CC:DD:EE:FF,10.0.0.30,Imprimante CDI</pre>
            </div>
        </div>

        <form wire:submit.prevent="import" class="space-y-4">
            <div class="form-control">
                <label class="label"><span class="label-text">Fichier CSV (max 2 Mo)</span></label>
                <input type="file" wire:model="csvFile" accept=".csv,.txt" class="file-input file-input-bordered w-full" />
                @error('csvFile')
                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex gap-2">
                <a href="{{ route('app.network.dhcp') }}" class="btn btn-ghost">Annuler</a>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="import">
                        <i class="fa-solid fa-file-import"></i> Importer
                    </span>
                    <span wire:loading wire:target="import">
                        <span class="loading loading-spinner loading-sm"></span> Import en cours…
                    </span>
                </button>
            </div>
        </form>

        <div class="alert alert-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <p class="text-sm">
                    Le service DHCP sera rechargé <strong>une seule fois</strong> à la fin de l'import.
                    Les lignes valides sont créées / mises à jour ; les lignes en erreur sont consignées
                    dans le rapport d'import (cache 24h).
                </p>
            </div>
        </div>
    </div>
</x-organisms.page>

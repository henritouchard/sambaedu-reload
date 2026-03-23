<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Migration - Assistance')] class extends Component {
    //
};

?>

<x-organisms.page title="Assistance Migration" description="Outils d'aide à la migration SE4FS vers SambaEdu-Reload" icon="fas fa-exchange-alt">

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-4">

        {{-- Card: Sync from AD --}}
        <a href="{{ route('admin.sync-from-ad') }}" class="card bg-base-100 shadow-md hover:shadow-xl transition-all duration-200 hover:-translate-y-1 border border-base-300/50">
            <div class="card-body">
                <div class="flex items-center gap-4 mb-2">
                    <div class="w-12 h-12 rounded-xl bg-primary/10 flex items-center justify-center">
                        <i class="fa-solid fa-rotate text-primary text-xl"></i>
                    </div>
                    <h2 class="card-title text-lg">Sync from AD</h2>
                </div>
                <p class="text-sm text-base-content/70">
                    Assistant de synchronisation depuis l'Active Directory. Import des utilisateurs, groupes et structures depuis le SE4FS.
                </p>
                <div class="card-actions justify-end mt-4">
                    <span class="badge badge-primary badge-outline">Synchronisation</span>
                </div>
            </div>
        </a>

        {{-- Card: Legacy Monitor --}}
        <a href="{{ route('admin.legacy-monitor') }}" class="card bg-base-100 shadow-md hover:shadow-xl transition-all duration-200 hover:-translate-y-1 border border-base-300/50">
            <div class="card-body">
                <div class="flex items-center gap-4 mb-2">
                    <div class="w-12 h-12 rounded-xl bg-warning/10 flex items-center justify-center">
                        <i class="fa-solid fa-eye text-warning text-xl"></i>
                    </div>
                    <h2 class="card-title text-lg">Legacy Monitor</h2>
                </div>
                <p class="text-sm text-base-content/70">
                    Surveillance des appels catchall en temps réel. Identifie les routes legacy encore utilisées pour prioriser la migration.
                </p>
                <div class="card-actions justify-end mt-4">
                    <span class="badge badge-warning badge-outline">Monitoring</span>
                </div>
            </div>
        </a>

    </div>

</x-organisms.page>

@extends('layouts.admin-sidebar')

@section('title', 'Test Modal')

@section('content')
    <div class="container mx-auto p-6">
        <h1 class="text-2xl font-bold mb-6">Test Modal vs Sidebar</h1>

        <div class="card p-6 space-y-4">
            <p class="text-base-content/70">
                Cette page sert à tester le z-index entre la sidebar et les modales.
            </p>

            <!-- Bouton pour ouvrir la modale DaisyUI standard -->
            <button class="btn btn-primary" onclick="document.getElementById('test_modal').showModal()">
                Ouvrir Modal (showModal)
            </button>

            <!-- Bouton pour ouvrir via checkbox (méthode DaisyUI alternative) -->
            <label for="test_modal_checkbox" class="btn btn-secondary">
                Ouvrir Modal (checkbox)
            </label>
        </div>
    </div>

    <!-- Modal DaisyUI avec showModal() -->
    <dialog id="test_modal" class="modal">
        <div class="modal-box">
            <h3 class="font-bold text-lg">Test Modal (showModal)</h3>
            <p class="py-4">Cette modale devrait apparaître AU-DESSUS de la sidebar.</p>
            <p class="text-sm text-base-content/60">Si vous voyez la sidebar par-dessus cette modale, il y a un problème de
                z-index.</p>
            <div class="modal-action">
                <form method="dialog">
                    <button class="btn">Fermer</button>
                </form>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>

    <!-- Modal DaisyUI avec checkbox -->
    <input type="checkbox" id="test_modal_checkbox" class="modal-toggle" />
    <div class="modal" role="dialog">
        <div class="modal-box">
            <h3 class="font-bold text-lg">Test Modal (checkbox)</h3>
            <p class="py-4">Cette modale devrait apparaître AU-DESSUS de la sidebar.</p>
            <p class="text-sm text-base-content/60">Si vous voyez la sidebar par-dessus cette modale, il y a un problème de
                z-index.</p>
            <div class="modal-action">
                <label for="test_modal_checkbox" class="btn">Fermer</label>
            </div>
        </div>
        <label class="modal-backdrop" for="test_modal_checkbox">Close</label>
    </div>
@endsection

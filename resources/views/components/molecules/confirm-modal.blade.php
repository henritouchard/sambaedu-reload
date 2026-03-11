{{--
Composant de modal de confirmation globale
Utilise la balise <dialog> HTML5 native pour éviter les problèmes de z-index

    Usage: Écoute l'événement Alpine 'open-confirm-modal' avec les paramètres :
    - title: Titre de la modal
    - message: Message de confirmation
    - confirmText: Texte du bouton de confirmation (défaut: 'Confirmer')
    - cancelText: Texte du bouton d'annulation (défaut: 'Annuler')
    - variant: Variante du bouton (primary, error, warning, success)
    - method: Méthode Livewire à appeler
    - params: Paramètres à passer à la méthode
    - wireId: ID du composant Livewire
    --}}

    <dialog id="confirm-modal-dialog" class="modal" x-data="{
        title: '',
        message: '',
        confirmText: 'Confirmer',
        cancelText: 'Annuler',
        variant: 'primary',
        method: null,
        params: [],
        wireId: null,
        
        show(detail) {
            this.title = detail.title || 'Confirmation';
            this.message = detail.message || 'Êtes-vous sûr ?';
            this.confirmText = detail.confirmText || 'Confirmer';
            this.cancelText = detail.cancelText || 'Annuler';
            this.variant = detail.variant || 'primary';
            this.method = detail.method || null;
            this.params = detail.params || [];
            this.wireId = detail.wireId || null;
            this.$el.showModal();
        },
        
        confirm() {
            if (this.wireId && this.method) {
                try {
                    const component = Livewire.find(this.wireId);
                    if (component) {
                        component.call(this.method, ...this.params);
                    }
                } catch (e) {
                    console.error('Erreur exécution action:', e);
                }
            }
            this.close();
        },
        
        close() {
            document.getElementById('confirm-modal-dialog').close();
            this.method = null;
            this.params = [];
            this.wireId = null;
        },
        
        getButtonClass() {
            const classes = {
                'primary': 'btn-primary',
                'error': 'btn-error',
                'warning': 'btn-warning',
                'success': 'btn-success',
            };
            return classes[this.variant] || 'btn-primary';
        }
    }" @open-confirm-modal.window="show($event.detail)" @close="method = null; params = []; wireId = null;">
        <div class="modal-box">
            <!-- Titre -->
            <h3 class="font-bold text-lg" x-text="title"></h3>

            <!-- Message -->
            <p class="py-4" x-text="message"></p>

            <!-- Actions -->
            <div class="modal-action">
                <button type="button" class="btn btn-ghost" @click="close()" x-text="cancelText"></button>
                <button type="button" class="btn" :class="getButtonClass()" @click="confirm()"
                    x-text="confirmText"></button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
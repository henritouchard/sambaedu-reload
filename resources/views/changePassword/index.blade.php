@extends('components.layouts.blank')

@section('content')
    <div class="min-h-screen hero bg-base-200">
        <div class="hero-content flex-col lg:flex-row-reverse">
            <div class="text-center lg:text-left">
                <x-atoms.logo class="mx-auto mb-6 max-w-xs" />
                <h1 class="text-2xl w-full text-center font-bold text-primary">Changement de mot de passe</h1>
            </div>
            <div class="card flex-shrink-0 w-full max-w-sm shadow-2xl bg-base-100 overflow-hidden break-words">
                <form method="POST" action="{{ route('auth.change-password.submit') }}" class="card-body break-words">
                    @csrf
                    @if (isset($token))
                        <input type="hidden" name="token" value="{{ $token }}">
                    @endif


                    <div class="form-control min-h-0">
                        <label class="label max-w-full break-words" for="current_password">
                            <span class="label-text">Mot de passe actuel</span>
                        </label>
                        <div class="relative">
                            <input id="current_password" name="current_password" type="password" required
                                class="input input-bordered w-full pr-10 @error('current_password') input-error @enderror"
                                placeholder="Saisissez votre mot de passe actuel">
                            <button type="button" class="absolute inset-y-0 right-0 flex items-center pr-3"
                                onclick="togglePasswordVisibility('current_password')" tabindex="-1">
                                <svg id="eye-open-current" class="h-5 w-5 text-base-content/50 hover:text-base-content"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                    </path>
                                </svg>
                                <svg id="eye-closed-current"
                                    class="h-5 w-5 text-base-content/50 hover:text-base-content hidden" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M10.5 12.5a2.5 2.5 0 1 1 5 0 2.5 2.5 0 0 1-5 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                    </path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18">
                                    </path>
                                </svg>
                            </button>
                        </div>
                        @error('current_password')
                            <label class="label max-w-full break-words">
                                <span
                                    class="label-text-alt text-error break-words whitespace-normal max-w-full block leading-relaxed">{{ $message }}</span>
                            </label>
                        @enderror
                    </div>

                    <div class="form-control min-h-0">
                        <label class="label max-w-full break-words" for="new_password">
                            <span class="label-text">Nouveau mot de passe</span>
                        </label>
                        <div class="relative">
                            <input id="new_password" name="new_password" type="password" required
                                class="input input-bordered w-full pr-10 @error('new_password') input-error @enderror"
                                placeholder="Saisissez votre nouveau mot de passe">
                            <button type="button" class="absolute inset-y-0 right-0 flex items-center pr-3"
                                onclick="togglePasswordVisibility('new_password')" tabindex="-1">
                                <svg id="eye-open-new" class="h-5 w-5 text-base-content/50 hover:text-base-content"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                    </path>
                                </svg>
                                <svg id="eye-closed-new" class="h-5 w-5 text-base-content/50 hover:text-base-content hidden"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M10.5 12.5a2.5 2.5 0 1 1 5 0 2.5 2.5 0 0 1-5 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                    </path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18">
                                    </path>
                                </svg>
                            </button>
                        </div>
                        @error('new_password')
                            <label class="label max-w-full break-words">
                                <span
                                    class="label-text-alt text-error break-words whitespace-normal max-w-full block leading-relaxed">{{ $message }}</span>
                            </label>
                        @enderror
                    </div>

                    <div class="form-control min-h-0">
                        <label class="label max-w-full break-words" for="new_password_confirmation">
                            <span class="label-text">Confirmer le nouveau mot de passe</span>
                        </label>
                        <div class="relative">
                            <input id="new_password_confirmation" name="new_password_confirmation" type="password"
                                required
                                class="input input-bordered w-full pr-10 @error('new_password_confirmation') input-error @enderror"
                                placeholder="Confirmez votre nouveau mot de passe">
                            <button type="button" class="absolute inset-y-0 right-0 flex items-center pr-3"
                                onclick="togglePasswordVisibility('new_password_confirmation')" tabindex="-1">
                                <svg id="eye-open-confirm" class="h-5 w-5 text-base-content/50 hover:text-base-content"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                    </path>
                                </svg>
                                <svg id="eye-closed-confirm"
                                    class="h-5 w-5 text-base-content/50 hover:text-base-content hidden" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M10.5 12.5a2.5 2.5 0 1 1 5 0 2.5 2.5 0 0 1-5 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                    </path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18">
                                    </path>
                                </svg>
                            </button>
                        </div>
                        @error('new_password_confirmation')
                            <label class="label max-w-full break-words">
                                <span
                                    class="label-text-alt text-error break-words whitespace-normal max-w-full block leading-relaxed">{{ $message }}</span>
                            </label>
                        @enderror
                    </div>

                    @if (isset($expires_at))
                        <br><span class="text-red-500 w-full text-center">⏰ Le token expirera à {{ $expires_at }}</span>
                    @endif

                    <div class="form-control mt-6">
                        <button type="submit" class="btn btn-primary w-full">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                                </path>
                            </svg>
                            Modifier le mot de passe
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script>
        function togglePasswordVisibility(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const eyeOpen = document.getElementById('eye-open-' + fieldId.split('_')[1]);
            const eyeClosed = document.getElementById('eye-closed-' + fieldId.split('_')[1]);

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeOpen.classList.add('hidden');
                eyeClosed.classList.remove('hidden');
            } else {
                passwordInput.type = 'password';
                eyeOpen.classList.remove('hidden');
                eyeClosed.classList.add('hidden');
            }
        }

        // Validation en temps réel des mots de passe
        document.addEventListener('DOMContentLoaded', function() {
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('new_password_confirmation');
            const form = document.querySelector('form');

            // Fonction pour vérifier la correspondance des mots de passe
            function checkPasswordMatch() {
                if (newPassword.value && confirmPassword.value) {
                    if (newPassword.value !== confirmPassword.value) {
                        confirmPassword.classList.add('input-error');
                        confirmPassword.classList.remove('input-success');
                        showPasswordMismatchError();
                    } else {
                        confirmPassword.classList.remove('input-error');
                        confirmPassword.classList.add('input-success');
                        hidePasswordMismatchError();
                    }
                } else {
                    confirmPassword.classList.remove('input-error', 'input-success');
                    hidePasswordMismatchError();
                }
            }

            // Fonction pour afficher l'erreur de correspondance
            function showPasswordMismatchError() {
                let errorDiv = document.getElementById('password-mismatch-error');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.id = 'password-mismatch-error';
                    errorDiv.className = 'label mt-1 max-w-full break-words';
                    errorDiv.innerHTML =
                        '<span class="label-text-alt text-error break-words whitespace-normal max-w-full block leading-relaxed text-sm">Les mots de passe ne correspondent pas</span>';

                    // Insérer après le champ de confirmation
                    const confirmField = confirmPassword.parentNode.parentNode;
                    confirmField.appendChild(errorDiv);
                }
            }

            // Fonction pour masquer l'erreur de correspondance
            function hidePasswordMismatchError() {
                const errorDiv = document.getElementById('password-mismatch-error');
                if (errorDiv) {
                    errorDiv.remove();
                }
            }

            // Fonction pour afficher un toast d'erreur
            function showErrorToast(message) {
                if (typeof window.dispatchEvent === 'function') {
                    window.dispatchEvent(new CustomEvent('toastMagic', {
                        detail: {
                            status: 'error',
                            title: 'Erreur',
                            message: message,
                            options: {
                                showCloseBtn: true,
                            }
                        }
                    }));
                }
            }

            // Fonction pour afficher un toast de succès
            function showSuccessToast(message) {
                if (typeof window.dispatchEvent === 'function') {
                    window.dispatchEvent(new CustomEvent('toastMagic', {
                        detail: {
                            status: 'success',
                            title: 'Succès',
                            message: message,
                            options: {
                                showCloseBtn: true,
                            }
                        }
                    }));
                }
            }

            // Validation en temps réel
            newPassword.addEventListener('input', checkPasswordMatch);
            confirmPassword.addEventListener('input', checkPasswordMatch);

            // Validation du formulaire avant soumission
            form.addEventListener('submit', function(e) {
                // Vérifier la correspondance des mots de passe
                if (newPassword.value !== confirmPassword.value) {
                    e.preventDefault();
                    showErrorToast('Les mots de passe ne correspondent pas');
                    confirmPassword.focus();
                    return false;
                }

                // Vérifier la longueur minimale
                if (newPassword.value.length < 8) {
                    e.preventDefault();
                    showErrorToast('Le nouveau mot de passe doit contenir au moins 8 caractères');
                    newPassword.focus();
                    return false;
                }

                // Vérifier que tous les champs sont remplis
                const currentPassword = document.getElementById('current_password');
                if (!currentPassword.value || !newPassword.value || !confirmPassword.value) {
                    e.preventDefault();
                    showErrorToast('Tous les champs sont obligatoires');
                    return false;
                }
            });

            // Afficher un toast de succès si le changement a réussi
            @if (session('toast_success'))
                showSuccessToast('{{ session('toast_success') }}');
            @endif

            // Afficher un toast d'erreur pour les erreurs
            @if (session('toast_error'))
                showErrorToast('{{ session('toast_error') }}');
            @endif
        });
    </script>
@endsection

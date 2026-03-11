@extends('layouts.auth')

@section('title', 'Connexion - SambaEdu')

@section('content')
    <div class="min-h-screen hero">
        <div class="hero-content flex-col lg:flex-row-reverse lg:align-center lg: gap-16">
            <div class="text-center">
                <img src="{{ asset('img/LogoSambaEdu.png') }}" alt="Logo SambaEdu" class="mx-auto mb-6 max-w-md">
                <p class="text-base-content/80 text-lg">
                    Accédez à votre espace utilisateur
                </p>
            </div>
            <div class="card flex-shrink-0 w-full max-w-sm shadow-2xl">

                <form action="{{ route('auth.authenticate') }}" method="POST" class="card-body">
                    @csrf
                    <div class="form-control">
                        <label class="label text-lg" for="login">
                            <span class="label-text">Nom d'utilisateur</span>
                        </label>
                        <input id="login" name="login" type="text" autofocus required
                            value="{{ old('login', $login) }}"
                            class="input w-full @error('login') @enderror @error('auth') input-error @enderror"
                            placeholder="Saisissez votre nom d'utilisateur"
                            @if ($autolog) disabled @endif>
                        @error('login')
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $message }}</span>
                            </label>
                        @enderror
                    </div>

                    <div class="form-control">
                        <label class="label text-lg" for="password">
                            <span class="label-text">Mot de passe</span>
                        </label>
                        <div class="relative">
                            <input id="password" name="password" type="password" required
                                class="input w-full pr-10 @error('password') input-error @enderror @error('auth') input-error @enderror"
                                placeholder="Saisissez votre mot de passe">
                            <button type="button" class="absolute inset-y-0 right-0 flex items-center pr-3"
                                onclick="togglePasswordVisibility()" tabindex="-1">
                                <svg id="eye-open" class="h-5 w-5 text-base-content/50 hover:text-base-content"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                    </path>
                                </svg>
                                <svg id="eye-closed" class="h-5 w-5 text-base-content/50 hover:text-base-content hidden"
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
                        @error('password')
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $message }}</span>
                            </label>
                        @enderror
                    </div>

                    @if ($autolog)
                        <div class="alert alert-info">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                class="stroke-current shrink-0 w-6 h-6">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span>Connexion automatique détectée pour : <strong>{{ $login }}</strong></span>
                        </div>
                    @endif

                    @error('auth')
                        <div class="alert alert-error">
                            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>{{ $message }}</span>
                        </div>
                    @enderror

                    <div class="form-control mt-6">
                        <button type="submit" class="btn btn-primary w-full">
                            <span class="flex items-center justify-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1">
                                    </path>
                                </svg>
                                Se connecter
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const eyeOpen = document.getElementById('eye-open');
            const eyeClosed = document.getElementById('eye-closed');

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
    </script>
@endsection

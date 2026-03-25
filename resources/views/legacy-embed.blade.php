<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Module Legacy' }} - SambaEdu</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    {!! ToastMagic::styles() !!}
</head>
<body class="bg-base-200/30">

    <div class="drawer relative lg:drawer-open">
        <input id="drawer-toggle" type="checkbox" class="drawer-toggle" />
        <div class="drawer-content flex flex-col max-h-screen">
            <x-organisms.navbar />

            <main class="flex-1 p-6 lg:pt-4 lg:py-0 max-h-full overflow-x-hidden relative z-10">
                <x-organisms.page
                    :title="$title"
                    description="Page legacy embarquée dans le layout SER"
                    back="{{ route('app.users') }}"
                >
                    <x-slot:actions>
                        <span class="badge badge-warning badge-outline gap-1">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                            Legacy
                        </span>
                    </x-slot:actions>

                    <style>
                        .legacy-content table { border-collapse: collapse; width: 100%; }
                        .legacy-content td, .legacy-content th { padding: 4px 8px; }
                        .legacy-content input[type="text"],
                        .legacy-content input[type="password"],
                        .legacy-content select,
                        .legacy-content textarea {
                            padding: 0.5rem;
                            border: 1px solid oklch(var(--bc) / 0.2);
                            border-radius: 0.375rem;
                            background: oklch(var(--b1));
                            color: oklch(var(--bc));
                        }
                        .legacy-content input[type="submit"],
                        .legacy-content input[type="button"],
                        .legacy-content button:not(.btn) {
                            padding: 0.5rem 1rem;
                            border-radius: 0.375rem;
                            background: oklch(var(--p));
                            color: oklch(var(--pc));
                            border: none;
                            cursor: pointer;
                        }
                        .legacy-content input[type="submit"]:hover,
                        .legacy-content input[type="button"]:hover,
                        .legacy-content button:not(.btn):hover {
                            opacity: 0.85;
                        }
                        .legacy-content h1 { font-size: 1.5rem; font-weight: bold; margin-bottom: 0.5rem; }
                        .legacy-content h2 { font-size: 1.25rem; font-weight: bold; margin-bottom: 0.5rem; }
                        .legacy-content a { color: oklch(var(--p)); text-decoration: underline; }
                        .legacy-content br + br { display: none; }
                    </style>

                    <div class="legacy-content card bg-base-100 shadow-sm p-6">
                        {!! $legacyHtml !!}
                    </div>
                </x-organisms.page>
            </main>

            {!! ToastMagic::scripts() !!}
        </div>

        <x-organisms.sidebar />

        @livewireScripts
        @stack('modals')
        @stack('scripts')
        <x-molecules.confirm-modal />
    </div>
</body>
</html>

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

                    {{-- Reset complet pour rendre le HTML legacy lisible dans Tailwind/DaisyUI --}}
                    <style>
                        /* ─── Layout & typographie ─────────────────── */
                        .legacy-content { font-size: 0.925rem; line-height: 1.6; color: oklch(var(--bc)); }
                        .legacy-content h1 { font-size: 1.5rem; font-weight: 700; margin: 0.75rem 0; }
                        .legacy-content h2 { font-size: 1.25rem; font-weight: 600; margin: 0.5rem 0; }
                        .legacy-content h3 { font-size: 1.1rem; font-weight: 600; margin: 0.5rem 0; }
                        .legacy-content a { color: oklch(var(--p)); text-decoration: underline; }
                        .legacy-content a:hover { opacity: 0.8; }
                        .legacy-content br + br { display: none; }
                        .legacy-content p { margin: 0.25rem 0; }

                        /* ─── Tables ───────────────────────────────── */
                        .legacy-content table { border-collapse: collapse; width: 100%; margin: 0.5rem 0; }
                        .legacy-content td, .legacy-content th {
                            padding: 0.5rem 0.75rem;
                            border: 1px solid oklch(var(--bc) / 0.1);
                            vertical-align: top;
                        }
                        .legacy-content th {
                            background: oklch(var(--b2));
                            font-weight: 600;
                            text-align: left;
                        }
                        .legacy-content tr:hover { background: oklch(var(--b2) / 0.5); }

                        /* ─── Inputs texte & select ────────────────── */
                        .legacy-content input[type="text"],
                        .legacy-content input[type="password"],
                        .legacy-content input[type="email"],
                        .legacy-content input[type="number"],
                        .legacy-content input[type="search"],
                        .legacy-content input[type="tel"],
                        .legacy-content input[type="url"],
                        .legacy-content input[type="file"],
                        .legacy-content select,
                        .legacy-content textarea {
                            display: inline-block;
                            padding: 0.5rem 0.75rem;
                            border: 1px solid oklch(var(--bc) / 0.3);
                            border-radius: 0.5rem;
                            background: oklch(var(--b1));
                            color: oklch(var(--bc));
                            font-size: 0.875rem;
                            line-height: 1.5;
                            min-height: 2.5rem;
                            transition: border-color 0.15s, box-shadow 0.15s;
                        }
                        .legacy-content input:focus,
                        .legacy-content select:focus,
                        .legacy-content textarea:focus {
                            outline: none;
                            border-color: oklch(var(--p));
                            box-shadow: 0 0 0 3px oklch(var(--p) / 0.15);
                        }
                        .legacy-content select { appearance: auto; padding-right: 2rem; }
                        .legacy-content select[multiple] { min-height: 6rem; }
                        .legacy-content textarea { min-height: 4rem; resize: vertical; }

                        /* ─── Boutons ──────────────────────────────── */
                        .legacy-content input[type="submit"],
                        .legacy-content input[type="button"],
                        .legacy-content input[type="reset"],
                        .legacy-content button:not(.btn) {
                            display: inline-flex;
                            align-items: center;
                            justify-content: center;
                            padding: 0.5rem 1.25rem;
                            border-radius: 0.5rem;
                            font-size: 0.875rem;
                            font-weight: 500;
                            border: none;
                            cursor: pointer;
                            min-height: 2.5rem;
                            transition: opacity 0.15s, transform 0.1s;
                        }
                        .legacy-content input[type="submit"] {
                            background: oklch(var(--p));
                            color: oklch(var(--pc));
                        }
                        .legacy-content input[type="button"],
                        .legacy-content input[type="reset"] {
                            background: oklch(var(--b3));
                            color: oklch(var(--bc));
                        }
                        .legacy-content input[type="submit"]:hover,
                        .legacy-content input[type="button"]:hover,
                        .legacy-content input[type="reset"]:hover,
                        .legacy-content button:not(.btn):hover {
                            opacity: 0.85;
                        }

                        /* ─── Checkbox & radio ─────────────────────── */
                        .legacy-content input[type="checkbox"],
                        .legacy-content input[type="radio"] {
                            appearance: auto;
                            width: 1rem;
                            height: 1rem;
                            accent-color: oklch(var(--p));
                            cursor: pointer;
                        }

                        /* ─── Fieldset & legend ────────────────────── */
                        .legacy-content fieldset {
                            border: 1px solid oklch(var(--bc) / 0.15);
                            border-radius: 0.5rem;
                            padding: 1rem;
                            margin: 0.5rem 0;
                        }
                        .legacy-content legend { font-weight: 600; padding: 0 0.5rem; }

                        /* ─── Messages legacy (font color=red, etc.) ─ */
                        .legacy-content font[color="red"],
                        .legacy-content span[style*="color:red"],
                        .legacy-content span[style*="color: red"] {
                            color: oklch(var(--er)) !important;
                            font-weight: 500;
                        }
                        .legacy-content font[color="green"],
                        .legacy-content span[style*="color:green"],
                        .legacy-content span[style*="color: green"] {
                            color: oklch(var(--su)) !important;
                        }

                        /* ─── Divers ───────────────────────────────── */
                        .legacy-content img { max-width: 100%; height: auto; }
                        .legacy-content hr {
                            border: none;
                            border-top: 1px solid oklch(var(--bc) / 0.1);
                            margin: 1rem 0;
                        }
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

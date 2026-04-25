<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') — SambaEdu</title>

    @vite(['resources/css/app.css'])
</head>
<body class="bg-base-200/30 min-h-screen flex items-center justify-center p-6">

    @php
        $accent = trim($__env->yieldContent('accent')) ?: 'warning';
        $accentClasses = match ($accent) {
            'error'   => ['text' => 'text-error',   'alert' => 'alert-error'],
            'info'    => ['text' => 'text-info',    'alert' => 'alert-info'],
            'success' => ['text' => 'text-success', 'alert' => 'alert-success'],
            default   => ['text' => 'text-warning', 'alert' => 'alert-warning'],
        };
    @endphp

    <div class="card bg-base-100 shadow-xl w-full max-w-xl">
        <div class="card-body items-center text-center p-10">

            <div class="flex items-baseline gap-3 {{ $accentClasses['text'] }}">
                <i class="@yield('icon') text-4xl"></i>
                <span class="text-7xl font-black tracking-tight">@yield('code')</span>
            </div>

            <h1 class="card-title text-2xl mt-4">@yield('heading')</h1>

            <p class="text-base-content/70 leading-relaxed">
                @yield('message')
            </p>

            @hasSection('hint')
                <div role="alert" class="alert {{ $accentClasses['alert'] }} alert-soft mt-4 text-left text-sm">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>@yield('hint')</span>
                </div>
            @endif

            <div class="card-actions justify-center mt-6 gap-2 flex-wrap">
                <button onclick="history.back()" class="btn btn-ghost">
                    <i class="fa-solid fa-arrow-left"></i>
                    Page précédente
                </button>
                <a href="{{ route('app.dashboard') }}" class="btn btn-primary">
                    <i class="fa-solid fa-house"></i>
                    Retour à l'accueil
                </a>
            </div>
        </div>
    </div>

</body>
</html>

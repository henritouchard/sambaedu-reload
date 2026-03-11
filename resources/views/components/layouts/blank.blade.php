<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? 'SambaEdu' }}</title>
        
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {!! ToastMagic::styles() !!}

    </head>
    <body>
        <!-- Bouton de basculement du thème -->
        <x-atoms.theme-toggle position="fixed" />

        @yield('content')
        

        {!! ToastMagic::scripts() !!}
    </body>
</html>

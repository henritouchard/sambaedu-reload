<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? 'SambaEdu' }}</title>
        
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {!! ToastMagic::styles() !!}

        @livewireStyles
    </head>
    <body>
        <!-- Bouton de basculement du thème -->
        <x-atoms.theme-toggle position="fixed" />

        <!-- Modal de confirmation globale -->
        <x-molecules.confirm-modal />

        {{ $slot }}
        
        @livewireScripts

        {!! ToastMagic::scripts() !!}
    </body>
</html>

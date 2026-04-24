@extends('errors.layout')

@section('code', '403')
@section('title', 'Accès refusé')
@section('icon', 'fa-solid fa-lock')
@section('accent', 'warning')
@section('heading', 'Accès refusé')
@section('message')
    Vous n'avez pas les droits nécessaires pour accéder à cette page.
    @if (!empty($exception?->getMessage()) && config('app.debug'))
        <br>
        <span class="text-base-content/50 text-sm italic">{{ $exception->getMessage() }}</span>
    @endif
@endsection
@section('hint', "Si vous pensez qu'il s'agit d'une erreur, contactez votre administrateur ou demandez une délégation de droits.")

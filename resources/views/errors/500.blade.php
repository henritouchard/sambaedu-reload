@extends('errors.layout')

@section('code', '500')
@section('title', 'Erreur serveur')
@section('icon', 'fa-solid fa-triangle-exclamation')
@section('accent', 'error')
@section('heading', 'Une erreur est survenue')
@section('message')
    Le serveur a rencontré un problème et n'a pas pu traiter votre demande.
    @if (!empty($exception?->getMessage()) && config('app.debug'))
        <br>
        <span class="text-base-content/50 text-sm italic font-mono">{{ $exception->getMessage() }}</span>
    @endif
@endsection
@section('hint', "L'incident a été consigné. Réessayez dans quelques instants ou contactez le support si le problème persiste.")

@extends('errors.layout')

@section('code', '404')
@section('title', 'Page introuvable')
@section('icon', 'fa-solid fa-map-location-dot')
@section('accent', 'info')
@section('heading', 'Page introuvable')
@section('message')
    La page que vous cherchez n'existe pas ou a été déplacée.
@endsection
@section('hint', "Vérifiez l'URL ou utilisez la recherche (Ctrl+K) pour trouver ce que vous cherchez.")

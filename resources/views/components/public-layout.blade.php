@props(['title' => null, 'description' => 'Connect capital with real businesses and asset-based opportunities through transparent Islamic financial structures.'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @include('partials.head-assets')
</head>
<body class="bg-white">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-3">Skip to content</a>
    <x-public.navbar />
    <main id="main">{{ $slot }}</main>
    <x-public.footer />
    @livewireScripts
</body>
</html>

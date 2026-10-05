<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    @include('partials.head-assets')
</head>
<body class="pattern-geo min-h-screen bg-brand-900">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <a href="{{ route('home') }}" class="mb-6 flex items-center gap-2 font-display text-2xl font-semibold text-white"><x-brand-mark class="h-9 w-9" />{{ config('app.name') }}</a>
        <div class="w-full max-w-md rounded-card bg-white p-6 shadow-lift sm:p-8">{{ $slot }}</div>
        <p class="mt-6 max-w-md text-center text-xs text-brand-200/70">{{ config('finance.shariah_disclaimer') }}</p>
    </div>
    @livewireScripts
</body>
</html>

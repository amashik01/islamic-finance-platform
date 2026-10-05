@props(['title', 'subtitle' => null])
<x-ui.card :title="$title" :subtitle="$subtitle" {{ $attributes }}>{{ $slot }}</x-ui.card>

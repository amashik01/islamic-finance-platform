@props(['name', 'class' => 'h-5 w-5'])
@php
    $paths = [
        'home' => 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
        'users' => 'M16 11a4 4 0 1 0-8 0M4 20a8 8 0 0 1 16 0',
        'building' => 'M4 21V5l8-2v18M12 9h8v12M7 9h2M7 13h2M7 17h2M15 13h2M15 17h2',
        'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z M9 12l2 2 4-4',
        'folder' => 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
        'document' => 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
        'cash' => 'M3 7h18v10H3zM12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5',
        'chart' => 'M4 20V10M10 20V4M16 20v-8M22 20H2',
        'bell' => 'M6 9a6 6 0 0 1 12 0c0 6 2 7 2 7H4s2-1 2-7M10 20a2 2 0 0 0 4 0',
        'cog' => 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6M19 12l2-1-1-3-2 .5-1.5-1.5.5-2-3-1-1 2h-2l-1-2-3 1 .5 2L6.5 8.500 4.500 8l-1 3 2 1v2l-2 1 1 3 2-.5L8 19l-.5 2 3 1 1-2h2l1 2 3-1-.5-2 1.500-1.500 2 .5 1-3-2-1z',
        'wallet' => 'M3 7a2 2 0 0 1 2-2h13v4M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2M16 14.500h2',
        'briefcase' => 'M4 8h16v11H4zM9 8V5h6v3M4 13h16',
        'scale' => 'M12 3v18M5 7h14M5 7l-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z',
        'search' => 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14M21 21l-5-5',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'x' => 'M6 6l12 12M18 6L6 18',
        'swap' => 'M7 7h13l-3-3M17 17H4l3 3',
        'clipboard' => 'M9 4h6v3H9zM7 5H5v16h14V5h-2M9 12h6M9 16h6',
        'logout' => 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10',
        'chevron' => 'M6 9l6 6 6-6',
    ];
@endphp
<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['document'] }}"/></svg>

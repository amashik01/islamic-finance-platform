<x-public-layout :title="$title"><section class="mx-auto max-w-3xl px-4 py-12 sm:px-6"><h1 class="font-display text-3xl font-semibold">{{ $title }}</h1>
<x-ui.alert type="warning" title="Draft placeholder" class="mt-6">This page is a placeholder. Final legal text must be prepared by qualified legal, regulatory and Shariah advisers before any real funds are handled. Operating an investment or financing platform may require licences or approvals.</x-ui.alert>
@if($page === 'shariah-disclaimer')<p class="mt-6 text-ink-700">{{ config('finance.shariah_disclaimer') }}</p>@endif
</section></x-public-layout>

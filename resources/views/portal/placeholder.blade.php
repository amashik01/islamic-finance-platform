<x-dynamic-component :component="$portal.'-layout'" :title="$title">
    <x-ui.empty-state :title="$title" message="This section is part of a later build phase{{ $phase ? ' ('.$phase.')' : '' }}. Access control and navigation are already in place." />
</x-dynamic-component>

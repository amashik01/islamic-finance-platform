@props(['title' => null])
<x-portal-shell portal="business" portal-label="Business Portal" :title="$title">{{ $slot }}</x-portal-shell>

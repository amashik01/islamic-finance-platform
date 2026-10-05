@props(['title' => null])
<x-portal-shell portal="investor" portal-label="Investor Portal" :title="$title">{{ $slot }}</x-portal-shell>

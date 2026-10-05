@props(['title' => null])
<x-portal-shell portal="admin" portal-label="Admin" :title="$title">{{ $slot }}</x-portal-shell>

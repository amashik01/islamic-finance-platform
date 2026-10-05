@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $label = method_exists($status, 'label') ? $status->label() : ucwords(strtolower(str_replace('_', ' ', $value)));
    $tone = match (true) {
        in_array($value, ['APPROVED','ACTIVE','COMPLETED','VERIFIED','PAID','POSTED','CONFIRMED','FUNDING']) => 'success',
        in_array($value, ['PENDING','REVIEW','UNDER_REVIEW','PROCESSING','NEEDS_REVISION','PARTIAL','SCHEDULED','DRAFT','PAUSED']) => 'warning',
        in_array($value, ['REJECTED','DEFAULTED','OVERDUE','FAILED','CANCELLED','REVERSED']) => 'danger',
        default => 'neutral',
    };
@endphp
<x-ui.badge :tone="$tone" {{ $attributes }}>{{ $label }}</x-ui.badge>

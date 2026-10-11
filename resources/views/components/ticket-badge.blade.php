@props(['value'])
@php
    $tone = match ($value->value) {
        'in_progress', 'resolved' => 'border-teal-200 bg-teal-50 text-teal-900',
        'urgent' => 'border-red-200 bg-red-50 text-red-900',
        'waiting_customer', 'high' => 'border-amber-200 bg-amber-50 text-amber-900',
        default => 'border-stone-200 bg-stone-100 text-slate-700',
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold leading-snug', $tone]) }}>{{ $value->label() }}</span>

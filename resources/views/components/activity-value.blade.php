@props(['field', 'value', 'users'])
@php
    $display = match ($field) {
        'status' => \App\Enums\TicketStatus::tryFrom((string) $value)?->label() ?? '—',
        'priority' => \App\Enums\TicketPriority::tryFrom((string) $value)?->label() ?? '—',
        'assigned_to' => $value === null ? 'Non assigné' : ($users->get($value)?->name ?? 'Agent nº '.$value),
        'resolved_at', 'closed_at' => $value === null ? '—' : \Carbon\CarbonImmutable::parse($value, 'UTC')->setTimezone('Europe/Paris')->format('d/m/Y · H:i'),
        default => $value ?? '—',
    };
@endphp
<span class="break-words whitespace-pre-wrap">{{ $display }}</span>

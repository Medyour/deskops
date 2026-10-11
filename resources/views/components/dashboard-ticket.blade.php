@props(['ticket'])
<div class="min-w-0">
    <a href="{{ route('tickets.show', $ticket) }}" class="block text-sm font-semibold break-words hover:text-teal-800">
        <span class="text-xs font-bold text-teal-800">{{ $ticket->reference }}</span> · {{ $ticket->title }}
    </a>
    <div class="mt-2 flex flex-wrap gap-2"><x-ticket-badge :value="$ticket->status" /><x-ticket-badge :value="$ticket->priority" /></div>
    <p class="mt-2 text-xs break-words text-slate-600">{{ $ticket->assignee?->name ?? 'Non assigné' }}</p>
    <p class="mt-1 text-xs text-slate-500">Créé le <x-ticket-time :value="$ticket->created_at" /></p>
</div>

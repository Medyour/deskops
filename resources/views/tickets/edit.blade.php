<x-layout :title="'Modifier '.$ticket->reference">
    <a href="{{ route('tickets.show', $ticket) }}" class="text-sm font-semibold text-teal-800 hover:underline">← Revenir au ticket</a>
    <div class="mt-6 max-w-3xl">
        <p class="eyebrow">{{ $ticket->reference }}</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Modifier les informations</h1>
        <p class="mt-3 text-slate-600">Chaque changement est conservé dans l’historique du ticket.</p>
        <form method="POST" action="{{ route('tickets.update', $ticket) }}" class="card mt-8">
            @csrf
            @method('PATCH')
            <input type="hidden" name="version" value="{{ old('version', $ticket->version) }}">
            <x-form-feedback />
            <x-ticket-fields :values="$ticket->only(['title', 'description', 'requester_name', 'requester_email']) + ['priority' => $ticket->priority->value]" />
            <div class="mt-8 flex flex-wrap gap-3 border-t border-stone-200 pt-6">
                <button class="button">Enregistrer les modifications</button>
                <a href="{{ route('tickets.show', $ticket) }}" class="button-secondary">Annuler</a>
            </div>
        </form>
    </div>
</x-layout>

<x-layout title="Créer un ticket">
    <a href="{{ route('tickets.index') }}" class="text-sm font-semibold text-teal-800 hover:underline">← Tous les tickets</a>
    <div class="mt-6 max-w-3xl">
        <p class="eyebrow">Nouvelle demande</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Créer un ticket</h1>
        <p class="mt-3 text-slate-600">Rassemblez les informations utiles. Le ticket sera créé au statut Nouveau, sans agent assigné.</p>
        <form method="POST" action="{{ route('tickets.store') }}" class="card mt-8">
            @csrf
            <x-form-feedback />
            <x-ticket-fields />
            <div class="mt-8 flex flex-wrap gap-3 border-t border-stone-200 pt-6">
                <button class="button">Créer le ticket</button>
                <a href="{{ route('tickets.index') }}" class="button-secondary">Annuler</a>
            </div>
        </form>
    </div>
</x-layout>

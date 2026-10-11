<x-layout title="Accès refusé">
    <section class="card mx-auto max-w-2xl" aria-labelledby="denied-title">
        <p class="eyebrow">Accès refusé · 403</p>
        <h1 id="denied-title" class="mt-3 text-3xl font-semibold tracking-tight">Cette action n’est pas autorisée.</h1>
        <p class="mt-4 leading-relaxed text-slate-600">Votre compte ne permet pas d’effectuer cette action. Aucune modification n’a été enregistrée.</p>
        <a href="{{ route('tickets.index') }}" class="button mt-6">Revenir aux tickets</a>
    </section>
</x-layout>

<x-layout title="Mon espace">
    <p class="text-xs font-bold tracking-widest text-teal-800 uppercase">Espace équipe</p>
    <div class="mt-4 flex flex-wrap items-start justify-between gap-6">
        <div>
            <h1 class="text-3xl font-semibold tracking-tight">Bonjour, {{ auth()->user()->name }}.</h1>
            <p class="mt-3 text-slate-600">Votre point de repère pour le support client.</p>
        </div>
        <span class="rounded-full border border-teal-200 bg-teal-50 px-4 py-2 text-sm font-medium text-teal-900">{{ auth()->user()->role->label() }}</span>
    </div>
    <section aria-labelledby="ready-title" class="mt-10 rounded-2xl border border-teal-200 bg-teal-50 p-6 sm:p-8">
        <h2 id="ready-title" class="text-lg font-semibold text-teal-950">Votre espace est prêt.</h2>
        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-teal-900">Cette première version pose les accès et les rôles. La liste des tickets, leur historique et les indicateurs seront ajoutés au fil des prochaines étapes.</p>
    </section>
    <div class="mt-6 grid gap-4 md:grid-cols-3">
        @foreach([
            ['Tickets', 'Une vue commune des demandes, avec recherche, filtres et assignation.'],
            ['Activité', 'Les commentaires et les changements réunis dans une chronologie.'],
            ['Tableau de bord', 'La charge de l’équipe, les priorités et les demandes à traiter.'],
        ] as [$heading, $description])
            <section class="card">
                <span class="text-xs font-semibold text-amber-800">À venir</span>
                <h2 class="mt-4 text-lg font-semibold">{{ $heading }}</h2>
                <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $description }}</p>
            </section>
        @endforeach
    </div>
</x-layout>

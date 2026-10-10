<x-layout title="Un support clair, une équipe alignée">
    <div class="grid items-start gap-12 lg:grid-cols-[1.3fr_1fr] lg:gap-16">
        <section>
            <p class="mb-6 inline-flex items-center gap-2 rounded-full border border-teal-200 bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-900">
                <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full bg-teal-700"></span>
                Mini-centre de support client
            </p>
            <h1 class="max-w-2xl text-5xl font-semibold leading-[1.08] tracking-tight sm:text-6xl">Chaque demande.<br>Un suivi clair.</h1>
            <p class="mt-7 max-w-lg text-lg leading-relaxed text-slate-600">DeskOps réunit les demandes, les échanges et les décisions dans un espace pensé pour les petites équipes support.</p>
            <div class="mt-9 flex flex-wrap gap-3">
                @auth
                    <a href="{{ route('dashboard') }}" class="button">Ouvrir mon espace <span aria-hidden="true" class="ml-3">→</span></a>
                @else
                    <a href="{{ route('login') }}" class="button">Accéder à mon espace <span aria-hidden="true" class="ml-3">→</span></a>
                @endauth
                <a href="#parcours" class="button-secondary">Découvrir le projet</a>
            </div>
            <p class="mt-5 text-xs text-slate-500">Accès équipe · Administrateurs et agents</p>
        </section>
        <aside aria-labelledby="foundation-title" class="rounded-3xl bg-teal-900 p-7 text-white sm:p-9">
            <p class="text-xs font-medium tracking-widest text-teal-200 uppercase">01 / Fondations</p>
            <h2 id="foundation-title" class="mt-6 text-2xl font-semibold tracking-tight">Le point de départ<br>d’un support organisé.</h2>
            <p class="mt-4 text-sm leading-relaxed text-teal-100">L’espace équipe et les accès sont prêts. Les outils de traitement des tickets arrivent à la prochaine étape.</p>
            <ul class="mt-8 space-y-4 text-sm">
                <li class="flex items-center justify-between border-t border-white/20 pt-4"><span>Authentification</span><span class="rounded-full bg-white/10 px-3 py-1 text-xs">Disponible</span></li>
                <li class="flex items-center justify-between border-t border-white/20 pt-4"><span>Rôles de l’équipe</span><span class="rounded-full bg-white/10 px-3 py-1 text-xs">Disponible</span></li>
                <li class="flex items-center justify-between border-t border-white/20 pt-4"><span>Workflow des tickets</span><span class="text-xs text-teal-200">À venir</span></li>
            </ul>
        </aside>
    </div>
    <section id="parcours" aria-labelledby="journey-title" class="mt-20">
        <div class="mb-7 flex flex-wrap items-end justify-between gap-4">
            <h2 id="journey-title" class="text-2xl font-semibold tracking-tight">Un parcours, du premier contact à la résolution.</h2>
            <span class="text-xs font-medium text-slate-500">Périmètre du MVP</span>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
            @foreach([
                ['01', 'Centraliser', 'Créer, rechercher et prioriser les tickets pour voir ce qui demande votre attention.'],
                ['02', 'Collaborer', 'Assigner les demandes, partager des commentaires et conserver l’historique des décisions.'],
                ['03', 'Piloter', 'Suivre la charge de l’équipe et les demandes en attente grâce à un tableau de bord lisible.'],
            ] as [$number, $heading, $description])
                <article class="card">
                    <p class="text-xs font-bold text-teal-700">{{ $number }} /</p>
                    <h3 class="mt-4 text-lg font-semibold">{{ $heading }}</h3>
                    <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $description }}</p>
                </article>
            @endforeach
        </div>
    </section>
</x-layout>

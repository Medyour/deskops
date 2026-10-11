<x-layout title="Tableau de bord">
    <div class="flex flex-wrap items-start justify-between gap-5">
        <div class="min-w-0">
            <p class="eyebrow">Pilotage du support · {{ auth()->user()->role->label() }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">Tableau de bord</h1>
            <p class="mt-3 break-words text-slate-600">Bonjour, {{ auth()->user()->name }}. Voici l’état des demandes {{ $mine ? 'qui vous sont assignées' : 'de l’équipe' }}.</p>
        </div>
        @can('create', \App\Models\Ticket::class)
            <a href="{{ route('tickets.create') }}" class="button">+ Créer un ticket</a>
        @endcan
    </div>
    <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
        <nav aria-label="Périmètre du tableau de bord" class="flex flex-wrap gap-2">
            <a href="{{ route('dashboard') }}" @if(!$mine) aria-current="page" @endif @class(['rounded-xl border px-4 py-2 text-sm font-semibold', 'border-teal-800 bg-teal-800 text-white' => !$mine, 'border-stone-300 bg-white text-slate-700 hover:bg-stone-100' => $mine])>Équipe @if(!$mine)<span class="sr-only">(vue actuelle)</span>@endif</a>
            @if(!auth()->user()->isAdministrator())
                <a href="{{ route('dashboard', ['scope' => 'mine']) }}" @if($mine) aria-current="page" @endif @class(['rounded-xl border px-4 py-2 text-sm font-semibold', 'border-teal-800 bg-teal-800 text-white' => $mine, 'border-stone-300 bg-white text-slate-700 hover:bg-stone-100' => !$mine])>Mes tickets @if($mine)<span class="sr-only">(vue actuelle)</span>@endif</a>
            @endif
        </nav>
        <p class="text-xs text-slate-500">Actualisé au chargement · <x-ticket-time :value="$generatedAt" /></p>
    </div>
    <x-form-feedback />

    <section aria-labelledby="metrics-title" class="mt-8">
        <h2 id="metrics-title" class="sr-only">Indicateurs {{ $mine ? 'de mes tickets' : 'de l’équipe' }}</h2>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach([
                ['total', 'Total des tickets', 'Tous les statuts, y compris résolus et fermés.', []],
                ['active', 'Tickets actifs', 'Nouveau, En cours ou En attente du client.', ['queue' => 'active']],
                ['unassigned', 'Non assignés', 'Tickets actifs sans agent assigné.', ['queue' => 'unassigned']],
                ['high_priority', 'Priorités élevées actives', 'Priorité Haute ou Urgente, hors tickets terminés.', ['queue' => 'high_priority']],
                ['urgent', 'Urgents actifs', 'Priorité Urgente parmi les tickets actifs.', ['queue' => 'urgent']],
                ['resolved_recently', 'Résolus sur 7 jours', 'Actuellement Résolu ou Fermé, résolution sur les 7 derniers jours.', ['queue' => 'resolved_recently']],
            ] as [$key, $label, $definition, $linkFilters])
                <div class="card min-w-0">
                    <dt class="text-sm font-semibold text-slate-600">{{ $label }}</dt>
                    <dd class="mt-3 text-4xl font-semibold tracking-tight">{{ $metrics[$key] }}</dd>
                    <dd class="mt-3 text-xs leading-relaxed text-slate-500">{{ $definition }}</dd>
                    <dd class="mt-4"><a href="{{ route('tickets.index', [...$filters, ...$linkFilters]) }}" class="text-sm font-semibold text-teal-800 underline decoration-teal-200 underline-offset-4 hover:decoration-teal-800">Voir les tickets<span class="sr-only"> : {{ $label }}</span> <span aria-hidden="true">↗</span></a></dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div class="mt-8 grid items-start gap-6 lg:grid-cols-2">
        <section aria-labelledby="attention-title" class="card min-w-0 border-teal-200">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="attention-title" class="text-lg font-semibold">À traiter <span class="ml-1 rounded-full bg-teal-50 px-2.5 py-1 text-sm text-teal-900">{{ $metrics['attention'] }}</span></h2>
                <a href="{{ route('tickets.index', [...$filters, 'queue' => 'attention']) }}" class="text-sm font-semibold text-teal-800 underline underline-offset-4">Voir toute la file</a>
            </div>
            <p class="mt-3 text-sm leading-relaxed text-slate-600">Tickets actifs non assignés ou de priorité Haute / Urgente. Priorité la plus élevée d’abord, puis demande la plus ancienne.</p>
            <p class="mt-2 text-xs text-slate-500">Aperçu limité à 5 tickets. Une demande n’est comptée qu’une fois.</p>
            <ul class="mt-4 divide-y divide-stone-100">
                @forelse($attentionTickets as $ticket)
                    <li class="py-4"><x-dashboard-ticket :ticket="$ticket" /></li>
                @empty
                    <li class="py-6 text-sm text-slate-600">Aucune demande dans cette file. Les autres tickets actifs restent accessibles depuis les indicateurs.</li>
                @endforelse
            </ul>
        </section>
        <section aria-labelledby="load-title" class="card min-w-0">
            <h2 id="load-title" class="text-lg font-semibold">{{ $mine ? 'Ma charge actuelle' : 'Charge actuelle par agent' }}</h2>
            <p class="mt-3 text-sm leading-relaxed text-slate-600">Nombre de tickets actifs assignés. Les tickets résolus et fermés sont exclus.</p>
            <dl class="mt-5 divide-y divide-stone-100">
                @forelse($agents as $agent)
                    <div class="flex items-center justify-between gap-4 py-4">
                        <dt class="min-w-0 break-words"><a href="{{ route('tickets.index', ['queue' => 'active', 'assignee' => $agent->id]) }}" class="text-sm font-semibold text-teal-800 hover:underline">{{ $agent->name }}</a></dt>
                        <dd class="shrink-0 text-sm"><span class="font-semibold">{{ $agent->active_tickets_count }}</span> {{ $agent->active_tickets_count === 1 ? 'ticket actif' : 'tickets actifs' }}</dd>
                    </div>
                @empty
                    <div class="py-4"><dt class="text-sm text-slate-600">Aucun agent à afficher.</dt><dd class="sr-only">0</dd></div>
                @endforelse
                <div class="flex items-center justify-between gap-4 border-t border-stone-200 py-4">
                    <dt class="text-sm font-semibold"><a href="{{ route('tickets.index', [...$filters, 'queue' => 'unassigned']) }}" class="text-teal-800 hover:underline">File non assignée</a></dt>
                    <dd class="shrink-0 text-sm"><span class="font-semibold">{{ $metrics['unassigned'] }}</span> {{ $metrics['unassigned'] === 1 ? 'ticket actif' : 'tickets actifs' }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-2">
        @foreach([
            ['statuses', 'Répartition par statut', \App\Enums\TicketStatus::cases(), $statuses, 'status'],
            ['priorities', 'Répartition par priorité', \App\Enums\TicketPriority::cases(), $priorities, 'priority'],
        ] as [$section, $heading, $cases, $counts, $filterKey])
            <section aria-labelledby="{{ $section }}-title" class="card min-w-0">
                <h2 id="{{ $section }}-title" class="text-lg font-semibold">{{ $heading }}</h2>
                <p class="mt-2 text-xs text-slate-500">Tous les {{ $metrics['total'] }} tickets de la vue {{ $mine ? 'Mes tickets' : 'Équipe' }}.</p>
                <dl class="mt-4 divide-y divide-stone-100">
                    @foreach($cases as $case)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <dt><a href="{{ route('tickets.index', [...$filters, $filterKey => $case->value]) }}" class="inline-flex rounded-full hover:underline"><x-ticket-badge :value="$case" /></a></dt>
                            <dd class="text-sm font-semibold">{{ $counts[$case->value] }}<span class="sr-only"> {{ $counts[$case->value] === 1 ? 'ticket' : 'tickets' }}</span></dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-2">
        <section aria-labelledby="recent-tickets-title" class="card min-w-0">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="recent-tickets-title" class="text-lg font-semibold">Tickets récents</h2>
                <a href="{{ route('tickets.index', $filters) }}" class="text-sm font-semibold text-teal-800 underline underline-offset-4">Tous les tickets</a>
            </div>
            <p class="mt-3 text-xs text-slate-500">Les 5 dernières créations, tous statuts confondus.</p>
            <ul class="mt-4 divide-y divide-stone-100">
                @forelse($recentTickets as $ticket)
                    <li class="py-4"><x-dashboard-ticket :ticket="$ticket" /></li>
                @empty
                    <li class="py-6 text-sm text-slate-600">Aucun ticket dans cette vue. Créez une demande ou consultez la vue Équipe.</li>
                @endforelse
            </ul>
        </section>
        <section aria-labelledby="recent-activities-title" class="card min-w-0">
            <h2 id="recent-activities-title" class="text-lg font-semibold">Dernières activités</h2>
            <p class="mt-3 text-xs text-slate-500">Les 5 dernières actions sur les tickets de cette vue · Heures de Paris.</p>
            <ol class="mt-4 divide-y divide-stone-100">
                @forelse($recentActivities as $activity)
                    <li class="py-4">
                        <p class="text-sm font-semibold">{{ $activity->type->label() }}</p>
                        <a href="{{ route('tickets.show', $activity->ticket) }}" class="mt-1 block text-sm break-words text-teal-800 hover:underline">{{ $activity->ticket->reference }} · {{ $activity->ticket->title }}</a>
                        <p class="mt-2 text-xs break-words text-slate-600">Par {{ $activity->actor->name }}</p>
                        <p class="mt-1 text-xs text-slate-500"><x-ticket-time :value="$activity->created_at" /></p>
                    </li>
                @empty
                    <li class="py-6 text-sm text-slate-600">Aucune activité enregistrée dans cette vue.</li>
                @endforelse
            </ol>
        </section>
    </div>
    <aside aria-label="Lecture des indicateurs" class="mt-6 rounded-xl border border-stone-200 px-5 py-4 text-xs leading-relaxed text-slate-600">
        <p>Les indicateurs reflètent les données enregistrées au chargement. « Résolus sur 7 jours » utilise la date de résolution, entre maintenant moins 7 jours et maintenant, bornes incluses. Les dates fixes de démonstration ne sont pas recalées sur aujourd’hui.</p>
        @if($mine)<p class="mt-2">La vue Mes tickets suit votre assignation actuelle, y compris pour les activités. Les tickets non assignés sont donc absents de cette vue.</p>@endif
    </aside>
</x-layout>

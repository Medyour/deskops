<x-layout title="Tickets">
    <div class="flex flex-wrap items-start justify-between gap-5">
        <div>
            <p class="eyebrow">Espace support</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">Tickets</h1>
            <p class="mt-3 text-slate-600">Une vue commune pour suivre chaque demande.</p>
        </div>
        @can('create', \App\Models\Ticket::class)
            <a href="{{ route('tickets.create') }}" class="button">+ Créer un ticket</a>
        @endcan
    </div>
    <form method="GET" action="{{ route('tickets.index') }}" class="card mt-8" aria-label="Recherche et filtres">
        <x-form-feedback />
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <x-ticket-field name="q" label="Rechercher" :value="$filters['q'] ?? ''" maxlength="100" placeholder="Référence, sujet ou contact" hint="Référence exacte ou texte du sujet / contact." />
            <div>
                <label for="status-filter" class="block text-sm font-semibold">Statut</label>
                <select id="status-filter" name="status" class="field" @if($errors->has('status')) aria-invalid="true" aria-describedby="status-error" @endif>
                    <option value="">Tous les statuts</option>
                    @foreach(\App\Enums\TicketStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(old('status', $filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                @error('status')<p id="status-error" class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="priority-filter" class="block text-sm font-semibold">Priorité</label>
                <select id="priority-filter" name="priority" class="field" @if($errors->has('priority')) aria-invalid="true" aria-describedby="priority-error" @endif>
                    <option value="">Toutes les priorités</option>
                    @foreach(\App\Enums\TicketPriority::cases() as $priority)
                        <option value="{{ $priority->value }}" @selected(old('priority', $filters['priority'] ?? '') === $priority->value)>{{ $priority->label() }}</option>
                    @endforeach
                </select>
                @error('priority')<p id="priority-error" class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="assignee-filter" class="block text-sm font-semibold">Assignation</label>
                <select id="assignee-filter" name="assignee" class="field" @if($errors->has('assignee')) aria-invalid="true" aria-describedby="assignee-error" @endif>
                    <option value="">Toute l’équipe</option>
                    <option value="me" @selected(old('assignee', $filters['assignee'] ?? '') === 'me')>Mes tickets</option>
                    <option value="unassigned" @selected(old('assignee', $filters['assignee'] ?? '') === 'unassigned')>Non assignés</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" @selected((string) old('assignee', $filters['assignee'] ?? '') === (string) $agent->id)>{{ $agent->name }}</option>
                    @endforeach
                </select>
                @error('assignee')<p id="assignee-error" class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="mt-5 flex flex-wrap items-center gap-4">
            <button class="button">Appliquer les filtres</button>
            <a href="{{ route('tickets.index') }}" class="text-sm font-semibold text-slate-600 underline decoration-stone-300 underline-offset-4 hover:text-teal-800">Réinitialiser</a>
        </div>
    </form>
    <section aria-labelledby="results-title" class="mt-8">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 id="results-title" class="text-sm font-semibold">{{ $tickets->total() }} {{ $tickets->total() === 1 ? 'ticket trouvé' : 'tickets trouvés' }}</h2>
            <p class="text-xs text-slate-500">Plus récents d’abord · Heures de Paris</p>
        </div>
        @if($tickets->isEmpty())
            <div class="card py-12 text-center">
                <p class="eyebrow">Aucun résultat</p>
                <h3 class="mt-3 text-xl font-semibold">Aucun ticket à afficher</h3>
                <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-slate-600">Essayez une recherche plus courte ou réinitialisez les filtres. Vous pouvez aussi enregistrer une nouvelle demande.</p>
                <a href="{{ route('tickets.index') }}" class="button-secondary mt-6">Réinitialiser les filtres</a>
            </div>
        @else
            <div role="region" aria-label="Liste des tickets, défilement horizontal si nécessaire" tabindex="0" class="hidden overflow-x-auto rounded-2xl border border-stone-200 bg-white lg:block">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Tickets correspondant à la recherche et aux filtres</caption>
                    <thead class="border-b border-stone-200 bg-stone-100 text-xs text-slate-600">
                        <tr>
                            @foreach(['Référence', 'Sujet', 'Statut', 'Priorité', 'Demandeur', 'Agent assigné', 'Dernière activité'] as $heading)
                                <th scope="col" class="px-4 py-4 font-semibold">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($tickets as $ticket)
                            <tr class="align-top hover:bg-stone-50">
                                <th scope="row" class="px-4 py-5 font-semibold"><a href="{{ route('tickets.show', $ticket) }}" class="whitespace-nowrap text-teal-800 hover:underline">{{ $ticket->reference }}</a></th>
                                <td class="max-w-64 px-4 py-5 break-words"><a href="{{ route('tickets.show', $ticket) }}" class="font-medium hover:text-teal-800">{{ $ticket->title }}</a></td>
                                <td class="px-4 py-5"><x-ticket-badge :value="$ticket->status" /></td>
                                <td class="px-4 py-5"><x-ticket-badge :value="$ticket->priority" /></td>
                                <td class="max-w-44 px-4 py-5 break-words">{{ $ticket->requester_name }}</td>
                                <td class="px-4 py-5 text-slate-600">{{ $ticket->assignee?->name ?? 'Non assigné' }}</td>
                                <td class="px-4 py-5 text-xs leading-relaxed text-slate-500"><x-ticket-time :value="$ticket->last_activity_at ?? $ticket->created_at" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="grid gap-4 md:grid-cols-2 lg:hidden">
                @foreach($tickets as $ticket)
                    <article class="card min-w-0">
                        <a href="{{ route('tickets.show', $ticket) }}" class="block">
                            <span class="text-xs font-bold text-teal-800">{{ $ticket->reference }}</span>
                            <h3 class="mt-2 font-semibold break-words">{{ $ticket->title }}</h3>
                        </a>
                        <div class="mt-3 flex flex-wrap gap-2"><x-ticket-badge :value="$ticket->status" /><x-ticket-badge :value="$ticket->priority" /></div>
                        <dl class="mt-4 space-y-2 text-sm">
                            <div class="flex justify-between gap-4"><dt class="shrink-0 text-slate-500">Demandeur</dt><dd class="min-w-0 text-right break-words">{{ $ticket->requester_name }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="shrink-0 text-slate-500">Agent</dt><dd class="min-w-0 text-right break-words">{{ $ticket->assignee?->name ?? 'Non assigné' }}</dd></div>
                            <div class="border-t border-stone-100 pt-3 text-xs text-slate-500"><dt class="mb-1">Dernière activité</dt><dd><x-ticket-time :value="$ticket->last_activity_at ?? $ticket->created_at" /></dd></div>
                        </dl>
                    </article>
                @endforeach
            </div>
        @endif
        @include('tickets.partials.pagination')
    </section>
</x-layout>

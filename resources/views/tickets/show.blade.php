<x-layout :title="$ticket->reference">
    <a href="{{ route('tickets.index') }}" class="text-sm font-semibold text-teal-800 hover:underline">← Tous les tickets</a>
    <div class="mt-6 flex flex-wrap items-start justify-between gap-5">
        <div class="w-full min-w-0 sm:w-auto sm:flex-1">
            <p class="eyebrow">{{ $ticket->reference }}</p>
            <h1 class="mt-2 text-3xl leading-tight font-semibold tracking-tight break-words">{{ $ticket->title }}</h1>
            <div class="mt-4 flex flex-wrap gap-2"><x-ticket-badge :value="$ticket->status" /><x-ticket-badge :value="$ticket->priority" /></div>
        </div>
        @can('update', $ticket)<a href="{{ route('tickets.edit', $ticket) }}" class="button-secondary">Modifier les informations</a>@endcan
    </div>
    <div class="mt-6"><x-form-feedback /></div>
    <div class="grid items-start gap-6 lg:grid-cols-3">
            <section aria-labelledby="description-title" class="card min-w-0 lg:col-span-2">
                <h2 id="description-title" class="text-lg font-semibold">La demande</h2>
                <p class="mt-4 text-sm leading-7 break-words whitespace-pre-wrap">{{ $ticket->description }}</p>
            </section>
        <aside class="min-w-0 space-y-6 lg:col-start-3 lg:row-start-1 lg:row-span-3" aria-label="Informations et actions du ticket">
            <section aria-labelledby="info-title" class="card">
                <h2 id="info-title" class="text-lg font-semibold">Informations</h2>
                <dl class="mt-5 space-y-4 text-sm">
                    <div><dt class="text-xs text-slate-500">Demandeur</dt><dd class="mt-1 font-medium break-words">{{ $ticket->requester_name }}</dd><dd class="mt-1 text-slate-600 break-all">{{ $ticket->requester_email }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Créé par</dt><dd class="mt-1">{{ $ticket->creator->name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Agent assigné</dt><dd class="mt-1">{{ $ticket->assignee?->name ?? 'Non assigné' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Création</dt><dd class="mt-1"><x-ticket-time :value="$ticket->created_at" /></dd></div>
                    <div><dt class="text-xs text-slate-500">Dernière activité</dt><dd class="mt-1"><x-ticket-time :value="$ticket->activities->last()?->created_at ?? $ticket->created_at" /></dd></div>
                    @if($ticket->resolved_at)<div><dt class="text-xs text-slate-500">Résolution</dt><dd class="mt-1"><x-ticket-time :value="$ticket->resolved_at" /></dd></div>@endif
                    @if($ticket->closed_at)<div><dt class="text-xs text-slate-500">Fermeture</dt><dd class="mt-1"><x-ticket-time :value="$ticket->closed_at" /></dd></div>@endif
                </dl>
            </section>
            @can('assign', [$ticket, auth()->id()])
                <section aria-labelledby="assignment-title" class="card">
                    <h2 id="assignment-title" class="mb-5 text-lg font-semibold">Assignation</h2>
                    @include('tickets.partials.assignment')
                </section>
            @endcan
            @if($transitions !== [])
                <section aria-labelledby="status-title" class="card">
                    <h2 id="status-title" class="mb-5 text-lg font-semibold">Faire avancer le ticket</h2>
                    @include('tickets.partials.status')
                </section>
            @else
                <p class="px-2 text-sm leading-relaxed text-slate-500">Aucun changement de statut disponible avec votre accès et l’assignation actuelle.</p>
            @endif
        </aside>
            <section aria-labelledby="comments-title" class="card min-w-0 lg:col-span-2">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="comments-title" class="text-lg font-semibold">Commentaires internes</h2>
                    <span class="text-sm text-slate-500">{{ $ticket->comments->count() }}</span>
                </div>
                <ol class="mt-6 space-y-5">
                    @forelse($ticket->comments as $comment)
                        <li id="comment-{{ $comment->id }}" class="rounded-xl border border-stone-200 bg-stone-50 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <span class="font-semibold text-slate-800">{{ $comment->author->name }}</span>
                                <span class="text-slate-500"><x-ticket-time :value="$comment->created_at" /></span>
                            </div>
                            <p class="mt-3 text-sm leading-relaxed break-words whitespace-pre-wrap">{{ $comment->body }}</p>
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">Aucun commentaire pour le moment.</li>
                    @endforelse
                </ol>
                @can('comment', $ticket)<div class="mt-6 border-t border-stone-200 pt-6">@include('tickets.partials.comment')</div>@endcan
            </section>
            <section aria-labelledby="history-title" class="card min-w-0 lg:col-span-2">
                <h2 id="history-title" class="text-lg font-semibold">Chronologie des activités</h2>
                <p class="mt-2 text-xs text-slate-500">Les événements sont conservés dans leur ordre de création. Heures de Paris.</p>
                <ol class="mt-6 space-y-6 border-l border-stone-200 pl-5">
                    @forelse($ticket->activities as $activity)
                        <li class="relative">
                            <span aria-hidden="true" class="absolute top-1 -left-6.5 h-3 w-3 rounded-full border-2 border-white bg-teal-700"></span>
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="text-sm font-semibold">{{ $activity->type->label() }}</p>
                                <span class="text-xs text-slate-500"><x-ticket-time :value="$activity->created_at" /></span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Par {{ $activity->actor->name }}</p>
                            @if($activity->reason)<p class="mt-3 rounded-lg bg-stone-50 p-3 text-sm break-words whitespace-pre-wrap">{{ $activity->reason }}</p>@endif
                            @if($activity->type === \App\Enums\TicketActivityType::CommentAdded)
                                <a href="#comment-{{ $activity->changes['comment_id']['to'] }}" class="mt-3 inline-block text-xs font-semibold text-teal-800 hover:underline">Voir le commentaire</a>
                            @else
                                <details class="mt-3 text-sm">
                                    <summary class="cursor-pointer font-medium text-teal-800">Voir les changements</summary>
                                    <dl class="mt-3 space-y-3 rounded-xl bg-stone-50 p-4">
                                        @foreach($activity->changes as $field => $change)
                                            <div>
                                                <dt class="text-xs font-semibold text-slate-600">{{ \App\Actions\Tickets\TicketInput::attributes()[$field] ?? match($field) { 'resolved_at' => 'Date de résolution', 'closed_at' => 'Date de fermeture', default => $field } }}</dt>
                                                <dd class="mt-1 text-xs leading-relaxed">
                                                    <span class="font-semibold">Avant : </span><x-activity-value :field="$field" :value="$change['from']" :users="$historyUsers" /><br>
                                                    <span class="font-semibold">Après : </span><x-activity-value :field="$field" :value="$change['to']" :users="$historyUsers" />
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </details>
                            @endif
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">Aucune activité enregistrée.</li>
                    @endforelse
                </ol>
            </section>
    </div>
</x-layout>

<x-layout :title="'Conflit · '.$ticket->reference">
    <a href="{{ route('tickets.show', $ticket) }}" target="_blank" rel="noopener" class="text-sm font-semibold text-teal-800 hover:underline">Ouvrir le ticket actuel (nouvel onglet) ↗</a>
    <div role="alert" class="mt-6 rounded-2xl border border-amber-300 bg-amber-50 p-6">
        <p class="text-xs font-bold tracking-widest text-amber-900 uppercase">Conflit de version · 409</p>
        <h1 class="mt-3 text-2xl font-semibold text-amber-950">Le ticket a changé. Votre saisie est conservée.</h1>
        <p class="mt-3 text-sm leading-relaxed text-amber-900">Votre demande n’a pas été enregistrée : ce ticket a changé ou la base est temporairement occupée. Comparez les informations ci-dessous. Toute nouvelle tentative demande votre confirmation et utilise la version actuelle.</p>
        <p class="mt-2 text-xs text-amber-900">Version observée : {{ $observedVersion }} · Version actuelle : {{ $ticket->version }}</p>
    </div>
    <div class="mt-6 grid items-start gap-6 lg:grid-cols-2">
        <section class="card min-w-0" aria-labelledby="draft-title">
            <h2 id="draft-title" class="text-lg font-semibold">Votre saisie non enregistrée</h2>
            <dl class="mt-5 space-y-4 text-sm">
                @foreach($draft as $field => $value)
                    <div>
                        <dt class="text-xs font-semibold text-slate-500">{{ \App\Actions\Tickets\TicketInput::attributes()[$field] ?? $field }}</dt>
                        <dd class="mt-1 break-words whitespace-pre-wrap"><x-activity-value :field="$field" :value="$value" :users="$agents->keyBy('id')" /></dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-5 text-xs leading-relaxed text-slate-500">Vous pouvez copier ces valeurs. Elles restent visibles ici même si votre autorisation a changé.</p>
        </section>
        <section class="card min-w-0" aria-labelledby="current-title">
            <h2 id="current-title" class="text-lg font-semibold">Valeurs actuelles · {{ $ticket->reference }}</h2>
            <dl class="mt-5 space-y-4 text-sm">
                @foreach($ticket->only(['title', 'description', 'requester_name', 'requester_email', 'assigned_to']) + ['status' => $ticket->status->value, 'priority' => $ticket->priority->value] as $field => $value)
                    <div><dt class="text-xs font-semibold text-slate-500">{{ \App\Actions\Tickets\TicketInput::attributes()[$field] }}</dt><dd class="mt-1 break-words whitespace-pre-wrap"><x-activity-value :field="$field" :value="$value" :users="$agents->keyBy('id')" /></dd></div>
                @endforeach
            </dl>
            @if($operation === 'tickets.comment')
                <h3 class="mt-6 font-semibold">Commentaires actuels</h3>
                @foreach($ticket->comments as $comment)<p class="mt-3 text-sm break-words whitespace-pre-wrap">{{ $comment->author->name }} : {{ $comment->body }}</p>@endforeach
            @endif
        </section>
    </div>
    <section class="card mt-6 max-w-3xl" aria-labelledby="retry-title">
        <h2 id="retry-title" class="mb-5 text-lg font-semibold">Reprendre après vérification</h2>
        @if($operation === 'tickets.update' && auth()->user()->can('update', $ticket))
            <form method="POST" action="{{ route('tickets.update', $ticket) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="version" value="{{ $ticket->version }}">
                <x-ticket-fields :values="$draft" />
                <button class="button mt-6">Enregistrer après vérification</button>
            </form>
        @elseif($operation === 'tickets.comment' && auth()->user()->can('comment', $ticket))
            @include('tickets.partials.comment')
        @elseif($operation === 'tickets.assign' && auth()->user()->can('assign', [$ticket, auth()->id()]))
            @include('tickets.partials.assignment')
        @elseif($operation === 'tickets.change-status' && $transitions !== [])
            @include('tickets.partials.status')
        @else
            <p class="text-sm text-slate-600">Cette action n’est plus disponible avec l’état ou l’assignation actuels. Votre saisie reste consultable ci-dessus.</p>
        @endif
    </section>
</x-layout>

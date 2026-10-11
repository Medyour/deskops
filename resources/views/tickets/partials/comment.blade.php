@can('comment', $ticket)
    <form method="POST" action="{{ route('tickets.comment', $ticket) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="version" value="{{ isset($draft) ? $ticket->version : old('version', $ticket->version) }}">
        <x-ticket-field name="body" label="Ajouter un commentaire interne" type="textarea" :value="$draft['body'] ?? ''" required rows="4" maxlength="5000" hint="Visible par l’équipe uniquement. Le commentaire sera conservé sans possibilité d’édition ni de suppression." />
        <button class="button">{{ isset($draft) ? 'Ajouter après vérification' : 'Ajouter le commentaire' }}</button>
    </form>
@endcan

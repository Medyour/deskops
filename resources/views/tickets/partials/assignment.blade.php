@can('assign', [$ticket, auth()->id()])
    <form method="POST" action="{{ route('tickets.assign', $ticket) }}" class="space-y-4">
        @csrf
        @method('PATCH')
        <input type="hidden" name="version" value="{{ isset($draft) ? $ticket->version : old('version', $ticket->version) }}">
        @if(auth()->user()->isAdministrator())
            @php($selectedAssignee = old('assigned_to', isset($draft) ? $draft['assigned_to'] : $ticket->assigned_to))
            <div>
                <label for="assigned_to" class="block text-sm font-semibold">Agent assigné</label>
                <select id="assigned_to" name="assigned_to" class="field" aria-describedby="assignment-hint{{ $errors->has('assigned_to') ? ' assigned_to-error' : '' }}" @if($errors->has('assigned_to')) aria-invalid="true" @endif>
                    @if($allowsRelease)<option value="" @selected((string) $selectedAssignee === '')>Non assigné</option>@endif
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" @selected((string) $selectedAssignee === (string) $agent->id)>{{ $agent->name }}</option>
                    @endforeach
                </select>
                <p id="assignment-hint" class="mt-2 text-xs leading-relaxed text-slate-500">{{ $allowsRelease ? 'Choisissez un agent ou laissez le ticket non assigné.' : 'L’assignation reste obligatoire à ce statut.' }}</p>
                @error('assigned_to')<p id="assigned_to-error" class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
            </div>
            <button class="button-secondary w-full">{{ isset($draft) ? 'Confirmer l’assignation après vérification' : 'Enregistrer l’assignation' }}</button>
        @else
            <input type="hidden" name="assigned_to" value="{{ auth()->id() }}">
            <p class="text-sm text-slate-600">Prenez cette demande pour commencer à la traiter.</p>
            <button class="button w-full">{{ isset($draft) ? 'Prendre le ticket après vérification' : 'Prendre ce ticket' }}</button>
        @endif
    </form>
@endcan

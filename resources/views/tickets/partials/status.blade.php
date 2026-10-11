@if($transitions !== [])
    <form method="POST" action="{{ route('tickets.change-status', $ticket) }}" class="space-y-4">
        @csrf
        @method('PATCH')
        <input type="hidden" name="version" value="{{ isset($draft) ? $ticket->version : old('version', $ticket->version) }}">
        <div>
            <label for="status" class="block text-sm font-semibold">Nouveau statut</label>
            <select id="status" name="status" class="field" required aria-describedby="status-hint{{ $errors->has('status') ? ' status-error' : '' }}" @if($errors->has('status')) aria-invalid="true" @endif>
                <option value="">Choisir une transition</option>
                @foreach($transitions as $target)
                    <option value="{{ $target->value }}" @selected(old('status', $draft['status'] ?? '') === $target->value)>{{ $target->label() }}{{ $ticket->status->requiresReasonFor($target) ? ' · motif requis' : '' }}</option>
                @endforeach
            </select>
            <p id="status-hint" class="mt-2 text-xs text-slate-500">Seules les transitions autorisées sont proposées.</p>
            @error('status')<p id="status-error" class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
        </div>
        <x-ticket-field name="reason" label="Motif ou résumé de résolution" type="textarea" :value="$draft['reason'] ?? ''" rows="3" maxlength="500" hint="Obligatoire pour les options « motif requis » : 5 à 500 caractères. Sinon, facultatif." />
        <button class="button-secondary w-full">{{ isset($draft) ? 'Confirmer le statut après vérification' : 'Changer le statut' }}</button>
    </form>
@endif

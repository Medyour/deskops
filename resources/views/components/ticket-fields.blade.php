@props(['values' => []])
<div class="space-y-6">
    <p class="text-xs text-slate-500">Toutes les informations ci-dessous sont obligatoires.</p>
    <x-ticket-field name="title" label="Sujet" :value="$values['title'] ?? ''" required minlength="5" maxlength="160" hint="Un sujet précis, de 5 à 160 caractères." autocomplete="off" />
    <x-ticket-field name="description" label="Description" type="textarea" :value="$values['description'] ?? ''" required minlength="10" maxlength="10000" rows="7" hint="Décrivez la demande en texte brut (10 à 10 000 caractères)." />
    <div class="grid gap-6 sm:grid-cols-2">
        <x-ticket-field name="requester_name" label="Nom du demandeur" :value="$values['requester_name'] ?? ''" required minlength="2" maxlength="120" autocomplete="off" hint="Le contact client à l’origine de la demande." />
        <x-ticket-field name="requester_email" label="E-mail du demandeur" type="email" :value="$values['requester_email'] ?? ''" required maxlength="254" autocomplete="off" hint="Adresse de contact. Aucun e-mail ne sera envoyé." />
    </div>
    <div>
        <label for="priority" class="block text-sm font-semibold">Priorité</label>
        <select id="priority" name="priority" class="field sm:max-w-xs" required aria-describedby="priority-hint{{ $errors->has('priority') ? ' priority-error' : '' }}" @if($errors->has('priority')) aria-invalid="true" @endif>
            @foreach(\App\Enums\TicketPriority::cases() as $priority)
                <option value="{{ $priority->value }}" @selected(old('priority', $values['priority'] ?? 'normal') === $priority->value)>{{ $priority->label() }}</option>
            @endforeach
        </select>
        <p id="priority-hint" class="mt-2 text-xs text-slate-500">Définit l’ordre de traitement de la demande.</p>
        @error('priority')<p id="priority-error" class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
    </div>
</div>

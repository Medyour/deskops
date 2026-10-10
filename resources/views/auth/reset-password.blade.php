<x-auth-layout title="Un nouveau départ.">
    <h2 class="mb-6 text-xl font-semibold">Choisir un nouveau mot de passe</h2>
    <x-form-feedback />
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email" class="text-sm font-medium">Adresse e-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username" class="field" @error('email') aria-invalid="true" aria-describedby="form-errors" @enderror>
        </div>
        <div>
            <label for="password" class="text-sm font-medium">Nouveau mot de passe</label>
            <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" class="field" aria-describedby="password-help @error('password') form-errors @enderror" @error('password') aria-invalid="true" @enderror>
            <p id="password-help" class="mt-2 text-xs text-slate-500">Au moins 12 caractères, avec majuscules, minuscules et chiffres.</p>
        </div>
        <div>
            <label for="password_confirmation" class="text-sm font-medium">Confirmer le mot de passe</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password" class="field">
        </div>
        <button class="button w-full">Enregistrer le mot de passe</button>
    </form>
</x-auth-layout>

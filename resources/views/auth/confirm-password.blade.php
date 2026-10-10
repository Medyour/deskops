<x-auth-layout title="Confirmer votre identité.">
    <h2 class="mb-6 text-xl font-semibold">Confirmation du mot de passe</h2>
    <x-form-feedback />
    <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5">
        @csrf
        <div>
            <label for="password" class="text-sm font-medium">Mot de passe actuel</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="current-password" class="field" @error('password') aria-invalid="true" aria-describedby="form-errors" @enderror>
        </div>
        <button class="button w-full">Confirmer</button>
    </form>
</x-auth-layout>

<x-auth-layout title="Retrouver votre accès.">
    <h2 class="text-xl font-semibold">Réinitialiser le mot de passe</h2>
    <p class="mt-3 mb-6 text-sm leading-relaxed text-slate-600">Saisissez l’adresse de votre compte pour recevoir un lien de réinitialisation.</p>
    <x-form-feedback />
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf
        <div>
            <label for="email" class="text-sm font-medium">Adresse e-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="field" @error('email') aria-invalid="true" aria-describedby="form-errors" @enderror>
        </div>
        <button class="button w-full">Demander un lien</button>
    </form>
    <a href="{{ route('login') }}" class="mt-6 inline-block text-sm font-medium text-teal-800 hover:underline">Retour à la connexion</a>
</x-auth-layout>

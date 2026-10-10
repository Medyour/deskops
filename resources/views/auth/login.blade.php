<x-auth-layout title="Ravi de vous retrouver.">
    <h2 class="mb-6 text-xl font-semibold">Connexion à DeskOps</h2>
    <x-form-feedback />
    <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
        @csrf
        <div>
            <label for="email" class="text-sm font-medium">Adresse e-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="field" @error('email') aria-invalid="true" aria-describedby="form-errors" @enderror>
        </div>
        <div>
            <label for="password" class="text-sm font-medium">Mot de passe</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="field" @error('password') aria-invalid="true" aria-describedby="form-errors" @enderror>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <label class="flex items-center gap-2"><input type="checkbox" name="remember" value="1" @checked(old('remember')) class="h-4 w-4 accent-teal-800">Se souvenir de moi</label>
            <a href="{{ route('password.request') }}" class="font-medium text-teal-800 hover:underline">Mot de passe oublié ?</a>
        </div>
        <button class="button w-full">Se connecter</button>
    </form>
    <p class="mt-6 text-xs leading-relaxed text-slate-500">Pas d’inscription publique. Votre accès est fourni par votre équipe.</p>
</x-auth-layout>

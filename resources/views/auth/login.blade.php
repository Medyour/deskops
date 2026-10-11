<x-auth-layout :title="config('app.demo_mode') ? 'Explorez DeskOps.' : 'Ravi de vous retrouver.'">
    <h2 class="mb-6 text-xl font-semibold">Connexion à DeskOps</h2>
    <x-form-feedback />
    @if(config('app.demo_mode'))
        <div class="mb-8 space-y-3" aria-label="Explorer la démonstration">
            <p class="text-sm leading-relaxed text-slate-600">Choisissez un rôle pour explorer le support, sans compte ni mot de passe.</p>
            <form method="POST" action="{{ route('demo.login') }}">
                @csrf
                <input type="hidden" name="role" value="administrator">
                <button class="button w-full">Explorer comme administrateur</button>
            </form>
            <form method="POST" action="{{ route('demo.login') }}">
                @csrf
                <input type="hidden" name="role" value="agent">
                <button class="button-secondary w-full">Explorer comme agent</button>
            </form>
        </div>
        <details class="border-t border-stone-200 pt-5">
            <summary class="cursor-pointer text-sm font-medium text-slate-600">Accès de l’équipe</summary>
            <div class="pt-5">
    @endif
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
            @unless(config('app.demo_mode'))
                <a href="{{ route('password.request') }}" class="font-medium text-teal-800 hover:underline">Mot de passe oublié ?</a>
            @endunless
        </div>
        <button class="button w-full">Se connecter</button>
    </form>
    @if(config('app.demo_mode'))
            </div>
        </details>
    @endif
    <p class="mt-6 text-xs leading-relaxed text-slate-500">{{ config('app.demo_mode') ? 'Les deux rôles partagent la même démonstration. Vos modifications sont temporaires.' : 'Pas d’inscription publique. Votre accès est fourni par votre équipe.' }}</p>
</x-auth-layout>

@props(['title' => 'Support client'])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="DeskOps, un espace simple pour organiser le support client.">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-4">Aller au contenu</a>
    <header class="border-b border-stone-200 bg-white">
        <nav aria-label="Navigation principale" class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-5">
            <a href="{{ route('home') }}" class="flex items-center gap-3 font-bold tracking-tight">
                <span aria-hidden="true" class="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-800 text-sm text-white">D</span>
                <span class="text-xl">DeskOps<span class="text-teal-700">.</span></span>
            </a>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                @auth
                    <a href="{{ route('tickets.index') }}" @if(request()->routeIs('tickets.*')) aria-current="page" @endif class="font-medium hover:text-teal-800">Tickets</a>
                    <a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif class="font-medium hover:text-teal-800">Mon espace</a>
                    @can('access-administration')
                        <a href="{{ route('admin') }}" @if(request()->routeIs('admin')) aria-current="page" @endif class="font-medium hover:text-teal-800">Administration</a>
                    @endcan
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-lg border border-stone-300 px-3 py-2 font-medium hover:bg-stone-100">Déconnexion</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="font-semibold text-teal-800 hover:underline">Se connecter <span aria-hidden="true">↗</span></a>
                @endauth
            </div>
        </nav>
    </header>
    <main id="main" tabindex="-1" class="mx-auto max-w-6xl px-6 py-12 sm:py-16">
        {{ $slot }}
    </main>
    <footer class="mx-auto flex max-w-6xl flex-wrap justify-between gap-3 border-t border-stone-200 px-6 py-6 text-xs text-slate-500">
        <span>DeskOps · Le support, avec méthode.</span>
        <span>Démonstration locale · Espace support</span>
    </footer>
</body>
</html>

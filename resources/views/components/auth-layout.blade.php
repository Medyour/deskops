@props(['title'])
<x-layout :title="$title">
    <div class="grid items-start gap-12 py-4 lg:grid-cols-2 lg:gap-24">
        <div class="max-w-lg lg:pt-8">
            <p class="mb-6 text-xs font-bold tracking-widest text-teal-800 uppercase">{{ config('app.demo_mode') ? 'Démonstration interactive' : 'Espace équipe' }}</p>
            <h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">{{ $title }}</h1>
            <p class="mt-6 text-lg leading-relaxed text-slate-600">Un espace commun pour suivre chaque demande et garder le fil de la relation client.</p>
            <div class="mt-10 border-l-2 border-teal-700 pl-5 text-sm leading-relaxed text-slate-600">
                {{ config('app.demo_mode') ? 'Explorez les rôles administrateur et agent avec les mêmes données fictives, sans mot de passe.' : 'L’accès est réservé aux administrateurs et aux agents de l’équipe support.' }}
            </div>
        </div>
        <div class="card w-full max-w-lg shadow-sm">
            {{ $slot }}
        </div>
    </div>
</x-layout>

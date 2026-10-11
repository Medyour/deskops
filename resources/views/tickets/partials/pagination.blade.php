@if($tickets->hasPages())
    <nav aria-label="Pagination des tickets" class="mt-6 flex flex-wrap items-center justify-between gap-4 border-t border-stone-200 pt-6 text-sm">
        <p class="text-slate-600">Page {{ $tickets->currentPage() }} sur {{ $tickets->lastPage() }} · {{ $tickets->firstItem() }}–{{ $tickets->lastItem() }} sur {{ $tickets->total() }}</p>
        <div class="flex gap-3">
            @if($tickets->onFirstPage())
                <span aria-disabled="true" class="button-secondary opacity-50">← Précédente</span>
            @else
                <a href="{{ $tickets->previousPageUrl() }}" rel="prev" class="button-secondary">← Précédente</a>
            @endif
            @if($tickets->hasMorePages())
                <a href="{{ $tickets->nextPageUrl() }}" rel="next" class="button-secondary">Suivante →</a>
            @else
                <span aria-disabled="true" class="button-secondary opacity-50">Suivante →</span>
            @endif
        </div>
    </nav>
@endif

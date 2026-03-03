@php
    if (! isset($scrollTo)) {
        $scrollTo = '#customers-table';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}'))?.scrollIntoView({ behavior: 'smooth', block: 'start' })
        JS
        : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Navegacion de paginacion" class="space-y-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-600">
                        <i class="fas fa-list-ol text-sm"></i>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-600">
                            Resultados
                        </p>
                        <p class="mt-1 text-sm font-medium leading-6 text-gray-700">
                            Mostrando
                            <span class="font-semibold text-gray-900">{{ $paginator->firstItem() }}</span>
                            a
                            <span class="font-semibold text-gray-900">{{ $paginator->lastItem() }}</span>
                            de
                            <span class="font-semibold text-gray-900">{{ $paginator->total() }}</span>
                            {{ $paginator->total() === 1 ? 'cliente' : 'clientes' }}
                        </p>
                    </div>
                </div>

                <div
                    class="rounded-2xl border border-emerald-100 bg-white px-4 py-3 text-center shadow-sm lg:min-w-[220px] lg:text-right">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">
                        Pagina actual
                    </p>
                    <p class="mt-1 text-sm font-semibold text-gray-900">
                        Pagina {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}
                    </p>
                </div>
            </div>

            <div class="space-y-3 sm:hidden">
                <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-center">
                    <p class="text-xs font-medium text-gray-500">
                        Usa los controles para navegar entre paginas.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    @if ($paginator->onFirstPage())
                        <span
                            class="inline-flex items-center justify-center rounded-2xl border border-gray-200 bg-gray-100 px-4 py-3 text-sm font-semibold text-gray-400">
                            <i class="fas fa-chevron-left mr-2 text-xs"></i>
                            Anterior
                        </span>
                    @else
                        <button
                            type="button"
                            wire:click="previousPage('{{ $paginator->getPageName() }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center justify-center rounded-2xl border border-emerald-200 bg-white px-4 py-3 text-sm font-semibold text-emerald-700 transition-colors hover:border-emerald-300 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-60">
                            <i class="fas fa-chevron-left mr-2 text-xs"></i>
                            Anterior
                        </button>
                    @endif

                    @if ($paginator->hasMorePages())
                        <button
                            type="button"
                            wire:click="nextPage('{{ $paginator->getPageName() }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center justify-center rounded-2xl border border-emerald-600 bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">
                            Siguiente
                            <i class="fas fa-chevron-right ml-2 text-xs"></i>
                        </button>
                    @else
                        <span
                            class="inline-flex items-center justify-center rounded-2xl border border-gray-200 bg-gray-100 px-4 py-3 text-sm font-semibold text-gray-400">
                            Siguiente
                            <i class="fas fa-chevron-right ml-2 text-xs"></i>
                        </span>
                    @endif
                </div>
            </div>

            <div class="hidden sm:block">
                <div class="overflow-x-auto pb-1">
                    <div class="flex min-w-max items-center justify-center gap-2">
                        @if ($paginator->onFirstPage())
                            <span
                                class="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-400">
                                <i class="fas fa-chevron-left text-xs"></i>
                                Anterior
                            </span>
                        @else
                            <button
                                type="button"
                                wire:click="previousPage('{{ $paginator->getPageName() }}')"
                                x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                wire:loading.attr="disabled"
                                dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after"
                                rel="prev"
                                class="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                                aria-label="{{ __('pagination.previous') }}">
                                <i class="fas fa-chevron-left text-xs"></i>
                                Anterior
                            </button>
                        @endif

                        @foreach ($elements as $element)
                            @if (is_string($element))
                                <span
                                    class="inline-flex min-w-[2.75rem] items-center justify-center rounded-2xl border border-gray-200 bg-white px-3 py-2.5 text-sm font-semibold text-gray-400">
                                    {{ $element }}
                                </span>
                            @endif

                            @if (is_array($element))
                                @foreach ($element as $page => $url)
                                    <span wire:key="paginator-{{ $paginator->getPageName() }}-page-{{ $page }}">
                                        @if ($page == $paginator->currentPage())
                                            <span aria-current="page">
                                                <span
                                                    class="inline-flex min-w-[2.75rem] items-center justify-center rounded-2xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm">
                                                    {{ $page }}
                                                </span>
                                            </span>
                                        @else
                                            <button
                                                type="button"
                                                wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                                x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                                wire:loading.attr="disabled"
                                                class="inline-flex min-w-[2.75rem] items-center justify-center rounded-2xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                                {{ $page }}
                                            </button>
                                        @endif
                                    </span>
                                @endforeach
                            @endif
                        @endforeach

                        @if ($paginator->hasMorePages())
                            <button
                                type="button"
                                wire:click="nextPage('{{ $paginator->getPageName() }}')"
                                x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                wire:loading.attr="disabled"
                                dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after"
                                rel="next"
                                class="inline-flex items-center gap-2 rounded-2xl border border-emerald-600 bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                                aria-label="{{ __('pagination.next') }}">
                                Siguiente
                                <i class="fas fa-chevron-right text-xs"></i>
                            </button>
                        @else
                            <span
                                class="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-400">
                                Siguiente
                                <i class="fas fa-chevron-right text-xs"></i>
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </nav>
    @endif
</div>

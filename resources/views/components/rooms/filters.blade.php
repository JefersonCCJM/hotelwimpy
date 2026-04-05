{{-- Room Manager Filters and Calendar Component --}}
<div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
    <div class="space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label
                    class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Buscar</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400 text-sm"></i>
                    </div>
                    <input type="text" autocomplete="off" wire:model.live.debounce.300ms="search"
                        class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                        placeholder="Número o Camas...">
                </div>
            </div>

            <div>
                <label
                    class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Estado</label>
                <div class="relative">
                    <select wire:model.live="status"
                        class="block w-full pl-3 pr-10 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent appearance-none bg-white">
                        <option value="">Todos los estados</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                        <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Calendar Block -->
        <div class="pt-4 border-t border-gray-100">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-center">
                <div class="lg:col-span-3 space-y-2">
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">MES DE
                        CONSULTA</label>
                    <div class="flex items-center bg-gray-50 border border-gray-200 rounded-xl p-1">
                        <button wire:click="changeDate('{{ $currentDate->copy()->subMonth()->format('Y-m-d') }}')"
                            class="p-2 hover:bg-white hover:shadow-sm rounded-lg transition-all text-gray-400">
                            <i class="fas fa-chevron-left text-xs"></i>
                        </button>
                        <span
                            class="flex-1 text-center text-xs font-bold text-gray-700 uppercase tracking-tighter">{{ $currentDate->translatedFormat('F Y') }}</span>
                        <button wire:click="changeDate('{{ $currentDate->copy()->addMonth()->format('Y-m-d') }}')"
                            class="p-2 hover:bg-white hover:shadow-sm rounded-lg transition-all text-gray-400">
                            <i class="fas fa-chevron-right text-xs"></i>
                        </button>
                    </div>
                </div>

                <div class="lg:col-span-9 space-y-2">
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">DÍAS DEL
                        MES</label>
                    <div class="overflow-x-auto pb-2 custom-scrollbar">
                        <div class="flex space-x-2 min-w-max">
                            @foreach ($daysInMonth as $day)
                                @php
                                    $isCurrent = $day->isSameDay($currentDate);
                                    $isToday = $day->isToday();
                                @endphp
                                <button type="button" wire:click="changeDate('{{ $day->format('Y-m-d') }}')"
                                    class="flex flex-col items-center justify-center min-w-[42px] h-14 rounded-xl transition-all border
                                    {{ $isCurrent ? 'bg-blue-600 border-blue-600 text-white shadow-md' : 'bg-gray-50 border-gray-100 text-gray-400 hover:border-blue-200 hover:text-blue-600' }}">
                                    <span
                                        class="text-[8px] font-bold uppercase tracking-tighter">{{ substr($day->translatedFormat('D'), 0, 1) }}</span>
                                    <span class="text-sm font-bold mt-0.5">{{ $day->day }}</span>
                                    @if ($isToday && !$isCurrent)
                                        <span class="w-1 h-1 bg-blue-500 rounded-full mt-1"></span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


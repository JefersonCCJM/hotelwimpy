@section('title', 'Gastos (Caja)')
@section('header', 'Gastos (Caja)')

<div class="space-y-6" wire:poll.5s x-data="{ confirmingDelete: false, deleteId: null }">
    <!-- 1. BLOQUE HEADER -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center space-x-3 sm:space-x-4">
                <div class="p-2.5 sm:p-3 rounded-xl bg-rose-50 text-rose-600">
                    <i class="fas fa-money-bill-wave text-lg sm:text-xl"></i>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Gastos (Caja)</h1>
                    <p class="text-xs sm:text-sm text-gray-500 mt-1">
                        Registre gastos pagados desde la caja (ventas + base). Esto afecta el cuadre del turno cuando
                        hay turno activo.
                    </p>
                </div>
            </div>

            <button type="button" wire:click="openCreateModal"
                class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-rose-600 bg-rose-600 text-white text-sm font-semibold hover:bg-rose-700 hover:border-rose-700 transition-all duration-200 shadow-sm hover:shadow-md">
                <i class="fas fa-plus mr-2"></i>
                <span>Registrar Gasto</span>
            </button>
        </div>
    </div>

    @if (session()->has('success'))
        <div
            class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl flex items-center shadow-sm animate-in fade-in slide-in-from-top-2">
            <i class="fas fa-check-circle mr-2 text-emerald-500"></i>
            <span class="text-sm font-medium">{{ session('success') }}</span>
        </div>
    @endif

    @if (session()->has('error'))
        <div
            class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl flex items-center shadow-sm animate-in fade-in slide-in-from-top-2">
            <i class="fas fa-exclamation-circle mr-2 text-rose-500"></i>
            <span class="text-sm font-medium">{{ session('error') }}</span>
        </div>
    @endif

    <!-- 2. BLOQUE FILTROS -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 shadow-sm">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Fecha -->
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2 ml-1">Filtrar por
                    Fecha</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-calendar text-gray-400 text-sm"></i>
                    </div>
                    <input type="date" wire:model.live="date" max="{{ date('Y-m-d') }}"
                        class="block w-full pl-10 pr-3 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:bg-white transition-all">
                </div>
            </div>

            <!-- Buscar por Motivo -->
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2 ml-1">Buscar por
                    Motivo</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400 text-sm"></i>
                    </div>
                    <input type="text" wire:model.live.debounce.300ms="search" autocomplete="off"
                        class="block w-full pl-10 pr-3 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:bg-white transition-all"
                        placeholder="Ej: Pago de luz, insumos...">
                </div>
            </div>
        </div>
    </div>

    <!-- 3. TABLA DE REGISTROS -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        @if ($outflows->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50/50">
                        <tr>
                            <th
                                class="px-4 py-3.5 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">
                                Fecha/Hora</th>
                            <th
                                class="px-4 py-3.5 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">
                                Recepcionista</th>
                            <th
                                class="px-4 py-3.5 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">
                                Motivo</th>
                            <th
                                class="px-4 py-3.5 text-right text-[10px] font-black text-gray-500 uppercase tracking-widest">
                                Monto</th>
                            @if (($isAdmin ?? false) || (($activeShiftId ?? null) && ($activeShiftStatus ?? '') === 'activo'))
                                <th
                                    class="px-4 py-3.5 text-right text-[10px] font-black text-gray-500 uppercase tracking-widest">
                                    Acciones</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-50">
                        @foreach ($outflows as $outflow)
                            <tr class="hover:bg-gray-50/50 transition-colors duration-150 group">
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm font-bold text-gray-900">{{ $outflow->updated_at->format('d/m/Y') }}
                                    </div>
                                    <div class="text-[10px] text-gray-400 font-bold uppercase tracking-tighter">
                                        {{ $outflow->updated_at->format('H:i') }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div
                                            class="w-7 h-7 rounded-lg bg-gray-100 flex items-center justify-center text-gray-500 mr-2 text-[10px] font-bold">
                                            {{ substr($outflow->shiftHandover->receptionist_name ?? $outflow->user->name, 0, 2) }}
                                        </div>
                                        <div class="text-sm font-medium text-gray-700">{{ $outflow->shiftHandover->receptionist_name ?? $outflow->user->name }}</div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="text-sm text-gray-600 leading-relaxed">{{ $outflow->reason }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right">
                                    <div class="text-sm font-black text-rose-600">
                                        -${{ number_format($outflow->amount, 0, ',', '.') }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right">
                                    <button type="button"
                                        @click="confirmingDelete = true; deleteId = {{ $outflow->id }}"
                                        class="p-2 text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-all">
                                        <i class="fas fa-trash-alt text-sm"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-4 border-t border-gray-100 bg-gray-50/30">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-gray-500">
                        Mostrando <span class="font-bold text-gray-900">{{ $outflows->firstItem() }}</span> a <span
                            class="font-bold text-gray-900">{{ $outflows->lastItem() }}</span> de <span
                            class="font-bold text-gray-900">{{ $outflows->total() }}</span> registros
                    </div>
                    <div class="bg-rose-50 px-4 py-2 rounded-xl border border-rose-100">
                        <span class="text-[10px] font-black text-rose-600 uppercase tracking-widest mr-2">Total
                            Salidas:</span>
                        <span
                            class="text-sm font-black text-rose-700">-${{ number_format($totalOutflows, 0, ',', '.') }}</span>
                    </div>
                </div>
                <div class="mt-4">
                    {{ $outflows->links() }}
                </div>
            </div>
        @else
            <div class="p-16 text-center">
                <div
                    class="inline-flex items-center justify-center w-16 h-16 rounded-3xl bg-gray-50 mb-4 text-gray-300">
                    <i class="fas fa-receipt text-2xl"></i>
                </div>
                <h3 class="text-sm font-black text-gray-900 uppercase tracking-widest">No hay salidas registradas</h3>
                <p class="text-xs text-gray-400 mt-1">No se encontraron egresos para los filtros seleccionados</p>
            </div>
        @endif
    </div>

    <!-- MODAL DE CREACIÓN -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-[100] overflow-y-auto" aria-labelledby="modal-title" role="dialog"
            aria-modal="true">
            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity"
                    wire:click="$set('showCreateModal', false)"></div>

                <div
                    class="relative transform overflow-hidden rounded-[2.5rem] bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg animate-in zoom-in-95 duration-200">
                    <form wire:submit.prevent="saveOutflow">
                        <div class="bg-white px-6 pt-8 pb-6 sm:p-10">
                            <div class="flex items-center space-x-4 mb-8">
                                <div class="p-3 rounded-2xl bg-rose-50 text-rose-600">
                                    <i class="fas fa-money-bill-wave text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-xl font-black text-gray-900 uppercase tracking-widest">Nueva Salida
                                    </h3>
                                    <p class="text-xs text-gray-500 font-medium">Registre un gasto o egreso de caja</p>
                                </div>
                            </div>

                            <div class="space-y-6">
                                <!-- Monto -->
                                <div>
                                    <label
                                        class="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2 ml-1">Monto
                                        de la Salida <span class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <div
                                            class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                                            <span class="font-bold text-sm">$</span>
                                        </div>
                                        <input type="text" wire:model.live.debounce.500ms="amount"
                                            oninput="maskCurrency(event)"
                                            class="block w-full pl-8 pr-4 py-4 bg-gray-50 border border-gray-200 rounded-2xl text-lg font-black text-gray-900 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:bg-white transition-all @error('amount') border-rose-300 @enderror"
                                            placeholder="0">
                                    </div>
                                    @error('amount')
                                        <p
                                            class="mt-2 text-[10px] text-rose-600 font-bold uppercase ml-1 tracking-tighter">
                                            {{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- Fecha -->
                                <div>
                                    <label
                                        class="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2 ml-1">Fecha
                                        de la Salida <span class="text-rose-500">*</span></label>
                                    <input type="date" wire:model="outflow_date" max="{{ date('Y-m-d') }}"
                                        class="block w-full px-4 py-4 bg-gray-50 border border-gray-200 rounded-2xl text-sm font-bold text-gray-900 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:bg-white transition-all @error('outflow_date') border-rose-300 @enderror">
                                    @error('outflow_date')
                                        <p
                                            class="mt-2 text-[10px] text-rose-600 font-bold uppercase ml-1 tracking-tighter">
                                            {{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- Motivo -->
                                <div>
                                    <label
                                        class="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2 ml-1">Motivo
                                        / Razón <span class="text-rose-500">*</span></label>
                                    <textarea wire:model="reason" rows="3"
                                        class="block w-full px-4 py-4 bg-gray-50 border border-gray-200 rounded-2xl text-sm font-medium text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:bg-white transition-all @error('reason') border-rose-300 @enderror"
                                        placeholder="Describa el motivo del gasto..."></textarea>
                                    @error('reason')
                                        <p
                                            class="mt-2 text-[10px] text-rose-600 font-bold uppercase ml-1 tracking-tighter">
                                            {{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div
                            class="bg-gray-50 px-6 py-6 sm:px-10 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                            <button type="button" wire:click="$set('showCreateModal', false)"
                                class="inline-flex justify-center px-6 py-3.5 text-xs font-bold uppercase tracking-widest text-gray-500 hover:text-gray-700 transition-all">
                                Cancelar
                            </button>
                            <button type="submit"
                                class="inline-flex justify-center rounded-2xl bg-rose-600 px-8 py-3.5 text-xs font-bold uppercase tracking-widest text-white shadow-xl shadow-rose-600/20 hover:bg-rose-700 transition-all active:scale-[0.98]">
                                <i class="fas fa-save mr-2"></i> Registrar Salida
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <!-- MODAL DE ELIMINACIÓN -->
    <div x-show="confirmingDelete" class="fixed inset-0 z-[110] overflow-y-auto" x-cloak>
        <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity"
                @click="confirmingDelete = false"></div>

            <div
                class="relative transform overflow-hidden rounded-[2.5rem] bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-md animate-in zoom-in-95 duration-200">
                <div class="bg-white px-6 pt-8 pb-6 sm:p-10 text-center">
                    <div
                        class="w-16 h-16 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-exclamation-triangle text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-black text-gray-900 uppercase tracking-widest mb-2">¿Eliminar Registro?
                    </h3>
                    <p class="text-sm text-gray-500 font-medium">Esta acción eliminará permanentemente esta salida de
                        dinero. ¿Estás seguro?</p>
                </div>
                <div class="bg-gray-50 px-6 py-6 flex flex-col sm:flex-row justify-center gap-3">
                    <button type="button" @click="confirmingDelete = false"
                        class="inline-flex justify-center px-6 py-3.5 text-xs font-bold uppercase tracking-widest text-gray-500 hover:text-gray-700">
                        Cancelar
                    </button>
                    <button type="button" wire:click="deleteOutflow(deleteId); confirmingDelete = false"
                        class="inline-flex justify-center rounded-2xl bg-rose-600 px-8 py-3.5 text-xs font-bold uppercase tracking-widest text-white shadow-xl shadow-rose-600/20 hover:bg-rose-700 transition-all">
                        Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

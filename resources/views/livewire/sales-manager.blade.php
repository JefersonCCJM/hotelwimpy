<div class="space-y-6" wire:poll.5s x-data="{ 
    filtersOpen: @entangle('filtersOpen'),
    receptionistId: @entangle('receptionist_id'),
    isAdmin: {{ Auth::user()->hasRole('Administrador') ? 'true' : 'false' }},
    showSaleModalOpen: @entangle('showSaleModalOpen'),
    editSaleModalOpen: @entangle('editSaleModalOpen'),
    confirmingDelete: false,
    deleteFormAction: ''
}">
    <!-- 1. BLOQUE HEADER -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center space-x-3 sm:space-x-4">
                <div class="p-2.5 sm:p-3 rounded-xl bg-green-50 text-green-600">
                    <i class="fas fa-shopping-cart text-lg sm:text-xl"></i>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Gestión de Ventas</h1>
                    <div class="flex items-center space-x-2 mt-1">
                        <span class="text-xs sm:text-sm text-gray-500">
                            <span class="font-semibold text-gray-900">{{ $totalSales }}</span> ventas del día
                        </span>
                        <span class="text-gray-300 hidden sm:inline">•</span>
                        <span class="text-xs sm:text-sm text-gray-500 hidden sm:inline">
                            <i class="fas fa-calendar-day mr-1"></i> {{ $currentDate->translatedFormat('d/m/Y') }}
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="flex items-center space-x-3">
                @can('create_sales')
                <a href="#quick-sale-section"
                   class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-green-600 bg-green-600 text-white text-sm font-semibold hover:bg-green-700 hover:border-green-700 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 shadow-sm hover:shadow-md">
                    <i class="fas fa-plus mr-2"></i>
                    <span>Registrar Venta</span>
                </a>
                @endcan
                
                @can('update_products')
                <livewire:update-product-prices />
                @endcan
            </div>
        </div>
    </div>

    <!-- 2. BLOQUE FILTROS Y CALENDARIO -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Buscar -->
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Buscar</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400 text-sm"></i>
                        </div>
                        <input type="text" 
                               wire:model.live.debounce.300ms="search"
                               class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all"
                               placeholder="Recepcionista, habitación...">
                    </div>
                </div>
                
                <!-- Estado de Deuda -->
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Estado de Deuda</label>
                    <div class="relative">
                        <select wire:model.live="debt_status"
                                class="block w-full pl-3 pr-10 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent appearance-none bg-white">
                            <option value="">Todos los estados</option>
                            <option value="pagado">Pagado</option>
                            <option value="pendiente">Pendiente</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Recepcionista -->
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Recepcionista</label>
                    <div class="relative">
                        <select wire:model.live="receptionist_id"
                                class="block w-full pl-3 pr-10 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent appearance-none bg-white">
                            <option value="">Todos</option>
                            @foreach($receptionists as $receptionist)
                                <option value="{{ $receptionist->id }}">
                                    {{ $receptionist->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BLOQUE CALENDARIO -->
            <div class="pt-4 border-t border-gray-100">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-center">
                    <div class="lg:col-span-3 space-y-2">
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">MES DE CONSULTA</label>
                        <div class="flex items-center bg-gray-50 border border-gray-200 rounded-xl p-1">
                            <button type="button" 
                                    wire:click="changeDate('{{ $currentDate->copy()->subMonth()->format('Y-m-d') }}')"
                                    class="p-2 hover:bg-white hover:shadow-sm rounded-lg transition-all text-gray-400">
                                <i class="fas fa-chevron-left text-xs"></i>
                            </button>
                            <span class="flex-1 text-center text-xs font-bold text-gray-700 uppercase tracking-tighter">{{ $currentDate->translatedFormat('F Y') }}</span>
                            @php
                                $isFutureMonth = $currentDate->copy()->startOfMonth()->addMonth()->isFuture();
                            @endphp
                            <button type="button" 
                                    @if(!$isFutureMonth) wire:click="changeDate('{{ $currentDate->copy()->addMonth()->format('Y-m-d') }}')" @endif
                                    @disabled($isFutureMonth)
                                    class="p-2 {{ $isFutureMonth ? 'text-gray-200 cursor-not-allowed' : 'hover:bg-white hover:shadow-sm text-gray-400' }} rounded-lg transition-all">
                                <i class="fas fa-chevron-right text-xs"></i>
                            </button>
                        </div>
                    </div>

                    <div class="lg:col-span-9 space-y-2">
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">DÍAS DEL MES</label>
                        <div class="overflow-x-auto pb-2 custom-scrollbar">
                            <div class="flex space-x-2 min-w-max">
                                @foreach($daysInMonth as $day)
                                    @php 
                                        $isCurrent = $day->isSameDay($currentDate);
                                        $isToday = $day->isToday();
                                        $isFuture = $day->isFuture();
                                        $dayKey = $day->format('Y-m-d');
                                        $daySalesCount = $salesByDay[$dayKey] ?? 0;
                                    @endphp
                                    <button type="button" 
                                            @if(!$isFuture) wire:click="changeDate('{{ $dayKey }}')" @endif
                                            @disabled($isFuture)
                                            class="flex flex-col items-center justify-center min-w-[42px] h-14 rounded-xl transition-all border
                                            {{ $isCurrent ? 'bg-green-600 border-green-600 text-white shadow-md' : '' }}
                                            {{ !$isCurrent && !$isFuture ? 'bg-gray-50 border-gray-100 text-gray-400 hover:border-green-200 hover:text-green-600' : '' }}
                                            {{ $isFuture ? 'bg-gray-50 border-gray-50 text-gray-200 cursor-not-allowed opacity-50' : '' }}">
                                        <span class="text-[8px] font-bold uppercase tracking-tighter">{{ substr($day->translatedFormat('D'), 0, 1) }}</span>
                                        <span class="text-sm font-bold mt-0.5">{{ $day->day }}</span>
                                        @if($isToday && !$isCurrent)
                                            <span class="w-1 h-1 bg-green-500 rounded-full mt-1"></span>
                                        @elseif($daySalesCount > 0 && !$isCurrent)
                                            <span class="w-1 h-1 bg-green-400 rounded-full mt-1"></span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FILTROS ADICIONALES -->
            <div class="pt-4 border-t border-gray-100">
                <div class="flex items-center justify-between mb-4">
                    <button type="button" 
                            @click="filtersOpen = !filtersOpen"
                            class="flex items-center text-sm font-semibold text-gray-900 hover:text-green-600 transition-colors">
                        <i class="fas fa-filter text-green-600 mr-2"></i>
                        <span x-text="filtersOpen ? 'Ocultar Filtros Avanzados' : 'Mostrar Filtros Avanzados'"></span>
                    </button>
                    <div class="flex gap-2">
                        <a href="{{ route('sales.byRoom', ['date' => $selectedDate]) }}" 
                           class="inline-flex items-center justify-center px-4 py-2 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 text-sm font-medium hover:bg-blue-100 transition-all duration-200">
                            <i class="fas fa-bed mr-2"></i>
                            <span class="hidden sm:inline">Por Habitación</span>
                        </a>
                        <a href="{{ route('sales.reports') }}" 
                           class="inline-flex items-center justify-center px-4 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-sm font-medium hover:bg-gray-50 transition-all duration-200">
                            <i class="fas fa-chart-bar mr-2"></i>
                            <span class="hidden sm:inline">Reportes</span>
                        </a>
                    </div>
                </div>
                
                <div x-show="filtersOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     x-cloak>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <!-- Método de Pago -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Método de Pago</label>
                            <div class="relative">
                                <select wire:model.live="payment_method"
                                        class="block w-full pl-3 pr-10 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent appearance-none bg-white">
                                    <option value="">Todos</option>
                                    <option value="efectivo">Efectivo</option>
                                    <option value="transferencia">Transferencia</option>
                                    <option value="ambos">Ambos</option>
                                    <option value="pendiente">Pendiente</option>
                                </select>
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Categoría -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Categoría</label>
                            <div class="relative">
                                <select wire:model.live="category_id"
                                        class="block w-full pl-3 pr-10 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent appearance-none bg-white">
                                    <option value="">Todas</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}">
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Habitación -->
<div>
                            <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Habitación</label>
                            <div class="relative">
                                <select wire:model.live="room_id"
                                        class="block w-full pl-3 pr-10 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent appearance-none bg-white">
                                    <option value="">Todas</option>
                                    <option value="normal">Personas Corrientes</option>
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}">
                                            Hab. {{ $room->room_number }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Botones de Acción -->
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-2 pt-4 border-t border-gray-100 mt-4">
                        <button type="button"
                                wire:click="clearFilters"
                                class="inline-flex items-center justify-center px-4 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-sm font-medium hover:bg-gray-50 transition-all duration-200">
                            <i class="fas fa-times mr-2"></i>
                            Limpiar Filtros
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. BLOQUE REGISTRO RAPIDO DE VENTA -->
    @can('create_sales')
    <div id="quick-sale-section" class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="p-2.5 rounded-xl bg-green-50 text-green-600">
                <i class="fas fa-cash-register text-lg"></i>
            </div>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-gray-900">Registro Rápido de Venta</h2>
                <p class="text-xs text-gray-500">Registra ventas sin abrir modal.</p>
            </div>
        </div>
        <livewire:create-sale :is-modal="false" :wire:key="'sales-inline-create-'.$selectedDate" />
    </div>
    @endcan

    <!-- 4. BLOQUE INGRESOS EXTERNOS -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-xl bg-emerald-50 text-emerald-600">
                    <i class="fas fa-hand-holding-usd text-lg"></i>
                </div>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-gray-900">Ingresos Externos a Base</h2>
                    <p class="text-xs text-gray-500">
                        Total del día: <span class="font-bold text-gray-900">${{ number_format($externalIncomesTotal ?? 0, 0, ',', '.') }}</span>
                    </p>
                </div>
            </div>
        </div>

        @can('create_sales')
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 mb-5">
            <div class="lg:col-span-2">
                <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Monto</label>
                <input type="number"
                       wire:model.defer="externalIncomeForm.amount"
                       min="0.01"
                       step="0.01"
                       class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                       placeholder="0">
                @error('externalIncomeForm.amount') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="lg:col-span-2">
                <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Metodo</label>
                <select wire:model.defer="externalIncomeForm.payment_method"
                        class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent bg-white">
                    <option value="efectivo">Efectivo</option>
                    <option value="transferencia">Transferencia</option>
                </select>
                @error('externalIncomeForm.payment_method') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="lg:col-span-5">
                <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Motivo</label>
                <input type="text"
                       wire:model.defer="externalIncomeForm.reason"
                       maxlength="180"
                       class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                       placeholder="Ejemplo: Ingreso por ajuste de caja">
                @error('externalIncomeForm.reason') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="lg:col-span-3">
                <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Notas (opcional)</label>
                <div class="flex gap-2">
                    <input type="text"
                           wire:model.defer="externalIncomeForm.notes"
                           maxlength="2000"
                           class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                           placeholder="Detalle adicional">
                    <button type="button"
                            wire:click="registerExternalIncome"
                            class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-all">
                        <i class="fas fa-save mr-2"></i>Guardar
                    </button>
                </div>
                @error('externalIncomeForm.notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
        @endcan

        <div class="overflow-x-auto border border-gray-100 rounded-xl">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50/70">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Hora</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Recepcionista</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Motivo</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Metodo</th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Monto</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-50">
                    @forelse(($externalIncomes ?? collect()) as $income)
                        <tr class="hover:bg-gray-50/50 transition-colors">
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $income->created_at?->format('H:i') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $income->user->name ?? 'N/A' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">
                                <div class="font-medium text-gray-900">{{ $income->reason }}</div>
                                @if(!empty($income->notes))
                                    <div class="text-xs text-gray-500">{{ $income->notes }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium {{ $income->payment_method === 'efectivo' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-blue-50 text-blue-700 border border-blue-100' }}">
                                    {{ ucfirst($income->payment_method) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-sm font-bold text-gray-900">${{ number_format($income->amount ?? 0, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">
                                No hay ingresos externos registrados para esta fecha.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tabla de Ventas -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        @if($sales->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50/50">
                        <tr>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-alt text-gray-400 mr-2 text-xs"></i>
                                    Fecha
                                </div>
                            </th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-user text-gray-400 mr-2 text-xs"></i>
                                    Recepcionista
                                </div>
                            </th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-bed text-gray-400 mr-2 text-xs"></i>
                                    Habitación
                                </div>
                            </th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-box text-gray-400 mr-2 text-xs"></i>
                                    Productos
                                </div>
                            </th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-dollar-sign text-gray-400 mr-2 text-xs"></i>
                                    Total
                                </div>
                            </th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-money-bill-wave text-gray-400 mr-2 text-xs"></i>
                                    Método de Pago
                                </div>
                            </th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                <div class="flex items-center">
                                    <i class="fas fa-file-invoice-dollar text-gray-400 mr-2 text-xs"></i>
                                    Estado
                                </div>
                            </th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                <div class="flex items-center justify-end">
                                    <i class="fas fa-cog text-gray-400 mr-2 text-xs"></i>
                                    Acciones
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-50">
                        @foreach($sales as $sale)
                            <tr class="hover:bg-gray-50/50 transition-colors duration-150 group">
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $sale->sale_date->format('d/m/Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $sale->created_at?->format('H:i') }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ $sale->user->name }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    @if($sale->room)
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-blue-50 text-blue-700 border border-blue-100">
                                            <i class="fas fa-bed mr-1.5 text-xs"></i>
                                            Hab. {{ $sale->room->room_number }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-gray-50 text-gray-500 border border-gray-100">
                                            <i class="fas fa-user mr-1.5 text-xs"></i>
                                            Normal
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <div class="space-y-1.5">
                                        <div class="text-xs font-medium text-gray-700">
                                            {{ $sale->items->count() }} producto(s)
                                        </div>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($sale->items as $item)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-medium bg-gray-100 text-gray-600 border border-gray-200">
                                                    {{ $item->product->name }} ({{ $item->quantity }})
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm font-bold text-gray-900">{{ formatCurrency($sale->total) }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    @if($sale->payment_method === 'ambos')
                                        <div class="space-y-1">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-purple-50 text-purple-700 border border-purple-100">
                                                <i class="fas fa-exchange-alt mr-1.5 text-xs"></i>
                                                Ambos
                                            </span>
                                            <div class="text-xs text-gray-600 space-y-0.5">
                                                <div class="flex items-center">
                                                    <i class="fas fa-money-bill-wave text-green-500 mr-1 text-xs"></i>
                                                    {{ formatCurrency($sale->cash_amount ?? 0) }}
                                                </div>
                                                <div class="flex items-center">
                                                    <i class="fas fa-university text-blue-500 mr-1 text-xs"></i>
                                                    {{ formatCurrency($sale->transfer_amount ?? 0) }}
                                                </div>
                                            </div>
                                        </div>
                                    @elseif($sale->payment_method === 'pendiente')
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-orange-50 text-orange-700 border border-orange-100">
                                            <i class="fas fa-clock mr-1.5 text-xs"></i>
                                            Pendiente
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium {{ $sale->payment_method === 'efectivo' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-blue-50 text-blue-700 border border-blue-100' }}">
                                            <i class="fas fa-{{ $sale->payment_method === 'efectivo' ? 'money-bill-wave' : 'university' }} mr-1.5 text-xs"></i>
                                            {{ ucfirst($sale->payment_method) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    @if($sale->debt_status === 'pagado')
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-green-50 text-green-700 border border-green-100">
                                            <i class="fas fa-check-circle mr-1.5 text-xs"></i>
                                            Pagado
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-red-50 text-red-700 border border-red-100">
                                            <i class="fas fa-exclamation-circle mr-1.5 text-xs"></i>
                                            Pendiente
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end space-x-1.5">
                                        @can('edit_sales')
                                        @if($sale->debt_status === 'pendiente')
                                        <div class="flex items-center gap-1.5 mr-1">
                                            <select wire:model="paymentMethodSelection.{{ $sale->id }}"
                                                    class="px-2 py-1.5 border border-gray-300 rounded-lg text-xs text-gray-800 bg-white focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                                <option value="efectivo">Efectivo</option>
                                                <option value="transferencia">Transferencia</option>
                                            </select>
                                            <button type="button"
                                                    wire:click="markSaleAsPaid({{ $sale->id }})"
                                                    class="inline-flex items-center px-2.5 py-1.5 rounded-lg bg-green-600 text-white text-xs font-semibold hover:bg-green-700 transition-colors"
                                                    title="Marcar como pagada">
                                                <i class="fas fa-check mr-1"></i>Pagar
                                            </button>
                                        </div>
                                        @endif
                                        @endcan

                                        <button type="button"
                                           wire:click="openShowSaleModal({{ $sale->id }})"
                                           class="p-2 text-blue-600 hover:text-blue-700 hover:bg-blue-50 rounded-lg transition-all duration-200" 
                                           title="Ver detalle">
                                            <i class="fas fa-eye text-sm"></i>
                                        </button>
                                        @can('edit_sales')
                                        <button type="button"
                                           wire:click="openEditSaleModal({{ $sale->id }})"
                                           class="p-2 text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 rounded-lg transition-all duration-200" 
                                           title="Editar">
                                            <i class="fas fa-edit text-sm"></i>
                                        </button>
                                        @endcan
                                        @can('delete_sales')
                                        <button type="button" 
                                                @click="confirmingDelete = true; deleteFormAction = '{{ route('sales.destroy', $sale) }}'"
                                                class="p-2 text-red-600 hover:text-red-700 hover:bg-red-50 rounded-lg transition-all duration-200" 
                                                title="Eliminar">
                                            <i class="fas fa-trash text-sm"></i>
                                        </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            
            <!-- Paginación -->
            <div class="px-4 py-4 border-t border-gray-100 bg-gray-50/50">
                {{ $sales->links() }}
            </div>
        @else
            <div class="p-16 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 mb-4">
                    <i class="fas fa-shopping-cart text-2xl text-gray-400"></i>
                </div>
                <h3 class="text-sm font-semibold text-gray-900 mb-1">No se encontraron ventas</h3>
                <p class="text-xs text-gray-500 mb-4">Intente ajustar los filtros de búsqueda</p>
                <a href="#quick-sale-section"
                   class="inline-flex items-center px-4 py-2 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700 transition-all duration-200">
                    <i class="fas fa-plus mr-2"></i>
                    Registrar Venta
                </a>
            </div>
        @endif
    </div>

    <!-- Modal de Confirmación Estilizado -->

    <!-- Modal Ver Venta -->
    <div x-show="showSaleModalOpen"
         class="fixed inset-0 z-[91] overflow-y-auto"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @keydown.escape.window="$wire.closeShowSaleModal()"
         x-cloak>
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="$wire.closeShowSaleModal()"></div>
        <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
            <div class="relative w-full max-w-5xl max-h-[92vh] overflow-y-auto rounded-2xl bg-gray-50 shadow-2xl border border-gray-100">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3 sm:px-6">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-eye text-blue-600"></i>
                        <h3 class="text-sm sm:text-base font-semibold text-gray-900">Detalle de Venta</h3>
                    </div>
                    <button type="button" @click="$wire.closeShowSaleModal()" class="text-gray-400 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-4 sm:p-6">
                    @if($showSaleModalOpen && $this->selectedSale)
                        <livewire:show-sale :sale="$this->selectedSale" :is-modal="true" :wire:key="'sales-show-modal-'.$selectedSaleId.'-'.$showSaleModalKey" />
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Editar Venta -->
    <div x-show="editSaleModalOpen"
         class="fixed inset-0 z-[92] overflow-y-auto"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @keydown.escape.window="$wire.closeEditSaleModal()"
         x-cloak>
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="$wire.closeEditSaleModal()"></div>
        <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
            <div class="relative w-full max-w-5xl max-h-[92vh] overflow-y-auto rounded-2xl bg-gray-50 shadow-2xl border border-gray-100">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3 sm:px-6">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-edit text-indigo-600"></i>
                        <h3 class="text-sm sm:text-base font-semibold text-gray-900">Editar Venta</h3>
                    </div>
                    <button type="button" @click="$wire.closeEditSaleModal()" class="text-gray-400 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-4 sm:p-6">
                    @if($editSaleModalOpen && $this->selectedSale)
                        <livewire:edit-sale :sale="$this->selectedSale" :is-modal="true" :wire:key="'sales-edit-modal-'.$selectedSaleId.'-'.$editSaleModalKey" />
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div x-show="confirmingDelete" 
         class="fixed inset-0 z-[100] overflow-y-auto" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         x-cloak>
        <!-- Fondo desenfocado -->
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity"></div>

        <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <div class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg border border-gray-100"
                 @click.away="confirmingDelete = false"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-50 sm:mx-0 sm:h-10 sm:w-10">
                            <i class="fas fa-exclamation-triangle text-red-600"></i>
                        </div>
                        <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                            <h3 class="text-lg font-bold leading-6 text-gray-900">¿Eliminar Venta?</h3>
                            <div class="mt-2">
                                <p class="text-sm text-gray-500">
                                    Esta acción no se puede deshacer. Los productos vendidos se **restaurarán automáticamente** al inventario.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 gap-2">
                    <form :action="deleteFormAction" method="POST" x-ref="deleteForm">
                        @csrf
                        @method('DELETE')
                        <button type="submit" 
                                class="inline-flex w-full justify-center rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-red-700 sm:ml-3 sm:w-auto transition-all">
                            Confirmar Eliminación
                        </button>
                    </form>
                    <button type="button" 
                            @click="confirmingDelete = false"
                            class="mt-3 inline-flex w-full justify-center rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto transition-all">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .custom-scrollbar::-webkit-scrollbar { 
        height: 4px; 
        width: 4px; 
    }
    .custom-scrollbar::-webkit-scrollbar-track { 
        background: transparent; 
    }
    .custom-scrollbar::-webkit-scrollbar-thumb { 
        background: #e5e7eb; 
        border-radius: 10px; 
    }
</style>
@endpush

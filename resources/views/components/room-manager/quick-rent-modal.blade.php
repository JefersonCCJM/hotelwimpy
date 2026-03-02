@props(['rentForm', 'additionalGuests', 'checkInDate'])

<div x-show="quickRentModal" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
    @if ($rentForm)
        <div class="flex items-center justify-center min-h-screen p-4">
            <div @click="quickRentModal = false" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
            <div
                class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden transform transition-all p-8 space-y-6">
                <div class="flex items-center justify-between border-b border-gray-100 pb-4">
                    <div class="flex items-center space-x-3">
                        <div
                            class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i class="fas fa-key"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900">Arrendar Hab. {{ $rentForm['room_number'] }}</h3>
                    </div>
                    <button @click="quickRentModal = false" class="text-gray-400 hover:text-gray-900"><i
                            class="fas fa-times text-xl"></i></button>
                </div>

                <div class="space-y-6">
                    <div class="space-y-4">
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between mb-1">
                                <label
                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">HUÉSPED
                                    PRINCIPAL</label>
                                <button type="button" @click="Livewire.dispatch('open-create-customer-modal')"
                                    class="text-[9px] font-bold text-blue-600 hover:text-blue-800 uppercase tracking-tighter flex items-center gap-1">
                                    <i class="fas fa-plus text-[8px]"></i>
                                    Nuevo Cliente
                                </button>
                            </div>
                            <div wire:ignore>
                                <select id="quick_customer_id"
                                    class="w-full bg-white border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-bold text-gray-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                                    data-placeholder="Seleccione un cliente">
                                    <option value="">Seleccione un cliente...</option>
                                </select>
                                <div id="no-customers-message"
                                    class="hidden text-xs text-amber-600 mt-2 flex items-center bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                    <i class="fas fa-exclamation-triangle mr-2 text-sm"></i>
                                    <span class="font-medium">No hay clientes registrados. Por favor, cree un nuevo
                                        cliente usando el botón <strong>"+ Nuevo Cliente"</strong> arriba.</span>
                                </div>
                            </div>
                            @error('rentForm.client_id')
                                <p class="text-[10px] text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <div class="flex items-center justify-between mb-1">
                                    <label
                                        class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">PERSONAS</label>
                                    <span class="text-[9px] text-gray-500 font-medium">Cap. máx:
                                        {{ $rentForm['max_capacity'] ?? 1 }}</span>
                                </div>
                                @php
                                    $additionalGuestsCount = is_array($additionalGuests) ? count($additionalGuests) : 0;
                                    $principalCount = !empty($rentForm['client_id']) ? 1 : 0;
                                    $totalPeople = $principalCount + $additionalGuestsCount;
                                    $maxCapacity = (int) ($rentForm['max_capacity'] ?? 1);
                                    $remaining = $maxCapacity - $totalPeople;
                                @endphp
                                <div
                                    class="w-full bg-gray-50 border border-gray-200 rounded-lg px-4 py-2.5 flex items-center justify-between">
                                    <div class="flex items-center space-x-2">
                                        <i class="fas fa-users text-gray-400 text-sm"></i>
                                        <span class="text-sm font-bold text-gray-900">{{ $totalPeople }}
                                            {{ $totalPeople == 1 ? 'persona' : 'personas' }}</span>
                                    </div>
                                    <span class="text-[10px] text-gray-500">/ {{ $maxCapacity }}</span>
                                </div>
                                @if ($totalPeople === 0)
                                    <p class="text-[10px] text-amber-600 mt-1 flex items-center">
                                        <i class="fas fa-exclamation-triangle mr-1 text-[8px]"></i>
                                        Debe seleccionar un cliente principal
                                    </p>
                                @elseif($totalPeople > $maxCapacity)
                                    <p class="text-[10px] text-red-600 mt-1 flex items-center">
                                        <i class="fas fa-exclamation-circle mr-1 text-[8px]"></i>
                                        Excede la capacidad máxima. Total: {{ $totalPeople }}/{{ $maxCapacity }}
                                    </p>
                                @elseif($remaining > 0)
                                    <p class="text-[10px] text-gray-500 mt-1">
                                        <i class="fas fa-info-circle mr-1 text-[8px]"></i>
                                        Puede agregar {{ $remaining }}
                                        {{ $remaining == 1 ? 'persona más' : 'personas más' }}
                                    </p>
                                @else
                                    <p class="text-[10px] text-emerald-600 mt-1">
                                        <i class="fas fa-check-circle mr-1 text-[8px]"></i>
                                        Capacidad máxima alcanzada ({{ $totalPeople }}/{{ $maxCapacity }})
                                    </p>
                                @endif
                                </div>
                            @endif
                            <div class="space-y-1.5">
                                <label
                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">CHECK-OUT</label>
                                <input type="date" wire:model.live="rentForm.check_out_date"
                                    min="{{ $checkInDate ? \Carbon\Carbon::parse($checkInDate)->addDay()->format('Y-m-d') : \App\Support\HotelTime::currentOperationalDate()->addDay()->format('Y-m-d') }}"
                                    class="w-full bg-gray-50 border border-gray-200 rounded-lg px-4 py-2.5 text-sm font-bold @error('rentForm.check_out_date') border-red-500 @enderror">
                                @error('rentForm.check_out_date')
                                    <p class="text-[10px] text-red-600 mt-1 flex items-center">
                                        <i class="fas fa-exclamation-circle mr-1 text-[8px]"></i>
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>
                        </div>

                        <!-- Huéspedes Adicionales -->
                        <div class="space-y-2 pt-2 border-t border-gray-100" x-data="{ showGuestSearch: false }">
                            <div class="flex items-center justify-between">
                                <label
                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">HUÉSPEDES
                                    ADICIONALES</label>
                                <button type="button" x-show="!showGuestSearch" @click="showGuestSearch = true"
                                    class="text-[9px] font-bold text-emerald-600 hover:text-emerald-800 uppercase tracking-tighter flex items-center gap-1">
                                    <i class="fas fa-user-plus text-[8px]"></i>
                                    Agregar
                                </button>
                            </div>

                            <!-- Selector de búsqueda -->
                            <div x-show="showGuestSearch" x-transition x-init="setTimeout(() => {
                                const event = new CustomEvent('init-additional-guest-select');
                                document.dispatchEvent(event);
                            }, 100)"
                                class="space-y-2 p-3 bg-gray-50 rounded-lg border border-gray-200" x-cloak>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-[10px] font-bold text-gray-700 uppercase tracking-widest">Buscar
                                        Cliente</label>
                                    <button type="button"
                                        @click="showGuestSearch = false; if (typeof window.additionalGuestSelect !== 'undefined' && window.additionalGuestSelect) { window.additionalGuestSelect.destroy(); window.additionalGuestSelect = null; }"
                                        class="text-gray-400 hover:text-gray-600">
                                        <i class="fas fa-times text-xs"></i>
                                    </button>
                                </div>
                                <div wire:ignore>
                                    <select id="additional_guest_customer_id" class="w-full"></select>
                                </div>
                                <div class="flex gap-2">
                                    <button type="button" @click="showGuestSearch = false"
                                        class="flex-1 px-3 py-1.5 text-[10px] font-bold text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50">
                                        Cancelar
                                    </button>
                                    <button type="button"
                                        @click="Livewire.dispatch('open-create-customer-modal-for-additional'); showGuestSearch = false"
                                        class="flex-1 px-3 py-1.5 text-[10px] font-bold text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100">
                                        <i class="fas fa-plus mr-1 text-[8px]"></i>
                                        Crear Nuevo
                                    </button>
                                </div>
                            </div>

                            @if (!empty($additionalGuests) && is_array($additionalGuests))
                                <div class="space-y-2 max-h-32 overflow-y-auto">
                                    @foreach ($additionalGuests as $index => $guest)
                                        <div
                                            class="flex items-center justify-between p-2 bg-gray-50 rounded-lg border border-gray-200">
                                            <div class="flex-1 min-w-0">
                                                <p class="text-xs font-bold text-gray-900 truncate"
                                                    title="{{ $guest['name'] }}">{{ $guest['name'] }}</p>
                                                <p class="text-[10px] text-gray-500 truncate"
                                                    title="ID: {{ $guest['identification'] }}">ID:
                                                    {{ $guest['identification'] }}</p>
                                            </div>
                                            <button type="button" wire:click="removeGuest({{ $index }})"
                                                class="text-red-500 hover:text-red-700 ml-2">
                                                <i class="fas fa-times text-xs"></i>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-[10px] text-gray-400 italic">No hay huéspedes adicionales registrados</p>
                            @endif
                            @error('additionalGuests')
                                <p class="text-[10px] text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- RESUMEN FINANCIERO -->
                        <div
                            class="space-y-4 p-6 bg-gradient-to-br from-gray-50 to-blue-50/30 rounded-2xl border border-gray-200">
                            <!-- Badge de Estado de Pago -->
                            @php
                                $total = (float) ($rentForm['total'] ?? 0);
                                $deposit = (float) ($rentForm['deposit'] ?? 0);
                                $balance = max(0, $total - $deposit);

                                if ($deposit >= $total && $total > 0) {
                                    $badgeText = 'Pagado';
                                    $badgeColor = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                                    $badgeIcon = 'fa-check-circle';
                                } elseif ($deposit > 0) {
                                    $badgeText = 'Pago parcial';
                                    $badgeColor = 'bg-amber-100 text-amber-700 border-amber-300';
                                    $badgeIcon = 'fa-exclamation-circle';
                                } else {
                                    $badgeText = 'Pendiente de pago';
                                    $badgeColor = 'bg-red-100 text-red-700 border-red-300';
                                    $badgeIcon = 'fa-exclamation-triangle';
                                }
                            @endphp

                            <div class="flex items-center justify-between">
                                <h4 class="text-xs font-bold text-gray-700 uppercase tracking-widest">Resumen
                                    Financiero</h4>
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full border {{ $badgeColor }} text-[10px] font-bold uppercase">
                                    <i class="fas {{ $badgeIcon }} text-[9px]"></i>
                                    {{ $badgeText }}
                                </span>
                            </div>

                            <!-- Montos -->
                            <div class="grid grid-cols-3 gap-4">
                                <div class="space-y-1">
                                    <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Total
                                        Hospedaje</p>
                                    <div class="flex items-center gap-1">
                                        <span class="text-[11px] text-gray-500">$</span>
                                        <input type="number" wire:model.live="rentForm.total" step="0.01"
                                            min="0"
                                            class="bg-transparent text-lg font-bold text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-200 rounded px-1 w-full">
                                    </div>
                                </div>

                                <div class="space-y-1">
                                    <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Abono
                                        Recibido</p>
                                    <div class="flex items-center gap-1">
                                        <span class="text-[11px] text-emerald-600">$</span>
                                        <input type="number" wire:model.live="rentForm.deposit" step="0.01"
                                            min="0" :max="$wire.rentForm.total"
                                            class="bg-transparent text-lg font-bold text-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-200 rounded px-1 w-full">
                                    </div>
                                </div>

                                <div class="space-y-1">
                                    <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Saldo
                                        Pendiente</p>
                                    <div class="flex items-center gap-1">
                                        <span
                                            class="text-[11px] {{ $balance > 0 ? 'text-red-600' : 'text-emerald-600' }}">$</span>
                                        <span
                                            class="text-lg font-bold {{ $balance > 0 ? 'text-red-600' : 'text-emerald-600' }}">
                                            {{ number_format($balance, 0, ',', '.') }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Botones Rápidos de Pago -->
                            <div class="flex gap-2 pt-2">
                                <button type="button" wire:click="setDepositFull"
                                    class="flex-1 px-3 py-2 text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg hover:bg-emerald-100 transition-colors">
                                    <i class="fas fa-check-double text-[9px] mr-1"></i>
                                    Pagar Todo
                                </button>
                                <button type="button" wire:click="setDepositHalf"
                                    class="flex-1 px-3 py-2 text-[10px] font-bold text-blue-700 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors">
                                    <i class="fas fa-percent text-[9px] mr-1"></i>
                                    Pagar 50%
                                </button>
                                <button type="button" wire:click="setDepositNone"
                                    class="flex-1 px-3 py-2 text-[10px] font-bold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                                    <i class="fas fa-times text-[9px] mr-1"></i>
                                    Sin Abono
                                </button>
                            </div>

                            <!-- Método de Pago -->
                            @if ($deposit > 0)
                                <div class="space-y-2 pt-3 border-t border-gray-200">
                                <label class="text-[10px] font-bold text-gray-600 uppercase tracking-widest">Método de
                                    Pago</label>
                                <select wire:model.live="rentForm.payment_method"
                                    class="w-full bg-white border-2 border-gray-300 rounded-lg px-4 py-2.5 text-sm font-bold text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                                    <option value="efectivo">💵 Efectivo</option>
                                    <option value="transferencia">🏦 Transferencia Bancaria</option>
                                </select>

                                <!-- Campos adicionales para Transferencia -->
                                @if ($rentForm['payment_method'] === 'transferencia')
                                    <div class="space-y-3 mt-3 p-4 bg-blue-50/50 rounded-lg border border-blue-200"
                                        x-data x-transition>
                                        <div class="space-y-1.5">
                                            <label
                                                class="text-[10px] font-bold text-blue-700 uppercase tracking-wide">Banco
                                                <span class="text-gray-400">(Opcional)</span></label>
                                            <input type="text" wire:model="rentForm.bank_name"
                                                placeholder="Ej: Bancolombia, Davivienda..."
                                                class="w-full bg-white border border-blue-200 rounded-lg px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-blue-400 focus:border-blue-400 outline-none">
                                        </div>
                                        <div class="space-y-1.5">
                                            <label
                                                class="text-[10px] font-bold text-blue-700 uppercase tracking-wide">Referencia
                                                / Comprobante <span class="text-gray-400">(Opcional)</span></label>
                                            <input type="text" wire:model="rentForm.reference"
                                                placeholder="Ej: #123456, CUS-789..."
                                                class="w-full bg-white border border-blue-200 rounded-lg px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-blue-400 focus:border-blue-400 outline-none">
                                        </div>
                                        <p class="text-[9px] text-blue-600 flex items-start gap-1.5 mt-2">
                                            <i class="fas fa-info-circle text-[10px] mt-0.5"></i>
                                            <span>Estos datos son informativos y ayudan a identificar la
                                                transacción.</span>
                                        </p>
                                    </div>
                                @endif
                                </div>
                            @endif

                            <!-- Advertencia según Balance -->
                            @if ($balance > 0)
                                <div class="flex items-start gap-3 p-4 bg-amber-50 border-2 border-amber-300 rounded-lg"
                                    x-data x-transition>
                                    <i class="fas fa-exclamation-triangle text-amber-600 text-lg mt-0.5"></i>
                                    <div class="flex-1 space-y-1">
                                        <p class="text-xs font-bold text-amber-900">Este arrendamiento quedará con
                                            saldo pendiente</p>
                                        <p class="text-[10px] text-amber-700 leading-relaxed">
                                            La habitación <strong>NO podrá liberarse</strong> hasta que se registre el
                                            pago completo del saldo pendiente
                                            (<strong>${{ number_format($balance, 0, ',', '.') }}</strong>).
                                        </p>
                                    </div>
                                </div>
                            @else
                                <div class="flex items-start gap-3 p-4 bg-emerald-50 border-2 border-emerald-300 rounded-lg"
                                    x-data x-transition>
                                    <i class="fas fa-check-circle text-emerald-600 text-lg mt-0.5"></i>
                                    <div class="flex-1">
                                        <p class="text-xs font-bold text-emerald-900">Arrendamiento totalmente pagado
                                        </p>
                                        <p class="text-[10px] text-emerald-700">La habitación podrá liberarse sin
                                            restricciones de pago.</p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Botón de Confirmación con Texto Dinámico -->
                    @php
                        // 🔐 VALIDACIÓN: Deshabilitar botón si se excede la capacidad
                        $isCapacityExceeded = $totalPeople > $maxCapacity;

                        if ($balance <= 0) {
                            $buttonText = 'Confirmar Arrendamiento (Pagado)';
                            $buttonClass = 'bg-emerald-600 hover:bg-emerald-700';
                        } elseif ($deposit > 0) {
                            $buttonText = 'Confirmar Arrendamiento (Pago Parcial)';
                            $buttonClass = 'bg-amber-600 hover:bg-amber-700';
                        } else {
                            $buttonText = 'Confirmar Arrendamiento (Queda Deuda)';
                            $buttonClass = 'bg-red-600 hover:bg-red-700';
                        }

                        // Si se excede la capacidad, deshabilitar y cambiar estilo
                        if ($isCapacityExceeded) {
                            $buttonClass = 'bg-gray-400 cursor-not-allowed';
                            $buttonText = 'No se puede confirmar (Excede capacidad)';
                        }
                    @endphp

                    <button wire:click="storeQuickRent" wire:loading.attr="disabled" wire:target="storeQuickRent"
                        @if ($isCapacityExceeded) disabled @endif
                        class="w-full {{ $buttonClass }} text-white py-4 rounded-xl text-xs font-bold uppercase tracking-widest transition-all shadow-lg disabled:opacity-50 disabled:cursor-not-allowed">
                        <span wire:loading.remove wire:target="storeQuickRent">
                            <i class="fas fa-key mr-2"></i>
                            {{ $buttonText }}
                        </span>
                        <span wire:loading wire:target="storeQuickRent" class="flex items-center justify-center">
                            <i class="fas fa-spinner fa-spin mr-2"></i>
                            Procesando...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

@props(['quickReservationForm'])

<div x-show="quickReservationModal" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
    @if ($quickReservationForm)
        <div class="flex items-center justify-center min-h-screen p-4">
            {{-- Overlay --}}
            <div @click="$wire.closeQuickReservation()" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>

            {{-- Panel --}}
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden transform transition-all">

                {{-- Header --}}
                <div class="flex items-center justify-between px-8 py-5 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-indigo-50">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center">
                            <i class="fas fa-bookmark text-sm"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Nueva Reserva</h3>
                            <p class="text-xs text-gray-500">Habitación {{ $quickReservationForm['room_number'] }}</p>
                        </div>
                    </div>
                    <button @click="$wire.closeQuickReservation()" class="text-gray-400 hover:text-gray-700 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <div class="p-8 space-y-6 max-h-[80vh] overflow-y-auto">

                    {{-- Bloque informativo: habitación + check-in bloqueados --}}
                    <div class="grid grid-cols-2 gap-4">
                        {{-- Habitación (bloqueada) --}}
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">HABITACIÓN</label>
                            <div class="flex items-center gap-2 w-full bg-gray-100 border border-gray-200 rounded-xl px-4 py-2.5">
                                <i class="fas fa-bed text-gray-400 text-sm"></i>
                                <span class="text-sm font-bold text-gray-700">{{ $quickReservationForm['room_number'] }}</span>
                                <span class="ml-auto">
                                    <i class="fas fa-lock text-gray-300 text-xs"></i>
                                </span>
                            </div>
                        </div>

                        {{-- Check-In (bloqueado) --}}
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">CHECK-IN</label>
                            <div class="flex items-center gap-2 w-full bg-gray-100 border border-gray-200 rounded-xl px-4 py-2.5">
                                <i class="fas fa-calendar-check text-gray-400 text-sm"></i>
                                <span class="text-sm font-bold text-gray-700">
                                    {{ \Carbon\Carbon::parse($quickReservationForm['check_in_date'])->translatedFormat('d M Y') }}
                                </span>
                                <span class="ml-auto">
                                    <i class="fas fa-lock text-gray-300 text-xs"></i>
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Check-Out (editable) --}}
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">
                            CHECK-OUT <span class="text-red-400">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-calendar-alt text-gray-400 text-sm"></i>
                            </div>
                            <input
                                type="date"
                                wire:model.live="quickReservationForm.check_out_date"
                                min="{{ \Carbon\Carbon::parse($quickReservationForm['check_in_date'])->addDay()->format('Y-m-d') }}"
                                class="w-full pl-9 bg-white border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-bold text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
                            >
                        </div>
                        @if (!empty($quickReservationForm['_nights']))
                            <p class="text-[10px] text-blue-600 ml-1">
                                <i class="fas fa-moon mr-1"></i>
                                {{ $quickReservationForm['_nights'] }} {{ $quickReservationForm['_nights'] == 1 ? 'noche' : 'noches' }}
                            </p>
                        @endif
                    </div>

                    {{-- Huésped principal --}}
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between mb-1">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">
                                HUÉSPED PRINCIPAL <span class="text-red-400">*</span>
                            </label>
                            <button type="button" @click="Livewire.dispatch('open-create-customer-modal')"
                                class="text-[9px] font-bold text-blue-600 hover:text-blue-800 uppercase tracking-tighter flex items-center gap-1">
                                <i class="fas fa-plus text-[8px]"></i>
                                Nuevo Cliente
                            </button>
                        </div>
                        <div wire:ignore>
                            <select
                                id="quick_reservation_customer_id"
                                class="w-full bg-white border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-bold text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
                                data-placeholder="Seleccione un cliente"
                            >
                                <option value="">Seleccione un cliente...</option>
                            </select>
                        </div>
                    </div>

                    {{-- Capacidad + Huéspedes opcionales --}}
                    <div class="grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-xl border border-gray-100">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">
                                ADULTOS <span class="text-gray-300">(Opcional)</span>
                            </label>
                            <input
                                type="number"
                                wire:model.live="quickReservationForm.adults"
                                min="0"
                                max="{{ $quickReservationForm['max_capacity'] }}"
                                placeholder="0"
                                class="w-full bg-white border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold text-gray-900 focus:ring-2 focus:ring-blue-400 focus:border-blue-400 outline-none"
                            >
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">
                                NIÑOS <span class="text-gray-300">(Opcional)</span>
                            </label>
                            <input
                                type="number"
                                wire:model.live="quickReservationForm.children"
                                min="0"
                                max="{{ $quickReservationForm['max_capacity'] }}"
                                placeholder="0"
                                class="w-full bg-white border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold text-gray-900 focus:ring-2 focus:ring-blue-400 focus:border-blue-400 outline-none"
                            >
                        </div>
                        <div class="col-span-2">
                            <p class="text-[10px] text-gray-400">
                                <i class="fas fa-users mr-1"></i>
                                Cap. máx. habitación: <strong>{{ $quickReservationForm['max_capacity'] }}</strong> personas
                            </p>
                        </div>
                    </div>

                    {{-- Notas --}}
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">
                            NOTAS <span class="text-gray-300">(Opcional)</span>
                        </label>
                        <textarea
                            wire:model="quickReservationForm.notes"
                            rows="2"
                            placeholder="Observaciones de la reserva..."
                            class="w-full bg-white border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-blue-400 focus:border-blue-400 outline-none resize-none"
                        ></textarea>
                    </div>

                    {{-- ══════════════ RESUMEN DE COBRO ══════════════ --}}
                    <div class="bg-[#1e293b] rounded-2xl p-6 space-y-5 border border-slate-700/50">
                        @php
                            $resTotal   = (float) ($quickReservationForm['total']   ?? 0);
                            $resDeposit = (float) ($quickReservationForm['deposit'] ?? 0);
                            $resBalance = max(0, $resTotal - $resDeposit);
                            $resMethod  = $quickReservationForm['payment_method'] ?? 'efectivo';

                            if ($resDeposit >= $resTotal && $resTotal > 0) {
                                $resBadgeText  = 'Liquidado';
                                $resBadgeClass = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
                            } elseif ($resDeposit > 0) {
                                $resBadgeText  = 'Pago parcial';
                                $resBadgeClass = 'bg-amber-500/10 text-amber-400 border-amber-500/20';
                            } else {
                                $resBadgeText  = 'Pendiente';
                                $resBadgeClass = 'bg-rose-500/10 text-rose-400 border-rose-500/20';
                            }
                        @endphp

                        {{-- Título resumen --}}
                        <div class="flex items-center justify-between">
                            <h4 class="text-base font-bold text-white tracking-tight">Resumen de Cobro</h4>
                            <span class="text-[10px] font-black uppercase tracking-widest px-3 py-1 rounded-full border {{ $resBadgeClass }}">
                                {{ $resBadgeText }}
                            </span>
                        </div>

                        {{-- Total estancia --}}
                        <div class="bg-[#0f172a] border border-slate-700 rounded-2xl p-4 text-center">
                            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-widest mb-2">Total Estancia</p>
                            <div class="relative mx-auto max-w-xs flex items-center justify-center gap-1">
                                <span class="text-slate-500 font-bold text-lg">$</span>
                                <input
                                    type="number"
                                    wire:model.live="quickReservationForm.total"
                                    step="1"
                                    min="0"
                                    class="w-full bg-slate-800 border border-slate-700 rounded-xl text-2xl font-bold tracking-tighter text-white text-center focus:ring-2 focus:ring-blue-500 outline-none px-3 py-2"
                                >
                            </div>
                        </div>

                        {{-- Abono + Saldo --}}
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Abono Inicial</p>
                                <div class="flex items-center gap-1">
                                    <span class="text-slate-400 text-sm">$</span>
                                    <input
                                        type="number"
                                        wire:model.live="quickReservationForm.deposit"
                                        step="1"
                                        min="0"
                                        class="w-full bg-slate-800 border border-slate-700 rounded-xl font-bold text-emerald-400 text-center focus:ring-2 focus:ring-emerald-500 outline-none px-3 py-2 text-sm"
                                    >
                                </div>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Saldo Pendiente</p>
                                <div class="flex items-center gap-1 bg-slate-800 border border-slate-700 rounded-xl px-3 py-2">
                                    <span class="text-slate-400 text-sm">$</span>
                                    <span class="text-sm font-bold {{ $resBalance > 0 ? 'text-amber-300' : 'text-emerald-400' }}">
                                        {{ number_format($resBalance, 0, ',', '.') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {{-- Botones rápidos de pago --}}
                        <div class="flex gap-2">
                            <button type="button" wire:click="setQuickReservationDepositFull"
                                class="flex-1 px-3 py-2 text-[10px] font-bold text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/20 transition-colors">
                                <i class="fas fa-check-double text-[9px] mr-1"></i>
                                Pagar Todo
                            </button>
                            <button type="button" wire:click="setQuickReservationDepositHalf"
                                class="flex-1 px-3 py-2 text-[10px] font-bold text-blue-400 bg-blue-500/10 border border-blue-500/20 rounded-lg hover:bg-blue-500/20 transition-colors">
                                <i class="fas fa-percent text-[9px] mr-1"></i>
                                Pagar 50%
                            </button>
                            <button type="button" wire:click="setQuickReservationDepositNone"
                                class="flex-1 px-3 py-2 text-[10px] font-bold text-slate-400 bg-slate-700/50 border border-slate-600 rounded-lg hover:bg-slate-700 transition-colors">
                                <i class="fas fa-times text-[9px] mr-1"></i>
                                Sin Abono
                            </button>
                        </div>

                        {{-- Método de pago (solo si hay abono) --}}
                        @if ($resDeposit > 0)
                            <div class="space-y-2 pt-3 border-t border-slate-700">
                                <label class="text-[10px] font-bold text-slate-300 uppercase tracking-widest">Método de Pago del Abono</label>
                                <select wire:model.live="quickReservationForm.payment_method"
                                    class="w-full bg-slate-800 border border-slate-700 rounded-xl px-4 py-2.5 text-sm font-bold text-slate-100 focus:ring-2 focus:ring-blue-500 outline-none">
                                    <option value="efectivo">💵 Efectivo</option>
                                    <option value="transferencia">🏦 Transferencia Bancaria</option>
                                </select>

                                @if ($resMethod === 'transferencia')
                                    <div class="space-y-3 mt-2 p-4 bg-blue-500/5 rounded-lg border border-blue-500/20" x-data x-transition>
                                        <div class="space-y-1.5">
                                            <label class="text-[10px] font-bold text-blue-400 uppercase tracking-wide">
                                                Banco <span class="text-slate-500">(Opcional)</span>
                                            </label>
                                            <input type="text" wire:model="quickReservationForm.bank_name"
                                                placeholder="Ej: Bancolombia, Davivienda..."
                                                class="w-full bg-slate-800 border border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder-slate-500 focus:ring-2 focus:ring-blue-400 outline-none">
                                        </div>
                                        <div class="space-y-1.5">
                                            <label class="text-[10px] font-bold text-blue-400 uppercase tracking-wide">
                                                Referencia <span class="text-slate-500">(Opcional)</span>
                                            </label>
                                            <input type="text" wire:model="quickReservationForm.reference"
                                                placeholder="Ej: #123456, CUS-789..."
                                                class="w-full bg-slate-800 border border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder-slate-500 focus:ring-2 focus:ring-blue-400 outline-none">
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- Advertencia de saldo --}}
                        @if ($resBalance > 0)
                            <div class="flex items-start gap-3 p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl">
                                <i class="fas fa-exclamation-triangle text-amber-400 mt-0.5"></i>
                                <p class="text-xs text-amber-300 leading-relaxed">
                                    La reserva quedará con saldo pendiente de
                                    <strong>${{ number_format($resBalance, 0, ',', '.') }}</strong>.
                                    El pago completo se requerirá al momento del check-in.
                                </p>
                            </div>
                        @else
                            <div class="flex items-start gap-3 p-3 bg-emerald-500/10 border border-emerald-500/20 rounded-xl">
                                <i class="fas fa-check-circle text-emerald-400 mt-0.5"></i>
                                <p class="text-xs text-emerald-300">Reserva totalmente pagada al confirmar.</p>
                            </div>
                        @endif
                    </div>

                    {{-- Botones de acción --}}
                    <div class="flex gap-3 pt-2">
                        <button type="button"
                            @click="$wire.closeQuickReservation()"
                            class="flex-1 px-4 py-3 text-sm font-bold text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
                            Cancelar
                        </button>
                        <button type="button"
                            wire:click="submitQuickReservation"
                            wire:loading.attr="disabled"
                            wire:target="submitQuickReservation"
                            class="flex-1 px-4 py-3 text-sm font-bold text-white rounded-xl transition-all shadow-lg disabled:opacity-50 disabled:cursor-not-allowed
                                {{ $resBalance <= 0 && $resTotal > 0 ? 'bg-emerald-600 hover:bg-emerald-700' : ($resDeposit > 0 ? 'bg-amber-600 hover:bg-amber-700' : 'bg-blue-600 hover:bg-blue-700') }}">
                            <span wire:loading.remove wire:target="submitQuickReservation">
                                <i class="fas fa-bookmark mr-2"></i>
                                Confirmar Reserva
                            </span>
                            <span wire:loading wire:target="submitQuickReservation" class="flex items-center justify-center">
                                <i class="fas fa-spinner fa-spin mr-2"></i>
                                Procesando...
                            </span>
                        </button>
                    </div>

                </div>{{-- end scroll panel --}}
            </div>
        </div>
    @endif
</div>
{{-- El JS (TomSelect + listeners) está centralizado en x-room-manager.scripts --}}

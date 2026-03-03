@props(['detailData', 'showAddSale', 'showAddDeposit'])

<div x-show="roomDetailModal" class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div @click="roomDetailModal = false" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden transform transition-all">
            @if ($detailData)
                <div class="px-8 py-6 border-b border-gray-100 flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                            <i class="fas fa-door-open"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Habitación
                                {{ $detailData['room']['room_number'] }}</h3>
                            @if (isset($detailData['is_past_date']) && $detailData['is_past_date'])
                                <p class="text-xs text-gray-500 mt-1">
                                    <i class="fas fa-history mr-1"></i> Vista histórica - Solo lectura
                                </p>
                            @endif
                        </div>
                    </div>
                    <button @click="roomDetailModal = false" class="text-gray-400 hover:text-gray-900"><i
                            class="fas fa-times text-xl"></i></button>
                </div>

                <div class="p-8 space-y-8">
                    @if ($detailData['reservation'])
                        <div class="space-y-8">
                            <!-- Cards de Resumen -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div class="p-4 bg-gray-50 rounded-xl text-center">
                                    <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Hospedaje</p>
                                    <p class="text-sm font-bold text-gray-900">
                                        ${{ number_format($detailData['total_hospedaje'], 0, ',', '.') }}</p>
                                </div>
                                <div class="p-4 bg-green-50 rounded-xl text-center relative group">
                                    <p class="text-[9px] font-bold text-green-600 uppercase mb-1">Abono</p>
                                    <p class="text-sm font-bold text-green-700">
                                        ${{ number_format($detailData['abono_realizado'], 0, ',', '.') }}</p>
                                    @if (!isset($detailData['is_past_date']) || !$detailData['is_past_date'])
                                        <button
                                            @click="editDeposit({{ $detailData['reservation']['id'] }}, {{ $detailData['abono_realizado'] }})"
                                            class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition-opacity text-green-600 hover:text-green-800">
                                            <i class="fas fa-edit text-[10px]"></i>
                                        </button>
                                    @endif
                                </div>
                                <div class="p-4 bg-gray-50 rounded-xl text-center">
                                    <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Consumos</p>
                                    <p class="text-sm font-bold text-gray-900">
                                        ${{ number_format($detailData['sales_total'], 0, ',', '.') }}</p>
                                </div>
                                @php
                                    $totalDebt = (float) ($detailData['total_debt'] ?? 0);
                                    $isCredit = $totalDebt < 0;
                                @endphp
                                <div
                                    class="p-4 {{ $isCredit ? 'bg-blue-50' : 'bg-red-50' }} rounded-xl text-center relative group">
                                    <p
                                        class="text-[9px] font-bold {{ $isCredit ? 'text-blue-600' : 'text-red-600' }} uppercase mb-1">
                                        {{ $isCredit ? 'Se Le Debe al Cliente' : 'Pendiente' }}
                                    </p>
                                    <p class="text-sm font-black {{ $isCredit ? 'text-blue-700' : 'text-red-700' }}">
                                        ${{ number_format(abs($totalDebt), 0, ',', '.') }}
                                    </p>
                                    @if (
                                        $isCredit &&
                                            isset($detailData['reservation']['id']) &&
                                            (!isset($detailData['is_past_date']) || !$detailData['is_past_date']))
                                        <button type="button"
                                            @click.stop="confirmRefund({{ $detailData['reservation']['id'] }}, {{ abs($totalDebt) }}, '{{ number_format(abs($totalDebt), 0, ',', '.') }}')"
                                            class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition-opacity text-blue-600 hover:text-blue-800"
                                            title="Registrar devolución">
                                            <i class="fas fa-check-circle text-[10px]"></i>
                                        </button>
                                    @endif
                                </div>
                            </div>

                            <!-- Sección de Consumos -->
                            <div class="space-y-4">
                                <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                                    <h4 class="text-xs font-bold text-gray-900 uppercase tracking-widest">Detalle de
                                        Consumos</h4>
                                    @if (!isset($detailData['is_past_date']) || !$detailData['is_past_date'])
                                        <button wire:click="toggleAddSale"
                                            class="text-[10px] font-bold text-blue-600 uppercase">+ Agregar
                                            Consumo</button>
                                    @endif
                                </div>

                                @if ($showAddSale)
                                    <div class="p-6 bg-gray-50 rounded-xl border border-gray-100 space-y-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div class="md:col-span-2" wire:ignore>
                                                <label
                                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Producto</label>
                                                <select wire:model="newSale.product_id" id="detail_product_id"
                                                    class="w-full"></select>
                                            </div>
                                            <div>
                                                <label
                                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Cantidad</label>
                                                <input type="number" wire:model="newSale.quantity" min="1"
                                                    class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-blue-500 outline-none">
                                            </div>
                                            <div>
                                                <label
                                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Método
                                                    de Pago</label>
                                                <select wire:model="newSale.payment_method"
                                                    class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-blue-500 outline-none">
                                                    <option value="efectivo">Efectivo</option>
                                                    <option value="transferencia">Transferencia</option>
                                                    <option value="pendiente">Pendiente (Cargar a cuenta)</option>
                                                </select>
                                            </div>
                                        </div>
                                        <button wire:click="addSale"
                                            class="w-full bg-blue-600 text-white py-3 rounded-xl text-[10px] font-bold uppercase tracking-widest hover:bg-blue-700 transition-all shadow-sm">Cargar
                                            Consumo</button>
                                    </div>
                                @endif

                                <div class="max-h-48 overflow-y-auto overflow-x-auto custom-scrollbar">
                                    <table class="min-w-[640px] w-full divide-y divide-gray-50">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th
                                                    class="px-4 py-2 text-left text-[9px] font-bold text-gray-400 uppercase">
                                                    Producto</th>
                                                <th
                                                    class="px-4 py-2 text-center text-[9px] font-bold text-gray-400 uppercase">
                                                    Cant</th>
                                                <th
                                                    class="px-4 py-2 text-center text-[9px] font-bold text-gray-400 uppercase">
                                                    Pago</th>
                                                <th
                                                    class="px-4 py-2 text-right text-[9px] font-bold text-gray-400 uppercase">
                                                    Total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            @foreach ($detailData['sales'] as $sale)
                                                <tr class="hover:bg-gray-50/50 transition-colors group">
                                                    <td class="px-4 py-3 text-xs font-bold text-gray-900">
                                                        {{ $sale['product']['name'] }}</td>
                                                    <td class="px-4 py-3 text-xs text-center font-bold text-gray-500">
                                                        {{ $sale['quantity'] }}</td>
                                                    <td class="px-4 py-3 text-xs text-center">
                                                        @if ($sale['is_paid'])
                                                            <div class="flex flex-col items-center space-y-1">
                                                                <span
                                                                    class="text-[9px] font-bold uppercase text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">{{ $sale['payment_method'] }}</span>
                                                                @if (!isset($detailData['is_past_date']) || !$detailData['is_past_date'])
                                                                    <button
                                                                        @click="confirmRevertSale({{ $sale['id'] }})"
                                                                        class="text-[8px] font-bold text-gray-400 underline uppercase tracking-tighter hover:text-red-600 opacity-0 group-hover:opacity-100 transition-opacity">Anular
                                                                        Pago</button>
                                                                @endif
                                                            </div>
                                                        @else
                                                            <div class="flex flex-col items-center space-y-1">
                                                                <span
                                                                    class="text-[9px] font-bold uppercase text-red-600 bg-red-50 px-2 py-0.5 rounded-full">Pendiente</span>
                                                                @if (!isset($detailData['is_past_date']) || !$detailData['is_past_date'])
                                                                    <button
                                                                        @click="confirmPaySale({{ $sale['id'] }})"
                                                                        class="text-[8px] font-bold text-blue-600 underline uppercase tracking-tighter hover:text-blue-800">Marcar
                                                                        Pago</button>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-3 text-xs text-right font-black text-gray-900">
                                                        ${{ number_format($sale['total'], 0, ',', '.') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Historial de Devoluciones -->
                            @if (!empty($detailData['refunds_history']) && count($detailData['refunds_history']) > 0)
                                <div class="space-y-4 pt-4 border-t border-gray-100">
                                    <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                                        <h4 class="text-xs font-bold text-gray-900 uppercase tracking-widest">Historial
                                            de Devoluciones</h4>
                                        <span class="text-[9px] text-gray-500 font-medium">Total:
                                            ${{ number_format($detailData['total_refunds'] ?? 0, 0, ',', '.') }}</span>
                                    </div>
                                    <div class="max-h-32 overflow-y-auto overflow-x-auto custom-scrollbar">
                                        <table class="min-w-[640px] w-full divide-y divide-gray-50">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th
                                                        class="px-4 py-2 text-left text-[9px] font-bold text-gray-400 uppercase">
                                                        Fecha</th>
                                                    <th
                                                        class="px-4 py-2 text-center text-[9px] font-bold text-gray-400 uppercase">
                                                        Monto</th>
                                                    <th
                                                        class="px-4 py-2 text-left text-[9px] font-bold text-gray-400 uppercase">
                                                        Registrado Por</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-50">
                                                @foreach ($detailData['refunds_history'] as $refund)
                                                    <tr class="hover:bg-gray-50/50 transition-colors">
                                                        <td class="px-4 py-3 text-xs font-bold text-gray-900">
                                                            {{ $refund['created_at'] }}</td>
                                                        <td
                                                            class="px-4 py-3 text-xs text-center font-bold text-blue-600">
                                                            ${{ number_format($refund['amount'], 0, ',', '.') }}</td>
                                                        <td class="px-4 py-3 text-xs text-gray-500">
                                                            {{ $refund['created_by'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif

                            <!-- Historial de Abonos -->
                            <div class="space-y-4 pt-4 border-t border-gray-100">
                                <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                                    <h4 class="text-xs font-bold text-gray-900 uppercase tracking-widest">Historial de
                                        Abonos</h4>
                                    @if (!isset($detailData['is_past_date']) || !$detailData['is_past_date'])
                                        <button wire:click="toggleAddDeposit"
                                            class="text-[10px] font-bold text-blue-600 uppercase">+ Agregar
                                            Abono</button>
                                    @endif
                                </div>

                                @if ($showAddDeposit)
                                    <div class="p-6 bg-gray-50 rounded-xl border border-gray-100 space-y-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label
                                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Monto</label>
                                                <input type="number" wire:model="newDeposit.amount" step="0.01"
                                                    min="0.01" placeholder="0.00"
                                                    class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-blue-500 outline-none">
                                                @error('newDeposit.amount')
                                                    <p class="text-[10px] text-red-600 mt-1">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            <div>
                                                <label
                                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Método
                                                    de Pago</label>
                                                <select wire:model="newDeposit.payment_method"
                                                    class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-blue-500 outline-none">
                                                    <option value="efectivo">Efectivo</option>
                                                    <option value="transferencia">Transferencia</option>
                                                </select>
                                                @error('newDeposit.payment_method')
                                                    <p class="text-[10px] text-red-600 mt-1">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            <div class="md:col-span-2">
                                                <label
                                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-widest ml-1">Notas
                                                    (Opcional)</label>
                                                <textarea wire:model="newDeposit.notes" rows="2"
                                                    class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
                                                @error('newDeposit.notes')
                                                    <p class="text-[10px] text-red-600 mt-1">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        </div>
                                        <button wire:click="addDeposit"
                                            class="w-full bg-blue-600 text-white py-3 rounded-xl text-[10px] font-bold uppercase tracking-widest hover:bg-blue-700 transition-all shadow-sm">Agregar
                                            Abono</button>
                                    </div>
                                @endif

                                <div class="max-h-48 overflow-y-auto overflow-x-auto custom-scrollbar">
                                    <table class="min-w-[700px] w-full divide-y divide-gray-50">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th
                                                    class="px-4 py-2 text-left text-[9px] font-bold text-gray-400 uppercase">
                                                    Fecha</th>
                                                <th
                                                    class="px-4 py-2 text-center text-[9px] font-bold text-gray-400 uppercase">
                                                    Monto</th>
                                                <th
                                                    class="px-4 py-2 text-center text-[9px] font-bold text-gray-400 uppercase">
                                                    Método</th>
                                                <th
                                                    class="px-4 py-2 text-left text-[9px] font-bold text-gray-400 uppercase">
                                                    Notas</th>
                                                <th
                                                    class="px-4 py-2 text-center text-[9px] font-bold text-gray-400 uppercase">
                                                    Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            @forelse($detailData['deposit_history'] ?? [] as $deposit)
                                                <tr class="hover:bg-gray-50/50 transition-colors group">
                                                    <td class="px-4 py-3 text-xs font-bold text-gray-900">
                                                        {{ $deposit['created_at'] }}</td>
                                                    <td class="px-4 py-3 text-xs text-center font-bold text-gray-900">
                                                        ${{ number_format($deposit['amount'], 0, ',', '.') }}</td>
                                                    <td class="px-4 py-3 text-xs text-center">
                                                        <span
                                                            class="text-[9px] font-bold uppercase {{ $deposit['payment_method'] === 'efectivo' ? 'text-emerald-600 bg-emerald-50' : 'text-blue-600 bg-blue-50' }} px-2 py-0.5 rounded-full">
                                                            {{ $deposit['payment_method'] }}
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3 text-xs text-gray-500">
                                                        {{ $deposit['notes'] ?? '-' }}</td>
                                                    <td class="px-4 py-3 text-center">
                                                        @if (!isset($detailData['is_past_date']) || !$detailData['is_past_date'])
                                                            <button type="button"
                                                                @click="confirmDeleteDeposit({{ $deposit['id'] }}, {{ $deposit['amount'] }}, '{{ number_format($deposit['amount'], 0, ',', '.') }}')"
                                                                class="text-red-500 hover:text-red-700 opacity-0 group-hover:opacity-100 transition-opacity"
                                                                title="Eliminar abono">
                                                                <i class="fas fa-trash text-xs"></i>
                                                            </button>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="5"
                                                        class="px-4 py-8 text-center text-xs text-gray-400">
                                                        No hay abonos registrados
                                                    </td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Historial de Estadía -->
                            @php
                                $pendingNights = collect($detailData['stay_history'] ?? [])->where('is_paid', false);
                                $pendingNightsCount = $pendingNights->count();
                                $pendingNightsTotal = $pendingNights->sum(fn($night) => (float) ($night['price'] ?? 0));
                            @endphp
                            <div class="space-y-4 pt-4 border-t border-gray-100">
                                <div class="flex items-center justify-between gap-2">
                                    <h4 class="text-xs font-bold text-gray-900 uppercase tracking-widest">Estado de Pago
                                        por Noches</h4>
                                    @if ((!isset($detailData['is_past_date']) || !$detailData['is_past_date']) && $pendingNightsCount > 0)
                                        <button type="button" x-data
                                            @click="
                                                const reservationId = {{ $detailData['reservation']['id'] ?? 0 }};
                                                const totalPendingAmount = {{ $pendingNightsTotal }};
                                                const financialContext = {
                                                    totalAmount: {{ $detailData['total_hospedaje'] ?? 0 }},
                                                    paymentsTotal: {{ $detailData['abono_realizado'] ?? 0 }},
                                                    balanceDue: {{ $detailData['total_debt'] ?? 0 }}
                                                };
                                                if (typeof window.openRegisterPayment === 'function') {
                                                    window.openRegisterPayment(reservationId, totalPendingAmount, financialContext, null);
                                                } else {
                                                    console.error('openRegisterPayment no esta disponible');
                                                }
                                            "
                                            class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-[9px] font-bold uppercase tracking-wider text-blue-700 transition-colors hover:bg-blue-100 hover:text-blue-800 cursor-pointer">
                                            Pagar todas
                                        </button>
                                    @endif
                                </div>
                                <div class="max-h-48 overflow-y-auto overflow-x-auto custom-scrollbar">
                                    <table class="min-w-[640px] w-full divide-y divide-gray-50">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th
                                                    class="px-4 py-2 text-left text-[9px] font-bold text-gray-400 uppercase">
                                                    Fecha</th>
                                                <th
                                                    class="px-4 py-2 text-center text-[9px] font-bold text-gray-400 uppercase">
                                                    Valor Noche</th>
                                                <th
                                                    class="px-4 py-2 text-right text-[9px] font-bold text-gray-400 uppercase">
                                                    Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            @foreach ($detailData['stay_history'] as $stay)
                                                <tr class="hover:bg-gray-50/50 transition-colors group">
                                                    <td class="px-4 py-3 text-xs font-bold text-gray-900">
                                                        {{ $stay['date'] }}</td>
                                                    <td class="px-4 py-3 text-xs text-center font-bold text-gray-500">
                                                        ${{ number_format($stay['price'], 0, ',', '.') }}</td>
                                                    <td class="px-4 py-3 text-xs text-right">
                                                        @if ($stay['is_paid'])
                                                            <div class="flex flex-col items-end space-y-1">
                                                                <span
                                                                    class="text-[9px] font-bold uppercase text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">Pagado</span>
                                                                @if (!isset($detailData['is_past_date']) || !$detailData['is_past_date'])
                                                                    <span
                                                                        class="text-[8px] text-gray-400 italic">Gestionar
                                                                        en pagos</span>
                                                                @endif
                                                            </div>
                                                        @else
                                                            <div class="flex flex-col items-end space-y-1">
                                                                <span
                                                                    class="text-[9px] font-bold uppercase text-red-600 bg-red-50 px-2 py-0.5 rounded-full">Pendiente</span>
                                                                @if (!isset($detailData['is_past_date']) || !$detailData['is_past_date'])
                                                                    <button type="button" x-data
                                                                        @click="
                                                                        const reservationId = {{ $detailData['reservation']['id'] ?? 0 }};
                                                                        const nightPrice = {{ $stay['price'] ?? 0 }};
                                                                        const nightDate = '{{ $stay['date'] ?? '' }}';
                                                                        const financialContext = {
                                                                            totalAmount: {{ $detailData['total_hospedaje'] ?? 0 }},
                                                                            paymentsTotal: {{ $detailData['abono_realizado'] ?? 0 }},
                                                                            balanceDue: {{ $detailData['total_debt'] ?? 0 }}
                                                                        };
                                                                        if (typeof window.openRegisterPayment === 'function') {
                                                                            window.openRegisterPayment(reservationId, nightPrice, financialContext, nightDate);
                                                                        } else {
                                                                            console.error('openRegisterPayment no está disponible');
                                                                        }
                                                                    "
                                                                        class="text-[8px] font-bold text-blue-600 underline uppercase tracking-tighter hover:text-blue-800 cursor-pointer">
                                                                        Pagar noche
                                                                    </button>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="text-center py-12">
                            <i class="fas fa-calendar-times text-4xl text-gray-200 mb-4"></i>
                            <p class="text-gray-500 font-medium">No hay reserva activa para esta fecha</p>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>

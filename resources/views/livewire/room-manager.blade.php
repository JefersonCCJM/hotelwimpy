{{-- 
    SINCRONIZACION EN TIEMPO REAL:
    - Mecanismo principal: Eventos Livewire (inmediatos cuando ambos componentes estan montados)
    - Mecanismo fallback: Polling cada 5s (garantiza sincronizacion 5s si el evento se pierde)
    - NO se usan WebSockets para mantener simplicidad y evitar infraestructura adicional
    - El polling es eficiente porque usa eager loading y no hace N+1 queries
--}}
    <div class="space-y-6 min-w-0" 
     wire:poll.5s="refreshRoomsPolling"
     x-data="{
    quickRentModal: @entangle('quickRentModal'),
    quickReservationModal: @entangle('quickReservationModal'),
        roomDetailModal: @entangle('roomDetailModal'),
        roomEditModal: @entangle('roomEditModal'),
        createRoomModal: @entangle('createRoomModal'),
        assignGuestsModal: @entangle('assignGuestsModal'),
        roomDailyHistoryModal: @entangle('roomDailyHistoryModal'),
        actionsMenuOpen: null,
        reservationsAccordionOpen: false,
        init() {
            const handleScroll = () => {
                if (this.actionsMenuOpen !== null) {
                    this.closeActionsMenu();
                }
            };
            window.addEventListener('scroll', handleScroll, true);
            document.addEventListener('scroll', handleScroll, true);
            this.$el.addEventListener('scroll', handleScroll, true);
        },
        openActionsMenu(roomId, event) {
            event.stopPropagation();
            if (this.actionsMenuOpen === roomId) {
                this.closeActionsMenu();
                return;
            }
            this.actionsMenuOpen = roomId;
        },
        closeActionsMenu() {
            this.actionsMenuOpen = null;
        }
}"
     @scroll.window="closeActionsMenu()">
    
    <!-- HEADER -->
    <x-room-manager.header :roomsCount="isset($rooms) ? $rooms->total() : (isset($releaseHistory) ? (method_exists($releaseHistory, 'total') ? $releaseHistory->total() : $releaseHistory->count()) : 0)" />
    <!-- RESUMEN DIARIO -->
    <x-room-manager.daily-stats :stats="$dailyStats ?? []" :currentDate="$currentDate" />

    

    <!-- PESTANAS -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm mb-6">
        <div class="border-b border-gray-200">
            <nav class="flex space-x-8 px-6 overflow-x-auto" aria-label="Tabs">
                <button 
                    wire:click="switchTab('rooms')"
                    :class="$wire.activeTab === 'rooms' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-bold text-sm transition-colors">
                    <i class="fas fa-door-open mr-2"></i>
                    Habitaciones
                            </button>
                <button 
                    wire:click="switchTab('history')"
                    :class="$wire.activeTab === 'history' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-bold text-sm transition-colors">
                    <i class="fas fa-history mr-2"></i>
                    Historial de Liberaciones
                            </button>
            </nav>
        </div>
        <div class="px-6 py-3 bg-gray-50/70 border-t border-gray-100">
            <button type="button"
                @click="reservationsAccordionOpen = !reservationsAccordionOpen"
                class="w-full flex items-center justify-between rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-left hover:bg-gray-50 transition-colors">
                <div class="flex items-center gap-3 min-w-0 flex-wrap">
                    <span class="text-xs font-bold uppercase tracking-wider text-gray-700">Reservas proximas (Hoy y manana)</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[11px] font-bold shrink-0">
                        Hoy: {{ (int) ($receptionReservationsSummary['today_count'] ?? 0) }}
                    </span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[11px] font-bold shrink-0">
                        Manana: {{ (int) ($receptionReservationsSummary['tomorrow_count'] ?? 0) }}
                    </span>
                </div>
                <i class="fas fa-chevron-down text-gray-500 transition-transform duration-200"
                    :class="reservationsAccordionOpen ? 'rotate-180' : ''"></i>
            </button>

            <div x-show="reservationsAccordionOpen" x-transition class="pt-3 grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="rounded-xl border border-blue-100 bg-white p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-bold uppercase tracking-wider text-blue-700">Reservas de hoy</p>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-blue-100 text-blue-700 text-xs font-bold">
                            {{ (int) ($receptionReservationsSummary['today_count'] ?? 0) }}
                        </span>
                    </div>
                    <p class="text-[11px] text-gray-500 mb-2">{{ $receptionReservationsSummary['today_date'] ?? '' }}</p>
                    <div class="mb-3 flex flex-wrap gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700 border border-emerald-200">
                            Check-in: {{ (int) data_get($receptionReservationsSummary, 'today_status_counts.checked_in', 0) }}
                        </span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                            Pendiente: {{ (int) data_get($receptionReservationsSummary, 'today_status_counts.pending_checkin', 0) }}
                        </span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-yellow-100 text-yellow-800 border border-yellow-200">
                            Checkout pendiente: {{ (int) data_get($receptionReservationsSummary, 'today_status_counts.pending_checkout', 0) }}
                        </span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-700 border border-red-200">
                            Cancelada: {{ (int) data_get($receptionReservationsSummary, 'today_status_counts.cancelled', 0) }}
                        </span>
                    </div>
                    @if(!empty($receptionReservationsSummary['today_items']))
                        <div class="space-y-2">
                            @foreach($receptionReservationsSummary['today_items'] as $item)
                                <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-xs font-semibold text-gray-800 truncate" title="{{ ($item['code'] ?? 'RES') . ' - ' . ($item['customer'] ?? 'Sin cliente') }}">{{ $item['code'] ?? 'RES' }} - {{ $item['customer'] ?? 'Sin cliente' }}</p>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold {{ $item['status_badge_class'] ?? 'bg-gray-100 text-gray-700 border border-gray-200' }}">
                                            {{ $item['status_label'] ?? 'Sin estado' }}
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-gray-500">Hab: {{ $item['rooms'] ?? '-' }}</p>
                                    @can('edit_reservations')
                                        @if(!empty($item['modal_payload']))
                                            @php
                                                $encodedModalPayload = base64_encode(json_encode($item['modal_payload'], JSON_UNESCAPED_UNICODE));
                                            @endphp
                                            <div class="mt-2">
                                                <button type="button"
                                                    data-reservation-payload="{{ $encodedModalPayload }}"
                                                    onclick="openReservationDetailFromEncoded(this)"
                                                    class="inline-flex items-center text-[11px] font-semibold text-blue-700 hover:text-blue-900 hover:underline">
                                                    <i class="fas fa-eye mr-1"></i>
                                                    Ver detalle
                                                </button>
                                            </div>
                                        @endif
                                    @endcan
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-gray-500 italic">No hay llegadas programadas para hoy.</p>
                    @endif
                </div>

                <div class="rounded-xl border border-emerald-100 bg-white p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-bold uppercase tracking-wider text-emerald-700">Reservas de mañana</p>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">
                            {{ (int) ($receptionReservationsSummary['tomorrow_count'] ?? 0) }}
                        </span>
                    </div>
                    <p class="text-[11px] text-gray-500 mb-2">{{ $receptionReservationsSummary['tomorrow_date'] ?? '' }}</p>
                    <div class="mb-3 flex flex-wrap gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700 border border-emerald-200">
                            Check-in: {{ (int) data_get($receptionReservationsSummary, 'tomorrow_status_counts.checked_in', 0) }}
                        </span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                            Pendiente: {{ (int) data_get($receptionReservationsSummary, 'tomorrow_status_counts.pending_checkin', 0) }}
                        </span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-700 border border-red-200">
                            Cancelada: {{ (int) data_get($receptionReservationsSummary, 'tomorrow_status_counts.cancelled', 0) }}
                        </span>
                    </div>
                    @if(!empty($receptionReservationsSummary['tomorrow_items']))
                        <div class="space-y-2">
                            @foreach($receptionReservationsSummary['tomorrow_items'] as $item)
                                <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-xs font-semibold text-gray-800 truncate" title="{{ ($item['code'] ?? 'RES') . ' - ' . ($item['customer'] ?? 'Sin cliente') }}">{{ $item['code'] ?? 'RES' }} - {{ $item['customer'] ?? 'Sin cliente' }}</p>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold {{ $item['status_badge_class'] ?? 'bg-gray-100 text-gray-700 border border-gray-200' }}">
                                            {{ $item['status_label'] ?? 'Sin estado' }}
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-gray-500">Hab: {{ $item['rooms'] ?? '-' }}</p>
                                    @can('edit_reservations')
                                        @if(!empty($item['modal_payload']))
                                            @php
                                                $encodedModalPayload = base64_encode(json_encode($item['modal_payload'], JSON_UNESCAPED_UNICODE));
                                            @endphp
                                            <div class="mt-2">
                                                <button type="button"
                                                    data-reservation-payload="{{ $encodedModalPayload }}"
                                                    onclick="openReservationDetailFromEncoded(this)"
                                                    class="inline-flex items-center text-[11px] font-semibold text-blue-700 hover:text-blue-900 hover:underline">
                                                    <i class="fas fa-eye mr-1"></i>
                                                    Ver detalle
                                                </button>
                                            </div>
                                        @endif
                                    @endcan
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-gray-500 italic">No hay llegadas programadas para manana.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($activeTab === 'rooms')
        <!-- FILTROS -->
        <x-room-manager.filters 
            :statuses="$statuses" 
            :ventilationTypes="$ventilationTypes" 
            :currentDate="$currentDate" 
            :daysInMonth="$daysInMonth" 
        />

        <!-- TABLA DE HABITACIONES -->
        <x-room-manager.rooms-table 
            :rooms="$rooms" 
            :currentDate="$currentDate" 
        />
    @elseif($activeTab === 'history')
        <!-- HISTORIAL DE LIBERACIONES -->
        <x-room-manager.release-history 
            :releaseHistory="$releaseHistory" 
        />
                                    @endif

    <!-- MODAL: DETALLE CUENTA -->
    <x-room-manager.room-detail-modal 
        :detailData="$detailData" 
        :showAddSale="$showAddSale"
        :showAddDeposit="$showAddDeposit"
    />
    
    <!-- MODAL: REGISTRAR PAGO (dentro del contexto del componente para usar @this) -->
    <x-notifications.payment-modal />

    <!-- MODAL: ARRENDAMIENTO RAPIDO -->
    <x-room-manager.quick-rent-modal
        :rentForm="$rentForm"
        :additionalGuests="$additionalGuests"
        :checkInDate="$date"
    />

    <!-- MODAL: RESERVA RAPIDA (con formulario completo) -->
    <x-room-manager.quick-reservation-modal
        :quickReservationForm="$quickReservationForm"
    />

    <!-- MODAL: CREAR CLIENTE -->
    <livewire:customers.create-customer-modal />

    <!-- MODAL: DETALLE HISTORIAL DE LIBERACION -->
    <x-room-manager.release-history-detail-modal 
        :releaseHistoryDetail="$releaseHistoryDetail" 
        :releaseHistoryDetailModal="$releaseHistoryDetailModal"
    />

    <!-- MODAL: CONFIRMACION DE LIBERACION -->
    <x-room-manager.room-release-confirmation-modal />

    <!-- MODAL: HUESPEDES -->
    <x-room-manager.guests-modal />

    <!-- MODAL: ASIGNAR HUESPEDES (Completar Reserva Activa) -->
    <x-room-manager.assign-guests-modal 
        :assignGuestsForm="$assignGuestsForm" 
    />

    <!-- MODAL: HISTORIAL DIARIO DE LIBERACIONES -->
    <x-room-manager.room-daily-history-modal 
        :roomDailyHistoryData="$roomDailyHistoryData" 
    />

    <!-- MODAL: CREAR HABITACION -->
    @if(auth()->check() && auth()->user()->hasRole('Administrador'))
        <x-room-manager.create-room-modal />
    @endif

    <!-- MODAL: EDITAR HABITACION -->
    @if((auth()->check() && auth()->user()->hasRole('Administrador')) && $roomEditData)
        <x-room-manager.room-edit-modal 
            :room="$roomEditData['room']" 
            :statuses="$roomEditData['statuses']"
            :ventilation_types="$roomEditData['ventilation_types']"
            :isOccupied="$roomEditData['isOccupied']"
        />
    @endif

    <!-- MODAL: EDITAR PRECIOS -->
    <x-room-manager.edit-prices-modal 
        :editPricesForm="$editPricesForm"
    />

    <!-- MODAL: TODOS LOS HUESPEDES -->
    <x-room-manager.all-guests-modal
        :allGuestsForm="$allGuestsForm"
    />

    <!-- MODAL: CAMBIAR HABITACION -->
    <x-room-manager.change-room-modal
        :changeRoomModal="$changeRoomModal"
        :changeRoomData="$changeRoomData"
        :availableRoomsForChange="$availableRoomsForChange"
    />

    <!-- MODAL: DETALLE DE RESERVA -->
    <div wire:ignore>
        <x-reservations.detail-modal />
    </div>

    <!-- SCRIPTS -->
    <x-room-manager.reservation-detail-modal-scripts />
    <x-reservations.calendar-scripts />
    <x-room-manager.scripts />
</div>

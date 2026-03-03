{{--
    SINCRONIZACIÓN EN TIEMPO REAL OPTIMIZADA:
    - Mecanismo principal: Eventos Livewire (inmediatos cuando ambos componentes están montados)
    - Mecanismo fallback: Polling inteligente cada 5s con detección de cambios (solo recarga si hay cambios)
    - Optimización: Polling solo cuando pestaña está visible (keep-alive.visible)
    - Optimización: Detección de cambios con hash para evitar recargas innecesarias
    - NO se usan WebSockets para mantener simplicidad y evitar infraestructura adicional
--}}
<div class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen"
     @if($this->shouldPoll())
         wire:poll.5s.keep-alive.visible="refresh"
     @endif>
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-6 max-w-7xl">
        <!-- Header -->
        <div class="bg-white rounded-2xl shadow-lg border-2 border-gray-200 p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center space-x-4">
                    <div class="flex-shrink-0">
                        <img src="{{ asset('assets/img/backgrounds/logo-Photoroom.png') }}" alt="Hotel Wimpy Suite" class="h-16 w-auto object-contain">
                    </div>
                    <div>
                        <h1 class="text-3xl sm:text-4xl font-black text-gray-900 leading-tight">Panel de Aseo</h1>
                        <p class="text-base sm:text-lg text-gray-600 mt-1 font-medium">Sistema de Gestión de Limpieza</p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <div class="flex items-center space-x-3 bg-gray-50 px-4 py-2 rounded-xl border border-gray-200">
                        <i class="fas fa-clock text-gray-600"></i>
                        <span class="text-base font-bold text-gray-700">{{ $currentTime }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Legend -->
        <div class="bg-white rounded-2xl shadow-md border-2 border-gray-200 p-5 mb-6">
            <h2 class="text-sm font-black text-gray-800 uppercase tracking-wider mb-4">Leyenda de Estados</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="flex items-center space-x-3 bg-emerald-50 px-4 py-3 rounded-xl border-2 border-emerald-300">
                    <div class="w-5 h-5 rounded-full bg-emerald-600 shadow-sm"></div>
                    <span class="text-sm font-bold text-emerald-800">Libre</span>
                </div>
                <div class="flex items-center space-x-3 bg-blue-50 px-4 py-3 rounded-xl border-2 border-blue-300">
                    <div class="w-5 h-5 rounded-full bg-blue-600 shadow-sm"></div>
                    <span class="text-sm font-bold text-blue-800">Ocupada</span>
                </div>
                <div class="flex items-center space-x-3 bg-yellow-50 px-4 py-3 rounded-xl border-2 border-yellow-300">
                    <div class="w-5 h-5 rounded-full bg-yellow-600 shadow-sm"></div>
                    <span class="text-sm font-bold text-yellow-800">Pendiente por Aseo</span>
                </div>
                <div class="flex items-center space-x-3 bg-purple-50 px-4 py-3 rounded-xl border-2 border-purple-300">
                    <div class="w-5 h-5 rounded-full bg-purple-600 shadow-sm"></div>
                    <span class="text-sm font-bold text-purple-800">Pendiente Checkout</span>
                </div>
            </div>
        </div>

        <!-- Rooms Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
            @forelse($rooms as $room)
                @php
                    $displayStatus = $room['display_status']; // RoomStatus Enum (Libre, Ocupada, etc.)
                    $cleaningStatus = $room['cleaning_status']; // Estado de limpieza (ya calculado, no necesita query adicional)
                    // If cleaning status is pending, use yellow card background regardless of display status
                    // Otherwise use display status color
                    $cardBgColor = ($cleaningStatus['code'] === 'pendiente')
                        ? 'bg-yellow-50 hover:bg-yellow-100'
                        : $displayStatus->cardBgColor();
                    $cardBorderColor = ($cleaningStatus['code'] === 'pendiente')
                        ? 'border-yellow-400'
                        : $displayStatus->borderColor();
                    $iconBadgeColor = ($cleaningStatus['code'] === 'pendiente')
                        ? 'bg-yellow-100 border-yellow-400 text-yellow-800'
                        : $displayStatus->badgeColor();
                    $iconColor = ($cleaningStatus['code'] === 'pendiente')
                        ? 'bg-yellow-50 text-yellow-700'
                        : $displayStatus->color();
                @endphp
                <div wire:key="room-{{ $room['id'] }}-{{ $room['last_cleaned_at']?->timestamp ?? 0 }}-{{ $room['cleaning_status']['code'] }}" class="room-card {{ $cardBgColor }} rounded-2xl shadow-lg border-3 {{ $cardBorderColor }} transition-all duration-300 hover:transform hover:-translate-y-1">
                    <div class="p-5 sm:p-6">
                        <!-- Room Number -->
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center space-x-3">
                                <div class="p-3 rounded-xl {{ $iconBadgeColor }}">
                                    <i class="fas {{ $displayStatus->icon() }} text-2xl {{ $iconColor }}"></i>
                                </div>
                                <h3 class="text-3xl sm:text-4xl font-black text-gray-900">#{{ $room['room_number'] }}</h3>
                            </div>
                        </div>

                        <!-- Real Status Badge (ESTADO) -->
                        <div class="mb-3">
                            <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold {{ $displayStatus->badgeColor() }} border-2">
                                <i class="fas {{ $displayStatus->icon() }} mr-2"></i>
                                {{ $displayStatus->label() }}
                            </span>
                        </div>

                        <!-- Cleaning Status Badge (ESTADO DE LIMPIEZA) -->
                        <div class="mb-5">
                            <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold {{ $cleaningStatus['color'] }}">
                                <i class="fas {{ $cleaningStatus['icon'] }} mr-2"></i>
                                {{ $cleaningStatus['label'] }}
                            </span>
                        </div>

                        <!-- Towel Information (only shown when cleaning status is pending) -->
                        @if($cleaningStatus['code'] === 'pendiente')
                            @php
                                $towelCount = $room['guests_count'] ?? $room['max_capacity'] ?? 1;
                            @endphp
                            <div class="mb-5 p-4 bg-yellow-100 border-2 border-yellow-300 rounded-xl">
                                <div class="flex items-center space-x-3">
                                    <i class="fas fa-info-circle text-yellow-700 text-lg"></i>
                                    <span class="text-sm font-bold text-yellow-800">
                                        {{ $towelCount }} {{ $towelCount === 1 ? 'toalla' : 'toallas' }} requerida{{ $towelCount === 1 ? '' : 's' }}
                                    </span>
                                </div>
                            </div>
                        @endif

                        <!-- Room Info -->
                        <div class="space-y-3 mb-5">
                            <div class="flex items-center space-x-3 text-base font-semibold text-gray-700">
                                <i class="fas fa-bed"></i>
                                <span>{{ $room['beds_count'] }} {{ $room['beds_count'] === 1 ? 'cama' : 'camas' }}</span>
                            </div>
                            <div class="flex items-center space-x-3 text-base font-semibold text-gray-700">
                                <i class="fas fa-users"></i>
                                <span>Capacidad: {{ $room['max_capacity'] }} personas</span>
                            </div>
                        </div>

                        <!-- Action Button -->
                        @if($room['can_mark_clean'])
                            @php
                                $buttonGradient = ($cleaningStatus['code'] === 'pendiente')
                                    ? 'from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700'
                                    : 'from-green-600 to-green-700 hover:from-green-700 hover:to-green-800';
                            @endphp
                            <button
                                wire:key="btn-mark-clean-{{ $room['id'] }}"
                                wire:click="markAsClean({{ $room['id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="markAsClean({{ $room['id'] }})"
                                type="button"
                                class="w-full py-4 px-6 bg-gradient-to-r {{ $buttonGradient }} disabled:from-gray-400 disabled:to-gray-500 text-white font-bold rounded-xl transition-all duration-200 flex items-center justify-center space-x-3 shadow-lg hover:shadow-xl disabled:cursor-not-allowed disabled:opacity-60 text-base">
                                <i class="fas fa-check-circle" wire:loading.remove wire:target="markAsClean({{ $room['id'] }})"></i>
                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="markAsClean({{ $room['id'] }})"></i>
                                <span wire:loading.remove wire:target="markAsClean({{ $room['id'] }})">Marcar como Limpia</span>
                                <span wire:loading wire:target="markAsClean({{ $room['id'] }})">Procesando...</span>
                            </button>
                        @else
                            @php
                                $buttonColor = ($cleaningStatus['code'] === 'pendiente')
                                    ? 'bg-yellow-100 border-yellow-300 text-yellow-800'
                                    : $displayStatus->cleanButtonColor();
                            @endphp
                            <div class="w-full py-4 px-6 border-2 font-bold rounded-xl text-center text-sm {{ $buttonColor }}">
                                <i class="fas fa-check-circle mr-2"></i>Habitación Limpia
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full bg-white rounded-2xl shadow-lg border-2 border-gray-200 p-12 text-center">
                    <div class="mb-6">
                        <i class="fas fa-door-open text-7xl text-gray-300"></i>
                    </div>
                    <h3 class="text-2xl font-black text-gray-700 mb-2">No hay habitaciones registradas</h3>
                    <p class="text-gray-500 font-medium">Contacte al administrador del sistema.</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- Notification Toast -->
    <div
        x-data="{ show: false, message: '', type: '' }"
        x-on:notify.window="show = true; message = $event.detail.message; type = $event.detail.type; setTimeout(() => show = false, 5000)"
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 transform translate-y-2"
        x-transition:enter-end="opacity-100 transform translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 transform translate-y-0"
        x-transition:leave-end="opacity-0 transform translate-y-2"
        class="fixed bottom-4 right-4 z-50 max-w-md"
        style="display: none;">
        <div
            :class="{
                'bg-green-500': type === 'success',
                'bg-red-500': type === 'error',
                'bg-blue-500': type === 'info'
            }"
            class="rounded-xl shadow-lg p-4 text-white font-bold">
            <div class="flex items-center space-x-3">
                <i :class="{
                    'fa-check-circle': type === 'success',
                    'fa-exclamation-circle': type === 'error',
                    'fa-info-circle': type === 'info'
                }" class="fas text-xl"></i>
                <span x-text="message"></span>
            </div>
        </div>
    </div>
</div>

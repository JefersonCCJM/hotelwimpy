@props(['allGuestsForm'])

@if ($allGuestsForm)
    <div x-data="{
        show: @entangle('allGuestsModal'),
        maxCapacity: {{ $allGuestsForm['max_capacity'] ?? 4 }},
        currentGuestCount: {{ count($allGuestsForm['guests'] ?? []) }},
        init() {
            this.$watch('show', (value) => {
                if (value) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = 'auto';
                }
            });
            // Sincronizar currentGuestCount cuando Livewire actualiza allGuestsForm.guests
            this.$watch(
                () => $wire.allGuestsForm?.guests?.length,
                (count) => {
                    if (count !== undefined) {
                        this.currentGuestCount = count;
                    }
                }
            );
        },
        close() {
            this.show = false;
            $wire.set('allGuestsModal', false);
        }
    }" x-show="show" x-cloak
        class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm overflow-y-auto h-full w-full z-50" style="display: none;">

        <div class="relative min-h-screen flex items-center justify-center p-4">
            <div class="relative bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-hidden">
                <!-- Header -->
                <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-6 text-white">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div
                                class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur-md flex items-center justify-center">
                                <i class="fas fa-users text-2xl"></i>
                            </div>
                            <div>
                                <h3 class="text-2xl font-bold">Todos los Huéspedes</h3>
                                <p class="text-blue-100 text-sm">Habitación: {{ $allGuestsForm['room_number'] ?? 'N/A' }}
                                </p>
                                <p class="text-blue-100 text-xs">Capacidad: <span
                                        x-text="currentGuestCount"></span>/<span x-text="maxCapacity"></span></p>
                            </div>
                        </div>
                        <button @click="close()" class="text-white/80 hover:text-white transition-colors">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="p-6 max-h-[60vh] overflow-y-auto">
                    @if (isset($allGuestsForm['guests']) && count($allGuestsForm['guests']) > 0)
                        <div class="space-y-4 mb-6">
                            @foreach ($allGuestsForm['guests'] as $guest)
                                <div
                                    class="border rounded-xl p-4 @if ($guest['is_primary']) border-blue-200 bg-blue-50 @else border-gray-200 bg-gray-50 @endif">
                                    <div class="flex items-start justify-between">
                                        <div class="flex items-start space-x-3">
                                            <div
                                                class="w-10 h-10 rounded-full @if ($guest['is_primary']) bg-blue-100 text-blue-600 @else bg-gray-100 text-gray-600 @endif flex items-center justify-center flex-shrink-0">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div class="flex-1">
                                                <div class="flex items-center space-x-2 mb-1 min-w-0">
                                                    <h4 class="font-semibold text-gray-900 max-w-[260px] truncate"
                                                        title="{{ $guest['name'] }}">{{ $guest['name'] }}</h4>
                                                    @if ($guest['is_primary'])
                                                        <span
                                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                            Principal
                                                        </span>
                                                    @else
                                                        <span
                                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                            Adicional
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="space-y-1 text-sm text-gray-600">
                                                    <div class="flex items-center space-x-2">
                                                        <i class="fas fa-id-card text-gray-400"></i>
                                                        <span>{{ $guest['identification'] }}</span>
                                                    </div>
                                                    <div class="flex items-center space-x-2">
                                                        <i class="fas fa-phone text-gray-400"></i>
                                                        <span>{{ $guest['phone'] }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8 mb-6">
                            <div
                                class="w-16 h-16 mx-auto rounded-full bg-gray-100 flex items-center justify-center mb-4">
                                <i class="fas fa-users text-gray-400 text-2xl"></i>
                            </div>
                            <p class="text-gray-500">No hay huéspedes asignados a esta habitación</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
@endif

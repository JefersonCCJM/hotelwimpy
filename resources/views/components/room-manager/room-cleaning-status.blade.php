@props(['room', 'selectedDate'])

@php
    use App\Support\HotelTime;
    use Carbon\Carbon;

    $normalizedSelectedDate = $selectedDate instanceof Carbon
        ? $selectedDate
        : Carbon::parse($selectedDate);

    $selectedDateKey = $normalizedSelectedDate->format('Y-m-d');
    $cleaningStatus = $room->cleaningStatus($normalizedSelectedDate);

    $statusConfig = match ($cleaningStatus['code']) {
        'limpia' => [
            'label' => 'Limpia',
            'icon' => 'fa-check-circle',
            'color' => 'bg-emerald-100 text-emerald-700 border border-emerald-200',
        ],
        'pendiente' => [
            'label' => 'Pendiente por aseo',
            'icon' => 'fa-broom',
            'color' => 'bg-yellow-100 text-yellow-700 border border-yellow-200',
        ],
        'mantenimiento' => [
            'label' => 'Mantenimiento',
            'icon' => 'fa-screwdriver-wrench',
            'color' => 'bg-amber-100 text-amber-700 border border-amber-200',
        ],
        default => [
            'label' => 'Limpia',
            'icon' => 'fa-check-circle',
            'color' => 'bg-emerald-100 text-emerald-700 border border-emerald-200',
        ],
    };

    $isPastDate = HotelTime::isOperationalPastDate($normalizedSelectedDate);
@endphp

<div
    wire:key="room-cleaning-status-{{ $room->id }}-{{ $selectedDateKey }}-{{ $cleaningStatus['code'] }}"
    x-data="{
        showDropdown: false,
        isPastDate: @js($isPastDate),
        panelStyle: '',
        syncPanelPosition() {
            if (!this.showDropdown || !this.$refs.trigger) return;

            const rect = this.$refs.trigger.getBoundingClientRect();
            const panelWidth = 256;
            const panelHeight = 176;
            const viewportPadding = 12;
            const preferUp = (window.innerHeight - rect.bottom) < panelHeight && rect.top > panelHeight;
            const top = preferUp
                ? Math.max(viewportPadding, rect.top - panelHeight - 8)
                : Math.min(window.innerHeight - panelHeight - viewportPadding, rect.bottom + 8);
            const left = Math.min(
                Math.max(viewportPadding, rect.left),
                Math.max(viewportPadding, window.innerWidth - panelWidth - viewportPadding)
            );

            this.panelStyle = `position: fixed; top: ${top}px; left: ${left}px; width: ${panelWidth}px; z-index: 9999;`;
        },
        toggleDropdown() {
            if (this.isPastDate) return;

            this.showDropdown = !this.showDropdown;
            if (this.showDropdown) {
                this.$nextTick(() => this.syncPanelPosition());
            }
        },
        closeDropdown() {
            this.showDropdown = false;
        },
        init() {
            const reposition = () => this.syncPanelPosition();
            window.addEventListener('resize', reposition);
            window.addEventListener('scroll', reposition, true);
        }
    }"
    class="relative inline-block"
>
    <button
        type="button"
        x-ref="trigger"
        @click="toggleDropdown()"
        :disabled="isPastDate"
        :class="isPastDate ? 'cursor-not-allowed opacity-75' : 'cursor-pointer hover:scale-105 transition-transform'"
        wire:loading.class="opacity-75 cursor-wait"
        wire:target="updateCleaningStatus"
        class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusConfig['color'] }}"
        title="{{ $isPastDate ? 'Estado historico (no editable)' : 'Clic para cambiar estado de limpieza' }}">
        <i class="fas {{ $statusConfig['icon'] }} mr-1.5"></i>
        <span>{{ $statusConfig['label'] }}</span>
        @if (!$isPastDate)
            <i class="fas fa-chevron-down ml-1.5 text-[10px]"></i>
        @endif
    </button>

    @if (!$isPastDate)
        <template x-teleport="body">
            <div
                x-show="showDropdown"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="transform opacity-0 scale-95"
                x-transition:enter-end="transform opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="transform opacity-100 scale-100"
                x-transition:leave-end="transform opacity-0 scale-95"
                x-cloak
                @click.outside="closeDropdown()"
                @keydown.escape.window="closeDropdown()"
                :style="panelStyle"
                class="fixed z-[9999] overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-xl"
                style="display: none;">
                <button
                    type="button"
                    wire:loading.attr="disabled"
                    wire:target="updateCleaningStatus"
                    @click="$wire.updateCleaningStatus('limpia'); closeDropdown()"
                    @disabled($cleaningStatus['code'] === 'limpia')
                    class="w-full text-left px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-emerald-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-start gap-2 @if($cleaningStatus['code'] === 'limpia') bg-emerald-50 text-emerald-700 @endif">
                    <i class="fas fa-check-circle mt-0.5 shrink-0 text-emerald-600"></i>
                    <span class="whitespace-normal leading-5">Marcar como limpia</span>
                </button>

                <button
                    type="button"
                    wire:loading.attr="disabled"
                    wire:target="updateCleaningStatus"
                    @click="$wire.updateCleaningStatus('pendiente'); closeDropdown()"
                    @disabled($cleaningStatus['code'] === 'pendiente')
                    class="w-full text-left px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-yellow-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-start gap-2 @if($cleaningStatus['code'] === 'pendiente') bg-yellow-50 text-yellow-700 @endif">
                    <i class="fas fa-broom mt-0.5 shrink-0 text-yellow-600"></i>
                    <span class="whitespace-normal leading-5">Marcar como pendiente</span>
                </button>

                <button
                    type="button"
                    wire:loading.attr="disabled"
                    wire:target="updateCleaningStatus"
                    @click="$wire.updateCleaningStatus('mantenimiento'); closeDropdown()"
                    @disabled($cleaningStatus['code'] === 'mantenimiento')
                    class="w-full text-left px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-amber-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-start gap-2 @if($cleaningStatus['code'] === 'mantenimiento') bg-amber-50 text-amber-700 @endif">
                    <i class="fas fa-screwdriver-wrench mt-0.5 shrink-0 text-amber-600"></i>
                    <span class="whitespace-normal leading-5">Marcar como mantenimiento</span>
                </button>
            </div>
        </template>
    @endif
</div>

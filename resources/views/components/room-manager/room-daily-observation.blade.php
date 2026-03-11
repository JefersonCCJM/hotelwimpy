@php
    use App\Support\HotelTime;
    use Carbon\Carbon;

    $normalizedSelectedDate = $selectedDate instanceof Carbon
        ? $selectedDate
        : Carbon::parse($selectedDate);

    $selectedDateKey = $normalizedSelectedDate->format('Y-m-d');
    $observation = $room->dailyObservation($normalizedSelectedDate);
    $isPastDate = HotelTime::isOperationalPastDate($normalizedSelectedDate);
@endphp

<div
    wire:key="room-daily-observation-{{ $room->id }}-{{ $selectedDateKey }}-{{ md5((string) $observation) }}"
    x-data="{
        showEditor: false,
        draft: @js((string) ($observation ?? '')),
        isPastDate: @js($isPastDate),
        panelStyle: '',
        syncPanelPosition() {
            if (!this.showEditor || !this.$refs.trigger) return;

            const rect = this.$refs.trigger.getBoundingClientRect();
            const panelWidth = 320;
            const panelHeight = 248;
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
        toggleEditor() {
            if (this.isPastDate) return;

            this.showEditor = !this.showEditor;
            if (this.showEditor) {
                this.$nextTick(() => this.syncPanelPosition());
            }
        },
        closeEditor() {
            this.showEditor = false;
        },
        init() {
            const reposition = () => this.syncPanelPosition();
            window.addEventListener('resize', reposition);
            window.addEventListener('scroll', reposition, true);
        }
    }"
    class="relative min-w-[220px]"
>
    <button
        type="button"
        x-ref="trigger"
        @click="toggleEditor()"
        :disabled="isPastDate"
        class="w-full rounded-xl border px-3 py-2 text-left transition-colors"
        :class="isPastDate ? 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-75' : 'border-gray-200 bg-white hover:border-blue-300 hover:bg-blue-50/40'">
        @if (!empty($observation))
            <div class="flex items-start gap-2">
                <i class="fas fa-note-sticky mt-0.5 text-[11px] text-blue-500"></i>
                <span class="block text-xs leading-5 text-gray-700" title="{{ $observation }}">
                    {{ \Illuminate\Support\Str::limit($observation, 80) }}
                </span>
            </div>
        @else
            <div class="flex items-center gap-2 text-gray-400">
                <i class="fas fa-note-sticky text-[11px]"></i>
                <span class="text-xs italic">{{ $isPastDate ? 'Sin observacion' : 'Agregar observacion' }}</span>
            </div>
        @endif
    </button>

    @if (!$isPastDate)
        <template x-teleport="body">
            <div
                x-show="showEditor"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="transform opacity-0 scale-95"
                x-transition:enter-end="transform opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="transform opacity-100 scale-100"
                x-transition:leave-end="transform opacity-0 scale-95"
                x-cloak
                @click.outside="closeEditor()"
                @keydown.escape.window="closeEditor()"
                :style="panelStyle"
                class="fixed z-[9999] rounded-xl border border-gray-200 bg-white p-3 shadow-xl"
                style="display: none;">
                <label class="mb-2 block text-[11px] font-bold uppercase tracking-wider text-gray-500">
                    Observacion del dia
                </label>
                <textarea
                    x-model="draft"
                    rows="4"
                    maxlength="500"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Escribe una observacion para esta habitacion..."></textarea>

                <div class="mt-3 flex items-center justify-between gap-2">
                    <button
                        type="button"
                        @click="draft = ''; $wire.$parent.saveRoomDailyObservation({{ $room->id }}, draft); closeEditor()"
                        class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                        Limpiar
                    </button>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="closeEditor(); draft = @js((string) ($observation ?? ''))"
                            class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                            Cancelar
                        </button>
                        <button
                            type="button"
                            @click="$wire.$parent.saveRoomDailyObservation({{ $room->id }}, draft); closeEditor()"
                            class="inline-flex items-center rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                            Guardar
                        </button>
                    </div>
                </div>
            </div>
        </template>
    @endif
</div>

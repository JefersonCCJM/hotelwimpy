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
    x-data="{ showEditor: false, draft: @js((string) ($observation ?? '')), isPastDate: @js($isPastDate) }"
    class="relative min-w-[220px]"
    @click.away="showEditor = false">
    <button
        type="button"
        @click="if (!isPastDate) showEditor = !showEditor"
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
        <div
            x-show="showEditor"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="transform opacity-0 scale-95"
            x-transition:enter-end="transform opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="transform opacity-100 scale-100"
            x-transition:leave-end="transform opacity-0 scale-95"
            x-cloak
            class="absolute left-0 top-full z-50 mt-2 w-[280px] rounded-xl border border-gray-200 bg-white p-3 shadow-xl"
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
                    @click="draft = ''; $wire.saveRoomDailyObservation({{ $room->id }}, draft); showEditor = false"
                    class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                    Limpiar
                </button>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="showEditor = false; draft = @js((string) ($observation ?? ''))"
                        class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button
                        type="button"
                        @click="$wire.saveRoomDailyObservation({{ $room->id }}, draft); showEditor = false"
                        class="inline-flex items-center rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                        Guardar
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

@php
    $days = collect($daysInMonth ?? [])->values();
    $calendarDate = $days->isNotEmpty()
        ? $days->first()->copy()->startOfMonth()
        : \App\Support\HotelTime::currentOperationalDate()->startOfMonth();

    $today = \App\Support\HotelTime::currentOperationalDate()->startOfDay();
    $sidebarWidthPx = 136;
    $dayCellWidthPx = 56;
    $todayIndex = $days->search(static fn ($day) => $day->isSameDay($today));
    $todayLineLeft = $todayIndex !== false && $todayIndex !== null
        ? $sidebarWidthPx + ($todayIndex * $dayCellWidthPx) + ($dayCellWidthPx / 2)
        : null;
    $canDoCheckIn = auth()->check() && auth()->user()->can('edit_reservations');
    $canDoPayments = auth()->check() && auth()->user()->can('edit_reservations');
    $canDoCancel = auth()->check() && auth()->user()->can('delete_reservations');

    $formatRoomsInfo = static function ($reservation): string {
        if (!$reservation || !isset($reservation->reservationRooms) || $reservation->reservationRooms->isEmpty()) {
            return 'Sin habitaciones asignadas';
        }

        $roomNumbers = $reservation->reservationRooms
            ->map(static fn ($reservationRoom) => $reservationRoom->room?->room_number)
            ->filter()
            ->values()
            ->all();

        return empty($roomNumbers) ? 'Sin habitaciones asignadas' : implode(', ', $roomNumbers);
    };

    // Todas las reservas (RES, RSV, WLK) se muestran en el calendario.
    // Las WLK y RSV (walk-ins) solo aparecen en su día de check-in y en color rojo.
    $shouldDisplayReservationOnCalendar = static function ($reservation): bool {
        if (!$reservation || empty($reservation->id)) {
            return false;
        }

        return true;
    };

    // Identifica reservas walk-in (WLK o RSV): se muestran un solo día y en rojo.
    $isWalkIn = static function ($reservation): bool {
        if (!$reservation) {
            return false;
        }

        $code = strtoupper(trim((string) ($reservation->reservation_code ?? '')));

        return str_starts_with($code, 'WLK-') || str_starts_with($code, 'RSV-');
    };

    $payloadsByReservationId = [];
    $checkedInReservationIds = [];
    $pendingCheckoutReservationIds = [];
    $buildReservationPayload = static function ($reservation) use (
        &$payloadsByReservationId,
        $formatRoomsInfo,
        $shouldDisplayReservationOnCalendar,
        $canDoCheckIn,
        $canDoPayments,
        $canDoCancel,
        $today
    ) {
        if (
            !$reservation
            || empty($reservation->id)
            || !$shouldDisplayReservationOnCalendar($reservation)
        ) {
            return null;
        }

        $reservationId = (int) $reservation->id;
        if (isset($payloadsByReservationId[$reservationId])) {
            return $payloadsByReservationId[$reservationId];
        }

        $firstReservationRoom = $reservation->reservationRooms?->first();
        $checkInDate = $firstReservationRoom?->check_in_date;
        $checkOutDate = $firstReservationRoom?->check_out_date;
        $checkInDateRaw = $checkInDate
            ? \Carbon\Carbon::parse($checkInDate)->toDateString()
            : null;
        $isCancelled = method_exists($reservation, 'trashed') && $reservation->trashed();
        $hasOperationalStay = false;
        $operationalStayStatuses = ['active', 'pending_checkout'];

        if (method_exists($reservation, 'relationLoaded') && $reservation->relationLoaded('stays')) {
            $hasOperationalStay = $reservation->stays->contains(
                static fn ($stay) => in_array((string) ($stay->status ?? ''), $operationalStayStatuses, true),
            );
        } elseif (method_exists($reservation, 'stays')) {
            $hasOperationalStay = $reservation->stays()
                ->whereIn('status', $operationalStayStatuses)
                ->exists();
        }

        $stayNightsTotalRaw = (float) ($reservation->stay_nights_total ?? 0);
        $reservationRoomsTotalRaw = isset($reservation->reservationRooms)
            ? (float) $reservation->reservationRooms->sum(static fn ($item) => (float) ($item->subtotal ?? 0))
            : 0.0;
        $enteredReservationTotalRaw = (float) ($reservation->total_amount ?? 0);
        $totalAmountRaw = $enteredReservationTotalRaw > 0
            ? $enteredReservationTotalRaw
            : max(0, $reservationRoomsTotalRaw, $stayNightsTotalRaw);
        $paymentsTotalRaw = isset($reservation->payments)
            ? (float) $reservation->payments->sum('amount')
            : (float) ($reservation->deposit_amount ?? 0);
        $balanceRaw = max(0, $totalAmountRaw - $paymentsTotalRaw);
        $latestPositivePayment = null;
        if (isset($reservation->payments)) {
            $paymentsCollection = $reservation->payments;
            $latestPositivePayment = $paymentsCollection
                ->where('amount', '>', 0)
                ->sortByDesc('id')
                ->first(static function ($candidatePayment) use ($paymentsCollection) {
                    $reversalReference = 'Anulacion de pago #' . (int) ($candidatePayment->id ?? 0);

                    return !$paymentsCollection->contains(
                        static fn ($existingPayment) => (float) ($existingPayment->amount ?? 0) < 0
                            && (string) ($existingPayment->reference ?? '') === $reversalReference
                    );
                });
        }

        $payloadsByReservationId[$reservationId] = [
            'id' => $reservationId,
            'reservation_code' => (string) ($reservation->reservation_code ?? ''),
            'customer_name' => $reservation->customer ? (string) ($reservation->customer->name ?? '') : 'Sin cliente asignado',
            'customer_identification' => $reservation->customer
                ? ($reservation->customer->identification_number
                    ? ($reservation->customer->identificationType
                        ? $reservation->customer->identificationType->name . ': ' . $reservation->customer->identification_number
                        : $reservation->customer->identification_number)
                    : '-')
                : '-',
            'customer_phone' => $reservation->customer ? (string) ($reservation->customer->phone ?? '-') : '-',
            'rooms' => $formatRoomsInfo($reservation),
            'check_in' => $checkInDate ? \Carbon\Carbon::parse($checkInDate)->format('d/m/Y') : 'N/A',
            'check_in_raw' => $checkInDateRaw,
            'check_out' => $checkOutDate ? \Carbon\Carbon::parse($checkOutDate)->format('d/m/Y') : 'N/A',
            'check_in_time' => $reservation->check_in_time ? substr((string) $reservation->check_in_time, 0, 5) : 'N/A',
            'guests_count' => (int) ($reservation->total_guests ?? 0),
            'payment_method' => $reservation->payment_method ? (string) $reservation->payment_method : 'N/A',
            'total' => number_format($totalAmountRaw, 0, ',', '.'),
            'deposit' => number_format($paymentsTotalRaw, 0, ',', '.'),
            'balance' => number_format($balanceRaw, 0, ',', '.'),
            'total_amount_raw' => $totalAmountRaw,
            'payments_total_raw' => $paymentsTotalRaw,
            'balance_raw' => $balanceRaw,
            'edit_url' => $isCancelled ? null : route('reservations.edit', $reservation),
            'delete_url' => !$isCancelled && $canDoCancel ? route('reservations.destroy', $reservation) : null,
            'check_in_url' => !$isCancelled && $canDoCheckIn ? route('reservations.check-in', $reservation) : null,
            'payment_url' => !$isCancelled && $canDoPayments ? route('reservations.register-payment', $reservation) : null,
            'cancel_payment_url' => $canDoPayments && $latestPositivePayment
                ? route('reservations.cancel-payment', ['reservation' => $reservation, 'payment' => $latestPositivePayment])
                : null,
            'pdf_url' => $isCancelled ? null : route('reservations.download', $reservation),
            'guests_document_view_url' => !$isCancelled && !empty($reservation->guests_document_path)
                ? route('reservations.guest-document.view', $reservation)
                : null,
            'guests_document_download_url' => !$isCancelled && !empty($reservation->guests_document_path)
                ? route('reservations.guest-document.download', $reservation)
                : null,
            'notes' => $reservation->notes ?? 'Sin notas adicionales',
            'status' => $isCancelled ? 'Cancelada' : ($reservation->status ?? 'Activa'),
            'has_operational_stay' => $hasOperationalStay,
            'can_cancel' => !$isCancelled && !$hasOperationalStay && $canDoCancel,
            'auto_cancel_no_show' => !$isCancelled
                && !$hasOperationalStay
                && !empty($checkInDateRaw)
                && \Carbon\Carbon::parse($checkInDateRaw)->startOfDay()->lt($today)
                && $canDoCancel,
            'can_checkin' => !$isCancelled && $canDoCheckIn && !$hasOperationalStay,
            'can_pay' => false,
            'can_cancel_payment' => !$isCancelled && $canDoPayments && !empty($latestPositivePayment),
            'last_payment_amount' => $latestPositivePayment ? (float) ($latestPositivePayment->amount ?? 0) : 0.0,
            'cancelled_at' => $isCancelled && $reservation->deleted_at
                ? $reservation->deleted_at->format('d/m/Y H:i')
                : null,
        ];

        return $payloadsByReservationId[$reservationId];
    };

    $resolveStayCheckoutDate = static function ($stay, int $roomId): ?\Carbon\Carbon {
        if (!empty($stay->check_out_at)) {
            return \Carbon\Carbon::parse($stay->check_out_at)->startOfDay();
        }

        $reservation = $stay->reservation ?? null;
        if (!$reservation || !isset($reservation->reservationRooms)) {
            return null;
        }

        $reservationRoom = $reservation->reservationRooms->first(static function ($item) use ($roomId) {
            return (int) ($item->room_id ?? 0) === $roomId && !empty($item->check_out_date);
        });

        if (!$reservationRoom) {
            $reservationRoom = $reservation->reservationRooms->first(static fn ($item) => !empty($item->check_out_date));
        }

        if (!$reservationRoom || empty($reservationRoom->check_out_date)) {
            return null;
        }

        return \Carbon\Carbon::parse($reservationRoom->check_out_date)->startOfDay();
    };

    $roomTimelines = [];

    foreach ($rooms as $room) {
        $ranges = [];
        $dayCells = [];
        $currentStatus = null;
        $currentReservationId = null;
        $rangeStart = null;
        $rangeReservation = null;

        $checkInByReservation = [];
        $checkOutByReservation = [];

        foreach ($room->reservationRooms as $reservationRoom) {
            $reservationId = (int) ($reservationRoom->reservation_id ?? 0);
            if ($reservationId <= 0) {
                continue;
            }

            if (empty($reservationRoom->reservation)) {
                continue;
            }

            if (!$shouldDisplayReservationOnCalendar($reservationRoom->reservation)) {
                continue;
            }

            $isCancelledReservation = method_exists($reservationRoom->reservation, 'trashed')
                && $reservationRoom->reservation->trashed();
            if ($isCancelledReservation) {
                continue;
            }

            if (!empty($reservationRoom->check_in_date)) {
                $checkInByReservation[$reservationId][\Carbon\Carbon::parse($reservationRoom->check_in_date)->toDateString()] = true;
            }

            if (!empty($reservationRoom->check_out_date)) {
                $checkOutByReservation[$reservationId][\Carbon\Carbon::parse($reservationRoom->check_out_date)->toDateString()] = true;
            }
        }

        foreach ($days as $dayIndex => $day) {
            $dayNormalized = $day->copy()->startOfDay();
            $isPastDate = $dayNormalized->lt($today);
            $dayStatus = 'free';
            $dayReservation = null;

            if ($isPastDate && isset($room->dailyStatuses)) {
                $snapshot = $room->dailyStatuses->first(
                    static fn ($item) => \Carbon\Carbon::parse($item->date)->startOfDay()->equalTo($dayNormalized),
                );

                if ($snapshot) {
                    $snapshotStatus = (string) ($snapshot->status ?? 'free');
                    $dayStatus = in_array(
                        $snapshotStatus,
                        ['free', 'reserved', 'occupied', 'maintenance', 'cleaning', 'checkout_day', 'pending_cleaning'],
                        true,
                    )
                        ? $snapshotStatus
                        : 'free';

                    if ($dayStatus === 'pending_cleaning') {
                        $dayStatus = 'cleaning';
                    }

                    $snapshotReservationId = (int) ($snapshot->reservation_id ?? 0);
                    if ($snapshotReservationId > 0) {
                        $snapshotReservationRoom = $room->reservationRooms
                            ->firstWhere('reservation_id', $snapshotReservationId);
                        $dayReservation = $snapshotReservationRoom?->reservation;

                        if ($dayReservation && !$shouldDisplayReservationOnCalendar($dayReservation)) {
                            $dayStatus = 'free';
                            $dayReservation = null;
                        }

                        if ($dayReservation && method_exists($dayReservation, 'trashed') && $dayReservation->trashed()) {
                            $dayStatus = 'free';
                            $dayReservation = null;
                        }

                        $hasTrackedStay = $room->stays->contains(
                            static function ($stay) use ($snapshotReservationId, $shouldDisplayReservationOnCalendar): bool {
                                if ((int) ($stay->reservation_id ?? 0) !== $snapshotReservationId) {
                                    return false;
                                }

                                if (!in_array((string) ($stay->status ?? ''), ['active', 'pending_checkout', 'finished'], true)) {
                                    return false;
                                }

                                return $shouldDisplayReservationOnCalendar($stay->reservation ?? null);
                            },
                        );

                        if ($hasTrackedStay) {
                            $dayStatus = 'occupied';
                            $dayReservation = null;
                        } elseif (!$dayReservation) {
                            // El snapshot apunta a una ocupación/registro que no debe mostrarse en este calendario.
                            $dayStatus = 'free';
                        }
                    }
                }
            } else {
                $activeStay = $room->stays
                    ->filter(static function ($stay) use ($dayNormalized, $room, $resolveStayCheckoutDate, $shouldDisplayReservationOnCalendar, $isWalkIn, $today) {
                        $status = (string) ($stay->status ?? '');
                        if (!in_array($status, ['active', 'pending_checkout', 'finished'], true)) {
                            return false;
                        }

                        if (empty($stay->check_in_at)) {
                            return false;
                        }

                        if (!$shouldDisplayReservationOnCalendar($stay->reservation ?? null)) {
                            return false;
                        }

                        $checkIn = \Carbon\Carbon::parse($stay->check_in_at)->startOfDay();
                        $checkOut = $resolveStayCheckoutDate($stay, (int) $room->id);
                        $isPendingCheckoutDay = $checkOut
                            && $dayNormalized->isSameDay($today)
                            && $dayNormalized->equalTo($checkOut)
                            && empty($stay->check_out_at)
                            && in_array($status, ['active', 'pending_checkout'], true);

                        if ($isPendingCheckoutDay) {
                            return true;
                        }

                        if ($status === 'pending_checkout') {
                            return false;
                        }

                        if ($checkOut) {
                            return $dayNormalized->gte($checkIn) && $dayNormalized->lt($checkOut);
                        }

                        // Fallback sin fecha de salida: walk-ins solo ocupan su día de entrada;
                        // las reservas normales mantienen la habitación ocupada hacia adelante.
                        if ($isWalkIn($stay->reservation ?? null)) {
                            return $dayNormalized->equalTo($checkIn);
                        }

                        return $dayNormalized->gte($checkIn);
                    })
                    ->sortBy(static function ($stay) use ($dayNormalized, $room, $resolveStayCheckoutDate, $today): int {
                        $status = (string) ($stay->status ?? '');
                        $checkOut = $resolveStayCheckoutDate($stay, (int) $room->id);
                        $isPendingCheckoutDay = $checkOut
                            && $dayNormalized->isSameDay($today)
                            && $dayNormalized->equalTo($checkOut)
                            && empty($stay->check_out_at)
                            && in_array($status, ['active', 'pending_checkout'], true);

                        if ($isPendingCheckoutDay) {
                            return 0;
                        }

                        return match ($status) {
                            'active' => 1,
                            'pending_checkout' => 2,
                            'finished' => 3,
                            default => 4,
                        };
                    })
                    ->first();

                if ($activeStay) {
                    $activeStayStatus = (string) ($activeStay->status ?? '');
                    $dayReservation = $activeStay->reservation ?? null;
                    $activeStayCheckOut = $resolveStayCheckoutDate($activeStay, (int) $room->id);
                    $isPendingCheckoutDay = $activeStayCheckOut
                        && $dayNormalized->isSameDay($today)
                        && $dayNormalized->equalTo($activeStayCheckOut)
                        && empty($activeStay->check_out_at)
                        && in_array($activeStayStatus, ['active', 'pending_checkout'], true);

                    if ($isPendingCheckoutDay) {
                        $dayStatus = 'pending_checkout';
                        if ($dayReservation && !empty($dayReservation->id)) {
                            $pendingCheckoutReservationIds[(int) $dayReservation->id] = true;
                        }
                    } elseif (in_array($activeStayStatus, ['active', 'pending_checkout'], true)) {
                        $dayStatus = 'checked_in';
                        if ($dayReservation && !empty($dayReservation->id)) {
                            $checkedInReservationIds[(int) $dayReservation->id] = true;
                        }
                    } else {
                        $dayStatus = 'occupied';
                    }
                } else {
                    $reservationRoomMatcher = static function ($item) use ($dayNormalized, $shouldDisplayReservationOnCalendar) {
                        if (empty($item->reservation) || empty($item->check_in_date) || empty($item->check_out_date)) {
                            return false;
                        }

                        if (!$shouldDisplayReservationOnCalendar($item->reservation)) {
                            return false;
                        }

                        if (method_exists($item->reservation, 'trashed') && $item->reservation->trashed()) {
                            return false;
                        }

                        $checkIn = \Carbon\Carbon::parse($item->check_in_date)->startOfDay();
                        $checkOut = \Carbon\Carbon::parse($item->check_out_date)->startOfDay();

                        return $dayNormalized->gte($checkIn) && $dayNormalized->lt($checkOut);
                    };

                    $reservationRoom = $room->reservationRooms
                        ->filter(static fn ($item) => $reservationRoomMatcher($item))
                        ->sort(static function ($a, $b): int {
                            $aIsCancelled = method_exists($a->reservation, 'trashed') && $a->reservation->trashed() ? 1 : 0;
                            $bIsCancelled = method_exists($b->reservation, 'trashed') && $b->reservation->trashed() ? 1 : 0;

                            if ($aIsCancelled !== $bIsCancelled) {
                                return $aIsCancelled <=> $bIsCancelled;
                            }

                            // If overlapping reservations exist, prefer the most recent one.
                            return ((int) ($b->reservation_id ?? 0)) <=> ((int) ($a->reservation_id ?? 0));
                        })
                        ->first();

                    if ($reservationRoom) {
                        $dayReservation = $reservationRoom->reservation;
                        $dayStatus = 'reserved';
                    }
                }
            }

            if ($dayStatus !== $currentStatus || ($dayReservation?->id) !== $currentReservationId) {
                if ($currentStatus !== null && $rangeStart !== null) {
                    $ranges[] = [
                        'status' => $currentStatus,
                        'start' => $rangeStart,
                        'end' => $dayIndex - 1,
                        'reservation' => $rangeReservation,
                    ];
                }

                $currentStatus = $dayStatus;
                $currentReservationId = $dayReservation?->id;
                $rangeStart = $dayIndex;
                $rangeReservation = $dayReservation;
            }
        }

        if ($currentStatus !== null && $rangeStart !== null) {
            $ranges[] = [
                'status' => $currentStatus,
                'start' => $rangeStart,
                'end' => max(0, $days->count() - 1),
                'reservation' => $rangeReservation,
            ];
        }

        foreach ($ranges as $range) {
            for ($index = $range['start']; $index <= $range['end']; $index++) {
                if (!isset($days[$index])) {
                    continue;
                }

                $reservation = $range['reservation'];
                $reservationId = (int) ($reservation->id ?? 0);
                $dayKey = $days[$index]->toDateString();

                $dayCells[$index] = [
                    'status' => (string) $range['status'],
                    'reservation' => $reservation,
                    'isRangeStart' => $index === $range['start'],
                    'isRangeEnd' => $index === $range['end'],
                    'isSingleDay' => $range['start'] === $range['end'],
                    'isCheckIn' => $reservationId > 0 && isset($checkInByReservation[$reservationId][$dayKey]),
                    'isCheckOut' => $reservationId > 0 && isset($checkOutByReservation[$reservationId][$dayKey]),
                ];
            }

            if (
                !empty($range['reservation'])
                && !(method_exists($range['reservation'], 'trashed') && $range['reservation']->trashed())
            ) {
                $buildReservationPayload($range['reservation']);
            }
        }

        $roomTimelines[(int) $room->id] = [
            'ranges' => $ranges,
            'dayCells' => $dayCells,
        ];
    }

    foreach (array_keys($checkedInReservationIds) as $checkedInReservationId) {
        if (!isset($payloadsByReservationId[$checkedInReservationId])) {
            continue;
        }

        $payloadsByReservationId[$checkedInReservationId]['status'] = 'Llego';
        $payloadsByReservationId[$checkedInReservationId]['can_checkin'] = false;
        $payloadsByReservationId[$checkedInReservationId]['can_pay'] = !empty($payloadsByReservationId[$checkedInReservationId]['payment_url']);
    }

    foreach (array_keys($pendingCheckoutReservationIds) as $pendingCheckoutReservationId) {
        if (!isset($payloadsByReservationId[$pendingCheckoutReservationId])) {
            continue;
        }

        $payloadsByReservationId[$pendingCheckoutReservationId]['status'] = 'Pendiente checkout';
        $payloadsByReservationId[$pendingCheckoutReservationId]['can_checkin'] = false;
        $payloadsByReservationId[$pendingCheckoutReservationId]['can_pay'] = !empty($payloadsByReservationId[$pendingCheckoutReservationId]['payment_url']);
    }

    $monthLabel = ucfirst($calendarDate->translatedFormat('F Y'));
    $prevMonth = $calendarDate->copy()->subMonth()->format('Y-m');
    $nextMonth = $calendarDate->copy()->addMonth()->format('Y-m');
    $initialCalendarMode = request()->get('calendar_mode', 'month');
    if (!in_array($initialCalendarMode, ['month', 'week', 'day'], true)) {
        $initialCalendarMode = 'month';
    }
    $initialDayIndex = is_int($todayIndex) ? $todayIndex : 0;
    $prevMonthUrl = route('reservations.index', ['view' => 'calendar', 'month' => $prevMonth, 'calendar_mode' => $initialCalendarMode]);
    $nextMonthUrl = route('reservations.index', ['view' => 'calendar', 'month' => $nextMonth, 'calendar_mode' => $initialCalendarMode]);
@endphp

<div
    x-data="reservationCalendarGrid({
        initialMode: @js($initialCalendarMode),
        initialDayIndex: {{ (int) $initialDayIndex }},
        daysCount: {{ (int) $days->count() }},
        prevMonthUrl: @js($prevMonthUrl),
        nextMonthUrl: @js($nextMonthUrl),
        monthLabel: @js($monthLabel),
    })"
    class="bg-gray-50 p-4 sm:p-6 rounded-2xl shadow-sm border border-gray-200">
    <div class="flex flex-col md:flex-row md:items-center justify-between mb-6 gap-4">
        <div class="flex items-center gap-4">
            <h2 class="text-xl font-bold text-gray-800">Calendario de Ocupación</h2>
            <div class="flex bg-white rounded-lg border border-gray-200 p-1 shadow-sm">
                <button
                    type="button"
                    @click="setMode('month')"
                    :class="isMode('month') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50'"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors">
                    Mes
                </button>
                <button
                    type="button"
                    @click="setMode('week')"
                    :class="isMode('week') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50'"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors">
                    Semana
                </button>
                <button
                    type="button"
                    @click="setMode('day')"
                    :class="isMode('day') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50'"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors">
                    Día
                </button>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <div class="flex items-center bg-white rounded-lg border border-gray-200 shadow-sm">
                <button type="button" @click="navigate(-1)"
                    class="p-2 hover:bg-gray-50 border-r border-gray-200" aria-label="Anterior">
                    <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <span class="px-4 py-2 text-sm font-semibold text-gray-700 capitalize" x-text="periodLabel()">{{ $monthLabel }}</span>
                <button type="button" @click="navigate(1)"
                    class="p-2 hover:bg-gray-50 border-l border-gray-200" aria-label="Siguiente">
                    <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </div>

            <button type="button" onclick="openCreateReservationModal()"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-all shadow-md flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Nueva Reserva
            </button>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto relative">
            @if ($todayLineLeft !== null)
                <div x-show="mode === 'month'" class="absolute top-0 bottom-0 w-px bg-orange-400 z-20 pointer-events-none"
                    style="left: {{ $todayLineLeft }}px; margin-top: 52px;">
                    <div
                        class="bg-orange-400 text-white text-[10px] px-1.5 py-0.5 rounded-full absolute -top-6 -left-4 font-bold">
                        HOY
                    </div>
                </div>
            @endif

            <table class="w-full border-separate border-spacing-0">
                <thead>
                    <tr class="bg-gray-50">
                        <th
                            class="sticky left-0 z-30 bg-gray-50 p-3 text-left border-r border-b border-gray-200 min-w-[136px] w-[136px] shadow-[4px_0_6px_-4px_rgba(0,0,0,0.1)]">
                            <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Habitación</span>
                        </th>
                        @foreach ($days as $dayIndex => $day)
                            <th :class="{ 'hidden': !isVisibleDay({{ $dayIndex }}) }" class="border-b border-r border-gray-100 min-w-[56px] w-[56px] py-3 transition-colors {{ $day->isSameDay($today) ? 'bg-orange-50/50' : '' }}">
                                <div class="flex flex-col items-center">
                                    <span
                                        class="text-[10px] font-bold text-gray-400 uppercase leading-none mb-1">{{ substr($day->translatedFormat('D'), 0, 1) }}</span>
                                    <span
                                        class="text-sm font-bold {{ $day->isSameDay($today) ? 'text-orange-600' : 'text-gray-700' }}">{{ $day->day }}</span>
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @forelse ($rooms as $room)
                        @php
                            $timeline = $roomTimelines[(int) $room->id] ?? ['dayCells' => []];
                        @endphp
                        <tr class="group hover:bg-gray-50/40 transition-colors">
                            <td
                                class="sticky left-0 z-30 bg-white group-hover:bg-gray-50/40 p-3 border-r border-b border-gray-100 min-w-[136px] w-[136px] shadow-[4px_0_6px_-4px_rgba(0,0,0,0.1)] transition-colors">
                                <div class="flex flex-col">
                                    <span class="text-sm font-bold text-gray-900 leading-tight">{{ $room->room_number }}</span>
                                    <div class="flex items-center gap-1 mt-1">
                                        <svg class="w-3 h-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path
                                                d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a2 2 0 012-2h12a2 2 0 012 2v4a2 2 0 01-2 2H4a2 2 0 01-2-2v-4z" />
                                        </svg>
                                        <span class="text-[10px] font-medium text-gray-500 uppercase tracking-tight">
                                            {{ (int) ($room->beds_count ?? 0) }} {{ (int) ($room->beds_count ?? 0) === 1 ? 'Cama' : 'Camas' }}
                                        </span>
                                    </div>
                                </div>
                            </td>

                            @foreach ($days as $dayIndex => $day)
                                @php
                                    $cell = $timeline['dayCells'][$dayIndex] ?? [
                                        'status' => 'free',
                                        'reservation' => null,
                                        'isRangeStart' => true,
                                        'isRangeEnd' => true,
                                        'isSingleDay' => true,
                                        'isCheckIn' => false,
                                        'isCheckOut' => false,
                                    ];

                                    $status = (string) ($cell['status'] ?? 'free');
                                    $reservation = $cell['reservation'] ?? null;
                                    $reservationId = (int) ($reservation->id ?? 0);
                                    $payload = $reservationId > 0 ? ($payloadsByReservationId[$reservationId] ?? null) : null;
                                    $cellIsWalkIn = $isWalkIn($reservation);

                                    $statusLabel = match ($status) {
                                        'checked_in' => 'Llego',
                                        'pending_checkout' => 'Pendiente checkout',
                                        'reserved' => 'Reservada',
                                        'occupied' => 'Ocupada',
                                        'cancelled' => 'Cancelada',
                                        'checkout_day' => 'Día de salida',
                                        'maintenance' => 'Mantenimiento',
                                        'cleaning' => 'Limpieza',
                                        default => 'Disponible',
                                    };

                                    $tooltipData = [
                                        'room' => (string) ($room->room_number ?? ''),
                                        'beds' => (int) ($room->beds_count ?? 0) . ((int) ($room->beds_count ?? 0) === 1 ? ' Cama' : ' Camas'),
                                        'date' => $day->format('d/m/Y'),
                                        'status' => $statusLabel,
                                    ];

                                    if ($reservation && $reservation->customer) {
                                        $tooltipData['customer'] = (string) ($reservation->customer->name ?? 'N/A');
                                        $tooltipData['check_in'] = $payload['check_in'] ?? 'N/A';
                                        $tooltipData['check_out'] = $payload['check_out'] ?? 'N/A';
                                    }

                                    $rounded = '';
                                    if (!empty($cell['isSingleDay'])) {
                                        $rounded = 'rounded-lg';
                                    } elseif (!empty($cell['isRangeStart'])) {
                                        $rounded = 'rounded-l-lg';
                                    } elseif (!empty($cell['isRangeEnd'])) {
                                        $rounded = 'rounded-r-lg';
                                    }

                                    if ($status === 'pending_checkout') {
                                        $barClasses = 'bg-yellow-400 hover:bg-yellow-500 text-white';
                                    } elseif ($cellIsWalkIn) {
                                        $barClasses = 'bg-red-500 hover:bg-red-600 text-white';
                                    } else {
                                        $barClasses = match ($status) {
                                            'checked_in' => 'bg-emerald-600 hover:bg-emerald-700 text-white',
                                            'reserved' => 'bg-indigo-500 hover:bg-indigo-600 text-white',
                                            'occupied' => 'bg-red-500 hover:bg-red-600 text-white',
                                            'cancelled' => 'bg-slate-100 border border-dashed border-slate-300 hover:bg-slate-200 text-slate-600',
                                            'checkout_day' => 'bg-blue-50 border-2 border-dashed border-blue-400 hover:bg-blue-100 text-blue-600',
                                            'maintenance' => 'bg-yellow-400 hover:bg-yellow-500 text-white',
                                            'cleaning' => 'bg-purple-400 hover:bg-purple-500 text-white',
                                            default => 'bg-emerald-50 border border-emerald-100 hover:bg-emerald-100 text-emerald-500',
                                        };
                                    }
                                @endphp

                                <td :class="{ 'hidden': !isVisibleDay({{ $dayIndex }}) }" class="border-r border-b border-gray-50 p-0 relative h-14 min-w-[56px] w-[56px]">
                                    @if ($status === 'free')
                                        <div class="m-1 h-10 rounded-md {{ $barClasses }} transition-colors cursor-pointer group/cell flex items-center justify-center"
                                            data-tooltip='@json($tooltipData)'>
                                            <span class="opacity-0 group-hover/cell:opacity-100 text-xs font-bold">+</span>
                                        </div>
                                    @else
                                        <div class="h-12 w-full {{ $rounded }} {{ $barClasses }} transition-all shadow-sm hover:brightness-110 {{ $payload ? 'cursor-pointer group/cell' : 'cursor-default' }}"
                                            data-tooltip='@json($tooltipData)'
                                            @if ($payload) onclick='openReservationDetail(@json($payload))' @endif>
                                            <div class="relative h-full w-full flex items-center justify-center overflow-hidden px-1">
                                                @if (!empty($cell['isRangeStart']) && $reservation && $reservation->customer)
                                                    <span class="text-[10px] font-bold truncate px-1">
                                                        {{ \Illuminate\Support\Str::limit((string) ($reservation->customer->name ?? 'Reserva'), 16) }}
                                                    </span>
                                                @endif

                                                @if (!empty($cell['isCheckIn']) && $status !== 'cancelled')
                                                    <i class="fas fa-sign-in-alt absolute left-1 top-1 text-[9px] opacity-80"></i>
                                                @endif

                                                @if (!empty($cell['isCheckOut']) && $status !== 'cancelled')
                                                    <i class="fas fa-sign-out-alt absolute right-1 top-1 text-[9px] opacity-80"></i>
                                                @endif

                                                @if ($status === 'cancelled')
                                                    <i class="fas fa-ban absolute left-1 bottom-1 text-[9px] opacity-80"></i>
                                                @endif

                                                @if ($status === 'checked_in')
                                                    <i class="fas fa-check-circle absolute left-1 bottom-1 text-[9px] opacity-80"></i>
                                                @endif

                                                @if ($status === 'pending_checkout')
                                                    <i class="fas fa-door-open absolute left-1 bottom-1 text-[9px] opacity-80"></i>
                                                @endif

                                                @if ($payload)
                                                    <i
                                                        class="fas fa-eye absolute bottom-1 right-1 text-[9px] opacity-0 group-hover/cell:opacity-90 transition-opacity"></i>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 1 + $days->count() }}" class="p-10 text-center text-sm text-gray-500">
                                No hay habitaciones configuradas para mostrar en el calendario.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            if (typeof window.reservationCalendarGrid !== 'function') {
                window.reservationCalendarGrid = function(config) {
                    var safeDaysCount = Math.max(0, Number((config && config.daysCount) || 0));
                    var maxIndex = Math.max(0, safeDaysCount - 1);
                    var safeInitialIndex = Math.min(Math.max(Number((config && config.initialDayIndex) || 0), 0), maxIndex);
                    var allowedModes = ['month', 'week', 'day'];
                    var safeInitialMode = allowedModes.indexOf(config && config.initialMode) !== -1
                        ? config.initialMode
                        : 'month';

                    return {
                        mode: safeInitialMode,
                        selectedDayIndex: safeInitialIndex,
                        daysCount: safeDaysCount,
                        monthLabel: (config && config.monthLabel) || '',
                        prevMonthUrl: (config && config.prevMonthUrl) || window.location.href,
                        nextMonthUrl: (config && config.nextMonthUrl) || window.location.href,

                        isMode: function(mode) {
                            return this.mode === mode;
                        },

                        setMode: function(mode) {
                            if (allowedModes.indexOf(mode) === -1) {
                                return;
                            }

                            this.mode = mode;
                            if (this.selectedDayIndex > this.daysCount - 1) {
                                this.selectedDayIndex = Math.max(0, this.daysCount - 1);
                            }
                        },

                        getWeekStartIndex: function() {
                            if (this.daysCount <= 0) {
                                return 0;
                            }

                            return Math.floor(this.selectedDayIndex / 7) * 7;
                        },

                        isVisibleDay: function(index) {
                            if (this.mode === 'month') {
                                return true;
                            }

                            if (this.mode === 'week') {
                                var start = this.getWeekStartIndex();
                                return index >= start && index < (start + 7);
                            }

                            return index === this.selectedDayIndex;
                        },

                        navigate: function(direction) {
                            if (this.mode === 'month') {
                                window.location.assign(direction < 0 ? this.prevMonthUrl : this.nextMonthUrl);
                                return;
                            }

                            var step = this.mode === 'week' ? 7 : 1;
                            var nextIndex = this.selectedDayIndex + (direction * step);
                            if (nextIndex >= 0 && nextIndex < this.daysCount) {
                                this.selectedDayIndex = nextIndex;
                                return;
                            }

                            window.location.assign(direction < 0 ? this.prevMonthUrl : this.nextMonthUrl);
                        },

                        periodLabel: function() {
                            if (this.mode === 'month' || this.daysCount <= 0) {
                                return this.monthLabel;
                            }

                            if (this.mode === 'week') {
                                var start = this.getWeekStartIndex() + 1;
                                var end = Math.min(this.getWeekStartIndex() + 7, this.daysCount);
                                return 'Semana ' + start + ' - ' + end;
                            }

                            return 'Día ' + (this.selectedDayIndex + 1);
                        },
                    };
                };
            }
        </script>
    @endpush
@endonce

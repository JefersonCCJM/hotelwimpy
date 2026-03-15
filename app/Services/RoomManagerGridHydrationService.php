<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomQuickReservation;
use App\Models\StayNight;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RoomManagerGridHydrationService
{
    private array $reservationStatusCache = [];

    /**
     * @param Collection<int, Room> $rooms
     * @return Collection<int, Room>
     */
    public function hydrateRooms(Collection $rooms, Carbon $selectedDate): Collection
    {
        if ($rooms->isEmpty()) {
            return $rooms;
        }

        $roomIds = $rooms->pluck('id');
        $quickReservedRoomMap = $this->getQuickReservedRoomMap($selectedDate, $roomIds);
        $nightStatusByReservationRoom = collect();

        if (Schema::hasTable('stay_nights')) {
            $nightStatusByReservationRoom = StayNight::query()
                ->whereDate('date', $selectedDate->toDateString())
                ->whereIn('room_id', $roomIds)
                ->get(['reservation_id', 'room_id', 'is_paid'])
                ->keyBy(static function (StayNight $night): string {
                    return ((int) $night->reservation_id) . '-' . ((int) $night->room_id);
                });
        }

        return $rooms->map(
            fn (Room $room): Room => $this->hydrateRoom(
                $room,
                $selectedDate,
                $quickReservedRoomMap,
                $nightStatusByReservationRoom
            )
        );
    }

    public function loadRoomForGrid(int $roomId, Carbon $selectedDate): ?Room
    {
        $startOfMonth = $selectedDate->copy()->startOfMonth();
        $endOfMonth = $selectedDate->copy()->endOfMonth();
        $endOfMonthWithBuffer = $endOfMonth->copy()->addDay();
        $with = [
            'roomType',
            'ventilationType',
            'reservationRooms' => function ($q) use ($startOfMonth, $endOfMonth) {
                $q->where('check_in_date', '<=', $endOfMonth->toDateString())
                    ->where('check_out_date', '>=', $startOfMonth->toDateString())
                    ->with(['reservation' => function ($r) {
                        $r->with(['customer', 'sales', 'payments']);
                    }]);
            },
            'operationalStatuses' => function ($q) use ($startOfMonth, $endOfMonthWithBuffer) {
                $q->whereDate('operational_date', '>=', $startOfMonth->toDateString())
                    ->whereDate('operational_date', '<=', $endOfMonthWithBuffer->toDateString());
            },
            'maintenanceBlocks' => function ($q) {
                $q->where('status_id', function ($subq) {
                    $subq->select('id')
                        ->from('room_maintenance_block_statuses')
                        ->where('code', 'active');
                });
            },
        ];

        if (Schema::hasTable('room_rates')) {
            $with[] = 'rates';
        }

        $room = Room::query()
            ->with($with)
            ->find($roomId);

        if (!$room) {
            return null;
        }

        return $this->hydrateRooms(collect([$room]), $selectedDate)->first();
    }

    /**
     * @param array<int, bool> $quickReservedRoomMap
     * @param Collection<string, StayNight> $nightStatusByReservationRoom
     */
    public function hydrateRoom(
        Room $room,
        Carbon $selectedDate,
        array $quickReservedRoomMap,
        Collection $nightStatusByReservationRoom
    ): Room {
        $operationalStatus = $room->getOperationalStatus($selectedDate);
        $roomIsQuickReserved = isset($quickReservedRoomMap[(int) $room->id]);
        if ($roomIsQuickReserved && in_array($operationalStatus, ['occupied', 'pending_checkout'], true)) {
            $roomIsQuickReserved = false;
        }

        $room->display_status = $room->getDisplayStatus($selectedDate);
        $room->current_stay = in_array($operationalStatus, ['occupied', 'pending_checkout'], true)
            ? $room->getAvailabilityService()->getStayForDate($selectedDate)
            : null;
        $room->current_reservation = $room->current_stay?->reservation;
        if (!$room->current_reservation && in_array($operationalStatus, ['occupied', 'pending_checkout'], true)) {
            $room->current_reservation = $room->getActiveReservation($selectedDate);
        }
        $room->future_reservation = $room->getFutureReservation($selectedDate);
        $room->pending_checkin_reservation = $this->getPendingCheckInReservationForRoom($room, $selectedDate);

        if ($room->current_stay) {
            $room->current_stay->loadMissing([
                'reservation.customer',
                'reservation.reservationRooms' => function ($query) use ($room) {
                    $query->where('room_id', $room->id);
                },
            ]);
        }

        if ($room->current_reservation) {
            $room->current_reservation->loadMissing(['customer']);
        }

        if ($room->future_reservation) {
            $room->future_reservation->loadMissing(['customer']);
        }

        if ($room->pending_checkin_reservation) {
            $room->pending_checkin_reservation->loadMissing(['customer']);
        }

        // Only reservations that overlap the selected operational day should tint the row.
        $reservationForVisual = $room->pending_checkin_reservation;
        $reservationForVisualCode = strtoupper(trim((string) ($reservationForVisual->reservation_code ?? '')));
        $hasPendingReservationVisual = $reservationForVisual
            && str_starts_with($reservationForVisualCode, 'RES-')
            && $operationalStatus === 'free_clean';

        $room->is_quick_reserved = $roomIsQuickReserved || $hasPendingReservationVisual;

        if ($room->current_reservation) {
            $reservation = $room->current_reservation;
            $nightStatusKey = ((int) $reservation->id) . '-' . ((int) $room->id);
            $nightStatusForOperationalDate = $nightStatusByReservationRoom->get($nightStatusKey);

            $stay = $room->current_stay;
            $reservationTotalAmount = (float) ($reservation->total_amount ?? 0);

            $reservation->loadMissing(['payments']);
            $paidAmount = (float) ($reservation->payments
                ->where('amount', '>', 0)
                ->sum('amount') ?? 0);

            $reservationRoom = $room->reservationRooms?->first(function ($rr) use ($selectedDate) {
                return $rr->check_in_date <= $selectedDate->toDateString()
                    && $rr->check_out_date >= $selectedDate->toDateString();
            });

            $totalNights = $reservationRoom?->nights ?? 1;
            if ($totalNights <= 0 && $reservationRoom) {
                $checkIn = $reservationRoom->check_in_date ? Carbon::parse($reservationRoom->check_in_date) : null;
                $checkOut = $reservationRoom->check_out_date ? Carbon::parse($reservationRoom->check_out_date) : null;
                if ($checkIn && $checkOut) {
                    $totalNights = max(1, $checkIn->diffInDays($checkOut));
                }
            }

            $roomContractTotal = $reservationTotalAmount;
            if ($reservationRoom) {
                $roomSubtotal = (float) ($reservationRoom->subtotal ?? 0);
                if ($roomSubtotal > 0) {
                    $roomContractTotal = $roomSubtotal;
                }
            }

            $pricePerNight = ($roomContractTotal > 0 && $totalNights > 0)
                ? round($roomContractTotal / $totalNights, 2)
                : 0.0;

            if ($stay && $stay->check_in_at) {
                $checkIn = Carbon::parse($stay->check_in_at)->startOfDay();
            } elseif ($reservationRoom && $reservationRoom->check_in_date) {
                $checkIn = Carbon::parse($reservationRoom->check_in_date)->startOfDay();
            } else {
                $checkIn = null;
            }

            $today = $selectedDate->copy()->startOfDay();
            if ($checkIn) {
                $nightsConsumed = $today->lt($checkIn)
                    ? 0
                    : max(1, $checkIn->diffInDays($today) + 1);
            } else {
                $nightsConsumed = 1;
            }

            $nightsConsumed = min($totalNights, $nightsConsumed);
            $expectedPaid = $pricePerNight * $nightsConsumed;

            if ($nightStatusForOperationalDate) {
                $room->is_night_paid = (bool) ($nightStatusForOperationalDate->is_paid ?? false);
            } else {
                $room->is_night_paid = $expectedPaid > 0 && $paidAmount >= $expectedPaid;
            }

            $refundsTotal = abs((float) ($reservation->payments
                ->where('amount', '<', 0)
                ->sum('amount') ?? 0));

            $totalStay = $roomContractTotal > 0 ? $roomContractTotal : ($pricePerNight * $totalNights);

            $reservation->loadMissing(['sales']);
            $salesDebt = 0.0;
            if ($reservation->sales) {
                $salesDebt = (float) $reservation->sales->where('is_paid', false)->sum('total');
            }

            $computedDebt = ($totalStay - $paidAmount) + $refundsTotal + $salesDebt;
            $room->total_debt = $computedDebt;
            if ($computedDebt <= 0.01) {
                $room->is_night_paid = true;
            }
        } else {
            $room->total_debt = 0;
            $room->is_night_paid = true;
        }

        return $room;
    }

    /**
     * @param Collection<int, int|string> $roomIds
     * @return array<int, bool>
     */
    private function getQuickReservedRoomMap(Carbon $selectedDate, Collection $roomIds): array
    {
        if ($roomIds->isEmpty()) {
            return [];
        }

        return RoomQuickReservation::query()
            ->whereDate('operational_date', $selectedDate->toDateString())
            ->whereIn('room_id', $roomIds->map(static fn ($id) => (int) $id)->all())
            ->pluck('room_id')
            ->map(static fn ($id) => (int) $id)
            ->flip()
            ->map(static fn () => true)
            ->all();
    }

    private function getPendingCheckInReservationForRoom(Room $room, Carbon $selectedDate): ?Reservation
    {
        $dateString = $selectedDate->toDateString();
        $excludedStatusIds = array_values(array_unique(array_filter([
            $this->resolveReservationStatusId('checked_in'),
            $this->resolveReservationStatusId('checked_out'),
            $this->resolveReservationStatusId('cancelled'),
        ])));

        $query = ReservationRoom::query()
            ->where('room_id', (int) $room->id)
            ->where(function ($query) use ($dateString) {
                $query->where(function ($checkInQuery) use ($dateString) {
                    $checkInQuery->whereNotNull('check_in_date')
                        ->whereDate('check_in_date', '<=', $dateString);
                })->orWhere(function ($fallbackQuery) use ($dateString) {
                    $fallbackQuery->whereNull('check_in_date')
                        ->whereHas('reservation', function ($reservationQuery) use ($dateString) {
                            $reservationQuery->whereDate('check_in_date', '<=', $dateString);
                        });
                });
            })
            ->where(function ($query) use ($dateString) {
                $query->whereNull('check_out_date')
                    ->orWhereDate('check_out_date', '>', $dateString);
            })
            ->whereHas('reservation', function ($query) {
                $query->whereNull('deleted_at');
            })
            ->whereDoesntHave('reservation.stays', function ($query) use ($room) {
                $query->where('room_id', (int) $room->id);
            })
            ->with(['reservation.customer'])
            ->orderBy('check_in_date')
            ->orderBy('id');

        if (!empty($excludedStatusIds) && Schema::hasColumn('reservations', 'status_id')) {
            $query->whereHas('reservation', function ($reservationQuery) use ($excludedStatusIds) {
                $reservationQuery->where(function ($statusQuery) use ($excludedStatusIds) {
                    $statusQuery->whereNull('status_id')
                        ->orWhereNotIn('status_id', $excludedStatusIds);
                });
            });
        }

        return $query->first()?->reservation;
    }

    private function resolveReservationStatusId(string $type): ?int
    {
        if (array_key_exists($type, $this->reservationStatusCache)) {
            return $this->reservationStatusCache[$type];
        }

        if (!Schema::hasTable('reservation_statuses')) {
            $this->reservationStatusCache[$type] = null;
            return null;
        }

        $normalize = static function (?string $value): string {
            $raw = trim((string) ($value ?? ''));
            if ($raw === '') {
                return '';
            }

            $normalized = Str::ascii(strtolower($raw));
            $normalized = str_replace(['-', ' '], '_', $normalized);

            return preg_replace('/_+/', '_', $normalized) ?? '';
        };

        $matches = [
            'checked_in' => ['checked_in', 'check_in', 'checkedin', 'arrived', 'llego'],
            'checked_out' => ['checked_out', 'check_out', 'checkedout', 'departed', 'salida', 'egresado', 'salio'],
            'cancelled' => ['cancelled', 'canceled', 'cancelada', 'cancelado', 'anulada', 'anulado'],
        ];

        $statuses = DB::table('reservation_statuses')
            ->select(['id', 'code', 'name'])
            ->get();

        $match = $statuses->first(function ($status) use ($normalize, $type, $matches): bool {
            $code = $normalize((string) ($status->code ?? ''));
            $name = $normalize((string) ($status->name ?? ''));

            if (in_array($code, $matches[$type] ?? [], true) || in_array($name, $matches[$type] ?? [], true)) {
                return true;
            }

            return match ($type) {
                'checked_in' => (str_contains($code, 'check') && str_contains($code, 'in'))
                    || (str_contains($name, 'check') && str_contains($name, 'in'))
                    || str_contains($code, 'llego')
                    || str_contains($name, 'llego'),
                'checked_out' => (str_contains($code, 'check') && str_contains($code, 'out'))
                    || (str_contains($name, 'check') && str_contains($name, 'out'))
                    || str_contains($code, 'salid')
                    || str_contains($name, 'salid')
                    || str_contains($code, 'egres')
                    || str_contains($name, 'egres'),
                'cancelled' => str_contains($code, 'cancel')
                    || str_contains($name, 'cancel')
                    || str_contains($code, 'anulad')
                    || str_contains($name, 'anulad'),
                default => false,
            };
        });

        $this->reservationStatusCache[$type] = $match ? (int) $match->id : null;

        return $this->reservationStatusCache[$type];
    }
}

<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\VentilationType;
use App\Models\ReservationRoom;
use App\Models\Reservation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ReservationSale;
use App\Models\RoomReleaseHistory;
use App\Models\RoomQuickReservation;
use App\Models\ShiftHandover;
use App\Models\Stay;
use App\Enums\ShiftHandoverStatus;
use App\Services\AuditService;
use App\Services\RoomOperationalStatusService;
use App\Services\ReservationCancellationService;
use App\Services\ReservationRoomPricingService;
use App\Models\StayNight;
use App\Enums\RoomDisplayStatus;
use App\Support\HotelTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RoomManager extends Component
{
    use WithPagination;
    private const ALLOWED_STATUS_FILTERS = ['libre', 'ocupada', 'pendiente_checkout'];
    private const ALLOWED_CLEANING_STATUS_FILTERS = ['limpia', 'pendiente', 'mantenimiento'];

    public function dehydrate(): void
    {
        $this->sanitizeUtf8PublicState();
    }

    // Propiedades de estado
    public string $activeTab = 'rooms';
    public $currentDate = null;
    public $date = null;
    public $search = '';
    public $statusFilter = null;
    public $cleaningStatusFilter = null;
    public $ventilationTypeFilter = null;

    // Modales
    public bool $quickRentModal = false;
    public bool $quickReservationModal = false;
    public bool $roomDetailModal = false;
    public bool $roomEditModal = false;
    public bool $createRoomModal = false;
    public bool $releaseHistoryDetailModal = false;
    public bool $roomReleaseConfirmationModal = false;
    public bool $guestsModal = false;
    public bool $assignGuestsModal = false;
    public bool $roomDailyHistoryModal = false;
    public bool $isReleasingRoom = false;
    public bool $editPricesModal = false;
    public bool $allGuestsModal = false;
    public bool $changeRoomModal = false;

    // Datos de modales
    public ?array $detailData = null;
    public ?array $rentForm = null;
    public ?array $quickReservationForm = null;
    public ?array $assignGuestsForm = null;
    public ?array $roomDailyHistoryData = null;
    public ?array $editPricesForm = null;
    public ?array $allGuestsForm = null;
    public array $changeRoomData = [];
    public array $availableRoomsForChange = [];
    
    // Computed properties para UX (no persistidos)
    public function getBalanceDueProperty()
    {
        if (!$this->rentForm) return 0;
        $total = (float)($this->rentForm['total'] ?? 0);
        $deposit = (float)($this->rentForm['deposit'] ?? 0);
        return max(0, $total - $deposit);
    }
    
    public function getPaymentStatusBadgeProperty()
    {
        if (!$this->rentForm) return ['text' => 'Sin datos', 'color' => 'gray'];
        
        $total = (float)($this->rentForm['total'] ?? 0);
        $deposit = (float)($this->rentForm['deposit'] ?? 0);
        
        if ($deposit >= $total && $total > 0) {
            return ['text' => 'Pagado', 'color' => 'emerald'];
        } elseif ($deposit > 0) {
            return ['text' => 'Pago parcial', 'color' => 'amber'];
        } else {
            return ['text' => 'Pendiente de pago', 'color' => 'red'];
        }
    }
    
    // Métodos para botones rápidos de pago
    public function setDepositFull()
    {
        if ($this->rentForm) {
            $this->rentForm['deposit'] = $this->rentForm['total'];
        }
    }
    
    public function setDepositHalf()
    {
        if ($this->rentForm) {
            $this->rentForm['deposit'] = round($this->rentForm['total'] / 2, 2);
        }
    }
    
    public function setDepositNone()
    {
        if ($this->rentForm) {
            $this->rentForm['deposit'] = 0;
            $this->resetQuickRentTransferFields();
        }
    }

    private function resetQuickRentTransferFields(): void
    {
        if (! $this->rentForm) {
            return;
        }

        $this->rentForm['payment_method'] = 'efectivo';
        $this->rentForm['bank_name'] = '';
        $this->rentForm['reference'] = '';
    }

    /**
     * Calcula el número total de huéspedes (principal + adicionales) con fallback a 1.
     */
    private function calculateGuestCount(): int
    {
        if (!$this->rentForm) {
            return 1;
        }

        $principal = !empty($this->rentForm['client_id']) ? 1 : 0;
        $additional = is_array($this->additionalGuests) ? count($this->additionalGuests) : 0;

        return max(1, $principal + $additional);
    }

    /**
     * Selecciona la tarifa adecuada según cantidad de huéspedes.
     * REGLA HOTELERA: Cada tarifa tiene un rango válido [min_guests, max_guests].
     * - Busca la primera tarifa cuyo rango contiene el número de huéspedes.
     * - max_guests debe ser > 0 (no existen rangos abiertos ambiguos en hotelería).
     * - Fallback al base_price_per_night si no hay tarifas o ninguna coincide.
     * 
     * @param Room $room Habitación con sus tarifas cargadas
     * @param int $guests Número de huéspedes
     * @return float Precio por noche válido (siempre > 0 si existe base_price)
     */
    private function findRateForGuests(Room $room, int $guests): float
    {
        if ($guests <= 0) {
            \Log::warning('findRateForGuests: Invalid guests count', ['guests' => $guests, 'room_id' => $room->id]);
            return (float)($room->base_price_per_night ?? 0);
        }

        $rates = $room->rates;

        if ($rates && $rates->isNotEmpty()) {
            $validRates = $rates->filter(function ($rate) use ($room) {
                $min = (int)($rate->min_guests ?? 0);
                $max = (int)($rate->max_guests ?? 0);
                if ($min <= 0 || $max <= 0) {
                    \Log::warning('findRateForGuests: Invalid rate range', [
                        'rate_id' => $rate->id,
                        'min_guests' => $rate->min_guests,
                        'max_guests' => $rate->max_guests,
                        'room_id' => $room->id,
                    ]);
                    return false;
                }

                return (float)($rate->price_per_night ?? 0) > 0;
            });

            $matchingRates = $validRates->filter(function ($rate) use ($guests) {
                $min = (int)($rate->min_guests ?? 0);
                $max = (int)($rate->max_guests ?? 0);
                return $guests >= $min && $guests <= $max;
            });

            if ($matchingRates->isNotEmpty()) {
                $exactMatches = $matchingRates->filter(function ($rate) use ($guests) {
                    return (int)($rate->min_guests ?? 0) === $guests
                        && (int)($rate->max_guests ?? 0) === $guests;
                });

                if ($exactMatches->count() > 1) {
                    \Log::warning('findRateForGuests: Duplicate exact rates detected', [
                        'room_id' => $room->id,
                        'guests' => $guests,
                        'rate_ids' => $exactMatches->pluck('id')->values()->toArray(),
                    ]);
                }

                $selectedRate = null;
                if ($exactMatches->isNotEmpty()) {
                    $selectedRate = $exactMatches
                        ->sortByDesc(fn($rate) => (int)($rate->id ?? 0))
                        ->first();
                } else {
                    $selectedRate = $matchingRates
                        ->sort(function ($a, $b) {
                            $aWidth = max(0, (int)($a->max_guests ?? 0) - (int)($a->min_guests ?? 0));
                            $bWidth = max(0, (int)($b->max_guests ?? 0) - (int)($b->min_guests ?? 0));

                            if ($aWidth === $bWidth) {
                                return (int)($b->id ?? 0) <=> (int)($a->id ?? 0);
                            }

                            return $aWidth <=> $bWidth;
                        })
                        ->first();
                }

                if ($selectedRate) {
                    $selectedPrice = (float)($selectedRate->price_per_night ?? 0);
                    \Log::info('findRateForGuests: Rate selected', [
                        'room_id' => $room->id,
                        'guests' => $guests,
                        'rate_id' => $selectedRate->id,
                        'min' => (int)($selectedRate->min_guests ?? 0),
                        'max' => (int)($selectedRate->max_guests ?? 0),
                        'price_per_night' => $selectedPrice,
                    ]);
                    return $selectedPrice;
                }
            }

            \Log::warning('findRateForGuests: No matching rate found', [
                'room_id' => $room->id,
                'guests' => $guests,
                'available_rates' => $validRates->map(fn($r) => [
                    'id' => $r->id,
                    'min' => $r->min_guests,
                    'max' => $r->max_guests,
                    'price' => $r->price_per_night,
                ])->toArray(),
            ]);
        } else {
            \Log::warning('findRateForGuests: No rates configured', [
                'room_id' => $room->id,
                'guests' => $guests,
            ]);
        }

        $basePrice = (float)($room->base_price_per_night ?? 0);
        if ($basePrice > 0) {
            \Log::info('findRateForGuests: Using base_price fallback', [
                'room_id' => $room->id,
                'guests' => $guests,
                'base_price_per_night' => $basePrice,
            ]);
            return $basePrice;
        }

        \Log::error('findRateForGuests: No price found', [
            'room_id' => $room->id,
            'guests' => $guests,
            'has_rates' => $rates && $rates->isNotEmpty(),
            'base_price' => $room->base_price_per_night,
        ]);
        return 0.0;
    }

    /**
     * Garantiza que exista un registro de noche para una fecha específica en una estadía.
     * 
     * SINGLE SOURCE OF TRUTH para el cobro por noches:
     * - Si ya existe una noche para esa fecha, no hace nada
     * - Si no existe, crea una nueva noche con precio calculado desde tarifas
     * - El precio se calcula basándose en la cantidad de huéspedes de la reserva
     * 
     * REGLA: Cada noche que una habitación está ocupada debe tener un registro en stay_nights
     * 
     * @param \App\Models\Stay $stay La estadía activa
     * @param \Carbon\Carbon $date Fecha de la noche a crear
     * @return \App\Models\StayNight|null La noche creada o existente, o null si falla
     */
    private function ensureNightForDate(\App\Models\Stay $stay, Carbon $date): ?\App\Models\StayNight
    {
        try {
            // Verificar si ya existe una noche para esta fecha
            $existingNight = \App\Models\StayNight::where('stay_id', $stay->id)
                ->whereDate('date', $date->toDateString())
                ->first();

            if ($existingNight) {
                // Ya existe, retornar sin crear
                return $existingNight;
            }

            // Obtener reserva y habitación para calcular precio
            $reservation = $stay->reservation;
            $room = $stay->room;

            if (!$reservation || !$room) {
                \Log::error('ensureNightForDate: Missing reservation or room', [
                    'stay_id' => $stay->id,
                    'date' => $date->toDateString()
                ]);
                return null;
            }

            // Cargar asignación de habitación en la reserva (fuente contractual de noches/precio)
            $reservationRoom = $reservation->reservationRooms()
                ->where('room_id', $room->id)
                ->first();

            // Regla de consistencia: si la stay ya tiene noches, reutilizar el último precio.
            $lastNight = \App\Models\StayNight::where('stay_id', $stay->id)
                ->orderByDesc('date')
                ->first();

            $price = $lastNight && (float)($lastNight->price ?? 0) > 0
                ? (float)$lastNight->price
                : 0.0;

            if ($price <= 0) {
                // REGLA: para reservas, el precio por noche se deriva del contrato de reserva,
                // nunca de la tarifa base de habitación.
                if ($reservationRoom) {
                    $reservationRoomPrice = (float)($reservationRoom->price_per_night ?? 0);
                    if ($reservationRoomPrice > 0) {
                        $price = $reservationRoomPrice;
                    } else {
                        $reservationRoomSubtotal = (float)($reservationRoom->subtotal ?? 0);
                        $reservationRoomNights = (int)($reservationRoom->nights ?? 0);

                        if ($reservationRoomNights <= 0 && !empty($reservationRoom->check_in_date) && !empty($reservationRoom->check_out_date)) {
                            $checkInDate = Carbon::parse($reservationRoom->check_in_date);
                            $checkOutDate = Carbon::parse($reservationRoom->check_out_date);
                            $reservationRoomNights = max(1, $checkInDate->diffInDays($checkOutDate));
                        }

                        if ($reservationRoomSubtotal > 0 && $reservationRoomNights > 0) {
                            $price = round($reservationRoomSubtotal / $reservationRoomNights, 2);
                        }
                    }
                }
            }

            if ($price <= 0) {
                // Fallback contractual: total de reserva dividido por noches totales configuradas.
                $totalAmount = (float)($reservation->total_amount ?? 0);
                $totalNights = (int)max(0, $reservation->reservationRooms()->sum('nights'));

                if ($totalAmount > 0 && $totalNights > 0) {
                    $price = round($totalAmount / $totalNights, 2);
                }
            }

            if ($price <= 0 && $reservationRoom) {
                $reservationRoomPrice = (float)($reservationRoom->price_per_night ?? 0);
                if ($reservationRoomPrice > 0) {
                    $price = $reservationRoomPrice;
                } else {
                    $reservationRoomSubtotal = (float)($reservationRoom->subtotal ?? 0);
                    $reservationRoomNights = (int)($reservationRoom->nights ?? 0);

                    if ($reservationRoomNights <= 0 && !empty($reservationRoom->check_in_date) && !empty($reservationRoom->check_out_date)) {
                        $checkInDate = Carbon::parse($reservationRoom->check_in_date);
                        $checkOutDate = Carbon::parse($reservationRoom->check_out_date);
                        $reservationRoomNights = max(1, $checkInDate->diffInDays($checkOutDate));
                    }

                    if ($reservationRoomSubtotal > 0 && $reservationRoomNights > 0) {
                        $price = round($reservationRoomSubtotal / $reservationRoomNights, 2);
                    }
                }
            }

            // Crear la noche
            $stayNight = \App\Models\StayNight::create([
                'stay_id' => $stay->id,
                'reservation_id' => $reservation->id,
                'room_id' => $room->id,
                'date' => $date->toDateString(),
                'price' => $price,
                'is_paid' => false, // Por defecto, pendiente
            ]);

            \Log::info('ensureNightForDate: Night created', [
                'stay_id' => $stay->id,
                'reservation_id' => $reservation->id,
                'room_id' => $room->id,
                'date' => $date->toDateString(),
                'price' => $price,
                'contract_total' => (float)($reservation->total_amount ?? 0)
            ]);

            return $stayNight;
        } catch (\Exception $e) {
            \Log::error('ensureNightForDate: Error creating night', [
                'stay_id' => $stay->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Recalcula total, noches y guests_count cuando cambia personas o fechas.
     */
    private function recalculateQuickRentTotals(?Room $room = null): void
    {
        if (!$this->rentForm) {
            return;
        }

        $roomModel = $room ?? Room::with('rates')->find($this->rentForm['room_id'] ?? null);
        if (!$roomModel) {
            return;
        }

        $guests = $this->calculateGuestCount();
        $nights = $this->getQuickRentNights();

        $pricingMode = (string)($this->rentForm['pricing_mode'] ?? 'auto');
        $manualPricePerNight = (float)($this->rentForm['manual_price_per_night'] ?? 0);

        if ($pricingMode === 'manual' && $manualPricePerNight > 0) {
            $total = round($manualPricePerNight * $nights, 2);
        } else {
            $pricePerNight = $this->findRateForGuests($roomModel, $guests);
            $total = round($pricePerNight * $nights, 2);
            $this->rentForm['pricing_mode'] = 'auto';
            $this->rentForm['manual_price_per_night'] = null;
        }

        $this->rentForm['guests_count'] = $guests;
        $this->rentForm['total'] = $total;
        $this->rentForm['_last_nights'] = $nights;

        $deposit = (float)($this->rentForm['deposit'] ?? 0);
        if ($deposit > $total) {
            $this->rentForm['deposit'] = $total;
        }
    }
    /**
     * Obtiene el numero de noches actual del formulario de arriendo rapido.
     */
    private function getQuickRentNights(): int
    {
        if (!$this->rentForm) {
            return 1;
        }

        try {
            $checkIn = Carbon::parse($this->rentForm['check_in_date'] ?? $this->date->toDateString());
            $checkOut = Carbon::parse($this->rentForm['check_out_date'] ?? $this->date->copy()->addDay()->toDateString());
            return max(1, $checkIn->diffInDays($checkOut));
        } catch (\Throwable $e) {
            \Log::warning('getQuickRentNights: Invalid check-in/check-out in rentForm', [
                'check_in_date' => $this->rentForm['check_in_date'] ?? null,
                'check_out_date' => $this->rentForm['check_out_date'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return 1;
        }
    }
    /**
     * Obtiene el ID del método de pago por código en payments_methods.
     */
    private function getPaymentMethodId(string $code): ?int
    {
        return DB::table('payments_methods')
            ->whereRaw('LOWER(code) = ?', [strtolower($code)])
            ->value('id');
    }

    /**
     * Detecta si un pago negativo corresponde a una reversión técnica
     * y no a una devolución real al cliente.
     */
    private function isPaymentReversalEntry(Payment $payment): bool
    {
        if ((float)($payment->amount ?? 0) >= 0) {
            return false;
        }

        return $this->extractReversedPaymentIdFromReference((string)($payment->reference ?? '')) !== null;
    }

    /**
     * Extrae el ID del pago original desde una referencia de reversión.
     * Soporta formatos históricos: "Anulacion de pago #123" y "reversal_of:123".
     */
    private function extractReversedPaymentIdFromReference(?string $reference): ?int
    {
        if (!$reference) {
            return null;
        }

        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $normalizedReference = Str::lower(Str::ascii($reference));

        if (preg_match('/anulacion\s+de\s+pago\s*#\s*(\d+)/i', $normalizedReference, $matches)) {
            return (int)($matches[1] ?? 0) ?: null;
        }

        if (preg_match('/reversal_of\s*:\s*(\d+)/i', $normalizedReference, $matches)) {
            return (int)($matches[1] ?? 0) ?: null;
        }

        return null;
    }

    /**
     * Separa pagos válidos y devoluciones reales para el detalle de habitación.
     * - Excluye pagos positivos que luego fueron revertidos.
     * - Excluye reversión técnica del historial de devoluciones.
     *
     * @return array{valid_deposits:\Illuminate\Support\Collection, refunds:\Illuminate\Support\Collection}
     */
    private function splitPaymentsForRoomDetail($payments): array
    {
        $paymentsCollection = collect($payments);

        $reversedPaymentIds = $paymentsCollection
            ->filter(fn($payment) => $this->isPaymentReversalEntry($payment))
            ->map(fn($payment) => $this->extractReversedPaymentIdFromReference((string)($payment->reference ?? '')))
            ->filter(fn($id) => !empty($id))
            ->map(fn($id) => (int)$id)
            ->unique()
            ->values();

        $validDeposits = $paymentsCollection
            ->filter(function ($payment) use ($reversedPaymentIds) {
                $amount = (float)($payment->amount ?? 0);
                if ($amount <= 0) {
                    return false;
                }

                return !$reversedPaymentIds->contains((int)($payment->id ?? 0));
            })
            ->values();

        $refunds = $paymentsCollection
            ->filter(function ($payment) {
                $amount = (float)($payment->amount ?? 0);
                if ($amount >= 0) {
                    return false;
                }

                return !$this->isPaymentReversalEntry($payment);
            })
            ->values();

        return [
            'valid_deposits' => $validDeposits,
            'refunds' => $refunds,
        ];
    }

    public ?array $additionalGuests = null;
    public ?array $releaseHistoryDetail = null;
    public ?array $roomEditData = null;
    public ?array $newSale = null;
    public ?array $newDeposit = null;
    public bool $showAddSale = false;
    public bool $showAddDeposit = false;

    // Propiedades derivadas
    public $daysInMonth = null;
    public ?array $statuses = null;
    public $ventilationTypes = null;

    protected $listeners = [
        'room-created' => '$refresh',
        'room-updated' => '$refresh',
        'refreshRooms' => 'loadRooms',
    ];

    private ?int $checkedInReservationStatusIdCache = null;
    private bool $checkedInReservationStatusResolved = false;
    private ?int $checkedOutReservationStatusIdCache = null;
    private bool $checkedOutReservationStatusResolved = false;
    private ?int $cancelledReservationStatusIdCache = null;
    private bool $cancelledReservationStatusResolved = false;

    private function isAdmin(): bool
    {
        $user = Auth::user();

        return (bool) ($user && $user->hasRole('Administrador'));
    }

    private function canEditOccupancy(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        if ($user->hasRole('Administrador')) {
            return true;
        }

        if ($user->hasAnyRole(['Recepcionista Día', 'Recepcionista Noche'])) {
            return true;
        }

        return $user->roles()
            ->pluck('name')
            ->contains(static fn ($name) => str_starts_with((string) $name, 'Recepcionista'));
    }

    /**
     * Fecha seleccionada normalizada para todas las validaciones operativas.
     */
    private function getSelectedDate(): Carbon
    {
        if ($this->date instanceof Carbon) {
            return $this->date->copy();
        }

        if (!empty($this->date)) {
            return Carbon::parse((string) $this->date);
        }

        return HotelTime::currentOperationalDate();
    }

    /**
     * Regla global: en Room Manager no se permite editar información histórica.
     */
    private function isSelectedDatePast(): bool
    {
        return HotelTime::isOperationalPastDate($this->getSelectedDate()->startOfDay());
    }

    /**
     * Guarda central para bloquear mutaciones cuando la fecha seleccionada es pasada.
     */
    private function blockEditsForPastDate(string $message = 'No se puede editar información de fechas pasadas.'): bool
    {
        if (!$this->isSelectedDatePast()) {
            return false;
        }

        $this->dispatch('notify', type: 'error', message: $message);
        return true;
    }

    public function mount($date = null, $search = null, $status = null)
    {
        $this->currentDate = $date ? Carbon::parse($date) : HotelTime::currentOperationalDate();
        $this->date = $this->currentDate;
        $this->search = $search ?? '';
        $this->statusFilter = in_array((string) $status, self::ALLOWED_STATUS_FILTERS, true) ? $status : null;
        
        // Generar array de días del mes
        $startOfMonth = $this->currentDate->copy()->startOfMonth();
        $daysCount = $this->currentDate->daysInMonth;
        $this->daysInMonth = collect(range(1, $daysCount))
            ->map(fn($day) => $startOfMonth->copy()->day($day))
            ->toArray();

        // Cargar catálogos
        $this->loadStatuses();
        $this->loadVentilationTypes();

        // El historial se carga en render() cuando se necesita
    }

    public function loadStatuses()
    {
        $this->statuses = RoomDisplayStatus::cases();
    }

    public function loadVentilationTypes()
    {
        $this->ventilationTypes = VentilationType::all(['id', 'code', 'name']);
    }

    protected function getRoomsQuery()
    {
        $query = Room::query();

        if ($this->search) {
            $query->where('room_number', 'like', '%' . $this->search . '%');
        }

        if ($this->ventilationTypeFilter) {
            $query->where('ventilation_type_id', $this->ventilationTypeFilter);
        }

        $startOfMonth = $this->currentDate->copy()->startOfMonth();
        $endOfMonth = $this->currentDate->copy()->endOfMonth();
        $endOfMonthWithBuffer = $endOfMonth->copy()->addDay();

        return $query->with([
            'roomType',
            'ventilationType',
            'reservationRooms' => function($q) use ($startOfMonth, $endOfMonth) {
                $q->where('check_in_date', '<=', $endOfMonth->toDateString())
                  ->where('check_out_date', '>=', $startOfMonth->toDateString())
                  ->with(['reservation' => function($r) {
                      $r->with(['customer', 'sales', 'payments']);
                  }]);
            },
            'rates',
            'operationalStatuses' => function ($q) use ($startOfMonth, $endOfMonthWithBuffer) {
                $q->whereDate('operational_date', '>=', $startOfMonth->toDateString())
                    ->whereDate('operational_date', '<=', $endOfMonthWithBuffer->toDateString());
            },
            'maintenanceBlocks' => function($q) {
                $q->where('status_id', function($subq) {
                    $subq->select('id')->from('room_maintenance_block_statuses')
                        ->where('code', 'active');
                });
            }
        ])
        ->orderBy('room_number');
    }

    /**
     * Obtiene el historial de liberación paginado.
     * Se calcula en render() para evitar problemas de serialización en Livewire.
     */
    protected function getReleaseHistory()
    {
        // Verificar si la tabla existe antes de intentar consultarla
        if (!Schema::hasTable('room_release_history')) {
            // Si la tabla no existe, retornar una colección vacía paginada
            return new \Illuminate\Pagination\LengthAwarePaginator(
                collect([]),
                0,
                15,
                1,
                ['path' => request()->url(), 'pageName' => 'releaseHistoryPage']
            );
        }
        
        try {
            // Cargar historial de liberación de habitaciones filtrado por fecha
            $query = RoomReleaseHistory::query()
                ->with(['room', 'customer', 'releasedBy'])
                ->orderBy('release_date', 'desc')
                ->orderBy('created_at', 'desc');
            
            // Filtrar por fecha: mostrar liberaciones del mes actual
            // Si hay fecha seleccionada, filtrar por ese mes
            if ($this->currentDate) {
                $startOfMonth = $this->currentDate->copy()->startOfMonth();
                $endOfMonth = $this->currentDate->copy()->endOfMonth();
                
                $query->whereBetween('release_date', [
                    $startOfMonth->toDateString(),
                    $endOfMonth->toDateString()
                ]);
            }
            // Si no hay fecha seleccionada, mostrar TODAS las liberaciones (sin filtro de fecha)
            
            // Aplicar búsqueda si existe
            if ($this->search) {
                $query->where(function($q) {
                    $q->where('room_number', 'like', '%' . $this->search . '%')
                      ->orWhere('customer_name', 'like', '%' . $this->search . '%')
                      ->orWhere('customer_identification', 'like', '%' . $this->search . '%');
                });
            }
            
            // Paginar resultados
            $paginator = $query->paginate(15, pageName: 'releaseHistoryPage');
            
            // Log para debugging
            \Log::info('Release history query executed', [
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'activeTab' => $this->activeTab,
                'currentDate' => $this->currentDate?->toDateString(),
                'search' => $this->search,
            ]);
            
            return $paginator;
        } catch (\Exception $e) {
            \Log::error('Error loading release history', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // En caso de error, retornar una colección vacía paginada
            return new \Illuminate\Pagination\LengthAwarePaginator(
                collect([]),
                0,
                15,
                1,
                ['path' => request()->url(), 'pageName' => 'releaseHistoryPage']
            );
        }
    }
    /**
     * @deprecated Usar getReleaseHistory() en render() en su lugar
     */
    public function loadReleaseHistory()
    {
        // Este método ya no es necesario, pero se mantiene para compatibilidad
        // El historial se carga directamente en render()
    }

    /**
     * Carga huéspedes de la reserva activa de una habitación.
     * 
     * SINGLE SOURCE OF TRUTH:
     * - Cliente principal: SIEMPRE viene de $reservation->customer (reservations.client_id)
     * - Huéspedes adicionales: SIEMPRE vienen de $reservationRoom->getGuests()
     *   que usa: reservation_room_guests â†’ reservation_guest_id â†’ reservation_guests.guest_id â†’ customers.id
     * 
     * Usa STAY (ocupación real con timestamps) en lugar de ReservationRoom (fechas).
     */
    /**
     * Resumen diario para vision general del hotel.
     */
    protected function getDailyOverviewStats(): array
    {
        $selectedDate = $this->date instanceof Carbon
            ? $this->date->copy()
            : Carbon::parse($this->date ?? HotelTime::currentOperationalDate());

        $dateString = $selectedDate->toDateString();
        $dayStart = HotelTime::startOfOperationalDay($selectedDate);
        $dayEnd = HotelTime::endOfOperationalDay($selectedDate);

        $activeRoomsQuery = Room::query();
        if (Schema::hasColumn('rooms', 'is_active')) {
            $activeRoomsQuery->where('is_active', true);
        }

        $roomsTotal = (clone $activeRoomsQuery)->count();
        if ($roomsTotal === 0) {
            $roomsTotal = Room::query()->count();
        }

        $occupiedStaysQuery = Stay::query()
            ->whereIn('status', ['active', 'pending_checkout'])
            ->where('check_in_at', '<=', $dayEnd)
            ->where(function ($query) use ($dayStart, $dateString) {
                // Caso 1: checkout real registrado y todavia intersecta la fecha
                $query->where(function ($q) use ($dayStart) {
                    $q->whereNotNull('check_out_at')
                        ->where('check_out_at', '>', $dayStart);
                })
                // Caso 2: checkout real null; validar limite contractual en reservation_rooms
                ->orWhere(function ($q) use ($dateString) {
                    $q->whereNull('check_out_at')
                        ->whereExists(function ($sub) use ($dateString) {
                            $sub->select(DB::raw(1))
                                ->from('reservation_rooms')
                                ->whereColumn('reservation_rooms.reservation_id', 'stays.reservation_id')
                                ->whereColumn('reservation_rooms.room_id', 'stays.room_id')
                                ->whereDate('reservation_rooms.check_out_date', '>=', $dateString);
                        });
                });
            });

        $roomsOccupied = (clone $occupiedStaysQuery)
            ->distinct()
            ->count('room_id');

        $occupiedReservationIds = (clone $occupiedStaysQuery)
            ->whereNotNull('reservation_id')
            ->distinct()
            ->pluck('reservation_id');

        // Alineado con la grilla: reservas activas = reservas con stay ocupando la fecha.
        $reservationsActive = $occupiedReservationIds->count();

        $guestsTotal = 0;
        $adultsTotal = 0;
        $childrenTotal = 0;

        if ($occupiedReservationIds->isNotEmpty()) {
            $reservationGuestsSummary = Reservation::query()
                ->whereIn('id', $occupiedReservationIds)
                ->selectRaw('
                    COALESCE(SUM(total_guests), 0) as total_guests_sum,
                    COALESCE(SUM(adults), 0) as adults_sum,
                    COALESCE(SUM(children), 0) as children_sum
                ')
                ->first();

            $guestsTotal = (int) ($reservationGuestsSummary->total_guests_sum ?? 0);
            $adultsTotal = (int) ($reservationGuestsSummary->adults_sum ?? 0);
            $childrenTotal = (int) ($reservationGuestsSummary->children_sum ?? 0);
        }

        $arrivalsToday = Stay::query()
            ->whereNotNull('reservation_id')
            ->whereNotNull('check_in_at')
            ->whereBetween('check_in_at', [$dayStart, $dayEnd])
            ->distinct()
            ->count('reservation_id');

        $departuresToday = Stay::query()
            ->whereNotNull('reservation_id')
            ->whereNotNull('check_out_at')
            ->where('status', 'finished')
            ->whereBetween('check_out_at', [$dayStart, $dayEnd])
            ->distinct()
            ->count('reservation_id');

        $roomsAvailable = max(0, $roomsTotal - $roomsOccupied);
        $occupancyRate = $roomsTotal > 0
            ? (int) round(($roomsOccupied / $roomsTotal) * 100)
            : 0;

        return [
            'date' => $dateString,
            'rooms_total' => $roomsTotal,
            'rooms_occupied' => $roomsOccupied,
            'rooms_available' => $roomsAvailable,
            'occupancy_rate' => $occupancyRate,
            'reservations_active' => $reservationsActive,
            'arrivals_today' => $arrivalsToday,
            'departures_today' => $departuresToday,
            'guests_total' => $guestsTotal,
            'adults_total' => $adultsTotal,
            'children_total' => $childrenTotal,
        ];
    }

    /**
     * Obtiene resumen de llegadas para una fecha (reservas con check_in en ese dia).
     *
     * @return array{count:int,items:array<int,array<string,mixed>>}
     */
    private function formatReservationRoomsForSummary(Reservation $reservation): string
    {
        if (!$reservation->relationLoaded('reservationRooms') || $reservation->reservationRooms->isEmpty()) {
            return 'Sin habitaciones asignadas';
        }

        $roomNumbers = $reservation->reservationRooms
            ->map(static fn ($reservationRoom) => $reservationRoom->room?->room_number)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return empty($roomNumbers) ? 'Sin habitaciones asignadas' : implode(', ', $roomNumbers);
    }

    /**
     * Estado operacional para tarjetas de resumen de reservas.
     *
     * checked_in: ya tuvo check-in registrado.
     * pending_checkin: aun no registra check-in.
     * cancelled: reserva cancelada (soft delete).
     *
     * @return array{key:string,label:string,badge:string}
     */
    private function resolveReservationStatusForSummary(Reservation $reservation): array
    {
        $isCancelled = method_exists($reservation, 'trashed') && $reservation->trashed();
        if ($isCancelled) {
            return [
                'key' => 'cancelled',
                'label' => 'Cancelada',
                'badge' => 'bg-red-100 text-red-700 border border-red-200',
            ];
        }

        $hasPendingCheckout = false;
        if ($reservation->relationLoaded('stays')) {
            $hasPendingCheckout = $reservation->stays->contains(
                static fn ($stay) => (string) ($stay->status ?? '') === 'pending_checkout',
            );
        } else {
            $hasPendingCheckout = $reservation->stays()
                ->where('status', 'pending_checkout')
                ->exists();
        }

        if ($hasPendingCheckout) {
            return [
                'key' => 'pending_checkout',
                'label' => 'Pendiente checkout',
                'badge' => 'bg-yellow-100 text-yellow-800 border border-yellow-200',
            ];
        }

        $operationalStayStatuses = ['active', 'pending_checkout'];
        $hasCheckIn = false;

        if ($reservation->relationLoaded('stays')) {
            $hasCheckIn = $reservation->stays->contains(
                static fn ($stay) => in_array((string) ($stay->status ?? ''), $operationalStayStatuses, true),
            );
        } else {
            $hasCheckIn = $reservation->stays()
                ->whereIn('status', $operationalStayStatuses)
                ->exists();
        }

        if ($hasCheckIn) {
            return [
                'key' => 'checked_in',
                'label' => 'Check-in realizado',
                'badge' => 'bg-emerald-100 text-emerald-700 border border-emerald-200',
            ];
        }

        return [
            'key' => 'pending_checkin',
            'label' => 'Pendiente check-in',
            'badge' => 'bg-amber-100 text-amber-800 border border-amber-200',
        ];
    }

    private function buildReservationModalPayloadForSummary(
        Reservation $reservation,
        bool $canDoCheckIn,
        bool $canDoPayments
    ): array {
        $reservationRooms = $reservation->relationLoaded('reservationRooms')
            ? $reservation->reservationRooms
            : collect();

        $firstReservationRoom = $reservationRooms->first();
        $checkInDate = $reservationRooms
            ->pluck('check_in_date')
            ->filter()
            ->sort()
            ->first() ?? $firstReservationRoom?->check_in_date;
        $checkOutDate = $reservationRooms
            ->pluck('check_out_date')
            ->filter()
            ->sortDesc()
            ->first() ?? $firstReservationRoom?->check_out_date;

        $statusMeta = $this->resolveReservationStatusForSummary($reservation);
        $isCancelled = $statusMeta['key'] === 'cancelled';
        $hasOperationalStay = $statusMeta['key'] === 'checked_in';

        $payments = $reservation->relationLoaded('payments') ? $reservation->payments : collect();
        $paymentsTotalRaw = $payments->isNotEmpty()
            ? (float) $payments->sum('amount')
            : (float) ($reservation->deposit_amount ?? 0);

        $stayNightsTotalRaw = (float) ($reservation->stay_nights_total ?? 0);
        $reservationRoomsTotalRaw = (float) $reservationRooms->sum(
            static fn ($reservationRoom) => (float) ($reservationRoom->subtotal ?? 0),
        );
        $enteredReservationTotalRaw = (float) ($reservation->total_amount ?? 0);
        $totalAmountRaw = $enteredReservationTotalRaw > 0
            ? $enteredReservationTotalRaw
            : max(0, $reservationRoomsTotalRaw, $stayNightsTotalRaw);
        $balanceRaw = max(0, $totalAmountRaw - $paymentsTotalRaw);

        $latestPositivePayment = $payments
            ->where('amount', '>', 0)
            ->sortByDesc('id')
            ->first(static function ($candidatePayment) use ($payments) {
                $candidateId = (int) ($candidatePayment->id ?? 0);
                if ($candidateId <= 0) {
                    return false;
                }

                $reversalReference = 'Anulacion de pago #' . $candidateId;

                return !$payments->contains(
                    static fn ($existingPayment) => (float) ($existingPayment->amount ?? 0) < 0
                        && (string) ($existingPayment->reference ?? '') === $reversalReference,
                );
            });

        $customer = $reservation->customer;
        $customerName = (string) ($customer?->name ?? 'Sin cliente asignado');
        $customerIdentificationValue = $customer?->identification_number ?: ($customer?->taxProfile?->identification ?? null);
        $customerIdentificationType = $customer?->identificationType?->name;
        $customerIdentification = $customerIdentificationValue
            ? (string) ($customerIdentificationType
                ? ($customerIdentificationType . ': ' . $customerIdentificationValue)
                : $customerIdentificationValue)
            : '-';

        $canCheckIn = !$isCancelled && $canDoCheckIn && !$hasOperationalStay;
        $canPay = !$isCancelled && $canDoPayments;

        $statusLabel = $statusMeta['label'];

        return [
            'id' => (int) $reservation->id,
            'reservation_code' => (string) ($reservation->reservation_code ?? ''),
            'customer_name' => $customerName,
            'customer_identification' => $customerIdentification,
            'customer_phone' => (string) ($customer?->phone ?? '-'),
            'rooms' => $this->formatReservationRoomsForSummary($reservation),
            'check_in' => $checkInDate ? Carbon::parse($checkInDate)->format('d/m/Y') : 'N/A',
            'check_out' => $checkOutDate ? Carbon::parse($checkOutDate)->format('d/m/Y') : 'N/A',
            'check_in_time' => $reservation->check_in_time ? substr((string) $reservation->check_in_time, 0, 5) : 'N/A',
            'guests_count' => (int) ($reservation->total_guests ?? 0),
            'total' => number_format($totalAmountRaw, 0, ',', '.'),
            'deposit' => number_format($paymentsTotalRaw, 0, ',', '.'),
            'balance' => number_format($balanceRaw, 0, ',', '.'),
            'total_amount_raw' => $totalAmountRaw,
            'payments_total_raw' => $paymentsTotalRaw,
            'balance_raw' => $balanceRaw,
            'edit_url' => $isCancelled ? null : route('reservations.edit', $reservation),
            'check_in_url' => $canCheckIn ? route('reservations.check-in', $reservation) : null,
            'payment_url' => $canPay ? route('reservations.register-payment', $reservation) : null,
            'cancel_payment_url' => null,
            'pdf_url' => $isCancelled ? null : route('reservations.download', $reservation),
            'guests_document_view_url' => !$isCancelled && !empty($reservation->guests_document_path)
                ? route('reservations.guest-document.view', $reservation)
                : null,
            'guests_document_download_url' => !$isCancelled && !empty($reservation->guests_document_path)
                ? route('reservations.guest-document.download', $reservation)
                : null,
            'notes' => $reservation->notes ?? 'Sin notas adicionales',
            'status' => $statusLabel,
            'status_key' => $statusMeta['key'],
            'status_badge_class' => $statusMeta['badge'],
            'has_operational_stay' => $hasOperationalStay,
            'can_cancel' => false,
            'can_checkin' => $canCheckIn,
            'can_pay' => $canPay,
            'can_cancel_payment' => false,
            'last_payment_amount' => $latestPositivePayment ? (float) ($latestPositivePayment->amount ?? 0) : 0.0,
            'cancelled_at' => $isCancelled && $reservation->deleted_at
                ? $reservation->deleted_at->format('d/m/Y H:i')
                : null,
        ];
    }

    private function resolveReservationSourceIdByCode(string $code): ?int
    {
        if (!Schema::hasTable('reservation_sources')) {
            return null;
        }

        $id = DB::table('reservation_sources')
            ->where('code', $code)
            ->value('id');

        return $id ? (int) $id : null;
    }

    protected function getArrivalsSummaryForDate(Carbon $date, int $limit = 6, bool $includePendingCheckout = false): array
    {
        $dateString = $date->toDateString();
        $canDoCheckIn = auth()->check() && auth()->user()->can('edit_reservations');
        $canDoPayments = auth()->check() && auth()->user()->can('edit_reservations');

        $reservationsQuery = Reservation::withTrashed()
            ->whereHas('reservationRooms', function ($query) use ($dateString, $includePendingCheckout) {
                $query->whereDate('check_in_date', $dateString);

                if ($includePendingCheckout) {
                    $query->orWhereDate('check_out_date', $dateString);
                }
            });

        // Mostrar solo reservas del flujo "Reservas" y excluir walk-in de Room Manager.
        if (Schema::hasColumn('reservations', 'source_id')) {
            $receptionSourceId = $this->resolveReservationSourceIdByCode('reception');

            if ($receptionSourceId !== null) {
                $reservationsQuery->where(function ($query) use ($receptionSourceId) {
                    $query->whereNull('source_id')
                        ->orWhere('source_id', $receptionSourceId);
                });
            } else {
                $walkInSourceId = $this->resolveReservationSourceIdByCode('walk_in');
                if ($walkInSourceId !== null) {
                    $reservationsQuery->where(function ($query) use ($walkInSourceId) {
                        $query->whereNull('source_id')
                            ->orWhere('source_id', '!=', $walkInSourceId);
                    });
                }
            }
        }

        $reservations = $reservationsQuery
            ->with([
                'customer:id,name,phone,identification_number,identification_type_id',
                'customer.identificationType:id,name',
                'customer.taxProfile:id,customer_id,identification',
                'payments:id,reservation_id,amount,reference',
                'stays:id,reservation_id,status',
                'reservationRooms' => function ($query) use ($dateString, $includePendingCheckout) {
                    $query->where(function ($reservationRoomQuery) use ($dateString, $includePendingCheckout) {
                        $reservationRoomQuery->whereDate('check_in_date', $dateString);

                        if ($includePendingCheckout) {
                            $reservationRoomQuery->orWhereDate('check_out_date', $dateString);
                        }
                    })->with('room:id,room_number');
                },
            ])
            ->orderBy('id')
            ->get();

        $statusCounts = [
            'checked_in' => 0,
            'pending_checkin' => 0,
            'pending_checkout' => 0,
            'cancelled' => 0,
        ];

        foreach ($reservations as $reservation) {
            $statusKey = $this->resolveReservationStatusForSummary($reservation)['key'];
            if (array_key_exists($statusKey, $statusCounts)) {
                $statusCounts[$statusKey]++;
            }
        }

        $items = $reservations
            ->take(max(1, $limit))
            ->map(function (Reservation $reservation) use ($canDoCheckIn, $canDoPayments): array {
                $statusMeta = $this->resolveReservationStatusForSummary($reservation);

                return [
                    'id' => (int) $reservation->id,
                    'code' => (string) ($reservation->reservation_code ?: ('RES-' . $reservation->id)),
                    'customer' => (string) ($reservation->customer?->name ?? 'Sin cliente'),
                    'rooms' => $this->formatReservationRoomsForSummary($reservation),
                    'status_key' => $statusMeta['key'],
                    'status_label' => $statusMeta['label'],
                    'status_badge_class' => $statusMeta['badge'],
                    'modal_payload' => $this->buildReservationModalPayloadForSummary(
                        $reservation,
                        $canDoCheckIn,
                        $canDoPayments,
                    ),
                ];
            })
            ->values()
            ->all();

        return [
            'count' => $reservations->count(),
            'status_counts' => $statusCounts,
            'items' => $items,
        ];
    }

    /**
     * Resumen rapido de reservas para recepcion: hoy y manana.
     */
    protected function getReceptionReservationsSummary(): array
    {
        $today = HotelTime::currentOperationalDate();
        $tomorrow = HotelTime::currentOperationalDate()->copy()->addDay();

        $todayData = $this->getArrivalsSummaryForDate($today, 6, true);
        $tomorrowData = $this->getArrivalsSummaryForDate($tomorrow);

        return [
            'today_date' => $today->format('d/m/Y'),
            'tomorrow_date' => $tomorrow->format('d/m/Y'),
            'today_count' => (int) ($todayData['count'] ?? 0),
            'tomorrow_count' => (int) ($tomorrowData['count'] ?? 0),
            'today_status_counts' => $todayData['status_counts'] ?? [],
            'tomorrow_status_counts' => $tomorrowData['status_counts'] ?? [],
            'today_items' => $todayData['items'] ?? [],
            'tomorrow_items' => $tomorrowData['items'] ?? [],
        ];
    }

    public function loadRoomGuests($roomId)
    {
        try {
            // Cargar room con relaciones necesarias (eager loading optimizado)
            $room = Room::with([
                'stays.reservation.customer.taxProfile',
                'stays.reservation.reservationRooms' => function ($q) use ($roomId) {
                    $q->where('room_id', $roomId);
                }
            ])->find($roomId);

            if (!$room) {
                return [
                    'room_number' => null,
                    'guests' => [],
                    'main_guest' => null,
                ];
            }

            // Obtener la Stay que intersecta con la fecha consultada
            $stay = $room->getAvailabilityService()->getStayForDate($this->date ?? HotelTime::currentOperationalDate());

            // GUARD CLAUSE: Si no hay stay o reserva, retornar vacío
            if (!$stay || !$stay->reservation) {
                return [
                    'room_number' => $room->room_number,
                    'guests' => [],
                    'main_guest' => null,
                ];
            }

            $reservation = $stay->reservation;

            // 1. Huésped principal - SINGLE SOURCE OF TRUTH: reservations.client_id
            $mainGuest = null;
            if ($reservation->customer) {
                $mainGuest = [
                    'id' => $reservation->customer->id,
                    'name' => $reservation->customer->name,
                    'identification' => $reservation->customer->taxProfile?->identification ?? null,
                    'phone' => $reservation->customer->phone ?? null,
                    'email' => $reservation->customer->email ?? null,
                    'is_main' => true,
                ];
            }

            // 2. ReservationRoom DE ESTA HABITACIÓN ESPECÍFICA
            $reservationRoom = $reservation->reservationRooms->firstWhere('room_id', $room->id);

            // 3. Huéspedes adicionales - SINGLE SOURCE OF TRUTH: reservationRoom->getGuests()
            // Ruta: reservation_room_guests â†’ reservation_guest_id â†’ reservation_guests.guest_id â†’ customers.id
            $additionalGuests = collect();
            if ($reservationRoom) {
                try {
                    $guestsCollection = $reservationRoom->getGuests();
                    
                    if ($guestsCollection && $guestsCollection->isNotEmpty()) {
                        $additionalGuests = $guestsCollection->map(function($guest) {
                            // Cargar taxProfile si no está cargado
                            if (!$guest->relationLoaded('taxProfile')) {
                                $guest->load('taxProfile');
                            }
                            
                            return [
                                'id' => $guest->id,
                                'name' => $guest->name,
                                'identification' => $guest->taxProfile?->identification ?? null,
                                'phone' => $guest->phone ?? null,
                                'email' => $guest->email ?? null,
                                'is_main' => false,
                            ];
                        });
                    }
                } catch (\Exception $e) {
                    // Si falla la carga de guests, retornar colección vacía sin romper el flujo
                    \Log::warning('Error loading additional guests in loadRoomGuests', [
                        'room_id' => $room->id,
                        'reservation_room_id' => $reservationRoom->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    $additionalGuests = collect();
                }
            }

            // 4. Combinar huésped principal y adicionales
            $guests = collect();
            if ($mainGuest) {
                $guests->push($mainGuest);
            }
            $guests = $guests->merge($additionalGuests);

            return [
                'room_number' => $room->room_number,
                'guests' => $guests->values()->toArray(),
                'main_guest' => $mainGuest,
            ];
        } catch (\Exception $e) {
            // Protección total: nunca lanzar excepciones
            \Log::error('Error in loadRoomGuests', [
                'room_id' => $roomId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'room_number' => null,
                'guests' => [],
                'main_guest' => null,
            ];
        }
    }


    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        // El historial se carga automáticamente en render() cuando activeTab === 'history'
    }

    public function refreshRoomsPolling()
    {
        if ($this->isReleasingRoom) {
            return; // NO refrescar mientras se libera una habitación
        }
        // Livewire automatically re-renders, no need to manually load
    }

    /**
     * Forzar recarga de habitaciones desde BD tras eventos.
     */
    public function loadRooms()
    {
        $this->resetPage();
    }

    /**
     * Obtiene mapa [room_id => true] de reservas rapidas para la fecha operativa.
     *
     * @param Carbon $selectedDate
     * @param \Illuminate\Support\Collection<int, int|string> $roomIds
     * @return array<int, bool>
     */
    private function getQuickReservedRoomMap(Carbon $selectedDate, \Illuminate\Support\Collection $roomIds): array
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

    private function resolveCheckedInReservationStatusId(): ?int
    {
        if ($this->checkedInReservationStatusResolved) {
            return $this->checkedInReservationStatusIdCache;
        }

        $this->checkedInReservationStatusResolved = true;

        $normalize = static function (?string $value): string {
            $raw = trim((string) ($value ?? ''));
            if ($raw === '') {
                return '';
            }

            $normalized = Str::ascii(strtolower($raw));
            $normalized = str_replace(['-', ' '], '_', $normalized);

            return preg_replace('/_+/', '_', $normalized) ?? '';
        };

        $statuses = DB::table('reservation_statuses')
            ->select(['id', 'code', 'name'])
            ->get();

        $match = $statuses->first(function ($status) use ($normalize): bool {
            $code = $normalize((string) ($status->code ?? ''));
            $name = $normalize((string) ($status->name ?? ''));

            if (in_array($code, ['checked_in', 'check_in', 'checkedin', 'arrived', 'llego'], true)) {
                return true;
            }

            if (in_array($name, ['checked_in', 'check_in', 'checkedin', 'arrived', 'llego'], true)) {
                return true;
            }

            return (str_contains($code, 'check') && str_contains($code, 'in'))
                || (str_contains($name, 'check') && str_contains($name, 'in'))
                || str_contains($code, 'llego')
                || str_contains($name, 'llego');
        });

        $this->checkedInReservationStatusIdCache = $match ? (int) $match->id : null;

        return $this->checkedInReservationStatusIdCache;
    }

    private function resolveCheckedOutReservationStatusId(): ?int
    {
        if ($this->checkedOutReservationStatusResolved) {
            return $this->checkedOutReservationStatusIdCache;
        }

        $this->checkedOutReservationStatusResolved = true;

        $normalize = static function (?string $value): string {
            $raw = trim((string) ($value ?? ''));
            if ($raw === '') {
                return '';
            }

            $normalized = Str::ascii(strtolower($raw));
            $normalized = str_replace(['-', ' '], '_', $normalized);

            return preg_replace('/_+/', '_', $normalized) ?? '';
        };

        $statuses = DB::table('reservation_statuses')
            ->select(['id', 'code', 'name'])
            ->get();

        $match = $statuses->first(function ($status) use ($normalize): bool {
            $code = $normalize((string) ($status->code ?? ''));
            $name = $normalize((string) ($status->name ?? ''));

            if (in_array($code, ['checked_out', 'check_out', 'checkedout', 'departed', 'salida', 'egresado', 'salio'], true)) {
                return true;
            }

            if (in_array($name, ['checked_out', 'check_out', 'checkedout', 'departed', 'salida', 'egresado', 'salio'], true)) {
                return true;
            }

            return (str_contains($code, 'check') && str_contains($code, 'out'))
                || (str_contains($name, 'check') && str_contains($name, 'out'))
                || str_contains($code, 'salid')
                || str_contains($name, 'salid')
                || str_contains($code, 'egres')
                || str_contains($name, 'egres');
        });

        $this->checkedOutReservationStatusIdCache = $match ? (int) $match->id : null;

        return $this->checkedOutReservationStatusIdCache;
    }

    private function resolveCancelledReservationStatusId(): ?int
    {
        if ($this->cancelledReservationStatusResolved) {
            return $this->cancelledReservationStatusIdCache;
        }

        $this->cancelledReservationStatusResolved = true;

        $normalize = static function (?string $value): string {
            $raw = trim((string) ($value ?? ''));
            if ($raw === '') {
                return '';
            }

            $normalized = Str::ascii(strtolower($raw));
            $normalized = str_replace(['-', ' '], '_', $normalized);

            return preg_replace('/_+/', '_', $normalized) ?? '';
        };

        $statuses = DB::table('reservation_statuses')
            ->select(['id', 'code', 'name'])
            ->get();

        $match = $statuses->first(function ($status) use ($normalize): bool {
            $code = $normalize((string) ($status->code ?? ''));
            $name = $normalize((string) ($status->name ?? ''));

            if (in_array($code, ['cancelled', 'canceled', 'cancelada', 'cancelado', 'anulada', 'anulado'], true)) {
                return true;
            }

            if (in_array($name, ['cancelled', 'canceled', 'cancelada', 'cancelado', 'anulada', 'anulado'], true)) {
                return true;
            }

            return str_contains($code, 'cancel')
                || str_contains($name, 'cancel')
                || str_contains($code, 'anulad')
                || str_contains($name, 'anulad');
        });

        $this->cancelledReservationStatusIdCache = $match ? (int) $match->id : null;

        return $this->cancelledReservationStatusIdCache;
    }

    /**
     * Limpia una reserva rapida especifica para una habitacion/fecha.
     */
    private function clearQuickReserveForDate(int $roomId, Carbon $selectedDate): void
    {
        RoomQuickReservation::query()
            ->where('room_id', $roomId)
            ->whereDate('operational_date', $selectedDate->toDateString())
            ->delete();
    }

    /**
     * Obtiene una reserva pendiente de check-in para una habitacion y fecha operativa.
     * Solo retorna reservas que aun no tienen una estadia registrada para esa habitacion.
     */
    private function getPendingCheckInReservationForRoom(Room $room, Carbon $selectedDate): ?Reservation
    {
        $dateString = $selectedDate->toDateString();
        $checkedInStatusId = $this->resolveCheckedInReservationStatusId();
        $checkedOutStatusId = $this->resolveCheckedOutReservationStatusId();
        $cancelledStatusId = $this->resolveCancelledReservationStatusId();
        $excludedStatusIds = array_values(array_unique(array_filter([
            $checkedInStatusId,
            $checkedOutStatusId,
            $cancelledStatusId,
        ])));

        return ReservationRoom::query()
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
                    ->orWhereDate('check_out_date', '>=', $dateString);
            })
            ->whereHas('reservation', function ($query) {
                $query->whereNull('deleted_at');
            })
            ->whereHas('reservation', function ($query) use ($excludedStatusIds) {
                if (!empty($excludedStatusIds)) {
                    $query->where(function ($statusQuery) use ($excludedStatusIds) {
                        $statusQuery->whereNull('status_id')
                            ->orWhereNotIn('status_id', $excludedStatusIds);
                    });
                }
            })
            ->whereDoesntHave('reservation.stays', function ($query) use ($room) {
                $query->where('room_id', (int) $room->id);
            })
            ->with(['reservation.customer'])
            ->orderBy('check_in_date')
            ->orderBy('id')
            ->first()
            ?->reservation;
    }

    private function roomHasQuickReservationMarker(int $roomId, Carbon $selectedDate): bool
    {
        return RoomQuickReservation::query()
            ->where('room_id', $roomId)
            ->whereDate('operational_date', $selectedDate->toDateString())
            ->exists();
    }

    private function reservationAlreadyUsesRoom(int $reservationId, int $roomId): bool
    {
        return ReservationRoom::query()
            ->where('reservation_id', $reservationId)
            ->where('room_id', $roomId)
            ->exists();
    }

    private function isRoomAvailableForChange(Room $room, Carbon $selectedDate, int $reservationId): bool
    {
        if ($room->isInMaintenance($selectedDate)) {
            return false;
        }

        if ($room->getOperationalStatus($selectedDate) !== 'free_clean') {
            return false;
        }

        if ($this->roomHasQuickReservationMarker((int) $room->id, $selectedDate)) {
            return false;
        }

        if ($this->reservationAlreadyUsesRoom($reservationId, (int) $room->id)) {
            return false;
        }

        return $this->getPendingCheckInReservationForRoom($room, $selectedDate) === null;
    }

    private function canApplyMaintenanceToRoom(Room $room, Carbon $selectedDate): array
    {
        $selectedDate = $selectedDate->copy()->startOfDay();
        $currentCleaningCode = data_get($room->cleaningStatus($selectedDate), 'code');
        $baseCleaningCode = data_get($room->baseCleaningStatus($selectedDate), 'code');
        $operationalStatus = $room->getOperationalStatus($selectedDate);

        if ($operationalStatus !== 'free_clean' || $baseCleaningCode !== 'limpia') {
            return [
                'valid' => false,
                'message' => 'Solo se puede poner en mantenimiento una habitacion libre y limpia.',
            ];
        }

        $conflictStart = $currentCleaningCode === 'mantenimiento'
            ? $selectedDate->copy()->addDay()
            : $selectedDate->copy();
        $conflictEnd = $selectedDate->copy()->addDays(2);

        for ($dateToCheck = $conflictStart->copy(); $dateToCheck->lt($conflictEnd); $dateToCheck->addDay()) {
            if ($this->roomHasQuickReservationMarker((int) $room->id, $dateToCheck)) {
                return [
                    'valid' => false,
                    'message' => 'No se puede poner en mantenimiento porque la habitacion ya esta marcada como reservada en el rango afectado.',
                ];
            }

            if ($this->roomHasStayConflictOnOperationalDate($room, $dateToCheck)
                || $this->roomHasReservationConflictOnOperationalDate($room, $dateToCheck)
                || $room->isInMaintenance($dateToCheck)) {
                return [
                    'valid' => false,
                    'message' => 'No se puede poner en mantenimiento porque la habitacion ya tiene reserva o bloqueo en el rango afectado.',
                ];
            }
        }

        return ['valid' => true, 'message' => null];
    }

    private function roomHasStayConflictOnOperationalDate(Room $room, Carbon $date): bool
    {
        $operationalDate = $date->copy()->startOfDay();
        $operationalStart = HotelTime::startOfOperationalDay($operationalDate);
        $operationalEnd = HotelTime::endOfOperationalDay($operationalDate);

        return Stay::query()
            ->where('room_id', (int) $room->id)
            ->whereIn('status', ['active', 'pending_checkout'])
            ->where('check_in_at', '<=', $operationalEnd)
            ->where(function ($query) use ($operationalStart) {
                $query->whereNull('check_out_at')
                    ->orWhere('check_out_at', '>=', $operationalStart);
            })
            ->exists();
    }

    private function roomHasReservationConflictOnOperationalDate(Room $room, Carbon $date): bool
    {
        $query = ReservationRoom::query()
            ->where('room_id', (int) $room->id)
            ->whereDate('check_in_date', '<=', $date->toDateString())
            ->whereDate('check_out_date', '>', $date->toDateString())
            ->whereHas('reservation', function ($query) {
                $query->whereNull('deleted_at');
            })
            ->whereDoesntHave('reservation.stays', function ($stayQuery) use ($room) {
                $stayQuery->where('room_id', (int) $room->id)
                    ->whereNotNull('check_in_at');
            });

        if (Schema::hasTable('reservation_statuses')) {
            $excludedStatusIds = array_values(array_filter([
                $this->resolveCheckedOutReservationStatusId(),
                $this->resolveCancelledReservationStatusId(),
            ]));

            if ($excludedStatusIds !== []) {
                $query->whereHas('reservation', function ($reservationQuery) use ($excludedStatusIds) {
                    $reservationQuery
                        ->whereNull('deleted_at')
                        ->whereNotIn('status_id', $excludedStatusIds);
                });
            }
        }

        return $query->exists();
    }

    private function moveQuickReservationMarker(int $currentRoomId, int $newRoomId, Carbon $selectedDate): void
    {
        RoomQuickReservation::query()
            ->where('room_id', $newRoomId)
            ->whereDate('operational_date', $selectedDate->toDateString())
            ->delete();

        $updated = RoomQuickReservation::query()
            ->where('room_id', $currentRoomId)
            ->whereDate('operational_date', $selectedDate->toDateString())
            ->update(['room_id' => $newRoomId]);

        if ($updated === 0) {
            RoomQuickReservation::query()
                ->where('room_id', $currentRoomId)
                ->whereDate('operational_date', $selectedDate->toDateString())
                ->delete();
        }
    }

    public function markRoomAsQuickReserved(int $roomId): void
    {
        $this->openQuickReservation($roomId);
    }

    public function openQuickReservation(int $roomId): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->canEditOccupancy()) {
            $this->dispatch('notify', type: 'error', message: 'Solo el administrador o recepcion puede marcar habitaciones como reservadas.');
            return;
        }

        $room = Room::with('rates')->findOrFail($roomId);
        $selectedDate = $this->getSelectedDate()->startOfDay();
        $operationalStatus = $room->getOperationalStatus($selectedDate);
        $cleaningCode = data_get($room->cleaningStatus($selectedDate), 'code');

        if ($operationalStatus !== 'free_clean' || $cleaningCode !== 'limpia') {
            $this->dispatch('notify', type: 'error', message: 'Solo se puede reservar una habitacion libre y limpia.');
            return;
        }

        $basePrice = $this->findRateForGuests($room, 1);
        if ($basePrice <= 0 && $room->base_price_per_night) {
            $basePrice = (float) $room->base_price_per_night;
        }

        $checkIn  = $selectedDate->toDateString();
        $checkOut = $selectedDate->copy()->addDay()->toDateString();

        $this->quickReservationForm = [
            'room_id'        => $room->id,
            'room_number'    => $room->room_number,
            'max_capacity'   => $room->max_capacity,
            'check_in_date'  => $checkIn,
            'check_out_date' => $checkOut,
            'customer_id'    => null,
            'adults'         => null,
            'children'       => null,
            'notes'          => '',
            'total'          => $basePrice,
            'deposit'        => 0,
            'payment_method' => 'efectivo',
            'bank_name'      => '',
            'reference'      => '',
            '_nights'        => 1,
        ];

        $this->quickReservationModal = true;
        $this->dispatch('quickReservationOpened');
    }

    public function closeQuickReservation(): void
    {
        $this->quickReservationModal = false;
        $this->quickReservationForm  = null;
    }

    public function updatedQuickReservationFormCheckOutDate(string $value): void
    {
        if (!$this->quickReservationForm) {
            return;
        }

        $this->quickReservationForm['check_out_date'] = $value;
        $this->recalculateQuickReservationTotals();
    }

    public function updatedQuickReservationFormCustomerId(mixed $value): void
    {
        if (!$this->quickReservationForm) {
            return;
        }

        $this->quickReservationForm['customer_id'] = ($value === '' || $value === null)
            ? null
            : (is_numeric($value) ? (int) $value : null);
    }

    public function setQuickReservationDepositFull(): void
    {
        if ($this->quickReservationForm) {
            $this->quickReservationForm['deposit'] = $this->quickReservationForm['total'];
        }
    }

    public function setQuickReservationDepositHalf(): void
    {
        if ($this->quickReservationForm) {
            $this->quickReservationForm['deposit'] = round((float) $this->quickReservationForm['total'] / 2, 2);
        }
    }

    public function setQuickReservationDepositNone(): void
    {
        if ($this->quickReservationForm) {
            $this->quickReservationForm['deposit']         = 0;
            $this->quickReservationForm['payment_method']  = 'efectivo';
            $this->quickReservationForm['bank_name']       = '';
            $this->quickReservationForm['reference']       = '';
        }
    }

    private function recalculateQuickReservationTotals(): void
    {
        if (!$this->quickReservationForm) {
            return;
        }

        $room = Room::with('rates')->find($this->quickReservationForm['room_id'] ?? null);
        if (!$room) {
            return;
        }

        try {
            $checkIn  = Carbon::parse($this->quickReservationForm['check_in_date']);
            $checkOut = Carbon::parse($this->quickReservationForm['check_out_date']);
            $nights   = max(1, $checkIn->diffInDays($checkOut));
        } catch (\Throwable) {
            $nights = 1;
        }

        $pricePerNight = $this->findRateForGuests($room, 1);
        if ($pricePerNight <= 0 && $room->base_price_per_night) {
            $pricePerNight = (float) $room->base_price_per_night;
        }

        $total = round($pricePerNight * $nights, 2);

        $this->quickReservationForm['_nights'] = $nights;
        $this->quickReservationForm['total']   = $total;

        $deposit = (float) ($this->quickReservationForm['deposit'] ?? 0);
        if ($deposit > $total) {
            $this->quickReservationForm['deposit'] = $total;
        }
    }

    public function submitQuickReservation(): void
    {
        if (!$this->quickReservationForm) {
            return;
        }

        $form = $this->quickReservationForm;

        // Validaciones básicas
        $customerId = $form['customer_id'] ?? null;
        if (empty($customerId)) {
            $this->dispatch('notify', type: 'error', message: 'Debe seleccionar un huésped principal.');
            return;
        }

        $checkOut = $form['check_out_date'] ?? null;
        if (empty($checkOut)) {
            $this->dispatch('notify', type: 'error', message: 'Debe ingresar la fecha de check-out.');
            return;
        }

        try {
            $checkIn  = Carbon::parse($form['check_in_date']);
            $checkOutDate = Carbon::parse($checkOut);
            if (!$checkOutDate->greaterThan($checkIn)) {
                $this->dispatch('notify', type: 'error', message: 'La fecha de check-out debe ser posterior a la de check-in.');
                return;
            }
        } catch (\Throwable) {
            $this->dispatch('notify', type: 'error', message: 'Fechas inválidas.');
            return;
        }

        $total   = (float) ($form['total'] ?? 0);
        $deposit = (float) ($form['deposit'] ?? 0);

        if ($total <= 0) {
            $this->dispatch('notify', type: 'error', message: 'El total debe ser mayor a cero.');
            return;
        }

        if ($deposit > $total) {
            $this->dispatch('notify', type: 'error', message: 'El abono no puede superar el total.');
            return;
        }

        try {
            $reservationService = new \App\Services\ReservationService();

            $reservation = $reservationService->createReservation([
                'room_id'        => (int) $form['room_id'],
                'customerId'     => (int) $customerId,
                'check_in_date'  => $form['check_in_date'],
                'check_out_date' => $form['check_out_date'],
                'check_in_time'  => HotelTime::checkInTime(),
                'check_out_time' => HotelTime::checkOutTime(),
                'total_amount'   => $total,
                'deposit'        => $deposit,
                'payment_method' => $form['payment_method'] ?? 'efectivo',
                'bank_name'      => $form['bank_name'] ?? null,
                'reference'      => $form['reference'] ?? null,
                'adults'         => !empty($form['adults']) ? (int) $form['adults'] : null,
                'children'       => !empty($form['children']) ? (int) $form['children'] : null,
                'notes'          => $form['notes'] ?? null,
            ]);

            // Marcar visualmente la habitación como reservada para el día
            $selectedDate = $this->getSelectedDate()->startOfDay();
            RoomQuickReservation::query()->updateOrCreate(
                [
                    'room_id'          => (int) $form['room_id'],
                    'operational_date' => $selectedDate->toDateString(),
                ],
                ['created_by' => Auth::id()]
            );

            $this->closeQuickReservation();
            $this->dispatch('notify', type: 'success', message: 'Reserva creada exitosamente (Cód: ' . $reservation->reservation_code . ').');
            $this->dispatch('refreshRooms');
        } catch (\Throwable $e) {
            \Log::error('Error creating quick reservation', [
                'room_id' => $form['room_id'] ?? null,
                'error'   => $e->getMessage(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'Error al crear la reserva: ' . $e->getMessage());
        }
    }

    public function cancelQuickReserve(int $roomId): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->canEditOccupancy()) {
            $this->dispatch('notify', type: 'error', message: 'Solo el administrador o recepcion puede cancelar reservas rapidas.');
            return;
        }

        try {
            $room = Room::findOrFail($roomId);
            $selectedDate = $this->getSelectedDate()->startOfDay();

            // 1. Eliminar el marcador visual del día
            $deleted = RoomQuickReservation::query()
                ->where('room_id', $room->id)
                ->whereDate('operational_date', $selectedDate->toDateString())
                ->delete();

            // 2. Buscar y cancelar la Reserva global asociada a este cuarto en esta fecha
            $reservation = Reservation::whereHas('reservationRooms', function ($q) use ($room, $selectedDate) {
                $q->where('room_id', $room->id)
                  ->whereDate('check_in_date', '<=', $selectedDate->toDateString())
                  ->whereDate('check_out_date', '>=', $selectedDate->toDateString());
            })
                ->whereDoesntHave('stays', function ($stayQuery) use ($room) {
                    $stayQuery->where('room_id', $room->id)
                        ->whereIn('status', ['active', 'pending_checkout']);
                })
                ->latest()
                ->first();

            if ($reservation) {
                // Cargar cliente antes de cancelar
                $reservation->loadMissing('customer');

                app(ReservationCancellationService::class)
                    ->cancelCompletely($reservation, Auth::id());

                // Audit log
                try {
                    (new AuditService())->logReservationCancelled($reservation, request());
                } catch (\Throwable) {
                    // El fallo del log no debe interrumpir el flujo
                }
            }

            if ($deleted > 0 || $reservation) {
                $this->dispatch('notify', type: 'success', message: 'Reserva cancelada correctamente.');
            } else {
                $this->dispatch('notify', type: 'info', message: 'No se encontro reserva rapida para cancelar en este dia.');
            }

            $this->dispatch('room-quick-reserve-cleared', roomId: (int) $room->id);
            $this->dispatch('refreshRooms');
        } catch (\Exception $e) {
            \Log::error('Error canceling quick room reservation', [
                'room_id' => $roomId,
                'date' => $this->getSelectedDate()->toDateString(),
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'Error al cancelar la reserva: ' . $e->getMessage());
        }
    }

    public function cancelReservation(int $roomId): void
    {
        // Alias legacy: cancelar estadia debe pasar por releaseRoom
        // para conservar validaciones de deuda e historial de liberacion.
        $this->releaseRoom($roomId);
    }


    /**
     * Continúa la estadía (extiende el checkout por un día).
     * 
     * Reactiva la estadía extendiendo la fecha de checkout de la reserva.
     * Esto quita el estado de "pending_checkout" permitiendo que la habitación
     * siga ocupada un día más.
     * 
     * REGLAS DE NEGOCIO:
     * - Solo funciona para estadías que están en "pending_checkout" (check_out_date = hoy)
     * - Extiende reservation_rooms.check_out_date en 1 día
     * - NO toca pagos (el total_amount se mantiene, se pagará después)
     * - NO crea nueva estadía (la stay actual continúa)
     * - NO rompe auditoría (solo extiende fecha)
     * 
     * @param int $roomId ID de la habitación
     * @return void
     */
    public function continueStay(int $roomId): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        try {
            $room = Room::findOrFail($roomId);
            $availabilityService = $room->getAvailabilityService();
            $selectedDate = $this->getSelectedDate()->startOfDay();
            $pricingService = app(ReservationRoomPricingService::class);

            if (!HotelTime::isOperationalToday($selectedDate)) {
                $this->dispatch('notify', [
                    'type' => 'warning',
                    'message' => 'La estadia solo se puede continuar en el dia operativo actual.'
                ]);
                return;
            }

            if ($availabilityService->isHistoricDate($selectedDate)) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'No se pueden hacer cambios en fechas historicas.'
                ]);
                return;
            }

            $operationalStatus = $room->getOperationalStatus($selectedDate);
            if ($operationalStatus !== 'pending_checkout') {
                $this->dispatch('notify', [
                    'type' => 'warning',
                    'message' => 'La estadia no esta en estado de checkout pendiente para continuar.'
                ]);
                return;
            }

            $continuedUntil = null;

            DB::transaction(function () use ($roomId, $room, $selectedDate, $availabilityService, $pricingService, &$continuedUntil): void {
                $stay = $availabilityService->getStayForDate($selectedDate);
                if (!$stay) {
                    throw new \RuntimeException('No hay una estadia activa para continuar.');
                }

                $reservation = $stay->reservation;
                if (!$reservation) {
                    throw new \RuntimeException('La estadia no tiene reserva asociada.');
                }

                $reservation->loadMissing(['reservationRooms']);
                $reservationRoom = $reservation->reservationRooms->firstWhere('room_id', $roomId);
                if (!$reservationRoom) {
                    throw new \RuntimeException('No se encontro la relacion reserva-habitacion.');
                }

                $checkoutDate = Carbon::parse((string) $reservationRoom->check_out_date)->startOfDay();
                if (!$checkoutDate->isSameDay($selectedDate)) {
                    throw new \RuntimeException('La estadia no esta en estado de checkout pendiente para continuar.');
                }

                $pricingService->syncReservationRoomContractSnapshot($reservation, $reservationRoom);
                $reservationRoom->refresh();

                $effectiveNightPrice = $pricingService->resolveEffectiveNightPrice($reservation, $reservationRoom);
                if ($effectiveNightPrice <= 0) {
                    throw new \RuntimeException('No se pudo resolver el precio vigente por noche para continuar la estadia.');
                }

                $newCheckOutDate = $checkoutDate->copy()->addDay();
                $checkInDate = Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay();
                $newNights = max(1, $checkInDate->diffInDays($newCheckOutDate));
                $currentSubtotal = round((float) ($reservationRoom->subtotal ?? 0), 2);

                $reservationRoom->update([
                    'check_out_date' => $newCheckOutDate->toDateString(),
                    'nights' => $newNights,
                    'price_per_night' => $effectiveNightPrice,
                    'subtotal' => round($currentSubtotal + $effectiveNightPrice, 2),
                ]);

                if ((int) $reservation->reservationRooms()->count() === 1
                    && Schema::hasColumn('reservations', 'check_in_date')
                    && Schema::hasColumn('reservations', 'check_out_date')) {
                    DB::table('reservations')
                        ->where('id', $reservation->id)
                        ->update([
                            'check_in_date' => $checkInDate->toDateString(),
                            'check_out_date' => $newCheckOutDate->toDateString(),
                            'updated_at' => now(),
                        ]);
                }

                if ($stay->status !== 'active' || $stay->check_out_at !== null) {
                    $stay->update([
                        'status' => 'active',
                        'check_out_at' => null,
                    ]);
                }

                $nightToCharge = $newCheckOutDate->copy()->subDay();
                $stayNight = $this->ensureNightForDate($stay, $nightToCharge);
                if ($stayNight && abs((float) ($stayNight->price ?? 0) - $effectiveNightPrice) > 0.009) {
                    $stayNight->update(['price' => $effectiveNightPrice]);
                }

                $reservation->refresh()->loadMissing(['reservationRooms']);
                $freshReservationRoom = $reservation->reservationRooms->firstWhere('id', (int) $reservationRoom->id);
                if ($freshReservationRoom) {
                    $pricingService->syncReservationRoomContractSnapshot($reservation, $freshReservationRoom);
                }

                $pricingService->syncStayNightPaidFlags($reservation);
                $pricingService->syncReservationFinancialSnapshot($reservation);

                $room->update([
                    'last_cleaned_at' => null,
                ]);

                $continuedUntil = $newCheckOutDate->copy();
            });

            $this->loadRooms();

            \Log::info('Continue stay executed', [
                'room_id' => $roomId,
                'selected_date' => $selectedDate->toDateString(),
                'continued_until' => $continuedUntil?->toDateString(),
            ]);

            $message = $continuedUntil
                ? 'La estadia ha sido continuada hasta el ' . $continuedUntil->format('d/m/Y') . '.'
                : 'La estadia ha sido continuada correctamente.';

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => $message
            ]);

        } catch (\Exception $e) {
            \Log::error('Error in continueStay', [
                'room_id' => $roomId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error al continuar la estadia: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Marca una habitación como limpia actualizando last_cleaned_at.
     * Solo permitido cuando cleaningStatus().code === 'pendiente'.
     */
    public function markRoomAsClean($roomId)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        try {
            $room = Room::find($roomId);
            if (!$room) {
                $this->dispatch('notify', type: 'error', message: 'Habitación no encontrada.');
                return;
            }

            // Validar por estado de limpieza real (SSOT de limpieza)
            $selectedDate = $this->getSelectedDate();
            $cleaningCode = data_get($room->cleaningStatus($selectedDate), 'code');
            if (!in_array($cleaningCode, ['pendiente', 'mantenimiento'], true)) {
                $this->dispatch('notify', type: 'error', message: 'La habitación no requiere limpieza.');
                return;
            }

            if ($cleaningCode === 'mantenimiento' && !$room->hasOperationalMaintenanceOverrideOn($selectedDate)) {
                $this->dispatch('notify', type: 'error', message: 'Esta habitacion tiene un bloqueo de mantenimiento activo que no se puede cerrar desde este menu.');
                return;
            }

            if ($room->hasOperationalMaintenanceOverrideOn($selectedDate)) {
                app(RoomOperationalStatusService::class)->clearMaintenance($room, $selectedDate, Auth::id());
            }

            $room->last_cleaned_at = now();
            $room->save();

            $this->dispatch('notify', type: 'success', message: 'Habitación marcada como limpia.');
            $this->dispatch('refreshRooms');
            
            // Notificar al frontend sobre el cambio de estado
            $this->dispatch('room-marked-clean', roomId: $room->id);
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Error al marcar habitación: ' . $e->getMessage());
            \Log::error('Error marking room as clean: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    public function updatedSearch()
    {
        $this->resetPage();
        
        // Si estamos en la pestaña de historial, resetear también esa página
        if ($this->activeTab === 'history') {
            $this->resetPage('releaseHistoryPage');
        }
    }

    public function updatedStatusFilter($value)
    {
        $this->statusFilter = in_array((string) $value, self::ALLOWED_STATUS_FILTERS, true) ? $value : null;
        $this->resetPage();
    }

    public function updatedCleaningStatusFilter($value)
    {
        $this->cleaningStatusFilter = in_array((string) $value, self::ALLOWED_CLEANING_STATUS_FILTERS, true) ? $value : null;
        $this->resetPage();
    }

    public function updatedVentilationTypeFilter()
    {
        $this->resetPage();
    }

    /**
     * CRITICAL: Todos los métodos de cambio de fecha deben:
     * 1. Actualizar $date y $currentDate
     * 2. Llamar loadRooms() para re-renderizar inmediatamente
     * 3. Disparar 'room-view-changed' para resetear Alpine.js
     * Esto evita estados heredados y delays visuales.
     */
    public function goToDate($date)
    {
        $this->date = Carbon::parse($date);
        $this->currentDate = $this->date;

        // CRITICAL: Forzar actualización inmediata
        $this->loadRooms();
        $this->dispatch('room-view-changed', date: $this->date->toDateString());
    }

    public function nextDay()
    {
        $this->date = $this->date->copy()->addDay();
        $this->currentDate = $this->date;

        // ðŸ”¥ GENERAR NOCHE PARA FECHA ACTUAL si hay stay activa
        // ðŸ” PROTECCIÓN: Solo generar noches para HOY, nunca para fechas futuras
        // ðŸ” PROTECCIÓN EXTRA: NO generar noche si HOY es checkout o después
        try {
            $today = HotelTime::currentOperationalDate();
            
            // Protección explícita: NO generar noches futuras
            
            // Solo generar noche si la fecha nueva es HOY
            if ($this->date->equalTo($today)) {
                // Obtener todas las habitaciones con stay activa para hoy
                $startOfToday = HotelTime::startOfOperationalDay($today);
                $endOfToday = HotelTime::endOfOperationalDay($today);
                
                $activeStays = \App\Models\Stay::where('check_in_at', '<=', $endOfToday)
                    ->where(function($q) use ($startOfToday) {
                        $q->whereNull('check_out_at')
                          ->orWhere('check_out_at', '>=', $startOfToday);
                    })
                    ->where('status', 'active')
                    ->with(['reservation.reservationRooms', 'room'])
                    ->get();

                foreach ($activeStays as $stay) {
                    // ðŸ” PROTECCIÓN CRÍTICA: NO generar noche si HOY es checkout o después
                    $reservationRoom = $stay->reservation->reservationRooms->first();
                    if ($reservationRoom && $reservationRoom->check_out_date) {
                        $checkout = Carbon::parse($reservationRoom->check_out_date);
                        
                        // Si HOY es >= checkout, NO generar noche (la noche del checkout NO se cobra)
                        if ($today->gte($checkout)) {
                            continue; // Saltar esta stay
                        }
                    }
                    
                    // Generar noche solo si HOY < checkout
                    $this->ensureNightForDate($stay, $today);

                    try {
                        if ($stay->reservation) {
                            $this->syncStayNightsPaymentCoverage($stay->reservation);
                        }
                    } catch (\Exception $e) {
                        \Log::warning('Error syncing stay nights in nextDay', [
                            'stay_id' => $stay->id,
                            'reservation_id' => $stay->reservation_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            // No crítico, solo log
            \Log::warning('Error generating nights in nextDay', [
                'error' => $e->getMessage()
            ]);
        }

        // CRITICAL: Forzar actualización inmediata
        $this->loadRooms();
        $this->dispatch('room-view-changed', date: $this->date->toDateString());
    }

    public function previousDay()
    {
        $this->date = $this->date->copy()->subDay();
        $this->currentDate = $this->date;

        // CRITICAL: Forzar actualización inmediata
        $this->loadRooms();
        $this->dispatch('room-view-changed', date: $this->date->toDateString());
    }

    /**
     * Cambia la fecha actual y regenera el arreglo de días del mes para los filtros.
     * 
     * CRITICAL: Fuerza recarga inmediata de habitaciones para evitar estados heredados.
     */
    public function changeDate($newDate)
    {
        $this->date = Carbon::parse($newDate);
        $this->currentDate = $this->date;

        $startOfMonth = $this->currentDate->copy()->startOfMonth();
        $daysCount = $this->currentDate->daysInMonth;
        $this->daysInMonth = collect(range(1, $daysCount))
            ->map(fn($day) => $startOfMonth->copy()->day($day))
            ->toArray();

        // CRITICAL: Forzar actualización inmediata
        $this->loadRooms();
        $this->dispatch('room-view-changed', date: $this->date->toDateString());
    }

    public function goToToday()
    {
        $this->date = HotelTime::currentOperationalDate();
        $this->currentDate = $this->date;
        
        // CRITICAL: Forzar actualización inmediata
        $this->loadRooms();
        $this->dispatch('room-view-changed', date: $this->date->toDateString());
    }

    public function openRoomDetail($roomId)
    {
        $this->showAddSale = false;
        $this->showAddDeposit = false;
        $this->newSale = null;
        $this->newDeposit = null;

        $room = Room::with([
            'reservationRooms' => function($q) {
                $q->where('check_in_date', '<=', $this->date->toDateString())
                  ->where('check_out_date', '>=', $this->date->toDateString());
            },
            'reservationRooms.reservation.customer',
            'reservationRooms.reservation.sales.product',
            'reservationRooms.reservation.payments',
            'rates',
            'maintenanceBlocks'
        ])->find($roomId);

        if (!$room) {
            return;
        }

        // Obtener información de acceso: si es fecha histórica, bloquear
        $availabilityService = $room->getAvailabilityService();
        $accessInfo = $availabilityService->getAccessInfo($this->date);

        if ($accessInfo['isHistoric']) {
            $this->dispatch('notify', type: 'warning', message: 'Información histórica: datos en solo lectura. No se permite modificar.');
        }

        // ðŸ”¥ CRITICAL FIX: Check if room is actually occupied on this date
        // Don't show details if the room has been released (stay is finished)
        // We need to check for ACTIVE stays, not just any stay
        $stay = $availabilityService->getStayForDate($this->date);
        
        // Debug logging
        \Log::info('openRoomDetail - Debug', [
            'room_id' => $roomId,
            'date' => $this->date->toDateString(),
            'stay_found' => $stay ? true : false,
            'stay_status' => $stay ? $stay->status : 'null',
            'stay_id' => $stay ? $stay->id : null,
        ]);
        
        $isActuallyOccupied = $stay && in_array($stay->status, ['active', 'pending_checkout']);
        
        if (!$isActuallyOccupied) {
            // Room is not occupied (stay is finished or doesn't exist), show empty state
            \Log::info('openRoomDetail - Room not occupied, showing empty state', [
                'room_id' => $roomId,
                'reason' => $stay ? "Stay status: {$stay->status}" : "No stay found"
            ]);
            
            $this->detailData = [
                'room' => [
                    'id' => $room->id,
                    'room_number' => $room->room_number,
                ],
                'reservation' => null,
                'sales' => [],
                'total_hospedaje' => 0,
                'abono_realizado' => 0,
                'sales_total' => 0,
                'total_debt' => 0,
                'stay_history' => [],
                'deposit_history' => [],
                'refunds_history' => [],
                'total_refunds' => 0,
                'is_past_date' => HotelTime::isOperationalPastDate($this->getSelectedDate()),
                'isHistoric' => $accessInfo['isHistoric'],
                'canModify' => $accessInfo['canModify'],
            ];
            $this->roomDetailModal = true;
            return;
        }

        $activeReservation = $room->getActiveReservation($this->date);
        $sales = collect();
        $payments = collect();
        $totalHospedaje = 0;
        $abonoRealizado = 0;
        $refundsTotal = 0;
        $salesTotal = 0;
        $totalDebt = 0;
        $identification = null;
        $stayHistory = [];
        $validDepositPayments = collect();
        $trueRefundPayments = collect();
        $roomShareRatio = 1.0;

        if ($activeReservation) {
            $reservationRoom = $room->reservationRooms
                ->firstWhere('reservation_id', $activeReservation->id);
            // ðŸ”¥ GENERAR NOCHES FALTANTES para todo el rango de la estadía
            try {
                $stay = $availabilityService->getStayForDate($this->date);
                if ($stay) {
                    $reservationRoom = $reservationRoom ?? $room->reservationRooms->first();
                    if ($reservationRoom) {
                        $checkIn = Carbon::parse($reservationRoom->check_in_date);
                        $checkOut = Carbon::parse($reservationRoom->check_out_date);
                        
                        // ðŸ” REGLA HOTELERA: La noche del check-out NO se cobra
                        // Generar noches para todo el rango desde check-in hasta check-out (exclusivo)
                        // Ejemplo: Check-in 18, Check-out 20 â†’ Noches: 18 y 19 (NO 20)
                        $currentDate = $checkIn->copy();
                        while ($currentDate->lt($checkOut)) {
                            $this->ensureNightForDate($stay, $currentDate);
                            $currentDate->addDay();
                        }
                    }
                }

                $this->normalizeReservationStayNightTotals($activeReservation);
                $this->syncStayNightsPaymentCoverage($activeReservation);
            } catch (\Exception $e) {
                // No crítico, solo log
                \Log::warning('Error generating nights in openRoomDetail', [
                    'room_id' => $roomId,
                    'error' => $e->getMessage()
                ]);
            }
            $sales = $activeReservation->sales ?? collect();
            $payments = $activeReservation->payments ?? collect();

            // ===== SSOT FINANCIERO: Separar pagos y devoluciones =====
            // REGLA CRÍTICA: payments.amount > 0 = dinero recibido (pagos)
            // payments.amount < 0 = dinero devuelto (devoluciones)
            // NO mezclar en sum(amount) porque se cancelan incorrectamente
            $paymentBuckets = $this->splitPaymentsForRoomDetail($payments);
            $validDepositPayments = $paymentBuckets['valid_deposits'] ?? collect();
            $trueRefundPayments = $paymentBuckets['refunds'] ?? collect();

            $validDepositsTotal = (float)($validDepositPayments->sum('amount') ?? 0);
            $trueRefundsTotal = abs((float)($trueRefundPayments->sum('amount') ?? 0));
            $reservationTotalHospedaje = 0.0;

            // ===== SSOT ABSOLUTO DEL HOSPEDAJE: stay_nights (NUEVO) =====
            // REGLA CRÍTICA: El total del hospedaje se calcula sumando todas las noches reales desde stay_nights
            // Esto permite rastrear cada noche individualmente y su estado de pago
            try {
                // Intentar usar stay_nights (si existe)
                $stayNightsQuery = \App\Models\StayNight::where('reservation_id', $activeReservation->id)
                    ->where('room_id', $room->id);

                if ($reservationRoom && !empty($reservationRoom->check_in_date) && !empty($reservationRoom->check_out_date)) {
                    $stayNightsQuery
                        ->whereDate('date', '>=', Carbon::parse((string) $reservationRoom->check_in_date)->toDateString())
                        ->whereDate('date', '<', Carbon::parse((string) $reservationRoom->check_out_date)->toDateString());
                }

                $stayNights = $stayNightsQuery
                    ->orderBy('date')
                    ->get();

                if ($stayNights->isNotEmpty()) {
                    // âœ… NUEVO SSOT: Calcular desde stay_nights
                    $totalHospedaje = (float)$stayNights->sum('price');
                    
                    // âœ… NUEVO SSOT: Leer stay_history desde stay_nights
                    $stayHistory = $stayNights->map(function($night) {
                        return [
                            'date' => $night->date->format('Y-m-d'),
                            'price' => (float)$night->price,
                            'is_paid' => (bool)$night->is_paid,
                        ];
                    })->toArray();
                } else {
                    // FALLBACK: Si no hay stay_nights aún, usar total_amount (compatibilidad)
                    $totalHospedaje = 0;
                    
                    $reservationRoom = $reservationRoom ?? $room->reservationRooms->first();
                    if ($reservationRoom) {
                        $checkIn = Carbon::parse($reservationRoom->check_in_date);
                        $checkOut = Carbon::parse($reservationRoom->check_out_date);
                        $nights = max(1, $checkIn->diffInDays($checkOut));
                        $pricePerNight = (float)($reservationRoom->price_per_night ?? 0);
                        if ($pricePerNight <= 0) {
                            $subtotal = (float)($reservationRoom->subtotal ?? 0);
                            if ($subtotal > 0 && $nights > 0) {
                                $pricePerNight = round($subtotal / $nights, 2);
                            }
                        }
                        $totalHospedaje = round($pricePerNight * $nights, 2);
                        
                        // Calcular stay_history desde fechas (fallback)
                        for ($i = 0; $i < $nights; $i++) {
                            $currentDate = $checkIn->copy()->addDays($i);
                            $stayHistory[] = [
                                'date' => $currentDate->format('Y-m-d'),
                                'price' => $pricePerNight,
                                'is_paid' => false, // Por defecto pendiente si no hay stay_nights
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Si falla (tabla no existe aún), usar fallback
                \Log::warning('Error reading stay_nights, using fallback', [
                    'reservation_id' => $activeReservation->id,
                    'error' => $e->getMessage()
                ]);
                
                if ($reservationRoom && !empty($reservationRoom->check_in_date) && !empty($reservationRoom->check_out_date)) {
                    $checkIn = Carbon::parse($reservationRoom->check_in_date);
                    $checkOut = Carbon::parse($reservationRoom->check_out_date);
                    $nights = max(1, $checkIn->diffInDays($checkOut));
                    $pricePerNight = (float)($reservationRoom->price_per_night ?? 0);
                    if ($pricePerNight <= 0) {
                        $subtotal = (float)($reservationRoom->subtotal ?? 0);
                        if ($subtotal > 0 && $nights > 0) {
                            $pricePerNight = round($subtotal / $nights, 2);
                        }
                    }
                    $totalHospedaje = round($pricePerNight * $nights, 2);
                } else {
                    $totalHospedaje = (float)($activeReservation->total_amount ?? 0);
                }
                $stayHistory = [];
            }

            // ===== VALIDACIÓN: Si totalHospedaje sigue siendo 0, algo está mal =====
            try {
                $reservationTotalHospedaje = $this->calculateReservationStayNightsTotalByRoomRanges($activeReservation);
            } catch (\Exception $e) {
                $reservationTotalHospedaje = 0.0;
            }

            if ($reservationTotalHospedaje <= 0) {
                $reservationTotalHospedaje = (float)($activeReservation->total_amount ?? 0);
            }

            $roomContractualTotal = round((float) ($reservationRoom?->subtotal ?? 0), 2);
            if (empty($stayHistory) && $roomContractualTotal > 0 && abs($roomContractualTotal - $totalHospedaje) > 0.01) {
                $totalHospedaje = $roomContractualTotal;
            }

            $roomShareRatio = $reservationTotalHospedaje > 0
                ? max(0.0, min(1.0, $totalHospedaje / $reservationTotalHospedaje))
                : 1.0;

            $abonoRealizado = round($validDepositsTotal * $roomShareRatio, 2);
            $refundsTotal = round($trueRefundsTotal * $roomShareRatio, 2);

            if ($totalHospedaje == 0) {
                \Log::warning('openRoomDetail: totalHospedaje is 0', [
                    'reservation_id' => $activeReservation->id,
                    'reservation_total_amount' => $activeReservation->total_amount,
                    'room_id' => $room->id,
                ]);
            }

            // ===== CALCULAR CONSUMOS =====
            $salesTotal = (float)($sales->sum('total') ?? 0);
            $salesDebt = (float)($sales->where('is_paid', false)->sum('total') ?? 0);

            // ===== CALCULAR DEUDA TOTAL (CORRECTA CON PAGOS Y DEVOLUCIONES SEPARADOS) =====
            // Fórmula: deuda = (hospedaje - abonos_reales) + devoluciones + consumos_pendientes
            // Si hay devoluciones, se suman porque representan dinero que se devolvió
            $totalDebt = ($totalHospedaje - $abonoRealizado) + $refundsTotal + $salesDebt;

            // Contingencia final:
            // si la cuenta de la habitacion ya esta al dia, no puede existir noche pendiente en UI.
            if ($totalDebt <= 0.01 && !empty($stayHistory)) {
                $hasPendingNight = collect($stayHistory)->contains(
                    static fn (array $night): bool => !(bool) ($night['is_paid'] ?? false)
                );

                if ($hasPendingNight) {
                    try {
                        $syncQuery = \App\Models\StayNight::query()
                            ->where('reservation_id', $activeReservation->id)
                            ->where('room_id', $room->id)
                            ->where('is_paid', false);

                        if ($reservationRoom && !empty($reservationRoom->check_in_date) && !empty($reservationRoom->check_out_date)) {
                            $syncQuery
                                ->whereDate('date', '>=', Carbon::parse((string) $reservationRoom->check_in_date)->toDateString())
                                ->whereDate('date', '<', Carbon::parse((string) $reservationRoom->check_out_date)->toDateString());
                        }

                        $updatedRows = (int) $syncQuery->update(['is_paid' => true]);
                        if ($updatedRows > 0) {
                            $stayHistory = array_map(static function (array $night): array {
                                $night['is_paid'] = true;
                                return $night;
                            }, $stayHistory);
                        }
                    } catch (\Exception $e) {
                        \Log::warning('openRoomDetail: could not force-paid pending nights despite settled debt', [
                            'reservation_id' => $activeReservation->id,
                            'room_id' => $room->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $identification = $activeReservation->customer->taxProfile->identification ?? null;
        }

        $this->detailData = [
            'room' => $room,
            'reservation' => $activeReservation,
            'display_status' => $room->getDisplayStatus($this->date),
            'sales' => $sales->map(function($sale) {
                return [
                    'id' => $sale->id,
                    'product' => [
                        'name' => $sale->product->name ?? null,
                    ],
                    'quantity' => $sale->quantity ?? 0,
                    'is_paid' => (bool)($sale->is_paid ?? false),
                    'payment_method' => $sale->payment_method ?? null,
                    'total' => (float)($sale->total ?? 0),
                ];
            })->values()->toArray(),
            'payments_history' => $payments->map(function($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => (float)($payment->amount ?? 0),
                    'method' => $payment->paymentMethod->name ?? null,
                    'created_at' => $payment->created_at,
                ];
            })->values()->toArray(),
            'total_hospedaje' => $totalHospedaje,
            'abono_realizado' => $abonoRealizado,
            'sales_total' => $salesTotal,
            'total_debt' => $totalDebt,
            'identification' => $identification,
            'stay_history' => $stayHistory,
            'deposit_history' => $validDepositPayments->map(function($payment) use ($roomShareRatio) {
                return [
                    'id' => $payment->id,
                    'amount' => round(((float)($payment->amount ?? 0)) * $roomShareRatio, 2),
                    'payment_method' => $payment->paymentMethod->name ?? 'N/A',
                    'notes' => $payment->reference ?? null,
                    'created_at' => $payment->created_at ? $payment->created_at->format('Y-m-d H:i') : null,
                ];
            })->filter(fn($row) => (float)($row['amount'] ?? 0) > 0)->values()->toArray(),
            'refunds_history' => $trueRefundPayments->map(function($payment) use ($roomShareRatio) {
                // Cargar createdBy si no está cargado
                if (!$payment->relationLoaded('createdBy')) {
                    $payment->load('createdBy');
                }
                
                return [
                    'id' => $payment->id,
                    'amount' => round(abs((float)($payment->amount ?? 0)) * $roomShareRatio, 2), // Valor absoluto para mostrar positivo en UI
                    'payment_method' => $payment->paymentMethod->name ?? 'N/A',
                    'bank_name' => $payment->bank_name ?? null,
                    'reference' => $payment->reference ?? null,
                    'created_by' => $payment->createdBy->name ?? 'N/A',
                    'created_at' => $payment->created_at ? $payment->created_at->format('Y-m-d H:i') : null,
                ];
            })->filter(fn($row) => (float)($row['amount'] ?? 0) > 0)->values()->toArray(),
            'total_refunds' => $refundsTotal ?? 0, // Total de devoluciones para mostrar en el header del historial
            'is_past_date' => HotelTime::isOperationalPastDate($this->getSelectedDate()),
            'isHistoric' => $accessInfo['isHistoric'],
            'canModify' => $accessInfo['canModify'],
        ];

        $this->roomDetailModal = true;
    }

    public function closeRoomDetail()
    {
        $this->roomDetailModal = false;
        $this->detailData = null;
        $this->showAddSale = false;
        $this->showAddDeposit = false;
        $this->newSale = null;
        $this->newDeposit = null;
    }

    public function toggleAddSale(): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        $this->showAddSale = !$this->showAddSale;
        if ($this->showAddSale) {
            $this->newSale = [
                'product_id' => null,
                'quantity' => 1,
                'payment_method' => 'efectivo',
            ];
            $this->dispatch('initAddSaleSelect');
        } else {
            $this->newSale = null;
        }
    }

    public function toggleAddDeposit(): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        $this->showAddDeposit = !$this->showAddDeposit;
        if ($this->showAddDeposit) {
            $this->newDeposit = [
                'amount' => null,
                'payment_method' => 'efectivo',
                'notes' => null,
            ];
        } else {
            $this->newDeposit = null;
        }
    }

    public function addSale(): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->detailData || !isset($this->detailData['reservation']['id'])) {
            $this->dispatch('notify', type: 'error', message: 'No se encontró la reserva.');
            return;
        }

        if (!$this->newSale || empty($this->newSale['product_id'])) {
            $this->dispatch('notify', type: 'error', message: 'Seleccione un producto.');
            return;
        }

        $reservationId = $this->detailData['reservation']['id'];
        $roomId = $this->detailData['room']['id'] ?? null;
        $roomNumber = $this->detailData['room']['room_number'] ?? '?';
        $productId = (int) $this->newSale['product_id'];
        $quantity = max(1, (int) ($this->newSale['quantity'] ?? 1));
        $paymentMethod = $this->newSale['payment_method'] ?? 'pendiente';

        $product = Product::find($productId);
        if (!$product) {
            $this->dispatch('notify', type: 'error', message: 'Producto no encontrado.');
            return;
        }

        if ($product->quantity < $quantity) {
            $this->dispatch('notify', type: 'error', message: "Stock insuficiente. Disponible: {$product->quantity}");
            return;
        }

        try {
            DB::beginTransaction();

            $isPaid = $paymentMethod !== 'pendiente';
            $unitPrice = (float) $product->price;
            $total = round($unitPrice * $quantity, 2);

            ReservationSale::create([
                'reservation_id' => $reservationId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $total,
                'payment_method' => $paymentMethod,
                'is_paid' => $isPaid,
            ]);

            $product->recordMovement(
                -$quantity,
                'room_consumption',
                "Consumo hab. {$roomNumber} - Reserva #{$reservationId}",
                $roomId
            );

            $this->recalculateReservationFinancials($reservationId);

            DB::commit();

            $this->newSale = null;
            $this->showAddSale = false;
            $this->openRoomDetail($roomId);
            $this->dispatch('notify', type: 'success', message: "Consumo registrado: {$product->name} x{$quantity}");

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error in addSale', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->dispatch('notify', type: 'error', message: 'Error al registrar consumo: ' . $e->getMessage());
        }
    }

    public function addDeposit(): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        // Validar que tenemos los datos necesarios
        if (!$this->newDeposit || !isset($this->newDeposit['amount']) || !isset($this->newDeposit['payment_method'])) {
            $this->dispatch('notify', type: 'error', message: 'Por favor complete todos los campos requeridos.');
            return;
        }

        // Validar que tenemos una reserva en el modal
        if (!$this->detailData || !isset($this->detailData['reservation']['id'])) {
            $this->dispatch('notify', type: 'error', message: 'No se encontró la reserva.');
            return;
        }

        $reservationId = $this->detailData['reservation']['id'];
        $amount = (float)($this->newDeposit['amount'] ?? 0);
        $paymentMethod = $this->newDeposit['payment_method'] ?? 'efectivo';
        $notes = $this->newDeposit['notes'] ?? null;

        // Si es transferencia, necesitamos bank_name y reference, pero por ahora los dejamos como null
        // ya que el formulario no los tiene
        $bankName = null;
        $reference = null;

        // Llamar a registerPayment con las notas
        $success = $this->registerPayment($reservationId, $amount, $paymentMethod, $bankName, $reference, $notes);

        // Limpiar el formulario y cerrar el panel solo si el pago fue exitoso
        if ($success) {
            $this->newDeposit = null;
            $this->showAddDeposit = false;
        }
    }

    public function paySale($saleId, $method): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        try {
            $sale = ReservationSale::find($saleId);
            if (!$sale) {
                $this->dispatch('notify', type: 'error', message: 'Consumo no encontrado.');
                return;
            }

            if (!in_array($method, ['efectivo', 'transferencia', 'pendiente'])) {
                $this->dispatch('notify', type: 'error', message: 'Método de pago inválido.');
                return;
            }

            $isPaid = $method !== 'pendiente';
            $sale->update([
                'payment_method' => $method,
                'is_paid' => $isPaid,
            ]);

            $this->recalculateReservationFinancials($sale->reservation_id);

            if ($this->detailData && isset($this->detailData['room']['id'])) {
                $this->openRoomDetail($this->detailData['room']['id']);
            }

            $message = $isPaid ? 'Pago de consumo registrado.' : 'Pago de consumo anulado.';
            $this->dispatch('notify', type: 'success', message: $message);

        } catch (\Exception $e) {
            \Log::error('Error in paySale', ['sale_id' => $saleId, 'error' => $e->getMessage()]);
            $this->dispatch('notify', type: 'error', message: 'Error: ' . $e->getMessage());
        }
    }

    public function deleteDeposit($depositId, $amount): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        try {
            $payment = Payment::find($depositId);
            if (!$payment) {
                $this->dispatch('notify', type: 'error', message: 'Pago no encontrado.');
                return;
            }

            $reservationId = $this->detailData['reservation']['id'] ?? null;
            if (!$reservationId || (int) $payment->reservation_id !== (int) $reservationId) {
                $this->dispatch('notify', type: 'error', message: 'El pago no pertenece a esta reserva.');
                return;
            }

            DB::beginTransaction();

            $reversalAmount = -abs((float) $payment->amount);
            Payment::create([
                'reservation_id' => $payment->reservation_id,
                'amount' => $reversalAmount,
                'payment_method_id' => $payment->payment_method_id,
                'reference' => "Anulacion de pago #{$payment->id}",
                'paid_at' => now(),
                'created_by' => auth()->id(),
                'notes' => "Reversal of payment #{$payment->id}",
            ]);

            // Desmarcar stay_nights pagadas LIFO
            $nightsToUnpay = abs((float) $payment->amount);
            $paidNights = StayNight::where('reservation_id', $reservationId)
                ->where('is_paid', true)
                ->orderByDesc('date')
                ->get();

            foreach ($paidNights as $night) {
                if ($nightsToUnpay <= 0) break;
                $nightPrice = (float) $night->price;
                if ($nightPrice <= 0) continue;
                if ($nightsToUnpay >= $nightPrice) {
                    $night->update(['is_paid' => false]);
                    $nightsToUnpay -= $nightPrice;
                }
            }

            $this->recalculateReservationFinancials($reservationId);

            DB::commit();

            if ($this->detailData && isset($this->detailData['room']['id'])) {
                $this->openRoomDetail($this->detailData['room']['id']);
            }

            $this->dispatch('notify', type: 'success', message: 'Abono eliminado correctamente.');

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error in deleteDeposit', ['deposit_id' => $depositId, 'error' => $e->getMessage()]);
            $this->dispatch('notify', type: 'error', message: 'Error: ' . $e->getMessage());
        }
    }

    public function updateDeposit($reservationId, $amount): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        try {
            $reservationId = (int) $reservationId;
            $newTotal = (float) $amount;

            if ($newTotal < 0) {
                $this->dispatch('notify', type: 'error', message: 'El monto no puede ser negativo.');
                return;
            }

            $reservation = Reservation::with('payments')->find($reservationId);
            if (!$reservation) {
                $this->dispatch('notify', type: 'error', message: 'Reserva no encontrada.');
                return;
            }

            $currentDeposit = (float) $reservation->payments->sum('amount');
            $difference = round($newTotal - $currentDeposit, 2);

            if (abs($difference) < 0.01) {
                $this->dispatch('notify', type: 'info', message: 'El abono ya es el monto indicado.');
                return;
            }

            if ($difference > 0) {
                $this->registerPayment($reservationId, $difference, 'efectivo', null, null, 'Ajuste de abono');
            } else {
                DB::beginTransaction();

                $paymentMethodId = $this->getPaymentMethodId('efectivo');
                Payment::create([
                    'reservation_id' => $reservationId,
                    'amount' => $difference,
                    'payment_method_id' => $paymentMethodId,
                    'reference' => 'Ajuste de abono (reduccion)',
                    'paid_at' => now(),
                    'created_by' => auth()->id(),
                ]);

                $this->recalculateReservationFinancials($reservationId);
                DB::commit();
            }

            if ($this->detailData && isset($this->detailData['room']['id'])) {
                $this->openRoomDetail($this->detailData['room']['id']);
            }

            $this->dispatch('notify', type: 'success', message: 'Abono actualizado correctamente.');

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error in updateDeposit', ['error' => $e->getMessage()]);
            $this->dispatch('notify', type: 'error', message: 'Error: ' . $e->getMessage());
        }
    }

    private function recalculateReservationFinancials(int $reservationId): void
    {
        $reservation = Reservation::with(['payments', 'sales'])->find($reservationId);
        if (!$reservation) return;

        try {
            $this->ensureStayNightsCoverageForReservation($reservation);
            $this->normalizeReservationStayNightTotals($reservation);
            $this->syncStayNightsPaymentCoverage($reservation);
            $reservation->refresh()->load(['payments', 'sales']);
        } catch (\Exception $e) {
            \Log::warning('Error syncing stay nights in recalculateReservationFinancials', [
                'reservation_id' => $reservationId,
                'error' => $e->getMessage(),
            ]);
        }

        $totalAmount = (float) StayNight::where('reservation_id', $reservationId)->sum('price');
        if ($totalAmount <= 0) {
            $totalAmount = (float) ($reservation->total_amount ?? 0);
        }

        $paymentsTotal = (float) ($reservation->payments->sum('amount') ?? 0);
        $salesDebt = (float) ($reservation->sales->where('is_paid', false)->sum('total') ?? 0);
        $balanceDue = $totalAmount - $paymentsTotal + $salesDebt;

        $paymentStatusCode = $balanceDue <= 0 ? 'paid' : ($paymentsTotal > 0 ? 'partial' : 'pending');
        $paymentStatusId = DB::table('payment_statuses')->where('code', $paymentStatusCode)->value('id');

        $reservation->update([
            'deposit_amount' => max(0, $paymentsTotal),
            'balance_due' => max(0, $balanceDue),
            'payment_status_id' => $paymentStatusId,
        ]);
    }

    /**
     * Registra un pago en la tabla payments (Single Source of Truth).
     * 
     * @param int $reservationId ID de la reserva
     * @param float $amount Monto del pago
     * @param string $paymentMethod Método de pago ('efectivo' o 'transferencia')
     * @param string|null $bankName Nombre del banco (solo si es transferencia)
     * @param string|null $reference Referencia de pago (solo si es transferencia)
     */
    /**
     * Obtiene el contexto financiero de una reserva para mostrar en el modal de pago
     */
    public function getFinancialContext($reservationId)
    {
        try {
            $reservation = Reservation::with(['payments', 'sales'])->find($reservationId);
            if (!$reservation) {
                return null;
            }

            $this->ensureStayNightsCoverageForReservation($reservation);
            $this->normalizeReservationStayNightTotals($reservation);

            $paymentsTotal = (float)($reservation->payments()->sum('amount') ?? 0);
            $salesDebt = (float)($reservation->sales?->where('is_paid', false)->sum('total') ?? 0);
            
            // âœ… NUEVO SSOT: Calcular desde stay_nights si existe
            try {
                $totalAmount = (float)\App\Models\StayNight::where('reservation_id', $reservationId)
                    ->sum('price');
                
                // Si no hay noches, usar fallback
                if ($totalAmount <= 0) {
                    $totalAmount = (float)($reservation->total_amount ?? 0);
                }
            } catch (\Exception $e) {
                // Si falla (tabla no existe), usar fallback
                $totalAmount = (float)($reservation->total_amount ?? 0);
            }

            // Para contexto de pago, priorizar el total contractual para evitar falsos saldos por drift legacy.
            $contractualTotal = $this->resolveReservationContractualLodgingTotal($reservation);
            if ($contractualTotal > 0) {
                $totalAmount = $contractualTotal;
            }
            
            $balanceDue = $totalAmount - $paymentsTotal + $salesDebt;

            return [
                'totalAmount' => $totalAmount,
                'paymentsTotal' => $paymentsTotal,
                'balanceDue' => max(0, $balanceDue),
            ];
        } catch (\Exception $e) {
            \Log::error('Error getting financial context', [
                'reservation_id' => $reservationId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    #[On('register-payment')]
    public function handleRegisterPayment($reservationId, $amount, $paymentMethod, $bankName = null, $reference = null, $nightDate = null)
    {
        $this->registerPayment($reservationId, $amount, $paymentMethod, $bankName, $reference, null, $nightDate);
    }

    #[On('sync-reservation-night-status')]
    public function handleSyncReservationNightStatus($reservationId, $nightDate = null): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        try {
            $reservationId = (int) $reservationId;
            if ($reservationId <= 0) {
                $this->dispatch('notify', type: 'error', message: 'ID de reserva invalido para sincronizar noches.');
                return;
            }

            $reservation = Reservation::find($reservationId);
            if (!$reservation) {
                $this->dispatch('notify', type: 'error', message: 'Reserva no encontrada para sincronizar noches.');
                return;
            }

            $this->ensureStayNightsCoverageForReservation($reservation);
            $this->normalizeReservationStayNightTotals($reservation);
            $this->syncStayNightsPaymentCoverage($reservation);

            $updated = 0;
            if ($this->isReservationLodgingCoveredByNetPayments($reservation)) {
                $updated = $this->forceMarkReservationNightsAsPaid(
                    $reservation,
                    !empty($nightDate) ? (string) $nightDate : null
                );
            }

            if ($this->roomDetailModal && $this->detailData && isset($this->detailData['room']['id'])) {
                $this->openRoomDetail((int) $this->detailData['room']['id']);
            }

            if ($updated > 0) {
                $this->dispatch('notify', type: 'success', message: 'Se sincronizo el estado de pago por noches.');
            } else {
                $this->dispatch('notify', type: 'info', message: 'Las noches ya estaban sincronizadas.');
            }
        } catch (\Exception $e) {
            \Log::error('Error syncing reservation night state', [
                'reservation_id' => $reservationId,
                'night_date' => $nightDate,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'No fue posible sincronizar el estado de noches.');
        }
    }

    #[On('openAssignGuests')]
    public function handleOpenAssignGuests(...$params)
    {
        $roomId = $params[0] ?? null;
        if ($roomId) {
            $this->openAssignGuests($roomId);
        }
    }

    #[On('openEditPrices')]
    public function handleOpenEditPrices(...$params)
    {
        $reservationId = $params[0] ?? null;
        if ($reservationId) {
            $this->openEditPrices($reservationId);
        }
    }

    #[On('showAllGuests')]
    public function handleShowAllGuests(...$params)
    {
        $reservationId = $params[0] ?? null;
        $roomId = $params[1] ?? null;
        if ($reservationId && $roomId) {
            $this->showAllGuests($reservationId, $roomId);
        }
    }

    /**
     * Cerrar modal de edición de precios
     */
    public function cancelEditPrices()
    {
        $this->editPricesModal = false;
        $this->editPricesForm = null;
    }

    // =========================================================
    // CAMBIO DE HABITACION
    // =========================================================

    public function openChangeRoom($roomId)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        try {
            $room = \App\Models\Room::findOrFail($roomId);
            $date = $this->date instanceof \Carbon\Carbon ? $this->date : \Carbon\Carbon::parse($this->date);
            $selectedDate = $date->copy()->startOfDay();
            $activeStay = Stay::query()
                ->where('room_id', (int) $room->id)
                ->whereNull('check_out_at')
                ->whereIn('status', ['active', 'pending_checkout'])
                ->with(['reservation.customer'])
                ->orderByDesc('check_in_at')
                ->first();

            $changeMode = null;
            $reservation = $activeStay?->reservation;

            if ($reservation) {
                $changeMode = 'active_stay';
            }

            if (!$reservation) {
                $reservation = $this->getPendingCheckInReservationForRoom($room, $selectedDate)
                    ?: $room->getFutureReservation($selectedDate);

                if ($reservation) {
                    $changeMode = 'pending_reservation';
                }
            }

            if (!$reservation) {
                $this->dispatch('notify', type: 'error', message: 'No se encontro una reserva activa o pendiente para esta habitacion.');
                return;
            }

            $reservation->loadMissing(['customer', 'reservationRooms']);
            $reservationRoom = $reservation->reservationRooms->firstWhere('room_id', (int) $room->id);
            if (!$reservationRoom) {
                $this->dispatch('notify', type: 'error', message: 'La reserva no tiene asignada esta habitacion.');
                return;
            }

            $allRooms = \App\Models\Room::active()->with('roomType')->orderBy('room_number')->get();
            $available = $allRooms->filter(function ($candidateRoom) use ($room, $selectedDate, $reservation) {
                if ((int) $candidateRoom->id === (int) $room->id) {
                    return false;
                }

                return $this->isRoomAvailableForChange($candidateRoom, $selectedDate, (int) $reservation->id);
            })->values();

            if ($available->isEmpty()) {
                $this->dispatch('notify', type: 'error', message: 'No hay habitaciones libres disponibles para el cambio.');
                return;
            }

            $this->changeRoomData = [
                'room_id'          => $room->id,
                'room_number'      => $room->room_number,
                'reservation_id'   => $reservation->id,
                'reservation_room_id' => (int) $reservationRoom->id,
                'reservation_code' => $reservation->reservation_code ?? ('#' . $reservation->id),
                'customer_name'    => $reservation->customer?->name ?? 'Sin cliente',
                'change_mode'      => $changeMode,
            ];

            $this->availableRoomsForChange = $available->map(fn($r) => [
                'id'          => $r->id,
                'room_number' => $r->room_number,
                'type_name'   => $r->roomType?->name ?? '',
            ])->toArray();

            $this->changeRoomModal = true;

        } catch (\Exception $e) {
            \Log::error('Error en openChangeRoom: ' . $e->getMessage());
            $this->dispatch('notify', type: 'error', message: 'Error al abrir cambio de habitacion: ' . $e->getMessage());
        }
    }

    public function submitChangeRoom($newRoomId)
    {
        if (!$newRoomId || $this->blockEditsForPastDate()) {
            return;
        }

        try {
            $reservationId = $this->changeRoomData['reservation_id'] ?? null;
            $currentRoomId = $this->changeRoomData['room_id'] ?? null;

            if (!$reservationId || !$currentRoomId) {
                $this->dispatch('notify', type: 'error', message: 'Datos de cambio incompletos.');
                return;
            }

            $newRoom = \App\Models\Room::findOrFail($newRoomId);
            $date    = $this->date instanceof \Carbon\Carbon ? $this->date : \Carbon\Carbon::parse($this->date);
            $selectedDate = $date->copy()->startOfDay();
            $reservation = \App\Models\Reservation::with(['stays', 'stayNights', 'reservationRooms'])->findOrFail($reservationId);

            if (!$this->isRoomAvailableForChange($newRoom, $selectedDate, (int) $reservationId)) {
                $this->dispatch('notify', type: 'error', message: 'La habitacion seleccionada ya no esta disponible.');
                return;
            }

            if ($this->reservationAlreadyUsesRoom((int) $reservationId, (int) $newRoomId)) {
                $this->dispatch('notify', type: 'error', message: 'La reserva ya tiene asignada la habitacion seleccionada.');
                return;
            }

            $reservationRoom = $reservation->reservationRooms->firstWhere('room_id', (int) $currentRoomId);
            if (!$reservationRoom) {
                $this->dispatch('notify', type: 'error', message: 'No se encontro la asignacion de habitacion que deseas mover.');
                return;
            }

            $activeStay = Stay::query()
                ->where('reservation_id', (int) $reservation->id)
                ->where('room_id', (int) $currentRoomId)
                ->whereNull('check_out_at')
                ->whereIn('status', ['active', 'pending_checkout'])
                ->orderByDesc('check_in_at')
                ->first();

            $changeMode = $activeStay ? 'active_stay' : 'pending_reservation';

            \DB::transaction(function () use ($reservation, $reservationRoom, $activeStay, $changeMode, $currentRoomId, $newRoomId, $selectedDate) {
                if ($activeStay) {
                    $transferTimestamp = now();
                    $activeStay->update([
                        'check_out_at' => $transferTimestamp,
                        'status' => 'finished',
                    ]);

                    $newStay = Stay::query()->create([
                        'reservation_id' => (int) $reservation->id,
                        'room_id' => (int) $newRoomId,
                        'check_in_at' => $transferTimestamp,
                        'check_out_at' => null,
                        'status' => in_array((string) $activeStay->status, ['active', 'pending_checkout'], true)
                            ? (string) $activeStay->status
                            : 'active',
                    ]);

                    StayNight::query()
                        ->where('reservation_id', (int) $reservation->id)
                        ->where('room_id', (int) $currentRoomId)
                        ->whereDate('date', '>=', $selectedDate->toDateString())
                        ->update([
                            'room_id' => (int) $newRoomId,
                            'stay_id' => (int) $newStay->id,
                        ]);

                    $reservationRoom->update(['room_id' => (int) $newRoomId]);

                    RoomQuickReservation::query()
                        ->whereIn('room_id', [(int) $currentRoomId, (int) $newRoomId])
                        ->whereDate('operational_date', $selectedDate->toDateString())
                        ->delete();

                    \App\Models\Room::where('id', (int) $currentRoomId)
                        ->update(['last_cleaned_at' => null]);
                } else {
                    $reservationRoom->update(['room_id' => (int) $newRoomId]);
                    $this->moveQuickReservationMarker((int) $currentRoomId, (int) $newRoomId, $selectedDate);

                    \App\Models\Room::where('id', (int) $currentRoomId)
                        ->update(['last_cleaned_at' => now()]);
                }
            });

            $this->cancelChangeRoom();
            $this->loadRooms();
            $this->dispatch('$refresh');
            $this->dispatch('refreshRooms');
            $this->dispatch('notify', type: 'success', message: $changeMode === 'active_stay'
                ? 'Habitacion cambiada correctamente. La nueva habitacion queda ocupada.'
                : 'Habitacion cambiada correctamente. La reserva sigue pendiente de check-in.');

        } catch (\Exception $e) {
            \Log::error('Error en submitChangeRoom: ' . $e->getMessage());
            $this->dispatch('notify', type: 'error', message: 'Error al cambiar habitacion: ' . $e->getMessage());
        }
    }

    public function cancelChangeRoom()
    {
        $this->changeRoomModal        = false;
        $this->changeRoomData         = [];
        $this->availableRoomsForChange = [];
    }

    // =========================================================
    // ANULAR INGRESO DEL DIA
    // =========================================================

    public function undoCheckout($roomId)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        $date = $this->getSelectedDate()->startOfDay();
        if (!HotelTime::isOperationalToday($date)) {
            $this->dispatch('notify', type: 'error', message: 'Solo se pueden anular ingresos del dia operativo actual.');
            return;
        }

        try {
            $room = \App\Models\Room::findOrFail($roomId);
            $operationalStart = HotelTime::startOfOperationalDay($date);
            $operationalEnd = HotelTime::endOfOperationalDay($date);

            $stay = \App\Models\Stay::where('room_id', $roomId)
                ->where('status', 'finished')
                ->whereBetween('check_out_at', [$operationalStart, $operationalEnd])
                ->orderByDesc('check_out_at')
                ->first();

            if (!$stay) {
                $this->dispatch('notify', type: 'error', message: 'No se encontro un egreso de hoy para anular en esta habitacion.');
                return;
            }

            $reservation = \App\Models\Reservation::with(['payments', 'stayNights'])->find($stay->reservation_id);

            if (!$reservation) {
                $this->dispatch('notify', type: 'error', message: 'No se encontro la reserva asociada al egreso.');
                return;
            }

            \DB::transaction(function () use ($stay, $reservation, $room, $date) {
                // 1. Revertir pagos positivos que no hayan sido ya revertidos
                $alreadyReversedIds = $reservation->payments()
                    ->where('amount', '<', 0)
                    ->get()
                    ->map(fn($p) => $this->extractReversedPaymentIdFromReference($p->reference))
                    ->filter()
                    ->values()
                    ->toArray();

                $reservation->payments()->where('amount', '>', 0)->get()
                    ->reject(fn($p) => in_array($p->id, $alreadyReversedIds))
                    ->each(function ($payment) use ($reservation) {
                        \App\Models\Payment::create([
                            'reservation_id'    => $reservation->id,
                            'amount'            => -abs((float) $payment->amount),
                            'payment_method_id' => $payment->payment_method_id,
                            'reference'         => "Anulacion de pago #{$payment->id}",
                            'paid_at'           => now(),
                            'created_by'        => auth()->id(),
                            'notes'             => 'Anulacion de ingreso del dia',
                        ]);
                    });

                // 2. Eliminar stay_nights
                \App\Models\StayNight::where('reservation_id', $reservation->id)->delete();

                // 3. Hard delete del stay (sin SoftDeletes)
                $stay->delete();

                // 4. Soft delete de la reserva
                $reservation->delete();

                // 5. Eliminar release history del egreso de hoy
                \App\Models\RoomReleaseHistory::where('reservation_id', $reservation->id)
                    ->whereDate('release_date', $date->toDateString())
                    ->delete();

                // 6. Marcar habitacion como limpia
                $room->update(['last_cleaned_at' => now()]);
            });

            $this->dispatch('notify', type: 'success', message: 'Ingreso anulado. La habitacion queda libre y limpia.');

        } catch (\Exception $e) {
            \Log::error('Error en undoCheckout: ' . $e->getMessage());
            $this->dispatch('notify', type: 'error', message: 'Error al anular el ingreso: ' . $e->getMessage());
        }
    }

    /**
     * Mostrar todos los huéspedes de una habitación
     */
    public function showAllGuests($reservationId, $roomId)
    {
        try {
            \Log::error('ðŸ”¥ showAllGuests llamado con reservationId: ' . $reservationId . ', roomId: ' . $roomId);
            
            $reservation = \App\Models\Reservation::with(['customer', 'reservationRooms'])->findOrFail($reservationId);
            $room = \App\Models\Room::findOrFail($roomId);
            
            \Log::error('ðŸ  Habitación encontrada:', [
                'room_id' => $room->id,
                'room_number' => $room->room_number,
                'max_capacity' => $room->max_capacity,
                'beds_count' => $room->beds_count
            ]);
            
            // Obtener el reservation room específico para esta habitación
            $reservationRoom = $reservation->reservationRooms->firstWhere('room_id', $roomId);
            
            // Preparar datos de todos los huéspedes
            $allGuests = [];
            
            // Agregar huésped principal si existe
            if ($reservation->customer) {
                $allGuests[] = [
                    'id' => $reservation->customer->id,
                    'name' => $reservation->customer->name,
                    'identification' => $reservation->customer->taxProfile->identification ?? 'Sin identificación',
                    'phone' => $reservation->customer->phone ?? 'Sin teléfono',
                    'type' => 'Principal',
                    'is_primary' => true
                ];
            }
            
            // Agregar huéspedes adicionales usando el método getGuests()
            if ($reservationRoom) {
                try {
                    $additionalGuests = $reservationRoom->getGuests();
                    \Log::error('ðŸ‘¥ Huéspedes adicionales encontrados: ' . $additionalGuests->count());
                    
                    foreach ($additionalGuests as $guest) {
                        $allGuests[] = [
                            'id' => $guest->id,
                            'name' => $guest->name,
                            'identification' => $guest->taxProfile->identification ?? 'Sin identificación',
                            'phone' => $guest->phone ?? 'Sin teléfono',
                            'type' => 'Adicional',
                            'is_primary' => false
                        ];
                    }
                } catch (\Exception $e) {
                    \Log::warning('Error cargando huéspedes adicionales:', [
                        'reservation_room_id' => $reservationRoom->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            \Log::error('ðŸ‘¥ Huéspedes encontrados:', $allGuests);
            
            // Preparar datos para el modal
            $isPastDate = $this->isSelectedDatePast();
            $canEditGuests = !$isPastDate && $this->canEditOccupancy();

            $this->allGuestsForm = [
                'reservation_id' => $reservationId,
                'room_id' => $roomId,
                'room_number' => $room->room_number,
                'max_capacity' => $room->max_capacity,
                'guests' => $allGuests,
                'is_past_date' => $isPastDate,
                'can_edit' => $canEditGuests,
            ];
            
            \Log::error('ðŸ’¾ allGuestsForm con capacidad:', [
                'max_capacity' => $this->allGuestsForm['max_capacity'],
                'guests_count' => count($this->allGuestsForm['guests'])
            ]);
            
            $this->allGuestsModal = true;
            
        } catch (\Exception $e) {
            \Log::error('âŒ Error en showAllGuests: ' . $e->getMessage(), [
                'reservation_id' => $reservationId,
                'room_id' => $roomId,
                'trace' => $e->getTraceAsString()
            ]);
            $this->dispatch('notify', type: 'error', message: 'Error al cargar los huéspedes: ' . $e->getMessage());
        }
    }

    /**
     * Agregar un nuevo huésped a la habitación
     */
    public function addGuestToRoom($data)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->canEditOccupancy()) {
            $this->dispatch('notify', type: 'error', message: 'Solo el administrador o recepción puede editar la ocupación.');
            return;
        }

        try {
            $reservationId = (int)($data['reservation_id'] ?? 0);
            $roomId = (int)($data['room_id'] ?? 0);
            $existingCustomerId = (int)($data['existing_customer_id'] ?? 0);
            $guestName = trim((string)($data['name'] ?? ''));
            $guestIdentification = trim((string)($data['identification'] ?? ''));
            $guestPhone = trim((string)($data['phone'] ?? ''));

            $guestIdentification = $guestIdentification !== '' ? $guestIdentification : null;
            $guestPhone = $guestPhone !== '' ? $guestPhone : null;

            if ($reservationId <= 0 || $roomId <= 0) {
                throw new \RuntimeException('Datos de reserva/habitación inválidos.');
            }

            $room = \App\Models\Room::findOrFail($roomId);
            $reservation = \App\Models\Reservation::with(['customer', 'reservationRooms'])->findOrFail($reservationId);
            $reservationRoom = $reservation->reservationRooms->firstWhere('room_id', $roomId);

            if (!$reservationRoom) {
                throw new \RuntimeException('No se encontró la relación reserva-habitación.');
            }

            $currentGuestCount = $reservation->customer ? 1 : 0;
            try {
                $additionalGuests = $reservationRoom->getGuests();
                $currentGuestCount += $additionalGuests->count();
            } catch (\Exception $e) {
                \Log::warning('Error contando huéspedes actuales:', [
                    'reservation_room_id' => $reservationRoom->id,
                    'error' => $e->getMessage()
                ]);
            }

            if ($currentGuestCount >= (int)($room->max_capacity ?? 1)) {
                $this->dispatch('notify', type: 'error', message: 'Capacidad máxima de la habitación alcanzada.');
                return;
            }

            $guestId = 0;

            if ($existingCustomerId > 0) {
                $existingCustomer = \App\Models\Customer::withoutGlobalScopes()->find($existingCustomerId);
                if (!$existingCustomer) {
                    throw new \RuntimeException('Cliente no encontrado.');
                }
                $guestId = (int) $existingCustomer->id;
            } else {
                if ($guestName === '') {
                    $this->dispatch('notify', type: 'error', message: 'El nombre del huésped es requerido.');
                    return;
                }

                $defaultIdentificationDocumentId = \App\Models\DianIdentificationDocument::query()
                    ->where('code', 'CC')
                    ->value('id')
                    ?? \App\Models\DianIdentificationDocument::query()->value('id');

                $defaultMunicipalityId = \App\Models\CompanyTaxSetting::query()->value('municipality_id')
                    ?? \App\Models\DianMunicipality::query()->value('factus_id')
                    ?? 149;

                $customer = null;

                if ($guestIdentification && $defaultIdentificationDocumentId) {
                    $existingProfile = \App\Models\CustomerTaxProfile::query()
                        ->where('identification', $guestIdentification)
                        ->where('identification_document_id', $defaultIdentificationDocumentId)
                        ->first();

                    if ($existingProfile) {
                        $customer = \App\Models\Customer::withoutGlobalScopes()->find($existingProfile->customer_id);
                    }
                }

                if (!$customer) {
                    $customer = new \App\Models\Customer();
                    $customer->name = $guestName;
                    $customer->phone = $guestPhone;
                    $customer->identification_number = $guestIdentification;
                    $customer->identification_type_id = $defaultIdentificationDocumentId;
                    $customer->created_at = now();
                    $customer->updated_at = now();
                    $customer->save();

                    if ($guestIdentification && $defaultIdentificationDocumentId && $defaultMunicipalityId) {
                        \App\Models\CustomerTaxProfile::query()->create([
                            'customer_id' => $customer->id,
                            'identification_document_id' => $defaultIdentificationDocumentId,
                            'identification' => $guestIdentification,
                            'municipality_id' => $defaultMunicipalityId,
                            'legal_organization_id' => 2,
                            'tribute_id' => 21,
                            'phone' => $guestPhone,
                        ]);
                    }
                }

                $guestId = (int)$customer->id;
            }

            if ($guestId <= 0) {
                throw new \RuntimeException('No fue posible determinar el huésped.');
            }

            if ((int)$reservation->client_id === $guestId) {
                $this->dispatch('notify', type: 'warning', message: 'Este cliente ya está asignado como huésped principal.');
                return;
            }

            $alreadyAssigned = DB::table('reservation_room_guests as rrg')
                ->join('reservation_guests as rg', 'rrg.reservation_guest_id', '=', 'rg.id')
                ->where('rrg.reservation_room_id', $reservationRoom->id)
                ->where('rg.customer_id', $guestId)
                ->exists();

            if ($alreadyAssigned) {
                $this->dispatch('notify', type: 'warning', message: 'Este huésped ya está asignado a la habitación.');
                return;
            }

            DB::transaction(function () use ($reservationRoom, $guestId): void {
                // Buscar o crear entrada en reservation_guests (cliente vinculado a la reserva)
                $existingGuest = DB::table('reservation_guests')
                    ->where('reservation_id', $reservationRoom->reservation_id)
                    ->where('customer_id', $guestId)
                    ->first();

                $reservationGuestId = $existingGuest
                    ? $existingGuest->id
                    : DB::table('reservation_guests')->insertGetId([
                        'reservation_id' => $reservationRoom->reservation_id,
                        'customer_id' => $guestId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                DB::table('reservation_room_guests')->insert([
                    'reservation_room_id' => $reservationRoom->id,
                    'reservation_guest_id' => $reservationGuestId,
                    'customer_id' => $guestId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->showAllGuests($reservationId, $roomId);
            $this->dispatch('notify', type: 'success', message: 'Huésped agregado correctamente.');

        } catch (\Exception $e) {
            \Log::error('Error en addGuestToRoom: ' . $e->getMessage(), [
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);
            $this->dispatch('notify', type: 'error', message: 'Error al agregar huésped: ' . $e->getMessage());
        }
    }

    /**
     * Guardar cambios en los precios de las noches
     */
    public function updatePrices()
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        try {
            if (!$this->editPricesForm || !isset($this->editPricesForm['nights'])) {
                throw new \Exception('No hay datos de precios para actualizar');
            }

            DB::beginTransaction();

            $updatedNightIds = [];
            $affectedRoomIds = [];

            foreach ($this->editPricesForm['nights'] as $nightData) {
                $nightId = (int) ($nightData['id'] ?? 0);
                if ($nightId <= 0) {
                    continue;
                }

                $stayNight = StayNight::find($nightId);
                if (!$stayNight) {
                    continue;
                }

                $newPrice = round(max(0, (float) ($nightData['price'] ?? 0)), 2);
                $stayNight->price = $newPrice;
                $stayNight->is_paid = (bool) ($nightData['is_paid'] ?? false);
                $stayNight->updated_at = now();
                $stayNight->save();

                $updatedNightIds[] = (int) $stayNight->id;
                $affectedRoomIds[] = (int) ($stayNight->room_id ?? 0);
            }

            if (empty($updatedNightIds)) {
                throw new \Exception('No se pudieron actualizar noches de estadia.');
            }

            $reservation = Reservation::with(['reservationRooms'])->find((int) ($this->editPricesForm['id'] ?? 0));
            if (!$reservation) {
                throw new \Exception('Reserva no encontrada para actualizar precios.');
            }
            $pricingService = app(ReservationRoomPricingService::class);

            $affectedRoomIds = array_values(array_unique(array_filter($affectedRoomIds, static fn ($id) => $id > 0)));
            if (empty($affectedRoomIds) && $reservation->reservationRooms->count() === 1) {
                $singleRoomId = (int) ($reservation->reservationRooms->first()->room_id ?? 0);
                if ($singleRoomId > 0) {
                    $affectedRoomIds = [$singleRoomId];
                }
            }

            $contractTotal = 0.0;

            foreach ($reservation->reservationRooms as $reservationRoom) {
                $roomId = (int) ($reservationRoom->room_id ?? 0);
                if ($roomId <= 0) {
                    continue;
                }
                if (!in_array($roomId, $affectedRoomIds, true)) {
                    $contractTotal += round(max(0, (float) ($reservationRoom->subtotal ?? 0)), 2);
                    continue;
                }

                $roomNightsQuery = StayNight::query()
                    ->where('reservation_id', $reservation->id)
                    ->where('room_id', $roomId);

                $roomNights = (int) ($reservationRoom->nights ?? 0);
                if (!empty($reservationRoom->check_in_date) && !empty($reservationRoom->check_out_date)) {
                    $checkIn = Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay();
                    $checkOut = Carbon::parse((string) $reservationRoom->check_out_date)->startOfDay();

                    if ($checkOut->gt($checkIn)) {
                        $roomNights = max(1, $checkIn->diffInDays($checkOut));
                        $roomNightsQuery
                            ->whereDate('date', '>=', $checkIn->toDateString())
                            ->whereDate('date', '<', $checkOut->toDateString());
                    }
                }

                $roomSubtotal = round(max(0, (float) (clone $roomNightsQuery)->sum('price')), 2);

                if ($roomNights <= 0) {
                    $roomNights = max(1, (int) (clone $roomNightsQuery)->count());
                }

                $latestNightPrice = round((float) ((clone $roomNightsQuery)
                    ->orderByDesc('date')
                    ->orderByDesc('id')
                    ->value('price') ?? 0), 2);
                if ($latestNightPrice <= 0) {
                    $latestNightPrice = $pricingService->resolveEffectiveNightPrice($reservation, $reservationRoom);
                }

                $reservationRoom->update([
                    'nights' => $roomNights,
                    'price_per_night' => $latestNightPrice > 0
                        ? $latestNightPrice
                        : ($roomNights > 0 ? round($roomSubtotal / $roomNights, 2) : 0),
                    'subtotal' => $roomSubtotal,
                ]);

                $contractTotal += $roomSubtotal;
            }

            $contractTotal = round(max(0, $contractTotal), 2);
            if ($contractTotal <= 0) {
                $contractTotal = (float) StayNight::query()
                    ->where('reservation_id', $reservation->id)
                    ->sum('price');
            }

            $reservation->refresh()->loadMissing(['reservationRooms']);
            $pricingService->syncStayNightPaidFlags($reservation);
            $pricingService->syncReservationFinancialSnapshot($reservation);

            DB::commit();

            $this->editPricesModal = false;
            $this->editPricesForm = null;

            if ($this->roomDetailModal && $this->detailData && isset($this->detailData['room']['id'])) {
                $this->openRoomDetail((int) $this->detailData['room']['id']);
            }

            $this->loadRooms();
            $this->dispatch('notify', type: 'success', message: 'Precios actualizados correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error en updatePrices: ' . $e->getMessage(), [
                'editPricesForm' => $this->editPricesForm,
                'trace' => $e->getTraceAsString(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'Error al actualizar precios: ' . $e->getMessage());
        }
    }

    public function registerPayment($reservationId, $amount, $paymentMethod, $bankName = null, $reference = null, $notes = null, $nightDate = null)
    {
        if ($this->blockEditsForPastDate()) {
            return false;
        }

        \Log::info('registerPayment called', [
            'reservation_id' => $reservationId,
            'amount' => $amount,
            'amount_type' => gettype($amount),
            'payment_method' => $paymentMethod,
            'payment_method_type' => gettype($paymentMethod),
            'bank_name' => $bankName,
            'reference' => $reference,
            'user_id' => auth()->id(),
        ]);
        
        try {
            // Validar y convertir reservationId
            $reservationId = (int)$reservationId;
            if ($reservationId <= 0) {
                \Log::error('Invalid reservation ID', ['reservation_id' => $reservationId]);
                $this->dispatch('notify', type: 'error', message: 'ID de reserva inválido.');
                $this->dispatch('reset-payment-modal-loading');
                return false;
            }
            
            $reservation = Reservation::find($reservationId);
            if (!$reservation) {
                \Log::error('Reservation not found', ['reservation_id' => $reservationId]);
                $this->dispatch('notify', type: 'error', message: 'Reserva no encontrada.');
                $this->dispatch('reset-payment-modal-loading');
                return false;
            }

            $this->ensureStayNightsCoverageForReservation($reservation);
            $this->normalizeReservationStayNightTotals($reservation);

            // Validar método de pago
            $paymentMethod = (string)$paymentMethod;
            if (!in_array($paymentMethod, ['efectivo', 'transferencia'])) {
                \Log::error('Invalid payment method', ['payment_method' => $paymentMethod]);
                $this->dispatch('notify', type: 'error', message: 'Método de pago inválido.');
                $this->dispatch('reset-payment-modal-loading');
                return false;
            }

            // Validar y convertir monto
            $amount = (float)$amount;
            if ($amount <= 0 || !is_numeric($amount)) {
                \Log::error('Invalid amount', ['amount' => $amount]);
                $this->dispatch('notify', type: 'error', message: 'El monto debe ser mayor a 0.');
                $this->dispatch('reset-payment-modal-loading');
                return false;
            }

            // Obtener balance antes del pago para determinar el mensaje
            $paymentsTotalBefore = (float)($reservation->payments()->sum('amount') ?? 0);
            $salesDebt = (float)($reservation->sales?->where('is_paid', false)->sum('total') ?? 0);
            
            // âœ… NUEVO SSOT: Calcular desde stay_nights si existe
            try {
                $totalAmount = (float)\App\Models\StayNight::where('reservation_id', $reservation->id)
                    ->sum('price');
                
                // Si no hay noches, usar fallback
                if ($totalAmount <= 0) {
                    $totalAmount = (float)($reservation->total_amount ?? 0);
                }
            } catch (\Exception $e) {
                // Si falla (tabla no existe), usar fallback
                $totalAmount = (float)($reservation->total_amount ?? 0);
            }
            
            $contractualTotal = $this->resolveReservationContractualLodgingTotal($reservation);
            if ($contractualTotal > 0) {
                $totalAmount = $contractualTotal;
            }

            $balanceDueBefore = $totalAmount - $paymentsTotalBefore + $salesDebt;

            // Validar que el monto no exceda el saldo pendiente
            if ($amount > ($balanceDueBefore + 0.009)) {
                // Contingencia productiva:
                // si la reserva ya está al día y solo quedó un estado de noche pendiente desfasado,
                // reparar estado y salir en éxito sin crear pago adicional.
                $nightRepairApplied = false;
                try {
                    $nightRepairApplied = $this->attemptNightStateRepairForPaidReservation(
                        $reservation,
                        !empty($nightDate) ? (string) $nightDate : null
                    );
                } catch (\Exception $e) {
                    \Log::warning('registerPayment: unable to repair night states on overpayment validation', [
                        'reservation_id' => $reservation->id,
                        'night_date' => $nightDate,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($nightRepairApplied || $balanceDueBefore <= 0.01) {
                    if ($this->roomDetailModal && $this->detailData && isset($this->detailData['room']['id'])) {
                        $this->openRoomDetail((int) $this->detailData['room']['id']);
                    }

                    $this->dispatch('notify', type: 'success', message: 'La cuenta ya estaba al dia. Se sincronizo el estado de noches pendientes.');
                    $this->dispatch('close-payment-modal');
                    $this->dispatch('payment-registered');
                    return true;
                }

                $this->dispatch('notify', type: 'error', message: "El monto no puede ser mayor al saldo pendiente (\${$balanceDueBefore}).");
                $this->dispatch('reset-payment-modal-loading');
                return false;
            }

            // Obtener o crear ID del método de pago
            $paymentMethodId = $this->getPaymentMethodId($paymentMethod);
            
            // Si no existe, crear el método de pago automáticamente
            if (!$paymentMethodId) {
                $methodData = match($paymentMethod) {
                    'efectivo' => ['code' => 'efectivo', 'name' => 'Efectivo'],
                    'transferencia' => ['code' => 'transferencia', 'name' => 'Transferencia'],
                    default => null
                };
                
                if ($methodData) {
                    try {
                        // Usar updateOrInsert para evitar duplicados y crear si no existe
                        DB::table('payments_methods')->updateOrInsert(
                            ['code' => $methodData['code']],
                            [
                                'name' => $methodData['name'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                        
                        // Obtener el ID del método recién creado o existente
                        $paymentMethodId = DB::table('payments_methods')
                            ->where('code', $methodData['code'])
                            ->value('id');
                    } catch (\Exception $e) {
                        \Log::error('Error creating payment method', [
                            'method' => $paymentMethod,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
            
            // Fallback: buscar por nombre o código alternativo
            if (!$paymentMethodId) {
                if ($paymentMethod === 'efectivo') {
                    $paymentMethodId = DB::table('payments_methods')
                        ->where(function($query) {
                            $query->where('name', 'Efectivo')
                                  ->orWhere('code', 'cash')
                                  ->orWhere('code', 'efectivo');
                        })
                        ->value('id');
                } elseif ($paymentMethod === 'transferencia') {
                    $paymentMethodId = DB::table('payments_methods')
                        ->where(function($query) {
                            $query->where('name', 'Transferencia')
                                  ->orWhere('code', 'transferencia')
                                  ->orWhere('code', 'transfer');
                        })
                        ->value('id');
                }
            }

            if (!$paymentMethodId) {
                $this->dispatch('notify', type: 'error', message: 'Error: No se pudo obtener o crear el método de pago. Contacte al administrador.');
                \Log::error('Payment method not found after all attempts', [
                    'payment_method' => $paymentMethod,
                    'available_methods' => DB::table('payments_methods')->get()->toArray()
                ]);
                $this->dispatch('reset-payment-modal-loading');
                return false;
            }

            // Validar que el usuario esté autenticado
            $userId = auth()->id();
            if (!$userId) {
                $this->dispatch('notify', type: 'error', message: 'Error: No se pudo identificar al usuario. Por favor, recargue la página e intente nuevamente.');
                \Log::error('User not authenticated when creating payment', [
                    'reservation_id' => $reservation->id,
                ]);
                $this->dispatch('reset-payment-modal-loading');
                return false;
            }

            // Crear el pago en la tabla payments (SSOT)
            try {
                \Log::info('Attempting to create payment', [
                    'reservation_id' => $reservation->id,
                    'amount' => $amount,
                    'payment_method_id' => $paymentMethodId,
                    'payment_method' => $paymentMethod,
                    'bank_name' => $paymentMethod === 'transferencia' ? ($bankName ?: null) : null,
                    'reference' => $paymentMethod === 'transferencia' ? ($reference ?: null) : 'Pago registrado',
                    'created_by' => $userId,
                ]);
                
                $payment = Payment::create([
                    'reservation_id' => $reservation->id,
                    'amount' => $amount,
                    'payment_method_id' => $paymentMethodId,
                    'bank_name' => $paymentMethod === 'transferencia' ? ($bankName ?: null) : null,
                    'reference' => $paymentMethod === 'transferencia' ? ($reference ?: null) : 'Pago registrado',
                    'paid_at' => now(),
                    'created_by' => $userId,
                ]);
                
                \Log::info('Payment created successfully', [
                    'payment_id' => $payment->id,
                    'reservation_id' => $reservation->id,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                $errorMessage = 'Error al crear el registro de pago.';
                if (str_contains($e->getMessage(), 'foreign key constraint')) {
                    $errorMessage = 'Error: El método de pago o la reserva no existe en el sistema.';
                } elseif (str_contains($e->getMessage(), 'column') && str_contains($e->getMessage(), 'cannot be null')) {
                    $errorMessage = 'Error: Faltan datos requeridos para registrar el pago.';
                }
                
                $this->dispatch('notify', type: 'error', message: $errorMessage);
                \Log::error('Error creating payment record', [
                    'reservation_id' => $reservation->id,
                    'amount' => $amount,
                    'payment_method_id' => $paymentMethodId,
                    'payment_method' => $paymentMethod,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                    'error_info' => $e->errorInfo ?? null,
                    'sql_state' => $e->errorInfo[0] ?? null,
                    'sql_error_code' => $e->errorInfo[1] ?? null,
                    'sql_error_message' => $e->errorInfo[2] ?? null,
                ]);
                $this->dispatch('reset-payment-modal-loading');
                return false;
            } catch (\Exception $e) {
                $this->dispatch('notify', type: 'error', message: 'Error inesperado al crear el pago: ' . $e->getMessage());
                \Log::error('Unexpected error creating payment record', [
                    'reservation_id' => $reservation->id,
                    'amount' => $amount,
                    'payment_method_id' => $paymentMethodId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->dispatch('reset-payment-modal-loading');
                return false;
            }

            // Reflejar el abono/pago en stay_nights (por fecha específica o FIFO global)
            try {
                $allocation = $this->allocatePaymentToStayNights($reservation, $amount, $nightDate);
                \Log::info('Payment allocated to stay nights', [
                    'reservation_id' => $reservation->id,
                    'payment_id' => $payment->id ?? null,
                    'night_date' => $nightDate,
                    'nights_marked' => $allocation['nights_marked'] ?? 0,
                    'remaining_amount' => $allocation['remaining_amount'] ?? 0,
                ]);
            } catch (\Exception $e) {
                // No crítico: el pago ya quedó registrado
                \Log::warning('Error allocating payment to stay nights', [
                    'reservation_id' => $reservation->id,
                    'night_date' => $nightDate,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $this->syncStayNightsPaymentCoverage($reservation);
            } catch (\Exception $e) {
                \Log::warning('Error syncing stay nights payment coverage', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Recalcular balance_due de la reserva
            $paymentsTotal = (float)($reservation->payments()->sum('amount') ?? 0);
            $balanceDue = $totalAmount - $paymentsTotal + $salesDebt;

            // Actualizar estado de pago de la reserva
            $paymentStatusCode = $balanceDue <= 0 ? 'paid' : ($paymentsTotal > 0 ? 'partial' : 'pending');
            $paymentStatusId = DB::table('payment_statuses')->where('code', $paymentStatusCode)->value('id');

            $reservation->update([
                'deposit_amount' => max(0, $paymentsTotal),
                'balance_due' => max(0, $balanceDue),
                'payment_status_id' => $paymentStatusId,
            ]);

            // Mensaje específico según el tipo de pago
            if ($balanceDue <= 0) {
                $this->dispatch('notify', type: 'success', message: 'Pago registrado. Cuenta al día.');
            } else {
                $formattedBalance = number_format($balanceDue, 0, ',', '.');
                $this->dispatch('notify', type: 'success', message: "Abono registrado. Saldo pendiente: \${$formattedBalance}");
            }

            // Refrescar la relación de pagos de la reserva para que se actualice en el modal
            $reservation->refresh();
            $reservation->load('payments');
            
            $this->dispatch('refreshRooms');
            
            // Cerrar el modal de pago si está abierto
            $this->dispatch('close-payment-modal');
            $this->dispatch('payment-registered');
            
            // Recargar datos del modal si está abierto
            if ($this->roomDetailModal && $this->detailData && isset($this->detailData['reservation']['id']) && $this->detailData['reservation']['id'] == $reservationId) {
                // Obtener el room_id desde reservation_rooms
                $reservationRoom = $reservation->reservationRooms()->first();
                if ($reservationRoom && $reservationRoom->room_id) {
                    // Forzar recarga del modal con los nuevos datos de pago
                    $this->openRoomDetail($reservationRoom->room_id);
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Error al registrar pago: ' . $e->getMessage());
            // Disparar evento para resetear loading del modal y mostrar el error
            $this->dispatch('reset-payment-modal-loading');
            \Log::error('Error registering payment', [
                'reservation_id' => $reservationId,
                'amount' => $amount ?? null,
                'payment_method' => $paymentMethod ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Apply a payment amount to stay nights.
     * - If nightDate is provided, tries that specific night first.
     * - Remaining amount is applied FIFO by date/room/id.
     *
     * @return array{nights_marked:int, remaining_amount:float}
     */
    private function allocatePaymentToStayNights(Reservation $reservation, float $amount, ?string $nightDate = null): array
    {
        $remaining = round(max(0, $amount), 2);
        $nightsMarked = 0;

        if ($remaining <= 0) {
            return ['nights_marked' => 0, 'remaining_amount' => 0.0];
        }

        if (!empty($nightDate)) {
            try {
                $targetDate = Carbon::parse($nightDate)->toDateString();
            } catch (\Throwable $e) {
                $targetDate = null;
            }

            if ($targetDate) {
                $targetNight = \App\Models\StayNight::query()
                    ->where('reservation_id', $reservation->id)
                    ->whereDate('date', $targetDate)
                    ->orderBy('id')
                    ->first();

                if ($targetNight && !$targetNight->is_paid) {
                    $nightPrice = round((float) ($targetNight->price ?? 0), 2);
                    if ($nightPrice <= 0 || $remaining >= $nightPrice) {
                        $targetNight->update(['is_paid' => true]);
                        $remaining = round(max(0, $remaining - max(0, $nightPrice)), 2);
                        $nightsMarked++;
                    }
                }
            }
        }

        if ($remaining > 0) {
            $unpaidNights = \App\Models\StayNight::query()
                ->where('reservation_id', $reservation->id)
                ->where('is_paid', false)
                ->orderBy('date')
                ->orderBy('room_id')
                ->orderBy('id')
                ->get();

            foreach ($unpaidNights as $night) {
                if ($remaining <= 0) {
                    break;
                }

                $nightPrice = round((float) ($night->price ?? 0), 2);
                if ($nightPrice <= 0) {
                    $night->update(['is_paid' => true]);
                    $nightsMarked++;
                    continue;
                }

                if ($remaining < $nightPrice) {
                    break;
                }

                $night->update(['is_paid' => true]);
                $remaining = round(max(0, $remaining - $nightPrice), 2);
                $nightsMarked++;
            }
        }

        return [
            'nights_marked' => $nightsMarked,
            'remaining_amount' => $remaining,
        ];
    }

    /**
     * Recalcula el estado pagado de stay_nights usando los pagos netos de la reserva.
     * Regla: los pagos cubren noches en orden FIFO (fecha ascendente).
     */
    private function syncStayNightsPaymentCoverage(Reservation $reservation): void
    {
        $reservation->loadMissing(['payments']);

        $availableAmount = round(max(0, (float) ($reservation->payments->sum('amount') ?? 0)), 2);

        $stayNights = StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->orderBy('date')
            ->orderBy('room_id')
            ->orderBy('id')
            ->get();

        foreach ($stayNights as $stayNight) {
            $nightPrice = round(max(0, (float) ($stayNight->price ?? 0)), 2);
            $shouldBePaid = $nightPrice <= 0 || $availableAmount >= $nightPrice;

            if ($shouldBePaid && $nightPrice > 0) {
                $availableAmount = round(max(0, $availableAmount - $nightPrice), 2);
            }

            if ((bool) $stayNight->is_paid !== $shouldBePaid) {
                $stayNight->update(['is_paid' => $shouldBePaid]);
            }
        }

        // Regla defensiva de producción:
        // si el hospedaje contractual ya está 100% cubierto por pagos netos,
        // no debe quedar ninguna noche en pendiente.
        if ($this->isReservationLodgingCoveredByNetPayments($reservation)) {
            StayNight::query()
                ->where('reservation_id', $reservation->id)
                ->where('is_paid', false)
                ->update(['is_paid' => true]);
        }
    }

    /**
     * Obtiene el total contractual del hospedaje de la reserva.
     */
    private function resolveReservationContractualLodgingTotal(Reservation $reservation): float
    {
        $reservation->loadMissing(['reservationRooms']);

        $reservationRooms = $reservation->reservationRooms ?? collect();
        $roomsWithSubtotal = $reservationRooms->filter(
            static fn ($room) => (float) ($room->subtotal ?? 0) > 0
        );

        if ($roomsWithSubtotal->isNotEmpty() && $roomsWithSubtotal->count() === $reservationRooms->count()) {
            return round((float) $roomsWithSubtotal->sum(static fn ($room) => (float) ($room->subtotal ?? 0)), 2);
        }

        $reservationTotal = round((float) ($reservation->total_amount ?? 0), 2);
        if ($reservationTotal > 0) {
            return $reservationTotal;
        }

        return round((float) StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->sum('price'), 2);
    }

    /**
     * Determina si los pagos netos ya cubren por completo el hospedaje contractual.
     */
    private function isReservationLodgingCoveredByNetPayments(Reservation $reservation): bool
    {
        $contractualTotal = $this->resolveReservationContractualLodgingTotal($reservation);
        if ($contractualTotal <= 0) {
            return false;
        }

        $paymentsNet = round(max(0, (float) $reservation->payments()->sum('amount')), 2);

        return $paymentsNet + 0.01 >= $contractualTotal;
    }

    /**
     * Marca noches como pagadas forzadamente para eliminar desalineaciones cuando la cuenta ya está al día.
     */
    private function forceMarkReservationNightsAsPaid(Reservation $reservation, ?string $nightDate = null): int
    {
        $query = StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('is_paid', false);

        if (!empty($nightDate)) {
            try {
                $query->whereDate('date', Carbon::parse((string) $nightDate)->toDateString());
            } catch (\Throwable $e) {
                // Ignorar formato inválido y continuar con todas las noches pendientes.
            }
        }

        return (int) $query->update(['is_paid' => true]);
    }

    /**
     * Intenta reparar estados de noches para reservas ya cubiertas.
     */
    private function attemptNightStateRepairForPaidReservation(Reservation $reservation, ?string $nightDate = null): bool
    {
        $this->syncStayNightsPaymentCoverage($reservation);

        if (!$this->isReservationLodgingCoveredByNetPayments($reservation)) {
            return false;
        }

        $updated = $this->forceMarkReservationNightsAsPaid($reservation, $nightDate);
        if ($updated > 0) {
            return true;
        }

        if (!empty($nightDate)) {
            try {
                $targetDate = Carbon::parse((string) $nightDate)->toDateString();
                $targetNight = StayNight::query()
                    ->where('reservation_id', $reservation->id)
                    ->whereDate('date', $targetDate)
                    ->orderBy('id')
                    ->first();

                if ($targetNight && (bool) $targetNight->is_paid) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Si la fecha no es válida, continuar con validación global.
            }
        }

        return !StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('is_paid', false)
            ->exists();
    }

    /**
     * Ensure stay night rows exist for all configured reservation room dates.
     */
    private function ensureStayNightsCoverageForReservation(Reservation $reservation): void
    {
        $reservation->loadMissing(['reservationRooms', 'stays']);
        $staysByRoom = $reservation->stays->keyBy(static fn ($stay) => (int) ($stay->room_id ?? 0));

        foreach ($reservation->reservationRooms as $reservationRoom) {
            $roomId = (int) ($reservationRoom->room_id ?? 0);
            if ($roomId <= 0 || empty($reservationRoom->check_in_date) || empty($reservationRoom->check_out_date)) {
                continue;
            }

            /** @var \App\Models\Stay|null $stay */
            $stay = $staysByRoom->get($roomId);
            if (!$stay) {
                continue;
            }

            $from = Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay();
            $to = Carbon::parse((string) $reservationRoom->check_out_date)->startOfDay();

            for ($cursor = $from->copy(); $cursor->lt($to); $cursor->addDay()) {
                $this->ensureNightForDate($stay, $cursor->copy());
            }
        }
    }

    /**
     * Corrige desalineaciones legacy entre subtotal contractual y suma de stay_nights.
     * Caso típico histórico: noches calculadas con 1 día menos y precio por noche inflado.
     */
    private function normalizeReservationStayNightTotals(Reservation $reservation): void
    {
        $reservation->loadMissing(['reservationRooms']);

        foreach ($reservation->reservationRooms as $reservationRoom) {
            $this->normalizeStayNightPricesForReservationRoom($reservation, $reservationRoom);
        }
    }

    /**
     * Normaliza precios por noche para que la suma del rango coincida con el subtotal contractual.
     * Solo auto-corrige cuando detecta patrón legacy seguro (precios uniformes o sin noches pagadas).
     */
    private function normalizeStayNightPricesForReservationRoom(
        Reservation $reservation,
        ReservationRoom $reservationRoom
    ): void {
        if (empty($reservationRoom->check_in_date) || empty($reservationRoom->check_out_date)) {
            return;
        }

        $roomId = (int) ($reservationRoom->room_id ?? 0);
        if ($roomId <= 0) {
            return;
        }

        $checkIn = Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay();
        $checkOut = Carbon::parse((string) $reservationRoom->check_out_date)->startOfDay();
        if (!$checkOut->gt($checkIn)) {
            return;
        }

        $stayNights = StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', $roomId)
            ->whereDate('date', '>=', $checkIn->toDateString())
            ->whereDate('date', '<', $checkOut->toDateString())
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        if ($stayNights->isEmpty()) {
            return;
        }

        $contractTotal = round((float) ($reservationRoom->subtotal ?? 0), 2);
        if ($contractTotal <= 0) {
            // Solo fallback a total_amount cuando la reserva tiene una sola habitación contractual.
            if ($reservation->reservationRooms->count() === 1) {
                $contractTotal = round((float) ($reservation->total_amount ?? 0), 2);
            }
        }

        if ($contractTotal <= 0) {
            return;
        }

        $currentTotal = round((float) $stayNights->sum('price'), 2);
        if (abs($currentTotal - $contractTotal) <= 0.01) {
            return;
        }

        $distinctPriceCount = $stayNights
            ->map(static fn (StayNight $night) => round((float) ($night->price ?? 0), 2))
            ->unique()
            ->count();

        // Si los precios por noche son distintos, asumir ajuste manual válido y no normalizar.
        if ($distinctPriceCount > 1) {
            return;
        }

        $nightCount = $stayNights->count();
        if ($nightCount <= 0) {
            return;
        }

        $totalCents = (int) round($contractTotal * 100);
        $baseCents = intdiv($totalCents, $nightCount);
        $remainderCents = $totalCents - ($baseCents * $nightCount);

        foreach ($stayNights as $index => $night) {
            $targetCents = $baseCents + ($index < $remainderCents ? 1 : 0);
            $targetPrice = round($targetCents / 100, 2);

            if (abs((float) ($night->price ?? 0) - $targetPrice) > 0.0001) {
                $night->update(['price' => $targetPrice]);
            }
        }

        $rangeNights = max(1, $checkIn->diffInDays($checkOut));
        $contractPricePerNight = round($contractTotal / $rangeNights, 2);
        $reservationRoom->update([
            'nights' => $rangeNights,
            'price_per_night' => $contractPricePerNight,
            'subtotal' => $contractTotal,
        ]);

        \Log::info('Normalized stay nights total for reservation room', [
            'reservation_id' => $reservation->id,
            'room_id' => $roomId,
            'contract_total' => $contractTotal,
            'previous_total' => $currentTotal,
            'night_count' => $nightCount,
        ]);
    }

    /**
     * Sincroniza stay_nights de una estadía con el rango contractual de reservation_room.
     * Elimina noches fuera de rango (si no están pagadas), crea faltantes y actualiza precios.
     *
     * @throws \RuntimeException Si hay noches pagadas fuera del nuevo rango.
     */
    private function syncStayNightsForReservationRoomRange(
        Reservation $reservation,
        ReservationRoom $reservationRoom,
        Stay $stay,
        Carbon $checkInDate,
        Carbon $checkOutDate,
        float $nightPrice
    ): void {
        if (!$checkOutDate->gt($checkInDate)) {
            throw new \RuntimeException('Rango de fechas inválido para sincronizar noches.');
        }

        $nightPrice = round(max(0, $nightPrice), 2);
        $targetDates = [];
        for ($cursor = $checkInDate->copy(); $cursor->lt($checkOutDate); $cursor->addDay()) {
            $targetDates[] = $cursor->toDateString();
        }

        $stayNights = StayNight::query()
            ->where('stay_id', $stay->id)
            ->where('room_id', (int) $reservationRoom->room_id)
            ->orderBy('date')
            ->get();

        foreach ($stayNights as $stayNight) {
            $nightDate = Carbon::parse((string) $stayNight->date)->toDateString();

            if (in_array($nightDate, $targetDates, true)) {
                continue;
            }

            if ((bool) $stayNight->is_paid) {
                throw new \RuntimeException(
                    'No se puede guardar: existen noches pagadas fuera del nuevo rango de fechas.'
                );
            }

            $stayNight->delete();
        }

        foreach ($targetDates as $targetDate) {
            StayNight::updateOrCreate(
                [
                    'stay_id' => $stay->id,
                    'date' => $targetDate,
                ],
                [
                    'reservation_id' => $reservation->id,
                    'room_id' => (int) $reservationRoom->room_id,
                    'price' => $nightPrice,
                ]
            );
        }
    }

    /**
     * Calcula total de hospedaje de una reserva usando stay_nights
     * acotado por los rangos contractuales de reservation_rooms.
     */
    private function calculateReservationStayNightsTotalByRoomRanges(Reservation $reservation): float
    {
        $reservation->loadMissing(['reservationRooms']);

        $total = 0.0;
        foreach ($reservation->reservationRooms as $reservationRoom) {
            if (empty($reservationRoom->check_in_date) || empty($reservationRoom->check_out_date)) {
                continue;
            }

            $checkIn = Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay();
            $checkOut = Carbon::parse((string) $reservationRoom->check_out_date)->startOfDay();

            if (!$checkOut->gt($checkIn)) {
                continue;
            }

            $roomTotal = (float) StayNight::query()
                ->where('reservation_id', $reservation->id)
                ->where('room_id', (int) $reservationRoom->room_id)
                ->whereDate('date', '>=', $checkIn->toDateString())
                ->whereDate('date', '<', $checkOut->toDateString())
                ->sum('price');

            $total += $roomTotal;
        }

        return round($total, 2);
    }

    /**
     * Registra una devolución de dinero al cliente.
     * 
     * SINGLE SOURCE OF TRUTH: Usa la tabla `payments` para registrar devoluciones.
     * Las devoluciones se registran como pagos con monto negativo.
     * 
     * REGLA FINANCIERA:
     * - Solo se puede devolver cuando hay PAGO EN EXCESO (overpaid > 0)
     * - overpaid = SUM(payments donde amount > 0) - total_amount
     * - Un pago completo (overpaid = 0) NO es un saldo a favor
     * - balance_due = 0 NO significa saldo a favor
     * 
     * @param int $reservationId ID de la reserva
     * @param float|null $amount Monto a devolver (opcional, si no se proporciona se usa todo el overpaid)
     * @param string|null $paymentMethod Método de pago ('efectivo' o 'transferencia', opcional, default: 'efectivo')
     * @param string|null $bankName Nombre del banco (solo para transferencia)
     * @param string|null $reference Referencia (solo para transferencia)
     * @return bool
     */
    public function registerCustomerRefund($reservationId, $amount = null, $paymentMethod = null, $bankName = null, $reference = null)
    {
        if ($this->blockEditsForPastDate()) {
            return false;
        }

        \Log::info('registerCustomerRefund called', [
            'reservation_id' => $reservationId,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'bank_name' => $bankName,
            'reference' => $reference,
        ]);

        try {
            // Validar y convertir reservationId
            $reservationId = (int)$reservationId;
            if ($reservationId <= 0) {
                \Log::error('Invalid reservation ID in registerCustomerRefund', ['reservation_id' => $reservationId]);
                $this->dispatch('notify', type: 'error', message: 'ID de reserva inválido.');
                return false;
            }

            $reservation = Reservation::with(['payments', 'sales'])->find($reservationId);
            if (!$reservation) {
                \Log::error('Reservation not found in registerCustomerRefund', ['reservation_id' => $reservationId]);
                $this->dispatch('notify', type: 'error', message: 'Reserva no encontrada.');
                return false;
            }

            // ===== PASO 0: REGLA HOTELERA CRÍTICA - Bloquear devoluciones mientras esté ocupada =====
            // REGLA: Nunca existe "saldo a favor" mientras la habitación siga OCUPADA
            // Un saldo a favor solo puede evaluarse cuando la estadía termina (stay.status = finished)
            $activeStay = \App\Models\Stay::where('reservation_id', $reservationId)
                ->whereNull('check_out_at')
                ->whereIn('status', ['active', 'pending_checkout'])
                ->exists();

            if ($activeStay) {
                $this->dispatch('notify', type: 'error', message: 'No se puede registrar devolución mientras la habitación esté ocupada. El pago se considera adelantado para noches futuras.');
                \Log::info('Refund blocked: Active stay exists', [
                    'reservation_id' => $reservationId,
                    'reason' => 'stay_active',
                ]);
                return false;
            }

            // ===== PASO 1: Calcular totales reales (REGLA FINANCIERA CORRECTA) =====
            // Solo contar pagos POSITIVOS (dinero que el cliente pagó)
            $totalPaid = (float)($reservation->payments->where('amount', '>', 0)->sum('amount') ?? 0);
            
            // âœ… NUEVO SSOT: Calcular desde stay_nights si existe
            try {
                $totalAmount = (float)\App\Models\StayNight::where('reservation_id', $reservationId)
                    ->sum('price');
                
                // Si no hay noches, usar fallback
                if ($totalAmount <= 0) {
                    $totalAmount = (float)($reservation->total_amount ?? 0);
                }
            } catch (\Exception $e) {
                // Si falla (tabla no existe), usar fallback
                $totalAmount = (float)($reservation->total_amount ?? 0);
            }
            
            // Calcular saldo a favor (pago en exceso)
            // overpaid > 0 significa que el cliente pagó MÁS de lo que debe
            $overpaid = $totalPaid - $totalAmount;

            // ===== PASO 2: Validar que existe saldo a favor para devolver =====
            // REGLA: Solo se puede devolver cuando hay pago en exceso (overpaid > 0)
            // Un pago completo (overpaid = 0) NO es un saldo a favor
            if ($overpaid <= 0) {
                $this->dispatch('notify', type: 'error', message: 'La cuenta está correctamente pagada. No hay saldo a favor para devolver.');
                \Log::info('Refund blocked: No overpaid amount', [
                    'reservation_id' => $reservationId,
                    'total_paid' => $totalPaid,
                    'total_amount' => $totalAmount,
                    'overpaid' => $overpaid,
                ]);
                return false;
            }

            // ===== PASO 3: Determinar monto a devolver =====
            // Si no se proporciona amount, usar todo el saldo a favor
            if ($amount === null) {
                $amount = $overpaid;
            }

            // Validar monto
            $amount = (float)$amount;
            if ($amount <= 0 || !is_numeric($amount)) {
                $this->dispatch('notify', type: 'error', message: 'El monto debe ser mayor a 0.');
                return false;
            }

            // ===== PASO 4: Validar que la devolución no supere el saldo a favor =====
            // REGLA: No se puede devolver más de lo que se pagó en exceso
            if ($amount > $overpaid) {
                $formattedOverpaid = number_format($overpaid, 0, ',', '.');
                $this->dispatch('notify', type: 'error', message: "La devolución no puede superar el saldo a favor del cliente (\${$formattedOverpaid}).");
                return false;
            }

            // Método de pago por defecto: efectivo
            $paymentMethod = $paymentMethod ? (string)$paymentMethod : 'efectivo';
            if (!in_array($paymentMethod, ['efectivo', 'transferencia'])) {
                $this->dispatch('notify', type: 'error', message: 'Método de pago inválido.');
                return false;
            }

            // Obtener o crear ID del método de pago
            $paymentMethodId = $this->getPaymentMethodId($paymentMethod);
            
            // Si no existe, crear el método de pago automáticamente
            if (!$paymentMethodId) {
                $methodData = match($paymentMethod) {
                    'efectivo' => ['code' => 'efectivo', 'name' => 'Efectivo'],
                    'transferencia' => ['code' => 'transferencia', 'name' => 'Transferencia'],
                    default => null
                };
                
                if ($methodData) {
                    try {
                        DB::table('payments_methods')->updateOrInsert(
                            ['code' => $methodData['code']],
                            [
                                'name' => $methodData['name'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                        
                        $paymentMethodId = DB::table('payments_methods')
                            ->where('code', $methodData['code'])
                            ->value('id');
                    } catch (\Exception $e) {
                        \Log::error('Error creating payment method in registerCustomerRefund', [
                            'method' => $paymentMethod,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            if (!$paymentMethodId) {
                $this->dispatch('notify', type: 'error', message: 'Error: No se pudo obtener o crear el método de pago.');
                \Log::error('Payment method not found in registerCustomerRefund', ['payment_method' => $paymentMethod]);
                return false;
            }

            // Validar que el usuario esté autenticado
            $userId = auth()->id();
            if (!$userId) {
                $this->dispatch('notify', type: 'error', message: 'Error: No se pudo identificar al usuario.');
                \Log::error('User not authenticated in registerCustomerRefund', ['reservation_id' => $reservation->id]);
                return false;
            }

            // Crear el pago negativo en la tabla payments (SSOT)
            try {
                \Log::info('Attempting to create refund payment', [
                    'reservation_id' => $reservation->id,
                    'amount' => -$amount, // NEGATIVO para devolución
                    'payment_method_id' => $paymentMethodId,
                    'payment_method' => $paymentMethod,
                ]);
                
                $payment = Payment::create([
                    'reservation_id' => $reservation->id,
                    'amount' => -$amount, // NEGATIVO para devolución
                    'payment_method_id' => $paymentMethodId,
                    'bank_name' => $paymentMethod === 'transferencia' ? ($bankName ?: null) : null,
                    'reference' => $paymentMethod === 'transferencia' ? ($reference ?: 'Devolución registrada') : 'Devolución en efectivo',
                    'paid_at' => now(),
                    'created_by' => $userId,
                ]);
                
                \Log::info('Refund payment created successfully', [
                    'payment_id' => $payment->id,
                    'reservation_id' => $reservation->id,
                    'amount' => -$amount,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                $errorMessage = 'Error al crear el registro de devolución.';
                if (str_contains($e->getMessage(), 'foreign key constraint')) {
                    $errorMessage = 'Error: El método de pago o la reserva no existe en el sistema.';
                } elseif (str_contains($e->getMessage(), 'column') && str_contains($e->getMessage(), 'cannot be null')) {
                    $errorMessage = 'Error: Faltan datos requeridos para registrar la devolución.';
                }
                
                $this->dispatch('notify', type: 'error', message: $errorMessage);
                \Log::error('Error creating refund payment record', [
                    'reservation_id' => $reservation->id,
                    'amount' => -$amount,
                    'error' => $e->getMessage(),
                ]);
                return false;
            } catch (\Exception $e) {
                $this->dispatch('notify', type: 'error', message: 'Error inesperado al registrar la devolución: ' . $e->getMessage());
                \Log::error('Unexpected error creating refund payment record', [
                    'reservation_id' => $reservation->id,
                    'amount' => -$amount,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return false;
            }

            // Recalcular balance_due de la reserva
            $reservation->refresh();
            $reservation->load('payments', 'sales');
            
            // CRÍTICO: Separar pagos positivos y negativos (devoluciones)
            $paymentsTotalAfter = (float)($reservation->payments->where('amount', '>', 0)->sum('amount') ?? 0);
            $refundsTotalAfter = abs((float)($reservation->payments->where('amount', '<', 0)->sum('amount') ?? 0));
            $salesDebt = (float)($reservation->sales->where('is_paid', false)->sum('total') ?? 0);
            
            // Fórmula: deuda = (hospedaje - abonos_reales) + devoluciones + consumos_pendientes
            $balanceDueAfter = ($totalAmount - $paymentsTotalAfter) + $refundsTotalAfter + $salesDebt;

            // Actualizar estado de pago de la reserva
            $paymentStatusCode = $balanceDueAfter <= 0 ? 'paid' : ($paymentsTotalAfter > 0 ? 'partial' : 'pending');
            $paymentStatusId = DB::table('payment_statuses')->where('code', $paymentStatusCode)->value('id');

            $reservation->update([
                'balance_due' => max(0, $balanceDueAfter),
                'payment_status_id' => $paymentStatusId,
            ]);

            // Mensaje de éxito
            $formattedAmount = number_format($amount, 0, ',', '.');
            $this->dispatch('notify', type: 'success', message: "Devolución de \${$formattedAmount} registrada correctamente.");

            // Emitir eventos para refrescar UI y cerrar modal
            $this->dispatch('refreshRooms');
            $this->dispatch('close-room-release-modal');

            return true;
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Error al registrar devolución: ' . $e->getMessage());
            \Log::error('Error registering customer refund', [
                'reservation_id' => $reservationId,
                'amount' => $amount ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Registra check-in desde acciones de habitacion usando la reserva pendiente.
     * Replica el comportamiento del check-in del detalle de reserva.
     */
    public function performReservationCheckIn(int $roomId): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->canEditOccupancy()) {
            $this->dispatch('notify', type: 'error', message: 'Solo el administrador o recepcion puede registrar check-in.');
            return;
        }

        $selectedDate = $this->getSelectedDate()->startOfDay();
        if (!HotelTime::isOperationalToday($selectedDate)) {
            $this->dispatch('notify', type: 'warning', message: 'El check-in solo se puede registrar en el dia operativo actual.');
            return;
        }

        try {
            $room = Room::findOrFail($roomId);
            $reservation = $this->getPendingCheckInReservationForRoom($room, $selectedDate);
            if (!$reservation) {
                $reservation = $room->getFutureReservation($selectedDate);
            }

            if (!$reservation) {
                $this->dispatch('notify', type: 'error', message: 'No hay una reserva pendiente de check-in para esta habitacion.');
                return;
            }

            if (method_exists($reservation, 'trashed') && $reservation->trashed()) {
                $this->dispatch('notify', type: 'error', message: 'No se puede registrar check-in para una reserva cancelada.');
                return;
            }

            $reservation->loadMissing(['reservationRooms.room', 'customer']);

            $roomIds = $reservation->reservationRooms
                ->pluck('room_id')
                ->filter()
                ->map(static fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($roomIds->isEmpty() && isset($reservation->room_id) && !empty($reservation->room_id)) {
                $roomIds = collect([(int) $reservation->room_id]);
            }

            if ($roomIds->isEmpty()) {
                $this->dispatch('notify', type: 'error', message: 'La reserva no tiene habitaciones asignadas para hacer check-in.');
                return;
            }

            $earliestCheckInDate = $reservation->reservationRooms
                ->pluck('check_in_date')
                ->filter()
                ->map(static fn ($date) => Carbon::parse((string) $date)->startOfDay())
                ->sortBy(static fn (Carbon $date) => $date->timestamp)
                ->first();

            if ($earliestCheckInDate && $selectedDate->lt($earliestCheckInDate)) {
                $this->dispatch(
                    'notify',
                    type: 'error',
                    message: 'No se puede registrar check-in antes de la fecha programada (' . $earliestCheckInDate->format('d/m/Y') . ').'
                );
                return;
            }

            foreach ($roomIds as $assignedRoomId) {
                $assignedRoom = $reservation->reservationRooms
                    ->firstWhere('room_id', $assignedRoomId)?->room
                    ?? Room::find($assignedRoomId);

                if ($assignedRoom && $assignedRoom->isInMaintenance($selectedDate)) {
                    $roomNumber = $assignedRoom->room_number ?? (string) $assignedRoomId;
                    $this->dispatch('notify', type: 'error', message: "La habitacion {$roomNumber} esta en mantenimiento y no permite check-in.");
                    return;
                }

                $conflictingStay = Stay::query()
                    ->where('room_id', $assignedRoomId)
                    ->whereIn('status', ['active', 'pending_checkout'])
                    ->where('reservation_id', '!=', $reservation->id)
                    ->first();

                if ($conflictingStay) {
                    $roomNumber = $reservation->reservationRooms
                        ->firstWhere('room_id', $assignedRoomId)?->room?->room_number
                        ?? Room::find($assignedRoomId)?->room_number
                        ?? (string) $assignedRoomId;

                    $this->dispatch('notify', type: 'error', message: "La habitacion {$roomNumber} ya esta ocupada por otra estadia activa.");
                    return;
                }
            }

            $createdAnyStay = false;
            DB::transaction(function () use ($reservation, $roomIds, $selectedDate, &$createdAnyStay) {
                foreach ($roomIds as $assignedRoomId) {
                    $existingStay = Stay::query()
                        ->where('reservation_id', $reservation->id)
                        ->where('room_id', $assignedRoomId)
                        ->whereIn('status', ['active', 'pending_checkout'])
                        ->first();

                    if ($existingStay) {
                        continue;
                    }

                    Stay::create([
                        'reservation_id' => $reservation->id,
                        'room_id' => $assignedRoomId,
                        'check_in_at' => now(),
                        'check_out_at' => null,
                        'status' => 'active',
                    ]);
                    $createdAnyStay = true;
                }

                $reservation->unsetRelation('stays');
                $this->ensureStayNightsCoverageForReservation($reservation);
                $this->normalizeReservationStayNightTotals($reservation);
                $this->syncStayNightsPaymentCoverage($reservation);

                $checkedInStatusId = $this->resolveCheckedInReservationStatusId();

                if (!empty($checkedInStatusId)) {
                    $reservation->status_id = (int) $checkedInStatusId;
                    $reservation->save();
                }

                foreach ($roomIds as $assignedRoomId) {
                    $this->clearQuickReserveForDate((int) $assignedRoomId, $selectedDate);
                }
            });

            $this->dispatch(
                'notify',
                type: 'success',
                message: $createdAnyStay ? 'Check-in registrado correctamente.' : 'La reserva ya tenia check-in registrado.'
            );

            $this->resetPage();
            $this->dispatch('$refresh');
            $this->dispatch('refreshRooms');
            $this->dispatch('room-rented', roomId: (int) $roomId);
            $this->dispatch('room-view-changed', date: $selectedDate->toDateString());
        } catch (\Throwable $e) {
            \Log::error('Error performing reservation check-in from room manager', [
                'room_id' => $roomId,
                'date' => $selectedDate->toDateString(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->dispatch('notify', type: 'error', message: 'No fue posible registrar el check-in. Intenta nuevamente.');
        }
    }

    public function openQuickRent($roomId)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        $room = Room::with('rates')->find($roomId);
        if ($room) {
            $selectedDate = $this->getSelectedDate();
            $cleaningCode = data_get($room->cleaningStatus($selectedDate), 'code');
            if ($cleaningCode === 'mantenimiento') {
                $this->dispatch('notify', type: 'error', message: 'No se puede arrendar esta habitacion mientras este en mantenimiento.');
                return;
            }

            if ($cleaningCode === 'pendiente') {
                $this->dispatch('notify', type: 'error', message: 'No se puede arrendar esta habitacion mientras este pendiente por aseo. Marquela como limpia primero.');
                return;
            }

            // Precio inicial para 1 huesped.
            $basePrice = $this->findRateForGuests($room, 1);
            if ($basePrice <= 0 && $room->base_price_per_night) {
                $basePrice = (float)$room->base_price_per_night;
            }

            $this->rentForm = [
                'room_id' => $roomId,
                'room_number' => $room->room_number,
                'check_in_date' => $selectedDate->toDateString(),
                'check_out_date' => $selectedDate->copy()->addDay()->toDateString(),
                'check_in_time' => HotelTime::checkInTime(), // Usar hora global del hotel
                'check_out_time' => HotelTime::checkOutTime(), // Usar hora global del hotel
                'client_id' => null,
                'guests_count' => 1,
                'max_capacity' => $room->max_capacity,
                'total' => $basePrice,
                'pricing_mode' => 'auto',
                'manual_price_per_night' => null,
                '_last_nights' => 1,
                'deposit' => 0,
                'payment_method' => 'efectivo',
                    'bank_name' => '', // Opcional para transferencias
                    'reference' => '', // Opcional para transferencias
            ];
            $this->additionalGuests = [];
            $this->quickRentModal = true;
            $this->dispatch('quickRentOpened');
                $this->recalculateQuickRentTotals($room);
        }
    }

    public function closeQuickRent()
    {
        $this->quickRentModal = false;
        $this->rentForm = null;
        $this->additionalGuests = null;
    }

    public function updatedRentFormCheckOutDate($value): void
    {
        $this->rentForm['check_out_date'] = $value;
        $this->recalculateQuickRentTotals();
    }

    public function updatedRentFormClientId($value): void
    {
        // ðŸ” NORMALIZAR: convertir cadena vacía a NULL (requisito de BD INTEGER)
        if ($value === '' || $value === null) {
            $this->rentForm['client_id'] = null;
        } else {
            $this->rentForm['client_id'] = is_numeric($value) ? (int)$value : null;
        }
        $this->recalculateQuickRentTotals();
    }

    public function updatedRentFormTotal($value): void
    {
        if (!$this->rentForm) {
            return;
        }

        $total = is_numeric($value) ? round(max(0, (float)$value), 2) : 0.0;
        $nights = $this->getQuickRentNights();

        if ($total > 0 && $nights > 0) {
            $this->rentForm['pricing_mode'] = 'manual';
            $this->rentForm['manual_price_per_night'] = round($total / $nights, 2);
        } else {
            $this->rentForm['pricing_mode'] = 'auto';
            $this->rentForm['manual_price_per_night'] = null;
        }

        if ((float)($this->rentForm['deposit'] ?? 0) > $total) {
            $this->rentForm['deposit'] = $total;
        }

        if ((float) ($this->rentForm['deposit'] ?? 0) <= 0) {
            $this->resetQuickRentTransferFields();
        }
    }

    public function updatedRentFormDeposit($value): void
    {
        if (! $this->rentForm) {
            return;
        }

        $total = (float) ($this->rentForm['total'] ?? 0);
        $deposit = is_numeric($value) ? round(max(0, (float) $value), 2) : 0.0;

        if ($total > 0 && $deposit > $total) {
            $deposit = $total;
        }

        $this->rentForm['deposit'] = $deposit;

        if ($deposit <= 0) {
            $this->resetQuickRentTransferFields();
        }
    }

    public function updatedRentFormPaymentMethod($value): void
    {
        if (! $this->rentForm) {
            return;
        }

        $this->rentForm['payment_method'] = (string) $value;

        if ($this->rentForm['payment_method'] !== 'transferencia') {
            $this->rentForm['bank_name'] = '';
            $this->rentForm['reference'] = '';
        }
    }

    public function addGuestFromCustomerId($customerId)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        $customer = \App\Models\Customer::find($customerId);
        
        if (!$customer) {
            $this->dispatch('notify', type: 'error', message: 'Cliente no encontrado.');
            return;
        }

        $room = null;
        if (!empty($this->rentForm['room_id'])) {
            $room = Room::with('rates')->find($this->rentForm['room_id']);
        }

        // ðŸ” VALIDACIÓN CRÍTICA: Verificar capacidad ANTES de agregar huésped adicional
        if ($room) {
            $principalCount = !empty($this->rentForm['client_id']) ? 1 : 0;
            $currentAdditionalCount = is_array($this->additionalGuests) ? count($this->additionalGuests) : 0;
            $totalAfterAdd = $principalCount + $currentAdditionalCount + 1;
            $maxCapacity = (int)($room->max_capacity ?? 1);

            if ($totalAfterAdd > $maxCapacity) {
                $this->dispatch('notify', type: 'error', message: "No se puede agregar más huéspedes. La habitación tiene capacidad máxima de {$maxCapacity} persona" . ($maxCapacity > 1 ? 's' : '') . ".");
                return;
            }
        }

        // Check if already added
        if (is_array($this->additionalGuests)) {
            foreach ($this->additionalGuests as $guest) {
                if (isset($guest['customer_id']) && $guest['customer_id'] == $customerId) {
                    $this->dispatch('notify', type: 'error', message: 'Este cliente ya fue agregado como huésped adicional.');
                    return;
                }
            }
        } else {
            $this->additionalGuests = [];
        }

        // Add guest
        $this->additionalGuests[] = [
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'identification' => $customer->taxProfile?->identification ?? 'N/A',
        ];

        $this->dispatch('guest-added');
        $this->dispatch('notify', type: 'success', message: 'Huésped adicional agregado.');

        // Recalcular total y contador de huéspedes
        $this->recalculateQuickRentTotals($room);
    }

    public function removeGuest($index)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (isset($this->additionalGuests[$index])) {
            unset($this->additionalGuests[$index]);
            $this->additionalGuests = array_values($this->additionalGuests);
            $this->dispatch('notify', type: 'success', message: 'Huésped removido.');
            $this->recalculateQuickRentTotals();
        }
    }

    public function submitQuickRent()
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->rentForm) {
            return;
        }

        try {
            $paymentMethod = $this->rentForm['payment_method'] ?? 'efectivo';
            $bankName = $paymentMethod === 'transferencia' ? trim($this->rentForm['bank_name'] ?? '') : null;
            $reference = $paymentMethod === 'transferencia' ? trim($this->rentForm['reference'] ?? '') : null;

            // BLOQUEO: Verificar si es fecha histórica
            if (HotelTime::isOperationalPastDate(Carbon::parse($this->rentForm['check_in_date']))) {
                throw new \RuntimeException('No se pueden crear reservas en fechas históricas.');
            }

            // ðŸ” NORMALIZAR client_id: convertir cadena vacía a NULL (requisito de BD INTEGER)
            $clientId = $this->rentForm['client_id'] ?? null;
            if ($clientId === '' || $clientId === null) {
                $clientId = null; // âœ… NULL para reservas sin cliente (walk-in sin asignar)
            } else {
                $clientId = is_numeric($clientId) ? (int)$clientId : null;
            }
            
            $validated = [
                'room_id' => $this->rentForm['room_id'],
                'check_in_date' => $this->rentForm['check_in_date'],
                'check_out_date' => $this->rentForm['check_out_date'],
                'client_id' => $clientId, // âœ… Normalizado: NULL o entero válido
                'guests_count' => $this->rentForm['guests_count'],
            ];

            // ===== CARGAR HABITACIÓN CON TARIFAS (OBLIGATORIO) =====
            // CRÍTICO: Usar with('rates') para asegurar que las tarifas estén cargadas
            // Usar findOrFail() para lanzar excepción automáticamente si no existe
            $room = Room::with('rates')->findOrFail($validated['room_id']);

            // Bloqueo de aseo: no permitir arrendar habitaciones pendientes por limpieza.
            $checkInOperationalDate = Carbon::parse($validated['check_in_date']);
            $cleaningCode = data_get($room->cleaningStatus($checkInOperationalDate), 'code');
            if ($cleaningCode === 'mantenimiento') {
                throw new \RuntimeException('No se puede arrendar esta habitacion porque esta en mantenimiento.');
            }

            if ($cleaningCode === 'pendiente') {
                throw new \RuntimeException('No se puede arrendar esta habitacion porque esta pendiente por aseo. Marquela como limpia antes de continuar.');
            }

            // ðŸ” VALIDACIÓN CRÍTICA: Verificar que NO se exceda la capacidad máxima
            $guests = $this->calculateGuestCount();
            $maxCapacity = (int)($room->max_capacity ?? 1);
            
            if ($guests > $maxCapacity) {
                throw new \RuntimeException(
                    "No se puede confirmar el arrendamiento. La cantidad de huéspedes ({$guests}) excede la capacidad máxima de la habitación ({$maxCapacity} persona" . ($maxCapacity > 1 ? 's' : '') . ")."
                );
            }

            $this->rentForm['guests_count'] = $guests;
            $validated['guests_count'] = $guests;

            // Regla hotelera: las noches se calculan por FECHA (check-out exclusivo), no por horas.
            $checkIn = Carbon::parse((string) $validated['check_in_date'])->startOfDay();
            $checkOut = Carbon::parse((string) $validated['check_out_date'])->startOfDay();
            $nights = max(1, $checkIn->diffInDays($checkOut));

            // ===== CALCULAR TOTAL DEL HOSPEDAJE (SSOT FINANCIERO) =====
            // REGLA CRÍTICA: El total puede venir de DOS fuentes (en orden de prioridad):
            // 1. PRECIO MANUAL/ACORDADO desde el formulario (rentForm.total) - SSOT absoluto
            // 2. CÁLCULO AUTOMÁTICO desde tarifas (findRateForGuests) - fallback
            //
            // REGLA: El total del hospedaje se define UNA SOLA VEZ al arrendar
            // Este valor NO se recalcula después, NO depende de payments, NO depende del release
            
            // Log para debugging: verificar datos antes del cálculo
            \Log::critical('QUICK RENT RAW FORM DATA', [
                'rentForm' => $this->rentForm,
                'guests' => $guests,
                'nights' => $nights,
                'rentForm_total' => $this->rentForm['total'] ?? null,
                'rates_count' => $room->rates->count(),
                'rates' => $room->rates->map(fn($r) => [
                    'id' => $r->id,
                    'min_guests' => $r->min_guests,
                    'max_guests' => $r->max_guests,
                    'price_per_night' => $r->price_per_night,
                ])->toArray(),
                'base_price_per_night' => $room->base_price_per_night,
            ]);
            
            // ===== OPCIÓN 1: PRECIO MANUAL/ACORDADO (SSOT ABSOLUTO) =====
            // Si el formulario tiene un total definido explícitamente (manual o calculado en frontend),
            // ese valor es la VERDAD ABSOLUTA y NO se recalcula desde tarifas
            $manualTotal = isset($this->rentForm['total']) ? (float)($this->rentForm['total']) : 0;
            $manualPricePerNight = isset($this->rentForm['manual_price_per_night'])
                ? (float)($this->rentForm['manual_price_per_night'])
                : 0;
            
            if ($manualTotal > 0) {
                // âœ… PRECIO MANUAL ES SSOT: usar directamente el valor del formulario
                $totalAmount = round($manualTotal, 2);
                $pricePerNight = $manualPricePerNight > 0
                    ? $manualPricePerNight
                    : ($nights > 0 ? round($totalAmount / $nights, 2) : $totalAmount);
                
                \Log::info('QuickRent: Using manual price from form (SSOT)', [
                    'room_id' => $room->id,
                    'manual_total' => $manualTotal,
                    'nights' => $nights,
                    'manual_price_per_night' => $manualPricePerNight,
                    'effective_price_per_night' => $pricePerNight,
                ]);
            } else {
                // ===== OPCIÓN 2: CÁLCULO AUTOMÁTICO DESDE TARIFAS (FALLBACK) =====
                // Si NO hay precio manual, calcular desde tarifas del sistema
                $pricePerNight = $this->findRateForGuests($room, $guests);
                $totalAmount = round($pricePerNight * $nights, 2);
                
                \Log::info('QuickRent: Using calculated price from rates (fallback)', [
                    'room_id' => $room->id,
                    'price_per_night' => $pricePerNight,
                    'nights' => $nights,
                    'calculated_total' => $totalAmount,
                ]);
            }
            
            // Validar que totalAmount sea mayor que 0
            if ($totalAmount <= 0) {
                throw new \RuntimeException('El total del hospedaje debe ser mayor a 0. Verifique las tarifas de la habitación.');
            }
            
            $depositAmount = (float)($this->rentForm['deposit'] ?? 0); // Del formulario
            $balanceDue = $totalAmount - $depositAmount;

            $paymentStatusCode = $balanceDue <= 0 ? 'paid' : ($depositAmount > 0 ? 'partial' : 'pending');
            $paymentStatusId = DB::table('payment_statuses')->where('code', $paymentStatusCode)->value('id');
            $walkInSourceId = $this->resolveReservationSourceIdByCode('walk_in');
            if ($walkInSourceId === null && Schema::hasTable('reservation_sources')) {
                DB::table('reservation_sources')->updateOrInsert(
                    ['code' => 'walk_in'],
                    [
                        'name' => 'Arriendo directo (sin reserva previa)',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $walkInSourceId = $this->resolveReservationSourceIdByCode('walk_in');
            }

            $walkInSourceId = $walkInSourceId
                ?? $this->resolveReservationSourceIdByCode('reception')
                ?? 1;

            $reservationCode = app(\App\Services\ReservationService::class)->generateCode('WLK');

            // ===== PASO 1: Crear reserva técnica para walk-in =====
            // CRÍTICO: total_amount es el SSOT financiero del hospedaje, debe persistirse correctamente
            $reservation = Reservation::create([
                'reservation_code' => $reservationCode,
                'client_id' => $validated['client_id'],
                'status_id' => 1, // pending
                'total_guests' => $validated['guests_count'],
                'adults' => $validated['guests_count'],
                'children' => 0,
                'total_amount' => $totalAmount,        // âœ… SSOT: Total del hospedaje (NO se recalcula)
                'deposit_amount' => $depositAmount,    // Abono inicial (puede cambiar con más pagos)
                'balance_due' => $balanceDue,          // Saldo pendiente (se recalcula con payments)
                'payment_status_id' => $paymentStatusId,
                'source_id' => $walkInSourceId, // walk_in
                'created_by' => auth()->id(),
            ]);
            
            // CRÍTICO: Refrescar reserva para asegurar que total_amount se persista correctamente
            $reservation->refresh();
            
            // Log para debugging: verificar que total_amount se guardó correctamente
            \Log::info('Quick Rent: Reservation created', [
                'reservation_id' => $reservation->id,
                'total_amount' => $reservation->total_amount,
                'price_per_night' => $pricePerNight,
                'nights' => $nights,
                'calculated_total' => $totalAmount,
                'deposit_amount' => $depositAmount,
            ]);
            
            // VALIDACIÓN CRÍTICA: Verificar que total_amount se guardó correctamente
            if ((float)($reservation->total_amount ?? 0) <= 0 || abs((float)$reservation->total_amount - $totalAmount) > 0.01) {
                \Log::error('Quick Rent: total_amount NOT persisted correctly', [
                    'reservation_id' => $reservation->id,
                    'expected_total' => $totalAmount,
                    'actual_total' => $reservation->total_amount,
                ]);
                throw new \RuntimeException("Error: El total del hospedaje no se guardó correctamente. Valor esperado: \${$totalAmount}, Valor guardado: \${$reservation->total_amount}");
            }

            // ===== REGISTRAR PAGO EN payments (SSOT FINANCIERO OBLIGATORIO) =====
            // REGLA CRÍTICA: SIEMPRE que haya un abono (depositAmount > 0), debe registrarse en payments
            // Esto es obligatorio para mantener coherencia financiera con:
            // - Room Detail Modal (usa payments como SSOT)
            // - Stay History (calcula noches pagadas desde payments)
            // - Room Release (evalúa pagos desde payments)
            // 
            // Independientemente del método de pago (efectivo o transferencia),
            // TODO abono recibido genera un registro en payments.
            
            if ($depositAmount > 0) {
                // Obtener payment_method_id según el método seleccionado
                $paymentMethodId = $this->getPaymentMethodId($paymentMethod);
                if (!$paymentMethodId) {
                    // Fallback: buscar método de pago por código o nombre
                    $paymentMethodId = DB::table('payments_methods')
                        ->where('code', strtolower($paymentMethod))
                        ->orWhere('name', ucfirst($paymentMethod))
                        ->value('id');
                }
                
                // Preparar referencia solo para transferencias
                $referencePayload = null;
                $bankNameValue = null;
                
                if ($paymentMethod === 'transferencia') {
                    if ($reference && $bankName) {
                        $referencePayload = sprintf('%s | Banco: %s', $reference, $bankName);
                        $bankNameValue = $bankName;
                    } elseif ($reference) {
                        $referencePayload = $reference;
                    } elseif ($bankName) {
                        $referencePayload = sprintf('Banco: %s', $bankName);
                        $bankNameValue = $bankName;
                    }
                } else {
                    // Para efectivo, usar referencia genérica
                    $referencePayload = 'Abono registrado en Quick Rent';
                }
                
                // Registrar pago en payments (SSOT financiero)
                Payment::create([
                    'reservation_id' => $reservation->id,
                    'amount' => $depositAmount,
                    'payment_method_id' => $paymentMethodId,
                    'bank_name' => $bankNameValue,
                    'reference' => $referencePayload,
                    'paid_at' => now(),
                    'created_by' => auth()->id(),
                ]);
                
                \Log::info('Quick Rent: Payment registered in SSOT', [
                    'reservation_id' => $reservation->id,
                    'amount' => $depositAmount,
                    'payment_method' => $paymentMethod,
                    'payment_method_id' => $paymentMethodId,
                ]);
            }

            // ===== PASO 2: Crear reservation_room =====
            $reservationRoom = ReservationRoom::create([
                'reservation_id' => $reservation->id,
                'room_id' => $validated['room_id'],
                'check_in_date' => $validated['check_in_date'],
                'check_out_date' => $validated['check_out_date'],
                'nights' => $nights,
                'price_per_night' => $pricePerNight,
                'subtotal' => round($totalAmount, 2),
            ]);

            // ===== PASO 2.5: Persistir huéspedes adicionales =====
            // SSOT: Huésped principal está en reservations.client_id
            // Huéspedes adicionales van en reservation_guests + reservation_room_guests
            if (!empty($this->additionalGuests) && is_array($this->additionalGuests)) {
                $additionalGuestIds = array_filter(
                    array_column($this->additionalGuests, 'customer_id'),
                    fn($id) => !empty($id) && is_numeric($id) && $id > 0
                );
                
                if (!empty($additionalGuestIds)) {
                    $this->assignGuestsToRoom($reservationRoom, $additionalGuestIds);
                }
            }

            // ===== PASO 3: CRÍTICO - Crear STAY activa AHORA (check-in inmediato) =====
            // Una stay activa es lo que marca que la habitación está OCUPADA
            $stay = \App\Models\Stay::create([
                'reservation_id' => $reservation->id,
                'room_id' => $validated['room_id'],
                'check_in_at' => now(), // Check-in INMEDIATO (timestamp)
                'check_out_at' => null, // Se completará al checkout
                'status' => 'active', // estados: active, pending_checkout, finished
            ]);

            // Sincronizar noches y estado de pago por noches desde el abono inicial.
            try {
                $this->ensureStayNightsCoverageForReservation($reservation);
                $this->normalizeReservationStayNightTotals($reservation);
                $this->syncStayNightsPaymentCoverage($reservation);
            } catch (\Exception $e) {
                \Log::warning('Quick Rent: Error syncing stay nights payment coverage', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // CRITICAL: Refrescar el modelo Room para invalidar cualquier caché de relaciones
            // Esto asegura que las siguientes consultas encuentren la stay recién creada
            $room = Room::find($validated['room_id']);
            if ($room) {
                // Invalidar la relación stays en memoria
                $room->unsetRelation('stays');
            }

            try {
                $this->clearQuickReserveForDate(
                    (int) $validated['room_id'],
                    Carbon::parse((string) $validated['check_in_date'])->startOfDay()
                );
            } catch (\Exception $e) {
                \Log::warning('Quick Rent: unable to clear quick reserve', [
                    'room_id' => $validated['room_id'],
                    'check_in_date' => $validated['check_in_date'],
                    'error' => $e->getMessage(),
                ]);
            }

            // ÉXITO: Habitación ahora debe aparecer como OCUPADA
            $this->dispatch('notify', type: 'success', message: 'Arriendo registrado exitosamente. Habitación ocupada.');
            $this->closeQuickRent();
            
            // CRITICAL: Forzar actualización inmediata de habitaciones para mostrar info de huésped y cuenta
            // Resetear paginación y forzar re-render completo
            $this->resetPage();
            $this->dispatch('$refresh');
            // Disparar evento para resetear Alpine.js y forzar re-render de componentes
            $this->dispatch('room-view-changed', date: $this->date->toDateString());
            $this->dispatch('room-rented', roomId: (int) $validated['room_id']);
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Error: ' . $e->getMessage());
        }
    }

    public function storeQuickRent()
    {
        return $this->submitQuickRent();
    }

    /**
     * Abre el modal para asignar cliente y huéspedes a una reserva activa existente.
     * 
     * CASO DE USO: Completar reserva activa sin cliente principal asignado
     * NO crea nueva reserva, solo completa la existente.
     * 
     * @param int $roomId ID de la habitación
     * @return void
     */
    public function openAssignGuests(int $roomId): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->canEditOccupancy()) {
            $this->dispatch('notify', type: 'error', message: 'Solo el administrador o recepción puede editar la ocupación.');
            return;
        }

        try {
            $room = Room::findOrFail($roomId);

            // Obtener stay activa para la fecha seleccionada
            $stay = $room->getAvailabilityService()->getStayForDate($this->date);
            
            if (!$stay || !$stay->reservation) {
                $this->dispatch('notify', type: 'error', message: 'No hay reserva activa para esta habitación.');
                return;
            }

            $reservation = $stay->reservation;

            // Cargar relaciones necesarias
            $reservation->loadMissing([
                'reservationRooms' => function($q) use ($roomId) {
                    $q->where('room_id', $roomId);
                },
                'customer',
                'payments'
            ]);

            // Obtener ReservationRoom para esta habitación
            $reservationRoom = $reservation->reservationRooms->firstWhere('room_id', $roomId);
            
            // Cargar huéspedes adicionales existentes
            $existingAdditionalGuests = [];
            if ($reservationRoom) {
                try {
                    $guestsCollection = $reservationRoom->getGuests();
                    if ($guestsCollection && $guestsCollection->isNotEmpty()) {
                        $existingAdditionalGuests = $guestsCollection->map(function($guest) {
                            return [
                                'customer_id' => $guest->id,
                                'name' => $guest->name,
                                'identification' => $guest->taxProfile?->identification ?? 'N/A',
                            ];
                        })->toArray();
                    }
                } catch (\Exception $e) {
                    \Log::warning('Error loading existing additional guests in openAssignGuests', [
                        'reservation_room_id' => $reservationRoom->id ?? null,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Calcular pagos totales para validar precio mínimo
            $paidAmount = (float)($reservation->payments->where('amount', '>', 0)->sum('amount') ?? 0);
            $checkInDate = !empty($reservationRoom?->check_in_date)
                ? Carbon::parse((string) $reservationRoom->check_in_date)->toDateString()
                : $this->date->toDateString();
            $checkOutDate = !empty($reservationRoom?->check_out_date)
                ? Carbon::parse((string) $reservationRoom->check_out_date)->toDateString()
                : $this->date->copy()->addDay()->toDateString();

            // Inicializar formulario
            $this->assignGuestsForm = [
                'reservation_id' => $reservation->id,
                'room_id' => $roomId,
                'client_id' => $reservation->client_id, // Puede ser null
                'client_name' => $reservation->customer?->name ?? '',
                'additional_guests' => $existingAdditionalGuests,
                'override_total_amount' => false,
                'total_amount' => (float)($reservation->total_amount ?? 0), // SSOT actual
                'current_paid_amount' => $paidAmount, // Para validación
                'max_capacity' => (int)($room->max_capacity ?? 1), // ðŸ” Para validación de capacidad
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'has_customer' => !empty($reservation->client_id),
            ];

            $this->assignGuestsModal = true;
            $this->dispatch('assignGuestsModalOpened');
        } catch (\Exception $e) {
            \Log::error('Error opening assign guests modal', [
                'room_id' => $roomId,
                'error' => $e->getMessage()
            ]);
            $this->dispatch('notify', type: 'error', message: 'Error al abrir el formulario: ' . $e->getMessage());
        }
    }

    /**
     * Cierra el modal de asignar huéspedes.
     */
    public function closeAssignGuests(): void
    {
        $this->assignGuestsModal = false;
        $this->assignGuestsForm = null;
    }

    /**
     * Completa una reserva activa asignando cliente principal y huéspedes adicionales.
     * 
     * REGLAS CRÍTICAS:
     * - NO crea nueva reserva (usa la existente)
     * - Permite editar fechas de ocupación activas sin crear nueva reserva
     * - Cliente principal es OBLIGATORIO
     * - Precio solo se actualiza si override_total_amount = true
     * - Nuevo precio debe ser >= pagos realizados
     * 
     * @return void
     */
    public function submitAssignGuests(): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->assignGuestsForm) {
            $this->dispatch('notify', type: 'error', message: 'Error: Formulario no inicializado.');
            return;
        }

        if (!$this->canEditOccupancy()) {
            $this->dispatch('notify', type: 'error', message: 'Solo el administrador o recepción puede editar la ocupación.');
            return;
        }

        try {
            $data = $this->assignGuestsForm;

            DB::transaction(function () use ($data) {
                // ===== PASO 1: Validar y cargar reserva =====
                $reservation = Reservation::lockForUpdate()->findOrFail($data['reservation_id']);

                // Validar que la reserva tiene un stay activo (no permitir modificar reservas liberadas)
                $stay = Stay::where('reservation_id', $reservation->id)
                    ->where('room_id', $data['room_id'])
                    ->whereNull('check_out_at')
                    ->whereIn('status', ['active', 'pending_checkout'])
                    ->first();

                if (!$stay) {
                    throw new \RuntimeException('No se puede modificar una reserva que no tiene estadía activa.');
                }

                // ===== PASO 2: Validar y asignar cliente principal (OBLIGATORIO) =====
                // ðŸ” DEBUG: Log del valor recibido
                \Log::info('submitAssignGuests: Validating client_id', [
                    'client_id' => $data['client_id'] ?? null,
                    'is_empty' => empty($data['client_id']),
                    'is_numeric' => isset($data['client_id']) ? is_numeric($data['client_id']) : false,
                    'assignGuestsForm' => $this->assignGuestsForm,
                ]);
                
                if (empty($data['client_id']) || !is_numeric($data['client_id']) || $data['client_id'] <= 0) {
                    throw new \RuntimeException('Debe asignar un cliente principal.');
                }

                $customerId = (int)$data['client_id'];
                
                // Verificar que el cliente existe
                $customer = \App\Models\Customer::withoutGlobalScopes()->find($customerId);
                if (!$customer) {
                    throw new \RuntimeException('El cliente seleccionado no existe.');
                }

                // Actualizar cliente principal (puede ser asignación inicial o cambio de cliente)
                // Si ya había un cliente, se actualiza; si no había, se asigna por primera vez
                $oldClientId = $reservation->client_id;
                $reservation->update([
                    'client_id' => $customerId,
                ]);
                
                // ðŸ”„ CRÍTICO: Refrescar la reserva DESPUÉS de actualizar para limpiar caché de Eloquent
                // Esto asegura que las relaciones cargadas después tengan los datos correctos
                $reservation->refresh();
                
                \Log::info('AssignGuests: Client principal updated', [
                    'reservation_id' => $reservation->id,
                    'old_client_id' => $oldClientId,
                    'new_client_id' => $customerId,
                    'client_id_after_refresh' => $reservation->client_id,
                ]);

                // ===== PASO 3: VALIDACIÓN DE CAPACIDAD (CRÍTICO) =====
                // Cargar habitación para obtener max_capacity
                $room = Room::findOrFail($data['room_id']);
                $maxCapacity = (int)($room->max_capacity ?? 1);
                
                // Calcular total de huéspedes: principal (1) + adicionales
                $principalCount = 1; // Cliente principal siempre cuenta
                $additionalGuestsCount = !empty($data['additional_guests']) && is_array($data['additional_guests']) 
                    ? count($data['additional_guests']) 
                    : 0;
                $totalGuests = $principalCount + $additionalGuestsCount;
                
                // Validar que NO se exceda la capacidad máxima
                if ($totalGuests > $maxCapacity) {
                    throw new \RuntimeException(
                        "No se puede confirmar la asignación. La cantidad de huéspedes ({$totalGuests}) excede la capacidad máxima de la habitación ({$maxCapacity} persona" . ($maxCapacity > 1 ? 's' : '') . ")."
                    );
                }

                // ===== PASO 4: Asignar huéspedes adicionales =====
                $reservationRoom = $reservation->reservationRooms()
                    ->where('room_id', $data['room_id'])
                    ->first();

                if (!$reservationRoom) {
                    throw new \RuntimeException('No se encontró la relación reserva-habitación.');
                }

                // Limpiar huéspedes adicionales existentes
                // Primero eliminar de reservation_room_guests
                // ===== PASO 4A: Editar fechas de ocupación =====
                $currentCheckInDate = !empty($reservationRoom->check_in_date)
                    ? Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay()
                    : $this->date->copy()->startOfDay();
                $currentCheckOutDate = !empty($reservationRoom->check_out_date)
                    ? Carbon::parse((string) $reservationRoom->check_out_date)->startOfDay()
                    : $currentCheckInDate->copy()->addDay();

                $newCheckInDate = !empty($data['check_in_date'])
                    ? Carbon::parse((string) $data['check_in_date'])->startOfDay()
                    : $currentCheckInDate->copy();
                $newCheckOutDate = !empty($data['check_out_date'])
                    ? Carbon::parse((string) $data['check_out_date'])->startOfDay()
                    : $currentCheckOutDate->copy();

                if (!$newCheckOutDate->gt($newCheckInDate)) {
                    throw new \RuntimeException('La fecha de salida debe ser posterior a la fecha de entrada.');
                }

                $today = HotelTime::currentOperationalDate();
                if ($newCheckInDate->gt($today)) {
                    throw new \RuntimeException('La fecha de entrada no puede ser futura para una ocupación activa.');
                }
                if ($newCheckOutDate->lt($today)) {
                    throw new \RuntimeException('La fecha de salida no puede ser anterior a hoy.');
                }

                $conflictingReservationRoom = ReservationRoom::query()
                    ->where('room_id', $data['room_id'])
                    ->where('id', '!=', $reservationRoom->id)
                    ->where('reservation_id', '!=', $reservation->id)
                    ->whereDate('check_in_date', '<', $newCheckOutDate->toDateString())
                    ->whereDate('check_out_date', '>', $newCheckInDate->toDateString())
                    ->whereHas('reservation', static function ($query): void {
                        // Ignorar reservas canceladas (soft-deleted).
                        $query->whereNull('deleted_at');
                    })
                    ->where(function ($query) use ($data, $today): void {
                        $roomId = (int) ($data['room_id'] ?? 0);

                        // Conflicto real 1: otra reserva con estadia operativa abierta en esta habitacion.
                        $query->whereHas('reservation.stays', static function ($stayQuery) use ($roomId): void {
                            $stayQuery
                                ->where('room_id', $roomId)
                                ->whereIn('status', ['active', 'pending_checkout'])
                                ->whereNull('check_out_at');
                        })
                        // Conflicto real 2: reserva futura (sin estadia iniciada aun) para esta habitacion.
                        ->orWhere(function ($futureReservationQuery) use ($roomId, $today): void {
                            $futureReservationQuery
                                ->whereDate('check_in_date', '>=', $today->toDateString())
                                ->whereDoesntHave('reservation.stays', static function ($stayQuery) use ($roomId): void {
                                    $stayQuery->where('room_id', $roomId);
                                });
                        });
                    })
                    ->first();

                if ($conflictingReservationRoom) {
                    \Log::warning('AssignGuests overlap detected', [
                        'current_reservation_id' => $reservation->id,
                        'room_id' => $data['room_id'],
                        'new_check_in_date' => $newCheckInDate->toDateString(),
                        'new_check_out_date' => $newCheckOutDate->toDateString(),
                        'conflicting_reservation_id' => $conflictingReservationRoom->reservation_id,
                        'conflicting_reservation_room_id' => $conflictingReservationRoom->id,
                        'conflicting_check_in_date' => $conflictingReservationRoom->check_in_date,
                        'conflicting_check_out_date' => $conflictingReservationRoom->check_out_date,
                    ]);

                    throw new \RuntimeException('Las nuevas fechas se cruzan con otra reserva para esta habitación.');
                }

                $newNights = max(1, $newCheckInDate->diffInDays($newCheckOutDate));
                $pricingService = app(ReservationRoomPricingService::class);
                $currentPricePerNight = $pricingService->resolveEffectiveNightPrice($reservation, $reservationRoom);
                if ($currentPricePerNight <= 0) {
                    $fallbackTotal = (float) ($reservation->total_amount ?? 0);
                    $currentPricePerNight = $fallbackTotal > 0
                        ? round($fallbackTotal / $newNights, 2)
                        : 0.0;
                }

                $reservationRoom->update([
                    'check_in_date' => $newCheckInDate->toDateString(),
                    'check_out_date' => $newCheckOutDate->toDateString(),
                    'nights' => $newNights,
                    'price_per_night' => $currentPricePerNight,
                    'subtotal' => round($currentPricePerNight * $newNights, 2),
                ]);

                $reservationRoomsCount = (int) $reservation->reservationRooms()->count();
                if ($reservationRoomsCount === 1 && Schema::hasColumn('reservations', 'check_in_date') && Schema::hasColumn('reservations', 'check_out_date')) {
                    DB::table('reservations')
                        ->where('id', $reservation->id)
                        ->update([
                            'check_in_date' => $newCheckInDate->toDateString(),
                            'check_out_date' => $newCheckOutDate->toDateString(),
                            'updated_at' => now(),
                        ]);
                }

                // Eliminar asignaciones de huéspedes adicionales de esta habitación
                DB::table('reservation_room_guests')
                    ->where('reservation_room_id', $reservationRoom->id)
                    ->delete();

                // Asignar nuevos huéspedes adicionales (si se proporcionaron)
                if (!empty($data['additional_guests']) && is_array($data['additional_guests'])) {
                    $additionalGuestIds = array_filter(
                        array_column($data['additional_guests'], 'customer_id'),
                        fn($id) => !empty($id) && is_numeric($id) && $id > 0
                    );

                    if (!empty($additionalGuestIds)) {
                        $this->assignGuestsToRoom($reservationRoom, $additionalGuestIds);
                    }
                }

                // ===== PASO 5: Actualizar monto del hospedaje (SSOT) =====
                $reservation->loadMissing(['sales']);
                $paidAmount = (float)($data['current_paid_amount'] ?? 0);
                $overrideTotalAmount = !empty($data['override_total_amount']) && $data['override_total_amount'] === true;

                if ($overrideTotalAmount) {
                    $newTotal = (float)($data['total_amount'] ?? 0);

                    if ($newTotal <= 0) {
                        throw new \RuntimeException('El total del hospedaje debe ser mayor a 0.');
                    }

                    if ($newTotal < $paidAmount) {
                        throw new \RuntimeException(
                            'El nuevo total del hospedaje no puede ser menor a lo ya pagado ($' . number_format($paidAmount, 0, ',', '.') . ').'
                        );
                    }

                    if ($reservationRoomsCount === 1) {
                        $manualPricePerNight = round($newTotal / $newNights, 2);
                        $reservationRoom->update([
                            'price_per_night' => $manualPricePerNight,
                            'subtotal' => round($newTotal, 2),
                        ]);
                    }
                } else {
                    $newTotal = (float) $reservation->reservationRooms()->sum('subtotal');
                    if ($newTotal <= 0) {
                        $newTotal = (float) ($reservation->total_amount ?? 0);
                    }
                }

                // Sincronizar noches cobrables con el nuevo rango de fechas y precio vigente.
                $finalNightPrice = (float) ($reservationRoom->fresh()->price_per_night ?? 0);
                if ($finalNightPrice <= 0 && $newNights > 0) {
                    $finalNightPrice = round($newTotal / $newNights, 2);
                }
                $this->syncStayNightsForReservationRoomRange(
                    $reservation,
                    $reservationRoom,
                    $stay,
                    $newCheckInDate,
                    $newCheckOutDate,
                    $finalNightPrice
                );
                try {
                    $this->syncStayNightsPaymentCoverage($reservation);
                } catch (\Exception $e) {
                    \Log::warning('Error syncing stay nights payment coverage in submitAssignGuests', [
                        'reservation_id' => $reservation->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Recalcular total desde stay_nights (SSOT) para evitar que quede el precio anterior.
                $stayNightsTotal = (float) StayNight::query()
                    ->where('reservation_id', $reservation->id)
                    ->sum('price');
                if ($stayNightsTotal > 0) {
                    $newTotal = $stayNightsTotal;
                }

                $salesDebt = (float)($reservation->sales?->where('is_paid', false)->sum('total') ?? 0);
                $newBalanceDue = $newTotal - $paidAmount + $salesDebt;

                $reservation->update([
                    'total_amount' => $newTotal,
                    'balance_due' => max(0, $newBalanceDue),
                ]);

                // ===== PASO 6: Actualizar total_guests en la reserva =====
                $reservation->refresh();
                $reservation->loadMissing(['reservationRooms']);
                $reservationRoom = $reservation->reservationRooms->firstWhere('room_id', $data['room_id']);
                
                if ($reservationRoom) {
                    try {
                        // Calcular total de huéspedes: principal (1) + adicionales
                        $principalCount = 1; // Cliente principal siempre cuenta
                        $additionalGuestsCount = $reservationRoom->getGuests()->count() ?? 0;
                        $totalGuests = $principalCount + $additionalGuestsCount;

                        $reservation->update([
                            'total_guests' => $totalGuests,
                            'adults' => $totalGuests, // Simplificación: todos son adultos
                            'children' => 0,
                        ]);
                    } catch (\Exception $e) {
                        // No crítico, solo log
                        \Log::warning('Error updating total_guests in submitAssignGuests', [
                            'reservation_id' => $reservation->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            });

            $this->dispatch('notify', type: 'success', message: 'Ocupación actualizada correctamente.');
            $this->closeAssignGuests();
            
            // ðŸ”„ CRÍTICO: Forzar refresh completo para recargar todas las relaciones desde BD
            // resetPage() re-ejecuta render() que usa getRoomsQuery() con eager loading fresco
            // Esto asegura que room-row.blade.php reciba datos frescos con customer cargado
            $this->resetPage(); // Re-ejecutar render() con datos frescos desde BD
            $this->dispatch('$refresh'); // Forzar re-render de Livewire

        } catch (\Exception $e) {
            \Log::error('Error submitting assign guests', [
                'form_data' => $this->assignGuestsForm ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->dispatch('notify', type: 'error', message: 'Error al asignar huéspedes: ' . $e->getMessage());
        }
    }

    /**
     * Agrega un huésped adicional al formulario de asignación.
     * 
     * @param int $customerId ID del cliente a agregar
     * @return void
     */
    public function addAssignGuest(int $customerId): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->canEditOccupancy()) {
            $this->dispatch('notify', type: 'error', message: 'Solo el administrador o recepción puede editar la ocupación.');
            return;
        }

        if (!$this->assignGuestsForm) {
            return;
        }

        try {
            $customer = \App\Models\Customer::withoutGlobalScopes()->find($customerId);
            if (!$customer) {
                $this->dispatch('notify', type: 'error', message: 'Cliente no encontrado.');
                return;
            }

            // Inicializar array si no existe
            if (!isset($this->assignGuestsForm['additional_guests']) || !is_array($this->assignGuestsForm['additional_guests'])) {
                $this->assignGuestsForm['additional_guests'] = [];
            }

            // Verificar duplicados
            foreach ($this->assignGuestsForm['additional_guests'] as $guest) {
                if (isset($guest['customer_id']) && (int)$guest['customer_id'] === $customerId) {
                    $this->dispatch('notify', type: 'warning', message: 'Este cliente ya está agregado como huésped adicional.');
                    return;
                }
            }

            // Verificar que no sea el cliente principal
            if (isset($this->assignGuestsForm['client_id']) && (int)$this->assignGuestsForm['client_id'] === $customerId) {
                $this->dispatch('notify', type: 'warning', message: 'Este cliente ya está asignado como cliente principal.');
                return;
            }

            // ðŸ” VALIDACIÓN CRÍTICA: Verificar capacidad ANTES de agregar huésped adicional
            $principalCount = !empty($this->assignGuestsForm['client_id']) ? 1 : 0;
            $currentAdditionalCount = count($this->assignGuestsForm['additional_guests'] ?? []);
            $totalAfterAdd = $principalCount + $currentAdditionalCount + 1;
            $maxCapacity = (int)($this->assignGuestsForm['max_capacity'] ?? 1);

            if ($totalAfterAdd > $maxCapacity) {
                $this->dispatch('notify', type: 'error', message: "No se puede agregar más huéspedes. La habitación tiene capacidad máxima de {$maxCapacity} persona" . ($maxCapacity > 1 ? 's' : '') . ".");
                return;
            }

            // Agregar huésped
            $this->assignGuestsForm['additional_guests'][] = [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'identification' => $customer->taxProfile?->identification ?? 'N/A',
            ];

            $this->dispatch('notify', type: 'success', message: 'Huésped adicional agregado.');
        } catch (\Exception $e) {
            \Log::error('Error adding assign guest', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            $this->dispatch('notify', type: 'error', message: 'Error al agregar huésped: ' . $e->getMessage());
        }
    }

    /**
     * Abre el modal de historial diario de liberaciones de una habitación.
     * 
     * CONCEPTO: Muestra TODAS las liberaciones que ocurrieron en un día específico
     * (por defecto HOY) desde room_release_history (auditoría inmutable).
     * 
     * DIFERENCIA CON openRoomDetail():
     * - openRoomDetail(): Estado operativo actual (stays/reservations activas)
     * - openRoomDailyHistory(): Historial histórico cerrado (room_release_history)
     * 
     * @param int $roomId ID de la habitación
     * @return void
     */
    public function openRoomDailyHistory(int $roomId): void
    {
        try {
            $room = Room::findOrFail($roomId);
            $date = $this->date->toDateString(); // Fecha seleccionada (HOY por defecto)

            // Obtener TODAS las liberaciones de esta habitación en el día seleccionado
            // ðŸ”§ QUERY DEFENSIVA: Usa release_date como principal, created_at como fallback
            // Esto garantiza que registros con release_date NULL o mal guardado no se pierdan
            $releases = RoomReleaseHistory::where('room_id', $roomId)
                ->where(function ($q) use ($date) {
                    // Prioridad 1: release_date (SSOT principal) - si existe y coincide
                    $q->where(function($subQ) use ($date) {
                        $subQ->whereNotNull('release_date')
                             ->whereDate('release_date', $date);
                    })
                    // Prioridad 2: created_at (fallback para registros con release_date NULL)
                    ->orWhere(function($subQ) use ($date) {
                        $subQ->whereNull('release_date')
                             ->whereDate('created_at', $date);
                    });
                })
                ->with('releasedBy')
                ->orderBy('created_at', 'desc') // Más recientes primero (última liberación arriba)
                ->get();
            
            // ðŸ” DEBUG: Log de la query para verificar qué se encontró
            \Log::info('Room daily history query executed', [
                'room_id' => $roomId,
                'date_filter' => $date,
                'releases_found' => $releases->count(),
                'releases_debug' => $releases->map(function($r) {
                    return [
                        'id' => $r->id,
                        'release_date' => $r->release_date?->toDateString(),
                        'created_at' => $r->created_at->toDateString(),
                        'customer' => $r->customer_name,
                    ];
                })->toArray(),
            ]);

            // Preparar datos para el modal
            $this->roomDailyHistoryData = [
                'room' => [
                    'id' => $room->id,
                    'room_number' => $room->room_number,
                ],
                'date' => $date,
                'date_formatted' => $this->date->format('d/m/Y'),
                'total_releases' => $releases->count(),
                'releases' => $releases->map(function ($release) {
                    // Determinar estado de la cuenta
                    $isPaid = (float)$release->pending_amount <= 0.01; // Tolerancia para floats
                    $hasConsumptions = (float)$release->consumptions_total > 0;
                    
                    return [
                        'id' => $release->id,
                        'released_at' => $release->created_at->format('H:i'),
                        'released_at_full' => $release->created_at->format('d/m/Y H:i'),
                        // âœ… SIEMPRE MOSTRAR - nunca ocultar por falta de cliente
                        'customer_name' => $release->customer_name ?: 'Sin huésped asignado', // âœ… Fallback semántico
                        'customer_identification' => $release->customer_identification ?: 'N/A',
                        'guests_count' => $release->guests_count ?? 0,

                        // Datos financieros
                        'total_amount' => (float)$release->total_amount,
                        'deposit' => (float)$release->deposit,
                        'consumptions_total' => (float)$release->consumptions_total,
                        'pending_amount' => (float)$release->pending_amount,
                        'is_paid' => $isPaid,
                        'has_consumptions' => $hasConsumptions,

                        // Snapshot (JSON deserializado)
                        'guests_data' => $release->guests_data ?? [],
                        'sales_data' => $release->sales_data ?? [],
                        'deposits_data' => $release->deposits_data ?? [],

                        // Operación
                        'released_by' => $release->releasedBy?->name ?? 'Sistema',
                        'target_status' => $release->target_status,
                        'check_in_date' => $release->check_in_date?->format('d/m/Y'),
                        'check_out_date' => $release->check_out_date?->format('d/m/Y'),
                    ];
                })->toArray(),
            ];

            $this->roomDailyHistoryModal = true;
        } catch (\Exception $e) {
            \Log::error('Error opening room daily history', [
                'room_id' => $roomId,
                'date' => $this->date->toDateString(),
                'error' => $e->getMessage()
            ]);
            $this->dispatch('notify', type: 'error', message: 'Error al cargar historial: ' . $e->getMessage());
        }
    }

    /**
     * Cierra el modal de historial diario.
     */
    public function closeRoomDailyHistory(): void
    {
        $this->roomDailyHistoryModal = false;
        $this->roomDailyHistoryData = null;
    }

    /**
     * Elimina un huésped adicional del formulario de asignación.
     * 
     * @param int $index Índice del huésped en el array
     * @return void
     */
    public function removeAssignGuest(int $index): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->canEditOccupancy()) {
            $this->dispatch('notify', type: 'error', message: 'Solo el administrador o recepción puede editar la ocupación.');
            return;
        }

        if (!$this->assignGuestsForm || !isset($this->assignGuestsForm['additional_guests'][$index])) {
            return;
        }

        unset($this->assignGuestsForm['additional_guests'][$index]);
        $this->assignGuestsForm['additional_guests'] = array_values($this->assignGuestsForm['additional_guests']);
        $this->dispatch('notify', type: 'success', message: 'Huésped removido.');
    }

    /**
     * Assign guests to a specific reservation room.
     * 
     * SINGLE SOURCE OF TRUTH:
     * - Huésped principal: reservations.client_id
     * - Huéspedes adicionales: reservation_guests + reservation_room_guests
     * 
     * Esta lógica es IDÉNTICA a ReservationController::assignGuestsToRoom()
     * para mantener consistencia arquitectónica.
     * 
     * @param ReservationRoom $reservationRoom
     * @param array $assignedGuestIds Array de customer IDs
     * @return void
     */
    private function assignGuestsToRoom(ReservationRoom $reservationRoom, array $assignedGuestIds): void
    {
        if (empty($assignedGuestIds)) {
            return;
        }

        // Filter valid guest IDs
        $validGuestIds = array_filter($assignedGuestIds, function ($id): bool {
            return !empty($id) && is_numeric($id) && $id > 0;
        });

        if (empty($validGuestIds)) {
            return;
        }

        // Verify guests exist in database
        $validGuestIds = \App\Models\Customer::withoutGlobalScopes()
            ->whereIn('id', $validGuestIds)
            ->pluck('id')
            ->toArray();

        if (empty($validGuestIds)) {
            return;
        }

        try {
            // Estructura de BD:
            // reservation_guests: id, reservation_id, customer_id
            // reservation_room_guests: id, reservation_room_id, reservation_guest_id

            foreach ($validGuestIds as $guestId) {
                // Buscar o crear entrada en reservation_guests (cliente vinculado a la reserva)
                $existingReservationGuest = DB::table('reservation_guests')
                    ->where('reservation_id', $reservationRoom->reservation_id)
                    ->where('customer_id', $guestId)
                    ->first();

                $reservationGuestId = $existingReservationGuest
                    ? $existingReservationGuest->id
                    : DB::table('reservation_guests')->insertGetId([
                        'reservation_id' => $reservationRoom->reservation_id,
                        'customer_id' => $guestId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                // Vincular la entrada de reservation_guests a esta habitación (si no existe ya)
                $existingRoomGuest = DB::table('reservation_room_guests')
                    ->where('reservation_room_id', $reservationRoom->id)
                    ->where('reservation_guest_id', $reservationGuestId)
                    ->exists();

                if (!$existingRoomGuest) {
                    DB::table('reservation_room_guests')->insert([
                        'reservation_room_id' => $reservationRoom->id,
                        'reservation_guest_id' => $reservationGuestId,
                        'customer_id' => $guestId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error assigning guests to room in Quick Rent', [
                'reservation_room_id' => $reservationRoom->id,
                'guest_ids' => $validGuestIds,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function openRoomEdit($roomId)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->isAdmin()) {
            $this->dispatch('notify', type: 'error', message: 'Solo el administrador puede editar habitaciones.');
            return;
        }

        $room = Room::with(['roomType', 'ventilationType', 'rates'])->find($roomId);
        if ($room) {
            $this->roomEditData = [
                'room' => $room,
                'ventilation_types' => $this->ventilationTypes,
                'statuses' => $this->statuses,
                'isOccupied' => $room->isOccupied(),
            ];
            $this->roomEditModal = true;
        }
    }

    public function closeRoomEdit()
    {
        $this->roomEditModal = false;
        $this->roomEditData = null;
    }

    public function deleteRoom(int $roomId): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        if (!$this->isAdmin()) {
            $this->dispatch('notify', type: 'error', message: 'Solo el administrador puede eliminar habitaciones.');
            return;
        }

        try {
            $room = Room::findOrFail($roomId);

            if ($room->getAvailabilityService()->getStayForDate($this->date ?? HotelTime::currentOperationalDate())) {
                throw new \RuntimeException('No se puede eliminar una habitación con ocupación activa.');
            }

            if ($room->reservations()->exists()) {
                throw new \RuntimeException('No se puede eliminar la habitación porque tiene reservas asociadas.');
            }

            $room->rates()->delete();
            $room->delete();

            $editingRoomId = (int) data_get($this->roomEditData, 'room.id', 0);
            if ($editingRoomId === $roomId) {
                $this->closeRoomEdit();
            }

            $this->dispatch('notify', type: 'success', message: 'Habitación eliminada correctamente.');
            $this->resetPage();
            $this->dispatch('$refresh');
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Error al eliminar habitación: ' . $e->getMessage());
        }
    }

    public function viewReleaseHistoryDetail($historyId)
    {
        $history = RoomReleaseHistory::with(['room', 'customer', 'releasedBy'])->find($historyId);
        if ($history) {
            // Convertir el objeto a array para compatibilidad con Livewire
            // Incluir también el nombre del usuario que liberó
            $historyArray = $history->toArray();
            $historyArray['released_by_name'] = $history->releasedBy?->name ?? 'N/A';
            $this->releaseHistoryDetail = $historyArray;
            $this->releaseHistoryDetailModal = true;
        } else {
            $this->dispatch('notify', type: 'error', message: 'Registro de historial no encontrado.');
        }
    }
    
    public function openReleaseHistoryDetail($roomId)
    {
        $room = Room::find($roomId);
        if ($room) {
            $this->releaseHistoryDetail = [
                'room' => $room,
                'history' => collect([]),
            ];
            $this->releaseHistoryDetailModal = true;
        }
    }

    public function closeReleaseHistoryDetail()
    {
        $this->releaseHistoryDetailModal = false;
        $this->releaseHistoryDetail = null;
    }

    public function openRoomReleaseConfirmation($roomId)
    {
        $room = Room::find($roomId);
        if ($room && $room->isOccupied()) {
            $this->detailData = [
                'room' => $room,
                'reservation' => $room->getActiveReservation($this->date),
            ];
            $this->roomReleaseConfirmationModal = true;
        }
    }

    public function closeRoomReleaseConfirmation()
    {
        $this->roomReleaseConfirmationModal = false;
        $this->detailData = null;
        $this->dispatch('close-room-release-modal');
    }

    public function loadRoomReleaseData($roomId, $isCancellation = false)
    {
        $room = Room::with([
            'reservationRooms.reservation' => function($q) {
                $q->with(['customer', 'sales.product', 'payments']);
            }
        ])->find($roomId);

        if (!$room) {
            return [
                'room_id' => $roomId,
                'room_number' => null,
                'reservation' => null,
                'sales' => [],
                'payments_history' => [],
                'refunds_history' => [],
                'total_hospedaje' => 0,
                'abono_realizado' => 0,
                'sales_total' => 0,
                'total_debt' => 0,
                'identification' => null,
                'is_cancellation' => $isCancellation,
            ];
        }

        $activeReservation = $room->getActiveReservation($this->date ?? now());
        $sales = collect();

        $totalHospedaje = 0;
        $abonoRealizado = 0;
        $salesTotal = 0;
        $totalDebt = 0;
        $payments = collect();
        $identification = null;

        if ($activeReservation) {
            $sales = $activeReservation->sales ?? collect();
            $payments = $activeReservation->payments ?? collect();

            // âœ… NUEVO SSOT: Total del hospedaje desde stay_nights si existe
            try {
                $totalHospedaje = (float)\App\Models\StayNight::where('reservation_id', $activeReservation->id)
                    ->sum('price');
                
                // Si no hay noches, usar fallback
                if ($totalHospedaje <= 0) {
                    $totalHospedaje = (float)($activeReservation->total_amount ?? 0);
                }
            } catch (\Exception $e) {
                // Si falla (tabla no existe), usar fallback
                $totalHospedaje = (float)($activeReservation->total_amount ?? 0);
            }

            // Usar suma real de pagos positivos (SSOT financiero), no deposit_amount que puede estar desactualizado
            $totalPaidPositive = (float)($payments->where('amount', '>', 0)->sum('amount') ?? 0);
            $abonoRealizado = $totalPaidPositive > 0 ? $totalPaidPositive : (float)($activeReservation->deposit_amount ?? 0);
            
            // Devoluciones (solo negativos, valor absoluto)
            $refundsTotal = abs((float)($payments->where('amount', '<', 0)->sum('amount') ?? 0));
            
            $salesTotal = (float)($sales->sum('total') ?? 0);
            $salesDebt = (float)($sales->where('is_paid', false)->sum('total') ?? 0);
            
            // ===== REGLA HOTELERA CRÍTICA: Calcular deuda solo si NO hay stay activa =====
            // REGLA: Mientras la habitación esté OCUPADA, pagos > total_hospedaje es PAGO ADELANTADO, NO saldo a favor
            // Solo se evalúa saldo a favor cuando stay.status = finished (checkout completado)
            $hasActiveStay = \App\Models\Stay::where('reservation_id', $activeReservation->id)
                ->whereNull('check_out_at')
                ->whereIn('status', ['active', 'pending_checkout'])
                ->exists();

            if ($hasActiveStay) {
                // ===== HABITACIÓN OCUPADA: Calcular deuda normal =====
                // Fórmula: deuda = (hospedaje - abonos_reales) + devoluciones + consumos_pendientes
                // Si totalPaid > total_hospedaje, totalDebt será NEGATIVO (pago adelantado)
                // PERO NO es "saldo a favor" - es crédito para noches futuras/consumos
                $totalDebt = ($totalHospedaje - $totalPaidPositive) + $refundsTotal + $salesDebt;
                // âœ… totalDebt < 0 = Pago adelantado (válido mientras esté ocupada)
                // âœ… totalDebt > 0 = Deuda pendiente
                // âœ… totalDebt = 0 = Al día
            } else {
                // ===== HABITACIÓN LIBERADA: Evaluar saldo a favor real =====
                // Aquí sí se evalúa si hay overpaid (saldo a favor) después de cerrar la estadía
                $overpaid = $totalPaidPositive - $totalHospedaje;
                if ($overpaid > 0) {
                    // Hay saldo a favor real (habrá que devolver)
                    $totalDebt = -$overpaid + $refundsTotal + $salesDebt;  // Negativo = se le debe
                } else {
                    // No hay saldo a favor o hay deuda pendiente
                    $totalDebt = abs($overpaid) + $refundsTotal + $salesDebt;
                }
            }
            
            $identification = $activeReservation->customer->taxProfile->identification ?? null;
        }

        return [
            'room_id' => $room->id,
            'room_number' => $room->room_number,
            'reservation' => $activeReservation ? $activeReservation->toArray() : null,
            'sales' => $sales->map(function($sale) {
                return [
                    'id' => $sale->id,
                    'product' => [
                        'name' => $sale->product->name ?? null,
                    ],
                    'quantity' => $sale->quantity ?? 0,
                    'is_paid' => (bool)($sale->is_paid ?? false),
                    'payment_method' => $sale->payment_method ?? null,
                    'total' => (float)($sale->total ?? 0),
                ];
            })->values()->toArray(),
            'payments_history' => $payments->map(function($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => (float)($payment->amount ?? 0),
                    'method' => $payment->method ?? null,
                    'created_at' => $payment->created_at,
                ];
            })->values()->toArray(),
            'refunds_history' => [],
            'total_hospedaje' => $totalHospedaje,
            'abono_realizado' => $abonoRealizado,
            'sales_total' => $salesTotal,
            'total_debt' => $totalDebt,
            'identification' => $identification,
            'cancel_url' => null,
            'is_cancellation' => $isCancellation,
        ];
    }

    public function updateCleaningStatus($roomId, $status)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        try {
            $room = Room::find($roomId);
            
            if (!$room) {
                $this->dispatch('notify', type: 'error', message: 'Habitación no encontrada.');
                return;
            }

            // Validar que no sea fecha histórica - usando lógica de HotelTime
            $today = HotelTime::currentOperationalDate();
            $selectedDate = $this->getSelectedDate()->startOfDay();
            
            // ðŸ”¥ PERMITIR cambios en fecha actual (hoy)
            if (HotelTime::isOperationalPastDate($selectedDate)) {
                $this->dispatch('notify', type: 'error', message: 'No se pueden hacer cambios en fechas históricas.');
                return;
            }
            
            // ðŸ”¥ DEBUG: Log para verificar qué fecha se está usando
            \Log::info('updateCleaningStatus', [
                'room_id' => $roomId,
                'status' => $status,
                'selectedDate' => $selectedDate->format('Y-m-d'),
                'today_operational' => $today->format('Y-m-d'),
                'isPast' => HotelTime::isOperationalPastDate($selectedDate)
            ]);

            // Validar que el estado sea válido
            if (!in_array($status, ['limpia', 'pendiente', 'mantenimiento'], true)) {
                $this->dispatch('notify', type: 'error', message: 'Estado de limpieza inválido.');
                return;
            }

            $operationalStatusService = app(RoomOperationalStatusService::class);
            $currentCleaningCode = data_get($room->cleaningStatus($selectedDate), 'code');
            $hasDailyMaintenanceOverride = $room->hasOperationalMaintenanceOverrideOn($selectedDate);

            if ($status !== 'mantenimiento' && $currentCleaningCode === 'mantenimiento' && !$hasDailyMaintenanceOverride) {
                $this->dispatch('notify', type: 'error', message: 'Esta habitacion tiene un bloqueo de mantenimiento activo que no se puede cerrar desde este menu.');
                return;
            }

            if ($status === 'mantenimiento') {
                $validation = $this->canApplyMaintenanceToRoom($room, $selectedDate);
                if (!($validation['valid'] ?? false)) {
                    $this->dispatch('notify', type: 'error', message: (string) ($validation['message'] ?? 'No fue posible poner la habitacion en mantenimiento.'));
                    return;
                }

                $operationalStatusService->markMaintenance($room, $selectedDate, Auth::id());
                $this->dispatch('notify', type: 'success', message: 'Habitacion marcada en mantenimiento para el dia operativo y el siguiente.');
                $this->loadRooms();
                return;
            }

            if ($hasDailyMaintenanceOverride) {
                $operationalStatusService->clearMaintenance($room, $selectedDate, Auth::id());
            }

            // Update cleaning status based on the status parameter
            if ($status === 'limpia') {
                $room->last_cleaned_at = now();
                $room->save();
                $this->dispatch('notify', type: 'success', message: 'Habitación marcada como limpia.');
                $this->dispatch('room-marked-clean', roomId: $room->id);
            } elseif ($status === 'pendiente') {
                $room->last_cleaned_at = null;
                $room->save();
                $this->dispatch('notify', type: 'success', message: 'Habitación marcada como pendiente de limpieza.');
            }
            
            // Refrescar habitaciones para actualizar la vista
            $this->loadRooms();
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Error al actualizar estado de limpieza: ' . $e->getMessage());
            \Log::error('Error updating cleaning status', [
                'room_id' => $roomId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function saveRoomDailyObservation(int $roomId, ?string $observation = null): void
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        $validator = Validator::make(
            ['observation' => $observation],
            ['observation' => ['nullable', 'string', 'max:500']]
        );

        if ($validator->fails()) {
            $this->dispatch('notify', type: 'error', message: $validator->errors()->first('observation'));
            return;
        }

        $room = Room::find($roomId);
        if (!$room) {
            $this->dispatch('notify', type: 'error', message: 'Habitacion no encontrada.');
            return;
        }

        $selectedDate = $this->getSelectedDate()->startOfDay();
        $normalizedObservation = trim((string) ($validator->validated()['observation'] ?? ''));

        app(RoomOperationalStatusService::class)->saveObservation(
            $room,
            $selectedDate,
            $normalizedObservation !== '' ? $normalizedObservation : null,
            Auth::id()
        );

        $this->dispatch(
            'notify',
            type: 'success',
            message: $normalizedObservation !== ''
                ? 'Observacion diaria guardada.'
                : 'Observacion diaria eliminada.'
        );

        $this->loadRooms();
    }

    public function confirmReleaseRoom($roomId)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        // Implementar lógica de liberación de habitación
        try {
            $room = Room::find($roomId);
            if ($room && $room->isOccupied()) {
                // Realizar checkout y liberar habitación
                $this->dispatch('notify', type: 'success', message: 'Habitación liberada exitosamente.');
                $this->closeRoomReleaseConfirmation();
            }
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Libera la habitación (checkout).
     * 
     * Flujo:
     * 1. Si hay deuda pendiente:
     *    - Valida que el usuario haya confirmado (en frontend)
     *    - Registra un pago para saldarlo
     * 2. Cierra el stay usando getStayForDate(today)
     * 3. Actualiza el estado de la reserva
     * 
     * REGLA: Solo libera si el balance queda en 0
     * 
     * @param int $roomId
     * @param string|null $status Ej: 'libre'
     */
    public function releaseRoom($roomId, $status = null, $paymentMethod = null, $bankName = null, $reference = null)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        $started = false;
        try {
            $this->isReleasingRoom = true;
            $room = Room::find($roomId);
            if (!$room) {
                $this->dispatch('notify', type: 'error', message: 'Habitación no encontrada.');
                $this->isReleasingRoom = false;
                return;
            }

            $this->dispatch('room-release-start', roomId: $roomId);
            $started = true;

            $availabilityService = $room->getAvailabilityService();
            $operationalDate = $this->getSelectedDate()->startOfDay();
            $today = $operationalDate->copy();
            $releaseTimestamp = now();

            if (!HotelTime::isOperationalToday($operationalDate)) {
                $this->dispatch('notify', type: 'warning', message: 'La liberacion solo se permite en el dia operativo actual.');
                if ($started) {
                    $this->dispatch('room-release-finished', roomId: $roomId);
                }
                return;
            }
            
            // BLOQUEO: No se puede liberar ocupaciones históricas
            if ($availabilityService->isHistoricDate($operationalDate)) {
                $this->dispatch('notify', type: 'error', message: 'No se pueden hacer cambios en fechas históricas.');
                if ($started) {
                    $this->dispatch('room-release-finished', roomId: $roomId);
                }
                return;
            }

            // ===== PASO 1: Obtener estadia ABIERTA real para hoy operativo =====
            // No usar getStayForDate() aqui porque puede devolver estadias historicas ya cerradas.
            $openStayQuery = Stay::query()
                ->where('room_id', (int) $room->id)
                ->whereNull('check_out_at')
                ->where('check_in_at', '<=', $releaseTimestamp)
                ->orderByDesc('check_in_at');

            $activeStay = $openStayQuery->first();

            if (!$activeStay) {
                $this->dispatch('notify', type: 'info', message: 'No hay ocupacion activa para liberar en el dia operativo actual.');
                if ($started) {
                    $this->dispatch('room-release-finished', roomId: $roomId);
                }
                $this->closeRoomReleaseConfirmation();
                return;
            }

            // ===== PASO 2: Obtener reserva y calcular deuda REAL desde SSOT =====
            $reservation = $activeStay->reservation;
            if (!$reservation) {
                $this->dispatch('notify', type: 'error', message: 'La ocupación no tiene reserva asociada.');
                if ($started) {
                    $this->dispatch('room-release-finished', roomId: $roomId);
                }
                return;
            }

            // ðŸ” RECALCULAR TODA LA DEUDA REAL DESDE SSOT
            $reservation->load(['payments', 'sales']);
            try {
                $this->ensureStayNightsCoverageForReservation($reservation);
                $this->normalizeReservationStayNightTotals($reservation);
            } catch (\Exception $e) {
                \Log::warning('Release Room: unable to normalize stay nights before debt check', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // âœ… NUEVO SSOT: Total del hospedaje desde stay_nights
            try {
                $totalHospedaje = (float)\App\Models\StayNight::where('reservation_id', $reservation->id)
                    ->sum('price');
                
                // Si no hay noches aún, usar fallback
                if ($totalHospedaje <= 0) {
                    $totalHospedaje = (float)($reservation->total_amount ?? 0);
                }
            } catch (\Exception $e) {
                // Si falla (tabla no existe), usar fallback
                $totalHospedaje = (float)($reservation->total_amount ?? 0);
            }
            
            // SOLO pagos positivos (SSOT financiero)
            $totalPaid = (float)($reservation->payments
                ->where('amount', '>', 0)
                ->sum('amount') ?? 0);
            
            // SOLO devoluciones (valores negativos en payments)
            $totalRefunds = abs((float)($reservation->payments
                ->where('amount', '<', 0)
                ->sum('amount') ?? 0));
            
            // Consumos NO pagados
            $totalSalesDebt = (float)($reservation->sales
                ->where('is_paid', false)
                ->sum('total') ?? 0);
            
            // ðŸ”´ DEUDA REAL TOTAL
            $realDebt = ($totalHospedaje - $totalPaid) + $totalRefunds + $totalSalesDebt;

            // ===== PASO 3: Si hay deuda, pagarla COMPLETA =====
            if ($realDebt > 0) {
                // Requiere datos de pago desde frontend
                if (!$paymentMethod) {
                    $this->dispatch('notify', type: 'error', message: 'Debe seleccionar un método de pago.');
                    if ($started) {
                        $this->dispatch('room-release-finished', roomId: $roomId);
                    }
                    return;
                }

                $paymentMethodId = $this->getPaymentMethodId($paymentMethod) ?? DB::table('payments_methods')
                    ->where('name', 'Efectivo')
                    ->orWhere('code', 'cash')
                    ->value('id');

                // âœ… Pagar TODO lo pendiente
                Payment::create([
                    'reservation_id' => $reservation->id,
                    'amount' => $realDebt,  // âœ… TODO lo que faltaba
                    'payment_method_id' => $paymentMethodId,
                    'bank_name' => $paymentMethod === 'transferencia' ? ($bankName ?: null) : null,
                    'reference' => $paymentMethod === 'transferencia' 
                        ? ($reference ?: null) 
                        : 'Pago total en liberación',
                    'paid_at' => now(),
                    'created_by' => auth()->id(),
                ]);

                // ===== PASO 3.5: Marcar consumos como pagados =====
                if ($totalSalesDebt > 0) {
                    $reservation->sales()
                        ->where('is_paid', false)
                        ->update(['is_paid' => true]);
                }
            }

            // ===== PASO 4: REVALIDAR que balance sea 0 (OBLIGATORIO) =====
            $reservation->refresh()->load(['payments', 'sales']);
            
            // Recalcular desde BD después de pagos y marcar consumos
            $finalPaid = (float)($reservation->payments
                ->where('amount', '>', 0)
                ->sum('amount') ?? 0);
            
            $finalSalesDebt = (float)($reservation->sales
                ->where('is_paid', false)
                ->sum('total') ?? 0);
            
            $finalRefunds = abs((float)($reservation->payments
                ->where('amount', '<', 0)
                ->sum('amount') ?? 0));
            
            $finalBalance = ($totalHospedaje - $finalPaid) + $finalRefunds + $finalSalesDebt;
            
            // ðŸ”’ VALIDACIÓN DEFENSIVA: No liberar si balance != 0
            if (abs($finalBalance) > 0.01) { // Tolerancia para floats
                $this->dispatch('notify', type: 'error', message: "Error crítico: No se puede liberar con saldo pendiente. Balance: \${$finalBalance}");
                \Log::error('Release Room: Attempted to release with non-zero balance', [
                    'room_id' => $roomId,
                    'reservation_id' => $reservation->id,
                    'final_balance' => $finalBalance,
                    'total_hospedaje' => $totalHospedaje,
                    'final_paid' => $finalPaid,
                    'final_sales_debt' => $finalSalesDebt,
                    'final_refunds' => $finalRefunds,
                ]);
                if ($started) {
                    $this->dispatch('room-release-finished', roomId: $roomId);
                }
                return;
            }

            // ===== PASO 5: Marcar TODAS las noches como pagadas =====
            // ðŸ”¥ CRÍTICO: Al liberar, todas las noches hasta HOY quedan pagadas
            // ðŸ” PROTECCIÓN: Solo marcar noches hasta hoy (evitar pagar noches futuras accidentalmente)
            try {
                \App\Models\StayNight::where('reservation_id', $reservation->id)
                    ->where('date', '<=', $today->toDateString()) // Solo noches hasta hoy operativo
                    ->where('is_paid', false)
                    ->update(['is_paid' => true]);
            } catch (\Exception $e) {
                // No crítico, solo log (si la tabla no existe aún, continuar)
                \Log::warning('Error marking nights as paid in releaseRoom', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage()
                ]);
            }

            // ===== PASO 6: Cerrar TODAS las stays abiertas de la habitacion =====
            // Defensa ante inconsistencias legacy (mas de una stay abierta para la misma habitacion).
            $checkoutTimestamp = $releaseTimestamp->copy();
            $closedStays = Stay::query()
                ->where('room_id', (int) $room->id)
                ->whereNull('check_out_at')
                ->where('check_in_at', '<=', $checkoutTimestamp)
                ->update([
                    'check_out_at' => $checkoutTimestamp,
                    'status' => 'finished',
                ]);

            if ($closedStays <= 0) {
                throw new \RuntimeException('No se pudo cerrar la estadia activa de la habitacion.');
            }

            // ===== PASO 7: Actualizar estado de la reserva =====
            $reservation->balance_due = 0;
            $reservation->payment_status_id = DB::table('payment_statuses')
                ->where('code', 'paid')
                ->value('id');
            $checkedOutStatusId = $this->resolveCheckedOutReservationStatusId();
            if (!empty($checkedOutStatusId)) {
                $reservation->status_id = (int) $checkedOutStatusId;
            }
            $reservation->save();
            $this->clearQuickReserveForDate((int) $room->id, $today);

            // Estado de limpieza al liberar.
            // Por defecto queda pendiente por aseo, salvo que se solicite "limpia" o "libre".
            $targetStatus = $status ? (string) $status : 'pendiente_aseo';
            if (!in_array($targetStatus, ['libre', 'limpia', 'pendiente_aseo', 'pendiente'], true)) {
                $targetStatus = 'pendiente_aseo';
            }

            $room->last_cleaned_at = in_array($targetStatus, ['pendiente_aseo', 'pendiente'], true)
                ? null
                : now();
            $room->save();

            \Log::info('Release Room: stay closure applied', [
                'room_id' => $room->id,
                'reservation_id' => $reservation->id,
                'closed_stays' => $closedStays,
                'checkout_timestamp' => $checkoutTimestamp->toDateTimeString(),
            ]);

            // ===== PASO 8: Crear registro en historial de liberación =====
            try {
                // Cargar relaciones necesarias (NO cargar 'guests' porque la relación está rota)
                $reservation->loadMissing([
                    'customer.taxProfile', 
                    'sales.product', 
                    'payments.paymentMethod',
                    'reservationRooms'
                ]);
                
                // ===== CALCULAR TOTALES (SSOT FINANCIERO) =====
                // âœ… NUEVO SSOT: Calcular desde stay_nights si existe
                try {
                    $totalAmount = (float)\App\Models\StayNight::where('reservation_id', $reservation->id)
                        ->sum('price');
                    
                    // Si no hay noches aún, usar fallback
                    if ($totalAmount <= 0) {
                        $totalAmount = (float)($reservation->total_amount ?? 0);
                    }
                } catch (\Exception $e) {
                    // Si falla (tabla no existe), usar fallback
                    $totalAmount = (float)($reservation->total_amount ?? 0);
                }
                
                // VALIDACIÓN CRÍTICA: Verificar que totalAmount existe y es válido
                if ($totalAmount <= 0) {
                    \Log::error('Release Room: totalAmount is 0 or null', [
                        'reservation_id' => $reservation->id,
                        'total_amount' => $reservation->total_amount,
                        'room_id' => $room->id,
                    ]);
                    // NO lanzar excepción para no bloquear el release, pero loguear el error
                    // Usar fallback: calcular desde ReservationRoom si existe
                    $reservationRoom = $reservation->reservationRooms->where('room_id', $room->id)->first();
                    if ($reservationRoom && $reservationRoom->price_per_night > 0) {
                        $nights = $reservationRoom->nights ?? 1;
                        $totalAmount = (float)($reservationRoom->price_per_night * $nights);
                        \Log::warning('Release Room: Using fallback totalAmount from ReservationRoom', [
                            'reservation_id' => $reservation->id,
                            'fallback_total' => $totalAmount,
                        ]);
                    }
                }
                
                // ðŸ” RECALCULAR TOTALES FINALES DESPUÉS DE PAGOS (SSOT)
                // Asegurar que tenemos los datos más recientes desde BD
                $reservation->refresh()->load(['payments', 'sales']);
                
                // Pagos finales (SOLO positivos)
                $finalPaidAmount = (float)($reservation->payments
                    ->where('amount', '>', 0)
                    ->sum('amount') ?? 0);
                
                // Consumos totales (todos)
                $consumptionsTotal = (float)($reservation->sales->sum('total') ?? 0);
                
                // Consumos pendientes (debe ser 0 después de marcar como pagados)
                $consumptionsPending = (float)($reservation->sales
                    ->where('is_paid', false)
                    ->sum('total') ?? 0);
                
                // ðŸ”’ VALIDACIÓN: Consumos pendientes debe ser 0
                if ($consumptionsPending > 0.01) {
                    \Log::warning('Release Room: Some sales still unpaid after marking as paid', [
                        'reservation_id' => $reservation->id,
                        'consumptions_pending' => $consumptionsPending,
                    ]);
                }
                
                // Obtener ReservationRoom para fechas
                $reservationRoom = $reservation->reservationRooms
                    ->where('room_id', $room->id)
                    ->first();
                
                $checkInDate = $reservationRoom 
                    ? Carbon::parse($reservationRoom->check_in_date) 
                    : ($reservation->check_in_date 
                        ? Carbon::parse($reservation->check_in_date) 
                        : Carbon::parse($activeStay->check_in_at));
                $checkOutDate = $reservationRoom 
                    ? Carbon::parse($reservationRoom->check_out_date) 
                    : ($reservation->check_out_date 
                        ? Carbon::parse($reservation->check_out_date) 
                        : $today);
                
                // ðŸ”’ REGLA ABSOLUTA: pending_amount SIEMPRE debe ser 0 al liberar
                // El snapshot refleja el estado FINAL (cerrado)
                $pendingAmount = 0;
                
                // Determinar target_status basado en el parámetro o estado de limpieza
                // targetStatus ya fue determinado y aplicado al estado de limpieza.
                
                // Preparar datos de huéspedes
                // Obtener huéspedes desde reservation_guests usando reservation_room_id
                $guestsData = [];
                
                // Cliente principal
                if ($reservation->customer) {
                    $guestsData[] = [
                        'id' => $reservation->customer->id,
                        'name' => $reservation->customer->name,
                        'identification' => $reservation->customer->taxProfile?->identification,
                        'is_main' => true,
                    ];
                }
                
                // Obtener huéspedes adicionales desde reservation_guests usando reservation_room_id
                if ($reservationRoom) {
                    try {
                        // Verificar si la tabla tax_profiles existe
                        $hasTaxProfiles = Schema::hasTable('tax_profiles');
                        
                        if ($hasTaxProfiles) {
                            $additionalGuests = DB::table('reservation_room_guests as rrg')
                                ->join('reservation_guests as rg', 'rrg.reservation_guest_id', '=', 'rg.id')
                                ->join('customers', 'rg.customer_id', '=', 'customers.id')
                                ->leftJoin('tax_profiles', 'customers.id', '=', 'tax_profiles.customer_id')
                                ->where('rrg.reservation_room_id', $reservationRoom->id)
                                ->select('customers.id', 'customers.name', 'tax_profiles.identification')
                                ->get();
                        } else {
                            // Si no existe tax_profiles, solo obtener datos básicos
                            $additionalGuests = DB::table('reservation_room_guests as rrg')
                                ->join('reservation_guests as rg', 'rrg.reservation_guest_id', '=', 'rg.id')
                                ->join('customers', 'rg.customer_id', '=', 'customers.id')
                                ->where('rrg.reservation_room_id', $reservationRoom->id)
                                ->select('customers.id', 'customers.name')
                                ->get();
                        }
                        
                        foreach ($additionalGuests as $guest) {
                            $guestsData[] = [
                                'id' => $guest->id,
                                'name' => $guest->name,
                                'identification' => $guest->identification ?? null,
                                'is_main' => false,
                            ];
                        }
                    } catch (\Exception $e) {
                        // Si falla la consulta de huéspedes, continuar sin ellos
                        \Log::warning('Error loading additional guests for release history', [
                            'reservation_room_id' => $reservationRoom->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                
                // ðŸ”¥ CRÍTICO: Asegurar que release_date sea la fecha real de liberación (SSOT para historial diario)
                // NO confiar en defaults ni Carbon automático - SETEARLO EXPLÍCITAMENTE
                $releaseDate = $today->toDateString(); // Fecha actual (HOY) - SSOT para historial diario
                
                // ðŸ” CUSTOMER: Puede ser NULL (walk-in sin asignar)
                // NO asumir que siempre existe customer - usar null-safe operator
                $customer = $reservation->customer; // puede ser null
                
                // Crear registro de historial (snapshot FINAL)
                $historyData = [
                    'room_id' => $room->id,
                    'reservation_id' => $reservation->id,
                    'customer_id' => $customer?->id, // âœ… puede ser null
                    'released_by' => auth()->id(),
                    'room_number' => $room->room_number,
                    // ðŸ’° FINANCIEROS FINALES (SSOT)
                    'total_amount' => $totalAmount,
                    'deposit' => $finalPaidAmount,  // âœ… Pagos finales después de pago automático
                    'consumptions_total' => $consumptionsTotal,
                    'pending_amount' => 0,  // ðŸ”’ SIEMPRE 0 al liberar (cuenta cerrada)
                    'guests_count' => $reservation->total_guests ?? count($guestsData) ?: 1,
                    'check_in_date' => $checkInDate->toDateString(),
                    'check_out_date' => $checkOutDate->toDateString(),
                    // ðŸ”¥ CRÍTICO: release_date DEBE ser la fecha real de liberación (SSOT para historial diario)
                    'release_date' => $releaseDate,  // âœ… Seteado explícitamente con fecha actual
                    'target_status' => $targetStatus,
                    // ðŸ” DATOS DENORMALIZADOS (NO obligatorios) - siempre con placeholder semántico si no hay cliente
                    'customer_name' => $customer?->name ?? 'Sin huésped asignado', // âœ… Nunca NULL, siempre placeholder
                    'customer_identification' => $customer?->taxProfile?->identification ?? null,
                    'customer_phone' => $customer?->phone ?? null,
                    'customer_email' => $customer?->email ?? null,
                    'reservation_data' => [
                        'id' => $reservation->id,
                        'reservation_code' => $reservation->reservation_code,
                        'client_id' => $reservation->client_id,
                        'status_id' => $reservation->status_id,
                        'total_guests' => $reservation->total_guests,
                        'adults' => $reservation->adults,
                        'children' => $reservation->children,
                        'total_amount' => (float)($reservation->total_amount ?? 0),
                        'deposit_amount' => (float)($reservation->deposit_amount ?? 0),
                        'balance_due' => (float)($reservation->balance_due ?? 0),
                        'payment_status_id' => $reservation->payment_status_id,
                        'source_id' => $reservation->source_id,
                        'created_by' => $reservation->created_by,
                        'notes' => $reservation->notes,
                        'created_at' => $reservation->created_at?->toDateTimeString(),
                        'updated_at' => $reservation->updated_at?->toDateTimeString(),
                    ],
                    'sales_data' => $reservation->sales->map(function($sale) {
                        return [
                            'id' => $sale->id,
                            'product_id' => $sale->product_id,
                            'product_name' => $sale->product->name ?? 'N/A',
                            'quantity' => $sale->quantity,
                            'unit_price' => (float)($sale->unit_price ?? 0),
                            'total' => (float)($sale->total ?? 0),
                            'is_paid' => (bool)($sale->is_paid ?? false),
                        ];
                    })->toArray(),
                    'deposits_data' => $reservation->payments->map(function($payment) {
                        return [
                            'id' => $payment->id,
                            'amount' => (float)($payment->amount ?? 0),
                            'payment_method' => $payment->paymentMethod->name ?? 'N/A',
                            'paid_at' => $payment->paid_at?->toDateString(),
                        ];
                    })->toArray(),
                    'guests_data' => $guestsData,
                ];
                
                // ðŸ” VALIDACIÓN PRE-CREACIÓN: Verificar que release_date no sea NULL
                if (empty($historyData['release_date']) || $historyData['release_date'] === null) {
                    \Log::error('CRITICAL: release_date is NULL before creating RoomReleaseHistory', [
                        'room_id' => $room->id,
                        'reservation_id' => $reservation->id,
                        'today' => $today->toDateString(),
                        'releaseDate' => $releaseDate,
                    ]);
                    // Forzar fecha actual como fallback absoluto
                    $historyData['release_date'] = now()->toDateString();
                }
                
                // ðŸ” DEBUG: Verificar datos antes de crear
                \Log::info('Creating room release history', [
                    'room_id' => $room->id,
                    'reservation_id' => $reservation->id,
                    'release_date_BEFORE' => $historyData['release_date'] ?? 'NULL',
                    'today' => $today->toDateString(),
                    'releaseDate_var' => $releaseDate ?? 'NULL',
                ]);
                
                $releaseHistory = RoomReleaseHistory::create($historyData);
                
                // ðŸ” DEBUG: Verificar datos después de crear
                $releaseHistory->refresh();
                \Log::info('Room release history created successfully', [
                    'room_id' => $room->id,
                    'reservation_id' => $reservation->id,
                    'history_id' => $releaseHistory->id,
                    'release_date_SAVED' => $releaseHistory->release_date?->toDateString(), // âœ… Verificar que se guardó correctamente
                    'created_at' => $releaseHistory->created_at->toDateString(),
                    'release_date_IN_DB' => DB::table('room_release_history')->where('id', $releaseHistory->id)->value('release_date'),
                    'target_status' => $targetStatus,
                ]);
            } catch (\Exception $e) {
                // No fallar la liberación si falla el historial, solo loguear
                \Log::error('Error creating room release history', [
                    'room_id' => $room->id,
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $this->dispatch('notify', type: 'success', message: 'Habitación liberada correctamente.');
            if ($started) {
                $this->dispatch('room-release-finished', roomId: $roomId);
            }
            $this->isReleasingRoom = false;
            $this->closeRoomReleaseConfirmation();
            $this->dispatch('refreshRooms');
        } catch (\Exception $e) {
            if ($started) {
                $this->dispatch('room-release-finished', roomId: $roomId);
            }
            $this->isReleasingRoom = false;
            $this->dispatch('notify', type: 'error', message: 'Error al liberar habitación: ' . $e->getMessage());
            \Log::error('Error releasing room: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Abrir modal para editar precios de una reservation
     */
    public function openEditPrices($reservationId)
    {
        if ($this->blockEditsForPastDate()) {
            return;
        }

        try {
            $reservation = \App\Models\Reservation::with(['stayNights', 'reservationRooms', 'stays'])
                ->findOrFail($reservationId);

            // Asegurar cobertura de noches (genera las que faltan si ya hay un stay activo)
            $this->ensureStayNightsCoverageForReservation($reservation);

            // Recargar noches tras el ensure
            $reservation->unsetRelation('stayNights');
            $stayNights = $reservation->stayNights()->orderBy('date')->get();

            // Si aun no hay noches (walkin sin check_out_date o sin stay), generar desde la stay activa
            if ($stayNights->isEmpty()) {
                $stay = $reservation->stays->whereNull('check_out_at')->first();
                $checkInDate = $stay
                    ? \Carbon\Carbon::parse($stay->check_in_at)->startOfDay()
                    : ($reservation->check_in_date ? \Carbon\Carbon::parse($reservation->check_in_date) : null);

                // check_out puede ser null (walkin en curso) -> usar manana como fin para incluir noche actual
                $checkOutDate = $reservation->check_out_date
                    ? \Carbon\Carbon::parse($reservation->check_out_date)
                    : HotelTime::currentOperationalDate()->addDay();

                if ($checkInDate) {
                    $nightCount = max(1, $checkInDate->diffInDays($checkOutDate));
                    $nightPrice = $reservation->total_amount > 0
                        ? (float)$reservation->total_amount / $nightCount
                        : 0;

                    for ($cursor = $checkInDate->copy(); $cursor->lt($checkOutDate); $cursor->addDay()) {
                        $stayNight = \App\Models\StayNight::create([
                            'reservation_id' => $reservation->id,
                            'stay_id'        => $stay?->id,
                            'room_id'        => $stay?->room_id ?? $reservation->reservationRooms->first()?->room_id,
                            'date'           => $cursor->toDateString(),
                            'price'          => $nightPrice,
                            'is_paid'        => false,
                        ]);

                        $stayNights->push($stayNight);
                    }
                }
            }

            // Preparar formulario
            $this->editPricesForm = [
                'id'           => $reservation->id,
                'total_amount' => (float)$reservation->total_amount,
                'nights'       => [],
            ];

            foreach ($stayNights as $night) {
                $this->editPricesForm['nights'][] = [
                    'id'      => $night->id,
                    'date'    => $night->date instanceof \Carbon\Carbon ? $night->date->format('Y-m-d') : (string)$night->date,
                    'price'   => (float)$night->price,
                    'is_paid' => (bool)$night->is_paid,
                ];
            }

            $this->editPricesModal = true;

        } catch (\Exception $e) {
            \Log::error('Error en openEditPrices: ' . $e->getMessage(), [
                'reservation_id' => $reservationId,
                'trace'          => $e->getTraceAsString(),
            ]);
            $this->dispatch('notify', type: 'error', message: 'Error al cargar los datos de la reserva: ' . $e->getMessage());
        }
    }

    public function render()
    {
        $this->sanitizeUtf8PublicState();

        $rooms = $this->getRoomsQuery()->paginate(30);
        $selectedDate = $this->getSelectedDate()->startOfDay();
        $quickReservedRoomMap = $this->getQuickReservedRoomMap(
            $selectedDate,
            $rooms->getCollection()->pluck('id')
        );
        $nightStatusByReservationRoom = StayNight::query()
            ->whereDate('date', $selectedDate->toDateString())
            ->whereIn('room_id', $rooms->getCollection()->pluck('id'))
            ->get(['reservation_id', 'room_id', 'is_paid'])
            ->keyBy(static function (StayNight $night): string {
                return ((int) $night->reservation_id) . '-' . ((int) $night->room_id);
            });
        $dailyStats = $this->getDailyOverviewStats();
        $receptionReservationsSummary = $this->getReceptionReservationsSummary();

        // Enriquecer rooms con estados y deudas
        $rooms->getCollection()->transform(function($room) use ($selectedDate, $quickReservedRoomMap, $nightStatusByReservationRoom) {
            $operationalStatus = $room->getOperationalStatus($selectedDate);
            $roomIsQuickReserved = isset($quickReservedRoomMap[(int) $room->id]);
            if ($roomIsQuickReserved) {
                if (in_array($operationalStatus, ['occupied', 'pending_checkout'], true)) {
                    $roomIsQuickReserved = false;
                }
            }

            $room->display_status = $room->getDisplayStatus($this->date);
            $room->current_reservation = in_array($operationalStatus, ['occupied', 'pending_checkout'], true)
                ? $room->getActiveReservation($this->date)
                : null;
            $room->future_reservation = $room->getFutureReservation($this->date);
            $room->pending_checkin_reservation = $this->getPendingCheckInReservationForRoom($room, $selectedDate);
            
            if ($room->current_reservation) {
                $room->current_reservation->loadMissing(['customer']);
            }
            
            if ($room->future_reservation) {
                $room->future_reservation->loadMissing(['customer']);
            }

            if ($room->pending_checkin_reservation) {
                $room->pending_checkin_reservation->loadMissing(['customer']);
            }

            $reservationForVisual = $room->pending_checkin_reservation ?: $room->future_reservation;
            $reservationForVisualCode = strtoupper(trim((string) ($reservationForVisual->reservation_code ?? '')));
            $hasPendingReservationVisual = $reservationForVisual
                && str_starts_with($reservationForVisualCode, 'RES-')
                && $operationalStatus === 'free_clean';

            $room->is_quick_reserved = $roomIsQuickReserved || $hasPendingReservationVisual;

            if ($room->current_reservation) {
                // ===============================
                // SSOT: CÁLCULO CORRECTO DE NOCHE PAGA
                // ===============================
                // REGLA: Una noche está pagada si los PAGOS POSITIVOS cubren el valor de las noches consumidas
                // Se usa reservation.total_amount como SSOT (NO tarifas, NO heurísticas)
                
                $reservation = $room->current_reservation;
                $nightStatusKey = ((int) $reservation->id) . '-' . ((int) $room->id);
                $nightStatusForOperationalDate = $nightStatusByReservationRoom->get($nightStatusKey);
                
                // Obtener stay activa para usar check_in_at real (timestamp)
                $stay = $room->getAvailabilityService()->getStayForDate($this->date);
                
                // Total contractual (SSOT absoluto)
                $reservationTotalAmount = (float)($reservation->total_amount ?? 0);
                
                // Pagos reales (SOLO positivos) - SSOT financiero
                // REGLA CRÍTICA: Separar pagos y devoluciones para coherencia
                $reservation->loadMissing(['payments']);
                $paidAmount = (float)($reservation->payments
                    ->where('amount', '>', 0)
                    ->sum('amount') ?? 0);
                
                // Obtener ReservationRoom para calcular total de noches
                $reservationRoom = $room->reservationRooms?->first(function($rr) {
                    return $rr->check_in_date <= $this->date->toDateString()
                        && $rr->check_out_date >= $this->date->toDateString();
                });
                
                // Total de noches del contrato (SSOT desde ReservationRoom)
                $totalNights = $reservationRoom?->nights ?? 1;
                if ($totalNights <= 0 && $reservationRoom) {
                    $checkIn = $reservationRoom->check_in_date ? Carbon::parse($reservationRoom->check_in_date) : null;
                    $checkOut = $reservationRoom->check_out_date ? Carbon::parse($reservationRoom->check_out_date) : null;
                    if ($checkIn && $checkOut) {
                        $totalNights = max(1, $checkIn->diffInDays($checkOut));
                    }
                }
                
                // Total contractual por habitación (si existe subtotal por habitación, usarlo).
                $roomContractTotal = $reservationTotalAmount;
                if ($reservationRoom) {
                    $roomSubtotal = (float)($reservationRoom->subtotal ?? 0);
                    if ($roomSubtotal > 0) {
                        $roomContractTotal = $roomSubtotal;
                    }
                }

                // Precio por noche DERIVADO DEL TOTAL CONTRACTUAL (NO desde tarifas)
                $pricePerNight = ($roomContractTotal > 0 && $totalNights > 0)
                    ? round($roomContractTotal / $totalNights, 2)
                    : 0;
                
                // Fechas para calcular noches consumidas
                // Priorizar stay->check_in_at (timestamp real) sobre reservationRoom->check_in_date (fecha planificada)
                if ($stay && $stay->check_in_at) {
                    $checkIn = Carbon::parse($stay->check_in_at)->startOfDay(); // Mantener startOfDay para cálculo de noches
                } elseif ($reservationRoom && $reservationRoom->check_in_date) {
                    $checkIn = Carbon::parse($reservationRoom->check_in_date)->startOfDay(); // Mantener startOfDay para cálculo de noches
                } else {
                    $checkIn = null;
                }
                
                $today = $this->date->copy()->startOfDay(); // Mantener startOfDay para cálculo de noches consumidas
                
                // Noches consumidas hasta la fecha vista (inclusive)
                // REGLA: Si hoy >= check_in, al menos 1 noche está consumida
                if ($checkIn) {
                    if ($today->lt($checkIn)) {
                        $nightsConsumed = 0;
                    } else {
                        // Noches desde check_in hasta hoy (inclusive): diffInDays + 1
                        $nightsConsumed = max(1, $checkIn->diffInDays($today) + 1);
                    }
                } else {
                    // Fallback: si no hay fecha de check-in, asumir 1 noche
                    $nightsConsumed = 1;
                }

                // No cobrar más noches que las contractuales de esta habitación.
                $nightsConsumed = min($totalNights, $nightsConsumed);
                
                // Total que debería estar pagado hasta hoy
                $expectedPaid = $pricePerNight * $nightsConsumed;
                
                // âœ… VERDAD FINAL: Noche pagada si pagos positivos >= esperado
                if ($nightStatusForOperationalDate) {
                    $room->is_night_paid = (bool) ($nightStatusForOperationalDate->is_paid ?? false);
                } else {
                    $room->is_night_paid = $expectedPaid > 0 && $paidAmount >= $expectedPaid;
                }

                // Calcular total_debt usando SSOT financiero (alineado con room-payment-info y room-detail-modal)
                // REGLA CRÍTICA: Separar pagos y devoluciones para coherencia financiera
                $refundsTotal = abs((float)($reservation->payments
                    ->where('amount', '<', 0)
                    ->sum('amount') ?? 0));
                
                // Usar total contractual por habitación como SSOT.
                $totalStay = $roomContractTotal > 0 ? $roomContractTotal : ($pricePerNight * $totalNights);
                
                // Cargar sales si no están cargadas
                $reservation->loadMissing(['sales']);
                
                $sales_debt = 0;
                if ($reservation->sales) {
                    $sales_debt = (float)$reservation->sales->where('is_paid', false)->sum('total');
                }
                
                // Fórmula alineada con room-payment-info: (total - abonos) + devoluciones + consumos
                $computedDebt = ($totalStay - $paidAmount) + $refundsTotal + $sales_debt;
                
                // Mostrar deuda contractual calculada en tiempo real para evitar desalineaciones
                // con balances legacy calculados con reglas anteriores.
                $room->total_debt = $computedDebt;
            } else {
                $room->total_debt = 0;
                $room->is_night_paid = true;
            }
            
            return $room;
        });

        // Aplicar filtros de estado operativo y limpieza (independientes)
        if ($this->statusFilter || $this->cleaningStatusFilter) {
            $selectedDate = $this->getSelectedDate();
            $rooms->setCollection(
                $rooms->getCollection()->filter(function($room) use ($selectedDate) {
                    $operationalStatus = $room->getOperationalStatus($selectedDate);
                    $cleaningCode = data_get($room->cleaningStatus($selectedDate), 'code');

                    $matchesOperationalStatus = match ((string) $this->statusFilter) {
                        'libre' => in_array($operationalStatus, ['free_clean', 'pending_cleaning'], true),
                        'ocupada' => $operationalStatus === 'occupied',
                        'pendiente_checkout' => $operationalStatus === 'pending_checkout',
                        default => true,
                    };

                    $matchesCleaningStatus = match ((string) $this->cleaningStatusFilter) {
                        'limpia' => $cleaningCode === 'limpia',
                        'pendiente' => $cleaningCode === 'pendiente',
                        'mantenimiento' => $cleaningCode === 'mantenimiento',
                        default => true,
                    };

                    return $matchesOperationalStatus && $matchesCleaningStatus;
                })
            );
        }

        // Cargar historial solo cuando se necesita (en la pestaña de historial)
        $releaseHistory = null;
        if ($this->activeTab === 'history') {
            $releaseHistory = $this->getReleaseHistory();
        }
        
        return view('livewire.room-manager', [
            'daysInMonth' => $this->daysInMonth,
            'currentDate' => $this->currentDate,
            'rooms' => $rooms,
            'releaseHistory' => $releaseHistory,
            'dailyStats' => $dailyStats,
            'receptionReservationsSummary' => $receptionReservationsSummary,
        ]);
    }

    /**
     * Sanitiza todas las propiedades publicas serializables para evitar
     * errores "Malformed UTF-8" durante la respuesta JSON de Livewire.
     */
    private function sanitizeUtf8PublicState(): void
    {
        $reflection = new \ReflectionObject($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || !$property->isInitialized($this)) {
                continue;
            }

            $name = $property->getName();
            $value = $this->{$name};
            $this->{$name} = $this->sanitizeUtf8Value($value);
        }
    }

    private function sanitizeUtf8Value(mixed $value): mixed
    {
        if (is_string($value)) {
            if (mb_check_encoding($value, 'UTF-8')) {
                return $value;
            }

            $fixed = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            if (is_string($fixed) && mb_check_encoding($fixed, 'UTF-8')) {
                return $fixed;
            }

            $fallback = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            return is_string($fallback) ? $fallback : '';
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitizeUtf8Value($item);
            }

            return $value;
        }

        return $value;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Customer;
use App\Models\Room;
use App\Models\Stay;
use App\Models\StayNight;
use App\Models\Payment;
use App\Models\RoomDailyStatus;
use App\Models\RoomReleaseHistory;
use App\Models\DianIdentificationDocument;
use App\Models\DianLegalOrganization;
use App\Models\DianCustomerTribute;
use App\Models\DianMunicipality;
use App\Models\ShiftHandover;
use App\Enums\ShiftHandoverStatus;
use App\Http\Requests\StoreReservationRequest;
use App\Services\AuditService;
use App\Services\ReservationReportService;
use App\Support\HotelTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Collection;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Throwable;

class ReservationController extends Controller
{
    public function __construct(
        private AuditService $auditService,
        private ReservationReportService $reportService
    ) {}
    /**
     * Livewire browser-events are not safe to dispatch from Controllers in all Livewire versions.
     * In some installations, LivewireManager::dispatch() does not exist and will throw a fatal Error,
     * preventing redirects (even though the DB transaction already happened).
     */
    private function safeLivewireDispatch(string $event): void
    {
        try {
            if (class_exists(\Livewire\Livewire::class)) {
                // This will work on Livewire versions that support it, otherwise may throw.
                \Livewire\Livewire::dispatch($event);
            }
        } catch (Throwable $e) {
            // Never break controller redirects because of a UI-only event.
            \Log::warning("Livewire dispatch skipped in controller for event: {$event}", [
                'exception' => $e,
            ]);
        }
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, $date = null)
    {
        $view = $request->get('view', 'calendar');
        $dateStr = $request->get('month', HotelTime::currentOperationalDate()->format('Y-m'));
        $date = Carbon::createFromFormat('Y-m', $dateStr);

        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        $daysInMonth = [];
        $tempDate = $startOfMonth->copy();
        while ($tempDate <= $endOfMonth) {
            $daysInMonth[] = $tempDate->copy();
            $tempDate->addDay();
        }

        $today = HotelTime::currentOperationalDate()->startOfDay();
        $hasPastDates = $startOfMonth->lt($today);
        
        // Load snapshots for past dates (immutable historical data)
        // ✅ CORRECCIÓN CRÍTICA: Cargar TODAS reservationRooms y stays
        $roomsQuery = Room::with([
            'reservationRooms' => function ($query) {
                // Load active and soft-deleted reservations for calendar traceability.
                $query->with([
                    'reservation' => function ($reservationQuery) {
                        $reservationQuery
                            ->withTrashed()
                            ->with(['customer', 'reservationRooms.room', 'payments'])
                            ->withSum('stayNights as stay_nights_total', 'price');
                    },
                ]);
            },
            'stays' => function ($query) {
                // Load all stays and reservation metadata used in calendar rendering.
                $query->with([
                    'reservation.customer',
                    'reservation.reservationRooms.room',
                    'reservation.payments',
                ]);
            },
        ]);
        
        // Load daily statuses for past dates if viewing past months
        if ($hasPastDates) {
            $roomsQuery->with([
                'dailyStatuses' => function ($query) use ($startOfMonth, $endOfMonth, $today) {
                    // Only load snapshots for dates that are in the past (before today)
                    $query->whereDate('date', '>=', $startOfMonth->toDateString())
                          ->whereDate('date', '<=', $endOfMonth->toDateString())
                          ->whereDate('date', '<', $today->toDateString());
                }
            ]);
        }
        
        $rooms = $roomsQuery->orderBy('room_number')->get();

        // Asegurarse de que el status se maneje como string para la vista si es necesario,
        // aunque Blade puede manejar el enum.

        $reservations = Reservation::withTrashed()
            ->with([
                'customer',
                'reservationRooms.room',
                'payments',
                'stays' => function ($query) {
                    $query->whereIn('status', ['active', 'pending_checkout']);
                },
            ])
            ->withSum('stayNights as stay_nights_total', 'price')
            ->latest()
            ->paginate(10);

        // Prepare data for create reservation modal
        try {
            $customers = Customer::withoutGlobalScopes()
                ->with('taxProfile')
                ->orderBy('name')
                ->get();

            $roomsForModal = Room::active()->get();
            $roomsData = $this->prepareRoomsData($roomsForModal);
            $dianCatalogs = $this->getDianCatalogs();

            // Prepare customers as simple array for Livewire
            $customersArray = $customers->map(function ($customer) {
                return [
                    'id' => (int) $customer->id,
                    'name' => (string) ($customer->name ?? ''),
                    'phone' => (string) ($customer->phone ?? 'S/N'),
                    'email' => $customer->email ? (string) $customer->email : null,
                    'taxProfile' => $customer->taxProfile ? [
                        'identification' => (string) ($customer->taxProfile->identification ?? 'S/N'),
                        'dv' => $customer->taxProfile->dv ? (string) $customer->taxProfile->dv : null,
                    ] : null,
                ];
            })->toArray();

            // Prepare rooms as simple array for Livewire
            $roomsArray = $roomsForModal->map(function ($room) {
                return [
                    'id' => (int) $room->id,
                    'room_number' => (string) ($room->room_number ?? ''),
                    'beds_count' => (int) ($room->beds_count ?? 0),
                    'max_capacity' => (int) ($room->max_capacity ?? 0),
                ];
            })->toArray();

            // Prepare DIAN catalogs as arrays
            $dianCatalogsArray = [
                'identificationDocuments' => $dianCatalogs['identificationDocuments']->toArray(),
                'legalOrganizations' => $dianCatalogs['legalOrganizations']->toArray(),
                'tributes' => $dianCatalogs['tributes']->toArray(),
                'municipalities' => $dianCatalogs['municipalities']->toArray(),
            ];

            return view('reservations.index', array_merge(
                compact(
                    'reservations',
                    'rooms',
                    'daysInMonth',
                    'view',
                    'date'
                ),
                [
                    'modalCustomers' => $customersArray,
                    'modalRooms' => $roomsArray,
                    'modalRoomsData' => $roomsData,
                    'modalIdentificationDocuments' => $dianCatalogsArray['identificationDocuments'],
                    'modalLegalOrganizations' => $dianCatalogsArray['legalOrganizations'],
                    'modalTributes' => $dianCatalogsArray['tributes'],
                    'modalMunicipalities' => $dianCatalogsArray['municipalities'],
                ]
            ));
        } catch (\Exception $e) {
            \Log::error('Error preparing modal data in ReservationController::index(): ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            // Return view with empty arrays for modal data
            return view('reservations.index', array_merge(
                compact(
                    'reservations',
                    'rooms',
                    'daysInMonth',
                    'view',
                    'date'
                ),
                [
                    'modalCustomers' => [],
                    'modalRooms' => [],
                    'modalRoomsData' => [],
                    'modalIdentificationDocuments' => [],
                    'modalLegalOrganizations' => [],
                    'modalTributes' => [],
                    'modalMunicipalities' => [],
                ]
            ));
        }
    }

    /**
     * Show the form for creating a new resource.
     * Always returns arrays, never null values.
     */
    public function create()
    {
        try {
            // Fetch customers with error handling
            $customers = Customer::withoutGlobalScopes()
                ->with('taxProfile')
                ->orderBy('name')
                ->get();

            // Fetch rooms with error handling
            $rooms = Room::active()->get();

            // Prepare rooms data for frontend (always returns array)
            $roomsData = $this->prepareRoomsData($rooms);

            // Get DIAN catalogs for customer creation modal (always returns valid collections)
            $dianCatalogs = $this->getDianCatalogs();

            // Prepare customers as simple array for Livewire
            // Ensure structure matches what Livewire expects
            $customersArray = $customers->map(function ($customer) {
                return [
                    'id' => (int) $customer->id,
                    'name' => (string) ($customer->name ?? ''),
                    'phone' => (string) ($customer->phone ?? 'S/N'),
                    'email' => $customer->email ? (string) $customer->email : null,
                    'taxProfile' => $customer->taxProfile ? [
                        'identification' => (string) ($customer->taxProfile->identification ?? 'S/N'),
                        'dv' => $customer->taxProfile->dv ? (string) $customer->taxProfile->dv : null,
                    ] : null,
                ];
            })->toArray();

// Ensure customersArray is always an array
            if (!is_array($customersArray)) {
                $customersArray = [];
            }

            // Prepare rooms as simple array for Livewire
            // Ensure structure matches what Livewire expects
            $roomsArray = $rooms->map(function ($room) {
                return [
                    'id' => (int) $room->id,
                    'room_number' => (string) ($room->room_number ?? ''),
                    'beds_count' => (int) ($room->beds_count ?? 0),
                    'max_capacity' => (int) ($room->max_capacity ?? 0),
                ];
            })->toArray();

            // Ensure roomsArray is always an array
            if (!is_array($roomsArray)) {
                $roomsArray = [];
            }

            // Ensure roomsData is always an array
            if (!is_array($roomsData)) {
                $roomsData = [];
            }

            // Prepare DIAN catalogs as arrays
            // Ensure all catalogs are arrays, never null
            $dianCatalogsArray = [
                'identificationDocuments' => $dianCatalogs['identificationDocuments']->toArray(),
                'legalOrganizations' => $dianCatalogs['legalOrganizations']->toArray(),
                'tributes' => $dianCatalogs['tributes']->toArray(),
                'municipalities' => $dianCatalogs['municipalities']->toArray(),
            ];

            // Ensure all catalog arrays are valid
            foreach ($dianCatalogsArray as $key => $value) {
                if (!is_array($value)) {
                    $dianCatalogsArray[$key] = [];
                }
            }

            return view('reservations.create', array_merge(
                [
                    'customers' => $customersArray,
                    'rooms' => $roomsArray,
                    'roomsData' => $roomsData,
                ],
                $dianCatalogsArray
            ));
        } catch (\Exception $e) {
            \Log::error('Error in ReservationController::create(): ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            // Return view with empty arrays to prevent 500 errors
            return view('reservations.create', [
    'customers' => [],
                'rooms' => [],
                'roomsData' => [],
                'identificationDocuments' => [],
                'legalOrganizations' => [],
                'tributes' => [],
                'municipalities' => [],
            ])->withErrors(['error' => 'Error al cargar los datos. Por favor, recarga la página.']);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreReservationRequest $request)
    {
        try {
            // 🔥 DEBUG: Log the incoming request data for debugging
            \Log::error('=== INICIO CREACIÓN RESERVA ===');
            \Log::error('Reservation store request', [
                'all_data' => $request->all(),
                'client_id' => $request->customerId,
                'room_id' => $request->room_id,
                'room_ids' => $request->room_ids,
                'total_amount' => $request->total_amount,
                'deposit' => $request->deposit,
                'guests_count' => $request->guests_count,
                'check_in_date' => $request->check_in_date,
                'check_out_date' => $request->check_out_date,
            ]);

            $data = $request->validated();
            \Log::error('Datos validados:', $data);

            // Determine if using multiple rooms or single room (backward compatibility)
            $roomIds = $request->has('room_ids') && is_array($request->room_ids)
                ? $request->room_ids
                : ($request->room_id ? [$request->room_id] : []);

            \Log::error('Room IDs determinados:', $roomIds);

            if (empty($roomIds)) {
                \Log::error('ERROR: No hay habitaciones seleccionadas');
                return back()->withInput()->withErrors(['room_id' => 'Debe seleccionar al menos una habitación.']);
            }

            // Validate dates and availability
            $checkInDate = Carbon::parse($request->check_in_date);
            $checkOutDate = Carbon::parse($request->check_out_date);

            \Log::error('Fechas procesadas:', [
                'check_in' => $checkInDate->format('Y-m-d H:i'),
                'check_out' => $checkOutDate->format('Y-m-d H:i'),
                'is_today_checkin' => $checkInDate->isToday(),
            ]);

            $dateValidation = $this->validateDates($checkInDate, $checkOutDate);
            if (!$dateValidation['valid']) {
                \Log::error('ERROR: Validación de fechas falló:', $dateValidation['errors']);
                return back()->withInput()->withErrors($dateValidation['errors']);
            }

            \Log::error('Validación de fechas OK');

            // 🔥 MVP: Validación de disponibilidad pospuesta para Fase 2
            // Validate availability for all rooms
            // $availabilityErrors = $this->validateRoomsAvailability($roomIds, $checkInDate, $checkOutDate);
            // if (!empty($availabilityErrors)) {
            //     return back()->withInput()->withErrors($availabilityErrors);
            // }

            // 🔥 MVP: Validación de asignación de huéspedes pospuesta para Fase 2
            // Validate guest assignment
            // $roomGuests = $request->room_guests ?? [];

            // Normalize roomGuests keys to integers (form sends them as strings)
            // $normalizedRoomGuests = [];
            // foreach ($roomGuests as $roomId => $assignedGuestIds) {
            //     $roomIdInt = is_numeric($roomId) ? (int) $roomId : 0;
            //     if ($roomIdInt > 0) {
            //         $normalizedRoomGuests[$roomIdInt] = $assignedGuestIds;
            //     }
            // }

            // $rooms = Room::whereIn('id', $roomIds)->get()->keyBy('id');

            // $guestValidationErrors = $this->validateGuestAssignment($normalizedRoomGuests, $rooms);
            // if (!empty($guestValidationErrors)) {
            //     return back()->withInput()->withErrors($guestValidationErrors);
            // }

            // Remove payment_method from data if not provided (it's optional)
            if (!isset($data['payment_method']) || empty($data['payment_method'])) {
                unset($data['payment_method']);
            }

            // For backward compatibility, use first room_id for the room_id field
            $data['room_id'] = $roomIds[0];

            \Log::error('Datos finales para crear reserva:', $data);

            $reservation = Reservation::create($data);
            $initialDeposit = round((float) ($data['deposit'] ?? 0), 2);

            if ($initialDeposit > 0) {
                $paymentMethodCode = strtolower(trim((string) ($data['payment_method'] ?? 'efectivo')));
                if (!in_array($paymentMethodCode, ['efectivo', 'transferencia'], true)) {
                    $paymentMethodCode = 'efectivo';
                }

                $paymentMethodId = $this->resolvePaymentMethodId($paymentMethodCode);

                Payment::create([
                    'reservation_id' => $reservation->id,
                    'amount' => $initialDeposit,
                    'payment_method_id' => $paymentMethodId,
                    'bank_name' => $paymentMethodCode === 'transferencia' ? ($request->bank_name ?? null) : null,
                    'reference' => $paymentMethodCode === 'transferencia'
                        ? ($request->reference ?? 'Abono inicial de reserva')
                        : 'Abono inicial de reserva',
                    'paid_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            }

            $totalAmount = round((float) ($reservation->total_amount ?? $data['total_amount'] ?? 0), 2);
            $paymentsTotal = round((float) ($reservation->payments()->sum('amount') ?? 0), 2);
            $balanceDue = max(0, $totalAmount - $paymentsTotal);
            $paymentStatusCode = $balanceDue <= 0
                ? 'paid'
                : ($paymentsTotal > 0 ? 'partial' : 'pending');
            $paymentStatusId = DB::table('payment_statuses')
                ->where('code', $paymentStatusCode)
                ->value('id');

            $reservation->update([
                'deposit_amount' => $paymentsTotal,
                'balance_due' => $balanceDue,
                'payment_status_id' => !empty($paymentStatusId)
                    ? (int) $paymentStatusId
                    : $reservation->payment_status_id,
            ]);
            
            \Log::error('✅ RESERVA CREADA - ID: ' . $reservation->id);
            \Log::error('Datos de reserva creada:', [
                'id' => $reservation->id,
                'client_id' => $reservation->client_id,
                'room_id' => $reservation->room_id,
                'total_amount' => $reservation->total_amount,
                'deposit' => $reservation->deposit,
            ]);

            // 🔥 MVP: Asignación de huéspedes pospuesta para Fase 2
            // Attach all rooms to reservation via pivot table
            foreach ($roomIds as $roomId) {
                \Log::error("Creando ReservationRoom para habitación {$roomId}");
                
                // ✅ CORRECCIÓN CRÍTICA: Guardar fechas en reservation_rooms
                $reservationRoom = ReservationRoom::create([
                    'reservation_id' => $reservation->id,
                    'room_id' => $roomId,
                    'check_in_date' => $checkInDate->toDateString(),
                    'check_out_date' => $checkOutDate->toDateString(),
                    'check_in_time' => $request->check_in_time ?? null,
                    'check_out_time' => $request->check_out_time ?? null,
                    'nights' => max(1, $checkInDate->diffInDays($checkOutDate)),
                    'price_per_night' => 0, // 🔥 MVP: Valor temporal para evitar NULL
                    'subtotal' => 0, // 🔥 MVP: Valor temporal para evitar NULL
                ]);

                \Log::error("✅ ReservationRoom creado - ID: {$reservationRoom->id}");
                \Log::error("Datos del ReservationRoom:", [
                    'id' => $reservationRoom->id,
                    'reservation_id' => $reservationRoom->reservation_id,
                    'room_id' => $reservationRoom->room_id,
                    'check_in_date' => $reservationRoom->check_in_date,
                    'check_out_date' => $reservationRoom->check_out_date,
                    'check_in_time' => $reservationRoom->check_in_time,
                    'nights' => $reservationRoom->nights,
                ]);

                // 🔥 MVP: Asignación de huéspedes a habitación específica pospuesta para Fase 2
                // Assign guests to this specific room if provided (use normalized array)
                // $roomIdInt = is_numeric($roomId) ? (int) $roomId : 0;
                // $this->assignGuestsToRoom($reservationRoom, $normalizedRoomGuests[$roomIdInt] ?? []);
            }

            // 🔥 MVP: NO crear Stay automáticamente - solo al check-in real
            // 🔥 AJUSTE CRÍTICO 1 y 2: Crear Stay y StayNight si check-in es HOY
            // if ($checkInDate->isToday()) {
            //     \Log::error('🔥 Check-in es HOY - Creando Stay activa');
            //     foreach ($roomIds as $roomId) {
            //         \Log::error("Creando Stay para habitación {$roomId}");
                    
            //         // Crear Stay activa (check-in inmediato)
            //         $stay = \App\Models\Stay::create([
            //             'reservation_id' => $reservation->id,
            //             'room_id' => $roomId,
            //             'check_in_at' => now(), // Check-in INMEDIATO (timestamp)
            //             'check_out_at' => null, // Se completará al checkout
            //             'status' => 'active', // Estados: active, pending_checkout, finished
            //         ]);

            //         \Log::error("✅ Stay creado - ID: {$stay->id}");
            //         \Log::error("Datos del Stay:", [
            //             'id' => $stay->id,
            //             'reservation_id' => $stay->reservation_id,
            //             'room_id' => $stay->room_id,
            //             'check_in_at' => $stay->check_in_at?->format('Y-m-d H:i:s'),
            //             'check_out_at' => $stay->check_out_at,
            //             'status' => $stay->status,
            //         ]);

            //         // 🔥 Generar la primera noche (StayNight)
            //         $this->ensureNightForDate($stay, $checkInDate);

            //         // 🔥 AJUSTE CRÍTICO 3: Estado de limpieza pendiente_aseo
            //         // Poner last_cleaned_at = null para que quede en estado pendiente_aseo
            //         Room::where('id', $roomId)->update([
            //             'last_cleaned_at' => null, // Pendiente por aseo
            //         ]);
                    
            //         \Log::error("Habitación {$roomId} marcada como pendiente de limpieza");
            //     }
            // } else {
            //     \Log::error('Check-in NO es hoy - no se crea Stay activa');
            // }

            \Log::error('🔥 MVP: Stay NO creado - la reserva solo existe en tabla reservations');
            \Log::error('🔥 El Stay se creará solo cuando el huésped haga check-in real');

            // Audit log for reservation creation
            $this->auditService->logReservationCreated($reservation, $request, $roomIds);

            \Log::error('🔥 Generando room_daily_statuses_data para el calendario');
            // 🔥 CRÍTICO: Generar room_daily_statuses_data para el calendario
            $roomDailyStatusService = app(\App\Services\RoomDailyStatusService::class);
            $roomDailyStatusService->generateForReservation($reservation);

            // Dispatch Livewire event for stats update
            $this->safeLivewireDispatch('reservation-created');

            \Log::error('=== FIN CREACIÓN RESERVA EXITOSA ===');
            \Log::error("Redirigiendo a calendario del mes: {$checkInDate->format('Y-m')}");

            // Redirect to reservations index with calendar view for the check-in month
            $month = $checkInDate->format('Y-m');
            return redirect()->route('reservations.index', ['view' => 'calendar', 'month' => $month])
                ->with('success', 'Reserva registrada exitosamente.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Re-throw validation exceptions to let Laravel handle them properly
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Error creating reservation: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            return back()->withInput()->withErrors(['error' => 'Error al crear la reserva: ' . $e->getMessage()]);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Reservation $reservation)
    {
        return view('reservations.show', compact('reservation'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Reservation $reservation)
    {
        try {
            $reservation->load([
                'customer.taxProfile',
                'rooms',
                'reservationRooms.room',
                'reservationRooms.guests.taxProfile',
            ]);

            $customers = Customer::withoutGlobalScopes()
                ->with('taxProfile')
                ->orderBy('name')
                ->get();

            $rooms = Room::active()->get();

            $roomsData = $this->prepareRoomsData($rooms);

            $customersArray = $customers->map(function ($customer) {
                return [
                    'id' => (int) $customer->id,
                    'name' => (string) ($customer->name ?? ''),
                    'phone' => (string) ($customer->phone ?? 'S/N'),
                    'email' => $customer->email ? (string) $customer->email : null,
                    'taxProfile' => $customer->taxProfile ? [
                        'identification' => (string) ($customer->taxProfile->identification ?? 'S/N'),
                        'dv' => $customer->taxProfile->dv ? (string) $customer->taxProfile->dv : null,
                    ] : null,
                ];
            })->toArray();

            if (!is_array($customersArray)) {
                $customersArray = [];
            }

            $roomsArray = $rooms->map(function ($room) {
                return [
                    'id' => (int) $room->id,
                    'room_number' => (string) ($room->room_number ?? ''),
                    'beds_count' => (int) ($room->beds_count ?? 0),
                    'max_capacity' => (int) ($room->max_capacity ?? 0),
                ];
            })->toArray();

            if (!is_array($roomsArray)) {
                $roomsArray = [];
            }

            if (!is_array($roomsData)) {
                $roomsData = [];
            }

            $dianCatalogs = $this->getDianCatalogs();
            $dianCatalogsArray = [
                'identificationDocuments' => $dianCatalogs['identificationDocuments']->toArray(),
                'legalOrganizations' => $dianCatalogs['legalOrganizations']->toArray(),
                'tributes' => $dianCatalogs['tributes']->toArray(),
                'municipalities' => $dianCatalogs['municipalities']->toArray(),
            ];

            foreach ($dianCatalogsArray as $key => $value) {
                if (!is_array($value)) {
                    $dianCatalogsArray[$key] = [];
                }
            }

            return view('reservations.edit', array_merge(
                [
                    'reservation' => $reservation,
                    'customers' => $customersArray,
                    'rooms' => $roomsArray,
                    'roomsData' => $roomsData,
                ],
                $dianCatalogsArray
            ));
        } catch (\Exception $e) {
            \Log::error('Error in ReservationController::edit(): ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
                'reservation_id' => $reservation->id,
            ]);

            return back()->withErrors(['error' => 'Error al cargar los datos. Por favor, recarga la página.']);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreReservationRequest $request, Reservation $reservation)
    {
        try {
            $data = $request->validated();

            $roomIds = $request->has('room_ids') && is_array($request->room_ids)
                ? $request->room_ids
                : ($request->room_id ? [$request->room_id] : []);

            if (empty($roomIds)) {
                return back()->withInput()->withErrors(['room_id' => 'Debe seleccionar al menos una habitación.']);
            }

            $checkInDate = Carbon::parse($request->check_in_date);
            $checkOutDate = Carbon::parse($request->check_out_date);

            $dateValidation = $this->validateDates($checkInDate, $checkOutDate);
            if (!$dateValidation['valid']) {
                return back()->withInput()->withErrors($dateValidation['errors']);
            }

            $availabilityErrors = $this->validateRoomsAvailabilityForUpdate(
                $roomIds,
                $checkInDate,
                $checkOutDate,
                (int) $reservation->id
            );
            if (!empty($availabilityErrors)) {
                return back()->withInput()->withErrors($availabilityErrors);
            }

            $roomGuests = $request->room_guests ?? [];
            $normalizedRoomGuests = [];
            foreach ($roomGuests as $roomId => $assignedGuestIds) {
                $roomIdInt = is_numeric($roomId) ? (int) $roomId : 0;
                if ($roomIdInt > 0) {
                    $normalizedRoomGuests[$roomIdInt] = $assignedGuestIds;
                }
            }

            $rooms = Room::whereIn('id', $roomIds)->get()->keyBy('id');
            $guestValidationErrors = $this->validateGuestAssignment($normalizedRoomGuests, $rooms);
            if (!empty($guestValidationErrors)) {
                return back()->withInput()->withErrors($guestValidationErrors);
            }

            if (!isset($data['payment_method']) || empty($data['payment_method'])) {
                unset($data['payment_method']);
            }

            $oldValues = [
                'room_id' => $reservation->room_id,
                'check_in_date' => $reservation->check_in_date?->format('Y-m-d'),
                'check_out_date' => $reservation->check_out_date?->format('Y-m-d'),
                'total_amount' => (float) $reservation->total_amount,
                'deposit' => (float) $reservation->deposit,
                'guests_count' => (int) $reservation->guests_count,
                'payment_method' => $reservation->payment_method,
            ];

            DB::beginTransaction();

            // Backward compatibility: use first room id as the reservation room_id.
            $data['room_id'] = $roomIds[0];
            $reservation->update($data);

            // Rebuild room assignments and room guests.
            $reservation->reservationRooms()->delete();

            foreach ($roomIds as $roomId) {
                $reservationRoom = ReservationRoom::create([
                    'reservation_id' => $reservation->id,
                    'room_id' => $roomId,
                ]);

                $roomIdInt = is_numeric($roomId) ? (int) $roomId : 0;
                $this->assignGuestsToRoom($reservationRoom, $normalizedRoomGuests[$roomIdInt] ?? []);
            }

            // Legacy guests (single room): sync to avoid duplicate rows.
            if ($request->has('guest_ids') && is_array($request->guest_ids) && !$request->has('room_guests')) {
                $this->syncGuestsToReservationLegacy($reservation, $request->guest_ids);
            }

            if ($checkInDate->isToday()) {
                Room::whereIn('id', $roomIds)->update(['status' => \App\Enums\RoomStatus::OCUPADA]);
            }

            $reservation->refresh();

            $this->auditService->logReservationUpdated($reservation, $request, $oldValues);
            $this->safeLivewireDispatch('reservation-updated');

            DB::commit();

            return redirect()->route('reservations.index')->with('success', 'Reserva actualizada correctamente.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error updating reservation: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'reservation_id' => $reservation->id,
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Error al actualizar la reserva: ' . $e->getMessage()]);
        }
    }

    private function validateRoomsAvailabilityForUpdate(
        array $roomIds,
        Carbon $checkIn,
        Carbon $checkOut,
        int $excludeReservationId
    ): array {
        $errors = [];

        foreach ($roomIds as $roomId) {
            $roomIdInt = is_numeric($roomId) ? (int) $roomId : 0;
            if ($roomIdInt <= 0) {
                continue;
            }

            if (!$this->isRoomAvailable($roomIdInt, $checkIn, $checkOut, $excludeReservationId)) {
                $room = Room::find($roomIdInt);
                $roomNumber = $room ? $room->room_number : $roomIdInt;
                $errors['room_ids'][] = "La habitación {$roomNumber} ya está reservada para las fechas seleccionadas.";
            }
        }

        return $errors;
    }

    private function syncGuestsToReservationLegacy(Reservation $reservation, array $guestIds): void
    {
        $validGuestIds = array_filter($guestIds, function ($id): bool {
            return !empty($id) && is_numeric($id) && $id > 0;
        });

        if (empty($validGuestIds)) {
            $reservation->guests()->sync([]);
            return;
        }

        $validGuestIds = Customer::withoutGlobalScopes()
            ->whereIn('id', $validGuestIds)
            ->pluck('id')
            ->toArray();

        $reservation->guests()->sync($validGuestIds);
    }

    /**
     * Get release data for a reservation (for showing release confirmation modal).
     */
    public function getReleaseData(Reservation $reservation)
    {
        $today = Carbon::today()->startOfDay();
        $date = $today; // Always use today for cancellation
        
        $reservation->load(['customer.taxProfile', 'sales.product', 'reservationDeposits', 'guests.taxProfile', 'rooms']);
        
        // Get first room (for multi-room reservations, use first room)
        $room = $reservation->rooms->first() ?? $reservation->room;
        
        if (!$room) {
            return response()->json([
                'room_id' => null,
                'room_number' => 'N/A',
                'reservation' => null,
                'identification' => 'N/A',
                'total_hospedaje' => 0,
                'abono_realizado' => 0,
                'sales_total' => 0,
                'consumos_pendientes' => 0,
                'total_debt' => 0,
                'total_refunds' => 0,
                'refund_registered' => false,
                'sales' => [],
                'deposit_history' => [],
                'refunds_history' => [],
            ]);
        }
        
        $total_hospedaje_completo = (float) $reservation->total_amount;
        $abono = (float) $reservation->deposit;
        $consumos_pagados = (float) $reservation->sales->where('is_paid', true)->sum('total');
        $consumos_pendientes = (float) $reservation->sales->where('is_paid', false)->sum('total');
        
        $checkIn = Carbon::parse($reservation->check_in_date);
        $checkOut = Carbon::parse($reservation->check_out_date);
        $daysTotal = max(1, $checkIn->diffInDays($checkOut));
        $dailyPrice = $daysTotal > 0 ? ($total_hospedaje_completo / $daysTotal) : $total_hospedaje_completo;
        
        // Calculate consumed lodging up to yesterday (since we're cancelling today)
        $yesterday = $today->copy()->subDay();
        $daysConsumed = $checkIn->diffInDays($yesterday) + 1;
        $daysConsumed = max(1, min($daysTotal, $daysConsumed));
        $total_hospedaje = $dailyPrice * $daysConsumed;
        
        // Get refunds history
        $refundsHistory = \App\Models\AuditLog::where('event', 'customer_refund_registered')
            ->whereRaw('JSON_EXTRACT(metadata, "$.reservation_id") = ?', [$reservation->id])
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($log) {
                return [
                    'id' => $log->id,
                    'amount' => (float) ($log->metadata['refund_amount'] ?? 0),
                    'created_at' => $log->created_at->format('d/m/Y H:i'),
                    'created_by' => $log->user->name ?? 'N/A',
                ];
            })
            ->toArray();
        
        $totalRefunds = collect($refundsHistory)->sum('amount');
        
        // Calculate debt (consumed lodging - deposit + pending consumptions)
        $total_debt = ($total_hospedaje - $abono) + $consumos_pendientes;
        
        return response()->json([
            'room_id' => $room->id,
            'room_number' => $room->room_number,
            'reservation' => [
                'id' => $reservation->id,
                'customer' => [
                    'name' => $reservation->customer->name,
                ],
            ],
            'identification' => $reservation->customer->taxProfile?->identification ?? 'N/A',
            'total_hospedaje' => $total_hospedaje,
            'abono_realizado' => $abono,
            'sales_total' => $consumos_pagados + $consumos_pendientes,
            'consumos_pendientes' => $consumos_pendientes,
            'total_debt' => $total_debt,
            'refunds_history' => $refundsHistory,
            'total_refunds' => $totalRefunds,
            'sales' => $reservation->sales->map(function($sale) {
                return [
                    'id' => $sale->id,
                    'product' => [
                        'name' => $sale->product->name ?? 'N/A',
                    ],
                    'quantity' => $sale->quantity,
                    'total' => (float) $sale->total,
                    'is_paid' => $sale->is_paid,
                    'payment_method' => $sale->payment_method,
                ];
            })->toArray(),
            'deposit_history' => $reservation->reservationDeposits()
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($deposit) {
                    return [
                        'id' => $deposit->id,
                        'amount' => (float) $deposit->amount,
                        'payment_method' => $deposit->payment_method,
                        'notes' => $deposit->notes,
                        'created_at' => $deposit->created_at->format('d/m/Y H:i'),
                    ];
                })
                ->toArray(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Reservation $reservation)
    {
        $reservation->delete();

        // Revertir abonos del turno activo al cancelar la reserva
        $activeShift = ShiftHandover::where('entregado_por', Auth::id())
            ->where('status', ShiftHandoverStatus::ACTIVE)
            ->first();

        if ($activeShift && $activeShift->started_at) {
            $shiftStart = $activeShift->started_at;
            $shiftEnd = now();

            $paymentsInShift = Payment::where('reservation_id', $reservation->id)
                ->where('amount', '>', 0)
                ->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$shiftStart, $shiftEnd])
                ->get();

            foreach ($paymentsInShift as $payment) {
                Payment::create([
                    'reservation_id' => $reservation->id,
                    'amount'          => -abs((float) $payment->amount),
                    'payment_method_id' => $payment->payment_method_id,
                    'paid_at'         => now(),
                    'created_by'      => Auth::id(),
                    'notes'           => 'Reversión por cancelación de reserva ' . ($reservation->reservation_code ?? '#' . $reservation->id),
                ]);
            }

            if ($paymentsInShift->isNotEmpty()) {
                $activeShift->updateTotals();
            }
        }

        // Audit log for reservation cancellation
        $this->auditService->logReservationCancelled($reservation, request());

        // Dispatch Livewire event for stats update
        $this->safeLivewireDispatch('reservation-cancelled');

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => 'Reserva cancelada correctamente.',
            ]);
        }

        return redirect()->route('reservations.index')->with('success', 'Reserva cancelada correctamente.');
    }

    /**
     * Register real guest arrival (check-in) for a reservation.
     * Creates active stays for assigned rooms and marks reservation as checked_in.
     */
    public function checkIn(Request $request, Reservation $reservation)
    {
        $respondError = function (string $message, int $status = 422) use ($request) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $message], $status);
            }

            return back()->withErrors(['check_in' => $message]);
        };

        try {
            if (method_exists($reservation, 'trashed') && $reservation->trashed()) {
                return $respondError('No se puede registrar check-in para una reserva cancelada.');
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
                return $respondError('La reserva no tiene habitaciones asignadas para hacer check-in.');
            }

            $earliestCheckInDate = $reservation->reservationRooms
                ->pluck('check_in_date')
                ->filter()
                ->map(static fn ($date) => Carbon::parse((string) $date)->startOfDay())
                ->sortBy(static fn (Carbon $date) => $date->timestamp)
                ->first();

            if ($earliestCheckInDate && Carbon::today()->lt($earliestCheckInDate)) {
                return $respondError(
                    'No se puede registrar check-in antes de la fecha programada (' .
                    $earliestCheckInDate->format('d/m/Y') . ').'
                );
            }

            foreach ($roomIds as $roomId) {
                $conflictingStay = Stay::query()
                    ->where('room_id', $roomId)
                    ->whereIn('status', ['active', 'pending_checkout'])
                    ->where('reservation_id', '!=', $reservation->id)
                    ->first();

                if ($conflictingStay) {
                    $roomNumber = $reservation->reservationRooms
                        ->firstWhere('room_id', $roomId)?->room?->room_number
                        ?? Room::find($roomId)?->room_number
                        ?? (string) $roomId;

                    return $respondError(
                        "La habitación {$roomNumber} ya está ocupada por otra estadía activa."
                    );
                }
            }

            $createdAnyStay = false;
            DB::transaction(function () use ($reservation, $roomIds, &$createdAnyStay) {
                foreach ($roomIds as $roomId) {
                    $existingStay = Stay::query()
                        ->where('reservation_id', $reservation->id)
                        ->where('room_id', $roomId)
                        ->whereIn('status', ['active', 'pending_checkout'])
                        ->first();

                    if ($existingStay) {
                        continue;
                    }

                    Stay::create([
                        'reservation_id' => $reservation->id,
                        'room_id' => $roomId,
                        'check_in_at' => now(),
                        'check_out_at' => null,
                        'status' => 'active',
                    ]);
                    $createdAnyStay = true;
                }

                // Generar cobertura completa de noches de todas las habitaciones de la reserva.
                $this->ensureStayNightsCoverageForReservation($reservation);
                // Sincronizar estado pagado de noches usando pagos ya existentes (abonos previos al check-in).
                $this->rebuildStayNightPaidStateFromPayments($reservation);
                $this->syncReservationFinancials($reservation);

                $checkedInStatusId = DB::table('reservation_statuses')
                    ->where('code', 'checked_in')
                    ->value('id');

                if (!empty($checkedInStatusId)) {
                    $reservation->status_id = (int) $checkedInStatusId;
                    $reservation->save();
                }
            });

            $this->safeLivewireDispatch('reservation-updated');

            $message = $createdAnyStay
                ? 'Check-in registrado correctamente.'
                : 'La reserva ya tenía check-in registrado.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => true, 'message' => $message]);
            }

            return back()->with('success', $message);
        } catch (\Throwable $e) {
            Log::error('Error registering reservation check-in', [
                'reservation_id' => $reservation->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $respondError('No fue posible registrar el check-in. Intenta nuevamente.', 500);
        }
    }

    /**
     * Register a payment (full or partial) for an arrived reservation.
     * The payment is reflected on stay nights by marking nights as paid in chronological order.
     */
    public function registerPayment(Request $request, Reservation $reservation)
    {
        $respondError = function (string $message, int $status = 422) use ($request) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $message], $status);
            }

            return back()->withErrors(['payment' => $message]);
        };

        try {
            if (method_exists($reservation, 'trashed') && $reservation->trashed()) {
                return $respondError('No se puede registrar pagos sobre una reserva cancelada.');
            }

            $validator = Validator::make($request->all(), [
                'amount' => ['required', 'numeric', 'min:0.01'],
                'payment_method' => ['required', 'string', 'in:efectivo,transferencia'],
                'bank_name' => ['nullable', 'string', 'max:120', 'required_if:payment_method,transferencia'],
                'reference' => ['nullable', 'string', 'max:120', 'required_if:payment_method,transferencia'],
                'night_date' => ['nullable', 'date'],
            ]);

            if ($validator->fails()) {
                return $respondError((string) $validator->errors()->first(), 422);
            }

            $validated = $validator->validated();
            $amount = (float) ($validated['amount'] ?? 0);
            $paymentMethodCode = (string) ($validated['payment_method'] ?? '');
            $bankName = !empty($validated['bank_name']) ? (string) $validated['bank_name'] : null;
            $reference = !empty($validated['reference']) ? (string) $validated['reference'] : null;
            $nightDate = !empty($validated['night_date']) ? (string) $validated['night_date'] : null;

            $hasArrival = Stay::query()
                ->where('reservation_id', $reservation->id)
                ->whereIn('status', ['active', 'pending_checkout'])
                ->exists();

            if (!$hasArrival) {
                return $respondError('Solo puedes registrar pagos cuando la reserva ya tiene check-in (Llego).');
            }

            $paymentMethodId = $this->resolvePaymentMethodId($paymentMethodCode);
            if (!$paymentMethodId) {
                return $respondError('No fue posible resolver el método de pago seleccionado.', 500);
            }

            $reservation->loadMissing(['reservationRooms', 'stays']);
            $this->ensureStayNightsCoverageForReservation($reservation);
            $this->rebuildStayNightPaidStateFromPayments($reservation);

            $totalLodging = $this->calculateReservationLodgingTotal($reservation);

            $paymentsTotalBefore = (float) Payment::query()
                ->where('reservation_id', $reservation->id)
                ->sum('amount');

            $balanceBefore = max(0, $totalLodging - $paymentsTotalBefore);
            if ($balanceBefore <= 0) {
                return $respondError('La reserva ya está completamente saldada.');
            }

            if ($amount > $balanceBefore) {
                return $respondError(
                    'El monto no puede ser mayor al saldo pendiente ($' . number_format($balanceBefore, 0, ',', '.') . ').'
                );
            }

            $allocation = null;
            $financialsAfter = [];
            DB::transaction(function () use (
                $reservation,
                $amount,
                $paymentMethodId,
                $paymentMethodCode,
                $bankName,
                $reference,
                $nightDate,
                $totalLodging,
                &$allocation,
                &$financialsAfter
            ) {
                Payment::create([
                    'reservation_id' => $reservation->id,
                    'amount' => $amount,
                    'payment_method_id' => $paymentMethodId,
                    'bank_name' => $paymentMethodCode === 'transferencia' ? $bankName : null,
                    'reference' => $paymentMethodCode === 'transferencia' ? $reference : ($reference ?: 'Pago registrado'),
                    'paid_at' => now(),
                    'created_by' => auth()->id(),
                ]);

                $allocation = $this->allocatePaymentToStayNights(
                    $reservation,
                    $amount,
                    $nightDate
                );

                $financialsAfter = $this->syncReservationFinancials($reservation, $totalLodging);
            });

            $this->safeLivewireDispatch('reservation-updated');

            if (empty($financialsAfter)) {
                $financialsAfter = $this->syncReservationFinancials($reservation, $totalLodging);
            }

            $paymentsTotalAfter = (float) ($financialsAfter['payments_total'] ?? 0.0);
            $balanceAfter = (float) ($financialsAfter['balance_due'] ?? 0.0);

            $message = $balanceAfter <= 0
                ? 'Pago registrado. La reserva quedó completamente saldada.'
                : 'Abono registrado. Saldo pendiente: $' . number_format($balanceAfter, 0, ',', '.');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => true,
                    'message' => $message,
                    'data' => [
                        'payments_total' => $paymentsTotalAfter,
                        'balance_due' => $balanceAfter,
                        'nights_marked' => (int) ($allocation['nights_marked'] ?? 0),
                    ],
                ]);
            }

            return back()->with('success', $message);
        } catch (\Throwable $e) {
            Log::error('Error registering reservation payment', [
                'reservation_id' => $reservation->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $respondError('No fue posible registrar el pago. Intenta nuevamente.', 500);
        }
    }

    /**
     * Cancel an already-registered payment by creating a reversing entry.
     * This preserves auditability and recalculates paid nights + financial totals.
     */
    public function cancelPayment(Request $request, Reservation $reservation, Payment $payment)
    {
        $respondError = function (string $message, int $status = 422) use ($request) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $message], $status);
            }

            return back()->withErrors(['payment_cancel' => $message]);
        };

        try {
            if (method_exists($reservation, 'trashed') && $reservation->trashed()) {
                return $respondError('No se puede anular pagos sobre una reserva cancelada.');
            }

            if ((int) ($payment->reservation_id ?? 0) !== (int) $reservation->id) {
                return $respondError('El pago no pertenece a la reserva indicada.', 404);
            }

            $paymentAmount = round((float) ($payment->amount ?? 0), 2);
            if ($paymentAmount <= 0) {
                return $respondError('Solo se pueden anular pagos positivos.');
            }

            $reversalReference = 'Anulacion de pago #' . (int) $payment->id;
            $alreadyReversed = Payment::query()
                ->where('reservation_id', $reservation->id)
                ->where('reference', $reversalReference)
                ->where('amount', '<', 0)
                ->exists();

            if ($alreadyReversed) {
                return $respondError('Este pago ya fue anulado previamente.');
            }

            $financialsAfter = [];
            DB::transaction(function () use ($reservation, $payment, $paymentAmount, $reversalReference, &$financialsAfter) {
                Payment::create([
                    'reservation_id' => $reservation->id,
                    'amount' => -1 * abs($paymentAmount),
                    'payment_method_id' => $payment->payment_method_id,
                    'bank_name' => $payment->bank_name,
                    'reference' => $reversalReference,
                    'paid_at' => now(),
                    'created_by' => auth()->id(),
                ]);

                $this->ensureStayNightsCoverageForReservation($reservation);
                $this->rebuildStayNightPaidStateFromPayments($reservation);
                $financialsAfter = $this->syncReservationFinancials($reservation);
            });

            $this->safeLivewireDispatch('reservation-updated');

            $balanceAfter = (float) ($financialsAfter['balance_due'] ?? 0.0);
            $message = 'Pago anulado correctamente. '
                . ($balanceAfter > 0
                    ? 'Saldo pendiente: $' . number_format($balanceAfter, 0, ',', '.')
                    : 'La reserva no tiene saldo pendiente.');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => true,
                    'message' => $message,
                    'data' => [
                        'balance_due' => $balanceAfter,
                        'payments_total' => (float) ($financialsAfter['payments_total'] ?? 0),
                        'paid_nights' => (int) ($financialsAfter['paid_nights'] ?? 0),
                        'total_nights' => (int) ($financialsAfter['total_nights'] ?? 0),
                    ],
                ]);
            }

            return back()->with('success', $message);
        } catch (\Throwable $e) {
            Log::error('Error cancelling reservation payment', [
                'reservation_id' => $reservation->id ?? null,
                'payment_id' => $payment->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $respondError('No fue posible anular el pago. Intenta nuevamente.', 500);
        }
    }

    /**
     * Ensure reservation has stay night rows for all configured reservation room dates.
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
                $checkInAt = Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay();
                $checkOutAt = Carbon::parse((string) $reservationRoom->check_out_date)->startOfDay();
                $isFinished = $checkOutAt->lte(Carbon::today()->startOfDay());

                $stay = Stay::create([
                    'reservation_id' => $reservation->id,
                    'room_id' => $roomId,
                    'check_in_at' => $checkInAt,
                    'check_out_at' => $isFinished ? $checkOutAt->copy()->setTime(13, 0) : null,
                    'status' => $isFinished ? 'finished' : 'active',
                ]);

                $staysByRoom->put($roomId, $stay);
            }

            $from = Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay();
            $to = Carbon::parse((string) $reservationRoom->check_out_date)->startOfDay();

            for ($cursor = $from->copy(); $cursor->lt($to); $cursor->addDay()) {
                $this->ensureNightForDate($stay, $cursor->copy());
            }
        }
    }

    /**
     * Apply payment amount to reservation nights.
     * - If night_date is provided, it is attempted first.
     * - Remaining amount is allocated in chronological order (FIFO by date/room/id).
     *
     * @return array{nights_marked:int, remaining_amount:float, targeted_night_marked:bool}
     */
    private function allocatePaymentToStayNights(Reservation $reservation, float $amount, ?string $nightDate = null): array
    {
        $remainingCents = (int) round(max(0, $amount) * 100);
        $nightsMarked = 0;
        $targetedNightMarked = false;
        $paymentQueue = $this->getStayNightPaymentQueue($reservation);

        if ($remainingCents <= 0 || empty($paymentQueue)) {
            return [
                'nights_marked' => 0,
                'remaining_amount' => 0.0,
                'targeted_night_marked' => false,
            ];
        }

        if (!empty($nightDate)) {
            try {
                $targetDate = Carbon::parse($nightDate)->toDateString();
            } catch (\Throwable $e) {
                $targetDate = null;
            }

            if ($targetDate) {
                foreach ($paymentQueue as $entry) {
                    /** @var \App\Models\StayNight $targetNight */
                    $targetNight = $entry['night'];
                    $shareCents = (int) ($entry['share_cents'] ?? 0);
                    if ((string) ($entry['date'] ?? '') !== $targetDate || (bool) $targetNight->is_paid) {
                        continue;
                    }

                    if ($shareCents <= 0 || $remainingCents >= $shareCents) {
                        $targetNight->is_paid = true;
                        $targetNight->save();

                        if ($shareCents > 0) {
                            $remainingCents = max(0, $remainingCents - $shareCents);
                        }
                        $nightsMarked++;
                        $targetedNightMarked = true;
                    }
                    break;
                }
            }
        }

        if ($remainingCents > 0) {
            foreach ($paymentQueue as $entry) {
                /** @var \App\Models\StayNight $night */
                $night = $entry['night'];
                $shareCents = (int) ($entry['share_cents'] ?? 0);
                if ((bool) $night->is_paid) {
                    continue;
                }

                if ($shareCents > 0 && $remainingCents < $shareCents) {
                    break;
                }

                $night->is_paid = true;
                $night->save();
                if ($shareCents > 0) {
                    $remainingCents = max(0, $remainingCents - $shareCents);
                }
                $nightsMarked++;
            }
        }

        return [
            'nights_marked' => $nightsMarked,
            'remaining_amount' => round($remainingCents / 100, 2),
            'targeted_night_marked' => $targetedNightMarked,
        ];
    }

    /**
     * Build stay-night payment queue using reservation total as source of truth.
     *
     * @return array<int, array{night:\App\Models\StayNight,share_cents:int,date:string}>
     */
    private function getStayNightPaymentQueue(Reservation $reservation): array
    {
        $nights = StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->orderBy('date')
            ->orderBy('room_id')
            ->orderBy('id')
            ->get()
            ->values();

        $count = $nights->count();
        if ($count <= 0) {
            return [];
        }

        $entries = [];
        $contractTotalCents = (int) round(max(0, (float) ($reservation->total_amount ?? 0)) * 100);

        if ($contractTotalCents > 0) {
            $baseCents = intdiv($contractTotalCents, $count);
            $remainder = $contractTotalCents - ($baseCents * $count);

            foreach ($nights as $index => $night) {
                $shareCents = $baseCents + ($index < $remainder ? 1 : 0);
                $entries[] = [
                    'night' => $night,
                    'share_cents' => $shareCents,
                    'date' => $night->date ? $night->date->toDateString() : '',
                ];
            }

            return $entries;
        }

        foreach ($nights as $night) {
            $entries[] = [
                'night' => $night,
                'share_cents' => (int) round(max(0, (float) ($night->price ?? 0)) * 100),
                'date' => $night->date ? $night->date->toDateString() : '',
            ];
        }

        return $entries;
    }

    /**
     * Calculate lodging total prioritizing the reservation total entered by user.
     */
    private function calculateReservationLodgingTotal(Reservation $reservation): float
    {
        $enteredTotal = (float) ($reservation->total_amount ?? 0);
        if ($enteredTotal > 0) {
            return $enteredTotal;
        }

        $reservationRoomsTotal = (float) $reservation->reservationRooms()
            ->sum('subtotal');
        if ($reservationRoomsTotal > 0) {
            return $reservationRoomsTotal;
        }

        $stayNightsTotal = (float) StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->sum('price');
        $legacyTotal = (float) ($reservation->total_amount ?? 0);

        return max(0, $stayNightsTotal, $legacyTotal);
    }

    /**
     * Sync reservation financial columns from current payments and lodging total.
     *
     * @return array{total_lodging:float,payments_total:float,balance_due:float,paid_nights:int,total_nights:int}
     */
    private function syncReservationFinancials(Reservation $reservation, ?float $lodgingTotal = null): array
    {
        $lodgingTotal = $lodgingTotal !== null ? (float) $lodgingTotal : $this->calculateReservationLodgingTotal($reservation);
        $paymentsTotal = (float) Payment::query()
            ->where('reservation_id', $reservation->id)
            ->sum('amount');
        $balanceDue = max(0, $lodgingTotal - $paymentsTotal);

        $paymentStatusCode = $balanceDue <= 0 ? 'paid' : ($paymentsTotal > 0 ? 'partial' : 'pending');
        $paymentStatusId = DB::table('payment_statuses')
            ->where('code', $paymentStatusCode)
            ->value('id');

        $reservation->forceFill([
            'deposit_amount' => max(0, $paymentsTotal),
            'balance_due' => $balanceDue,
            'payment_status_id' => !empty($paymentStatusId) ? (int) $paymentStatusId : $reservation->payment_status_id,
        ])->save();

        $paidNights = (int) StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('is_paid', true)
            ->count();
        $totalNights = (int) StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->count();

        return [
            'total_lodging' => $lodgingTotal,
            'payments_total' => $paymentsTotal,
            'balance_due' => $balanceDue,
            'paid_nights' => $paidNights,
            'total_nights' => $totalNights,
        ];
    }

    /**
     * Rebuild paid/unpaid flags from net payments (positive - negative).
     * Useful after reversing a payment to ensure full consistency.
     *
     * @return array{paid_nights:int,total_nights:int,remaining_amount:float}
     */
    private function rebuildStayNightPaidStateFromPayments(Reservation $reservation): array
    {
        $remainingCents = (int) round(max(0, (float) Payment::query()
            ->where('reservation_id', $reservation->id)
            ->sum('amount')) * 100);
        $paymentQueue = $this->getStayNightPaymentQueue($reservation);
        $totalNights = count($paymentQueue);

        $paidNights = 0;
        foreach ($paymentQueue as $entry) {
            /** @var \App\Models\StayNight $night */
            $night = $entry['night'];
            $shareCents = (int) ($entry['share_cents'] ?? 0);

            $shouldBePaid = $shareCents <= 0 || $remainingCents >= $shareCents;
            if ($shouldBePaid && $shareCents > 0) {
                $remainingCents = max(0, $remainingCents - $shareCents);
            }

            if ((bool) $night->is_paid !== $shouldBePaid) {
                $night->is_paid = $shouldBePaid;
                $night->save();
            }

            if ($shouldBePaid) {
                $paidNights++;
            }
        }

        return [
            'paid_nights' => $paidNights,
            'total_nights' => $totalNights,
            'remaining_amount' => round($remainingCents / 100, 2),
        ];
    }

    /**
     * Resolve payment method id from catalog, creating it if missing.
     */
    private function resolvePaymentMethodId(string $paymentMethodCode): ?int
    {
        $code = strtolower(trim($paymentMethodCode));
        $names = [
            'efectivo' => 'Efectivo',
            'transferencia' => 'Transferencia',
        ];

        if (!isset($names[$code])) {
            return null;
        }

        $existingId = DB::table('payments_methods')
            ->where('code', $code)
            ->value('id');

        if (!empty($existingId)) {
            return (int) $existingId;
        }

        DB::table('payments_methods')->updateOrInsert(
            ['code' => $code],
            [
                'name' => $names[$code],
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $createdId = DB::table('payments_methods')
            ->where('code', $code)
            ->value('id');

        return !empty($createdId) ? (int) $createdId : null;
    }

    /**
     * Release an active reservation by creating snapshot of previous day and modifying check_out_date.
     */
    private function releaseActiveReservation(Reservation $reservation)
    {
        DB::transaction(function() use ($reservation) {
            $today = Carbon::today()->startOfDay();
            $yesterday = $today->copy()->subDay();
            
            // Load necessary relations
            $reservation->load([
                'customer.taxProfile',
                'sales.product',
                'reservationDeposits',
                'guests.taxProfile',
                'rooms'
            ]);
            
            // Get rooms for this reservation (support multi-room reservations)
            $rooms = $reservation->rooms;
            if ($rooms->isEmpty() && $reservation->room) {
                $rooms = collect([$reservation->room]);
            }
            
            if ($rooms->isEmpty()) {
                throw new \Exception('No se encontraron habitaciones asociadas a la reserva.');
            }
            
            // Store original check_out_date before modifying
            $originalCheckOutDate = Carbon::parse($reservation->check_out_date)->startOfDay();
            
            // Create snapshot of yesterday's state for all rooms (using ORIGINAL check_out_date)
            foreach ($rooms as $room) {
                $this->createRoomSnapshot($room, $yesterday, $reservation, $originalCheckOutDate);
            }
            
            // Use first room for release history (will contain all reservation info)
            $room = $rooms->first();
            
            // Calculate financial information up to yesterday
            $start = Carbon::parse($reservation->check_in_date)->startOfDay();
            $end = $originalCheckOutDate;
            
            $consumptionsTotal = (float) $reservation->sales->sum('total');
            $consumptionsPending = (float) $reservation->sales->where('is_paid', false)->sum('total');
            $depositsTotal = (float) $reservation->deposit;
            $totalAmount = (float) $reservation->total_amount;
            
            // Calculate consumed lodging up to yesterday
            $daysTotal = max(1, $start->diffInDays($end));
            $dailyPrice = $daysTotal > 0 ? ($totalAmount / $daysTotal) : $totalAmount;
            $daysConsumed = $start->diffInDays($yesterday) + 1;
            $daysConsumed = max(1, min($daysTotal, $daysConsumed));
            $totalHospedajeConsumido = $dailyPrice * $daysConsumed;
            
            $pendingAmount = ($totalHospedajeConsumido - $depositsTotal) + $consumptionsPending;
            
            // Create release history
            RoomReleaseHistory::create([
                'room_id' => $room->id,
                'reservation_id' => $reservation->id,
                'client_id' => $reservation->client_id,
                'released_by' => Auth::id(),
                'room_number' => $room->room_number,
                'total_amount' => $totalAmount,
                'deposit' => $depositsTotal,
                'consumptions_total' => $consumptionsTotal,
                'pending_amount' => $pendingAmount,
                'guests_count' => $reservation->guests_count,
                'check_in_date' => $reservation->check_in_date,
                'check_out_date' => $reservation->check_out_date,
                'release_date' => $yesterday->toDateString(),
                'target_status' => 'libre',
                'customer_name' => $reservation->customer->name,
                'customer_identification' => $reservation->customer->taxProfile?->identification,
                'customer_phone' => $reservation->customer->phone,
                'customer_email' => $reservation->customer->email,
                'reservation_data' => $reservation->toArray(),
                'sales_data' => $reservation->sales->map(function($sale) {
                    return [
                        'id' => $sale->id,
                        'product_id' => $sale->product_id,
                        'product_name' => $sale->product->name ?? 'N/A',
                        'quantity' => $sale->quantity,
                        'unit_price' => (float) $sale->unit_price,
                        'total' => (float) $sale->total,
                        'payment_method' => $sale->payment_method,
                        'is_paid' => $sale->is_paid,
                        'created_at' => $sale->created_at?->toDateTimeString(),
                    ];
                })->toArray(),
                'deposits_data' => $reservation->reservationDeposits->map(function($deposit) {
                    return [
                        'id' => $deposit->id,
                        'amount' => (float) $deposit->amount,
                        'payment_method' => $deposit->payment_method,
                        'notes' => $deposit->notes,
                        'created_at' => $deposit->created_at?->toDateTimeString(),
                    ];
                })->toArray(),
                'guests_data' => $reservation->guests->map(function($guest) {
                    return [
                        'id' => $guest->id,
                        'name' => $guest->name,
                        'identification' => $guest->taxProfile?->identification,
                        'phone' => $guest->phone,
                        'email' => $guest->email,
                    ];
                })->toArray(),
            ]);
            
            // Modify reservation to end yesterday instead of deleting
            $reservation->update(['check_out_date' => $yesterday->toDateString()]);
            
            // Audit log
            $this->auditService->logReservationCancelled($reservation, request());
        });
        
        // Dispatch Livewire event for stats update
        $this->safeLivewireDispatch('reservation-cancelled');
        
        return redirect()->route('reservations.index')
            ->with('success', 'Reserva finalizada. La habitación quedó libre desde hoy. La información histórica se ha preservado.');
    }

    /**
     * Create a snapshot of room status for a specific date.
     * Creates snapshot BEFORE modifying reservation, so getDisplayStatus will use original reservation state.
     */
    private function createRoomSnapshot(Room $room, Carbon $date, Reservation $reservation, Carbon $originalCheckOutDate = null): void
    {
        // Use original check_out_date if provided, otherwise use reservation's current check_out_date
        $checkOutForSnapshot = $originalCheckOutDate ?? Carbon::parse($reservation->check_out_date);
        
        // getDisplayStatus queries DB directly, so it will use current reservation state
        // Since we create snapshot BEFORE modifying reservation, it captures correct state
        $displayStatus = $room->getDisplayStatus($date);
        $cleaning = $room->cleaningStatus($date);
        
        // Prepare guests data
        $guestsData = null;
        if ($reservation->customer) {
            $mainGuest = [
                'id' => $reservation->customer->id,
                'name' => $reservation->customer->name,
                'identification' => $reservation->customer->taxProfile?->identification ?? null,
                'phone' => $reservation->customer->phone,
                'email' => $reservation->customer->email,
                'is_main' => true,
            ];
            
            $additionalGuests = $reservation->guests->map(function($guest) {
                return [
                    'id' => $guest->id,
                    'name' => $guest->name,
                    'identification' => $guest->taxProfile?->identification ?? null,
                    'phone' => $guest->phone,
                    'email' => $guest->email,
                    'is_main' => false,
                ];
            })->toArray();
            
            $guestsData = array_merge([$mainGuest], $additionalGuests);
        }
        
        // Snapshots are IMMUTABLE - only create if doesn't exist
        // Never update existing snapshots to preserve historical data integrity
        RoomDailyStatus::firstOrCreate(
            [
                'room_id' => $room->id,
                'date' => $date->toDateString(),
            ],
            [
                'status' => $displayStatus,
                'cleaning_status' => $cleaning['code'],
                'reservation_id' => $reservation->id,
                'guest_name' => $reservation->customer?->name ?? null,
                'guests_data' => $guestsData,
                'check_out_date' => $checkOutForSnapshot->toDateString(),
                'total_amount' => (float) $reservation->total_amount,
            ]
        );
    }

    /**
     * Download the reservation support in PDF format.
     */
    public function download(Reservation $reservation)
    {
        $reservation->loadMissing([
            'customer.taxProfile',
            'reservationRooms.room',
        ]);

        $reservationRooms = $reservation->reservationRooms
            ->sortBy('check_in_date')
            ->values();

        $checkInDate = null;
        $checkOutDate = null;
        $checkInTime = null;

        if ($reservationRooms->isNotEmpty()) {
            $firstRoom = $reservationRooms->first();
            $lastRoom = $reservationRooms
                ->sortByDesc('check_out_date')
                ->first();

            $checkInDate = !empty($firstRoom?->check_in_date)
                ? Carbon::parse((string) $firstRoom->check_in_date)
                : null;

            $checkOutDate = !empty($lastRoom?->check_out_date)
                ? Carbon::parse((string) $lastRoom->check_out_date)
                : null;

            $checkInTime = !empty($firstRoom?->check_in_time)
                ? substr((string) $firstRoom->check_in_time, 0, 5)
                : null;
        }

        $totalAmount = (float) ($reservation->total_amount ?? 0);
        $depositAmount = (float) ($reservation->deposit_amount ?? 0);
        $balanceDue = max(0, (float) ($reservation->balance_due ?? ($totalAmount - $depositAmount)));

        $nights = 0;
        if ($checkInDate && $checkOutDate && $checkOutDate->gt($checkInDate)) {
            $nights = $checkInDate->diffInDays($checkOutDate);
        } elseif ($reservationRooms->isNotEmpty()) {
            $nights = (int) max(0, $reservationRooms->sum(fn ($rr) => (int) ($rr->nights ?? 0)));
        }

        $roomSummaries = $reservationRooms
            ->map(function (ReservationRoom $reservationRoom): string {
                $roomNumber = (string) ($reservationRoom->room->room_number ?? ('ID ' . $reservationRoom->room_id));
                $bedsCount = (int) ($reservationRoom->room->beds_count ?? 0);
                $bedsLabel = $bedsCount === 1 ? 'Cama' : 'Camas';

                if ($bedsCount > 0) {
                    return "Habitacion {$roomNumber} ({$bedsCount} {$bedsLabel})";
                }

                return "Habitacion {$roomNumber}";
            })
            ->unique()
            ->values()
            ->all();

        $fileToken = (string) ($reservation->reservation_code ?: ('RES-' . $reservation->id));
        $fileToken = preg_replace('/[^A-Za-z0-9_-]/', '_', $fileToken) ?: ('RES-' . $reservation->id);

        $pdf = Pdf::loadView('reservations.pdf', [
            'reservation' => $reservation,
            'roomSummaries' => $roomSummaries,
            'checkInDate' => $checkInDate,
            'checkOutDate' => $checkOutDate,
            'checkInTime' => $checkInTime,
            'nights' => $nights,
            'totalAmount' => $totalAmount,
            'depositAmount' => $depositAmount,
            'balanceDue' => $balanceDue,
            'issuedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download("Comprobante_Reserva_{$fileToken}.pdf");
    }

    public function viewGuestsDocument(Reservation $reservation)
    {
        $path = (string) ($reservation->guests_document_path ?? '');

        if ($path === '' || !Storage::disk('public')->exists($path)) {
            abort(404, 'El documento adjunto no existe para esta reserva.');
        }

        $filename = (string) ($reservation->guests_document_original_name ?: basename($path));

        return Storage::disk('public')->response($path, $filename);
    }

    public function downloadGuestsDocument(Reservation $reservation)
    {
        $path = (string) ($reservation->guests_document_path ?? '');

        if ($path === '' || !Storage::disk('public')->exists($path)) {
            abort(404, 'El documento adjunto no existe para esta reserva.');
        }

        $filename = (string) ($reservation->guests_document_original_name ?: basename($path));

        return Storage::disk('public')->download($path, $filename);
    }

    /**
     * Export reservations report as PDF with date range.
     */
    public function exportMonthlyReport(Request $request)
    {
        $startDateInput = $request->get('start_date');
        $endDateInput = $request->get('end_date');

        try {
            $startDate = $startDateInput
                ? Carbon::createFromFormat('Y-m-d', $startDateInput)->startOfDay()
                : now()->subMonths(3)->startOfDay();

            $endDate = $endDateInput
                ? Carbon::createFromFormat('Y-m-d', $endDateInput)->endOfDay()
                : now()->endOfDay();
        } catch (\Exception $e) {
            return back()->withErrors([
                'dates' => 'Formato de fecha inválido. Usa YYYY-MM-DD.',
            ])->withInput($request->only(['start_date', 'end_date']));
        }

        if ($startDate->gt($endDate)) {
            return back()->withErrors([
                'dates' => 'La fecha inicial no puede ser mayor que la fecha final.',
            ])->withInput($request->only(['start_date', 'end_date']));
        }

        $twoYearsAgo = now()->subYears(2)->startOfDay();
        if ($startDate->lt($twoYearsAgo)) {
            return back()->withErrors([
                'dates' => 'No se permiten exportaciones con fecha inicial mayor a 2 años atrás.',
            ])->withInput($request->only(['start_date', 'end_date']));
        }

        $oneYearFuture = now()->addYear()->endOfDay();
        if ($endDate->gt($oneYearFuture)) {
            return back()->withErrors([
                'dates' => 'No se permiten exportaciones con fecha final mayor a 1 año hacia adelante.',
            ])->withInput($request->only(['start_date', 'end_date']));
        }

        // Filtra por traslape del rango contra reservation_rooms (fuente real de fechas).
        $reservations = Reservation::withTrashed()
            ->with(['customer', 'reservationRooms.room'])
            ->whereHas('reservationRooms', function ($query) use ($startDate, $endDate) {
                $query->whereDate('check_in_date', '<=', $endDate->toDateString())
                    ->whereDate('check_out_date', '>=', $startDate->toDateString());
            })
            ->orderByDesc('created_at')
            ->get();

        $reportData = [
            'reservations' => $reservations,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'totalReservations' => $reservations->count(),
            'activeReservations' => $reservations->filter(fn ($r) => !$r->trashed())->count(),
            'cancelledReservations' => $reservations->filter(fn ($r) => $r->trashed())->count(),
            'totalAmount' => $reservations->sum(fn ($r) => (float) ($r->total_amount ?? 0)),
            'totalDeposit' => $reservations->sum(fn ($r) => (float) ($r->deposit_amount ?? 0)),
        ];

        $pdf = Pdf::loadView('reservations.monthly-report-pdf', $reportData);

        return $pdf->download(
            "Reporte_Reservaciones_{$startDate->format('Y-m-d')}_a_{$endDate->format('Y-m-d')}.pdf",
        );
    }

    /**
     * Check if a room is available for a given date range.
     */
    public function checkAvailability(Request $request)
    {
        $checkIn = Carbon::parse($request->check_in_date);
        $checkOut = Carbon::parse($request->check_out_date);
        $excludeReservationId = $request->reservation_id ? (int) $request->reservation_id : null;

        $isAvailable = $this->isRoomAvailable(
            (int) $request->room_id,
            $checkIn,
            $checkOut,
            $excludeReservationId
        );

        return response()->json(['available' => $isAvailable]);
    }

    /**
     * Prepare rooms data for frontend consumption.
     * Similar to CustomerController::getTaxCatalogs() pattern.
     * Always returns array with all required keys, never null.
     */
    private function prepareRoomsData(Collection $rooms): array
    {
        if ($rooms->isEmpty()) {
            return [];
        }

        return $rooms->map(function (Room $room): array {
            $occupancyPrices = $room->occupancy_prices ?? [];

            // Fallback to legacy prices if occupancy_prices is empty
            if (empty($occupancyPrices) || !is_array($occupancyPrices)) {
                $defaultPrice = (float) ($room->price_per_night ?? 0);
                $occupancyPrices = [
                    1 => (float) ($room->price_1_person ?? $defaultPrice),
                    2 => (float) ($room->price_2_persons ?? $defaultPrice),
                ];
                // Calculate additional person prices
                $additionalPrice = (float) ($room->price_additional_person ?? 0);
                $maxCapacity = (int) ($room->max_capacity ?? 2);
                for ($i = 3; $i <= $maxCapacity; $i++) {
                    $occupancyPrices[$i] = $occupancyPrices[2] + ($additionalPrice * ($i - 2));
                }
            } else {
                // Ensure keys are integers (JSON may return string keys)
                $normalizedPrices = [];
                foreach ($occupancyPrices as $key => $value) {
                    $normalizedPrices[(int) $key] = (float) $value;
                }
                $occupancyPrices = $normalizedPrices;
            }

            // Calculate additional person price
            $defaultPrice = (float) ($room->price_per_night ?? 0);
            $price1Person = (float) ($room->price_1_person ?? $defaultPrice);
            $price2Persons = (float) ($room->price_2_persons ?? $defaultPrice);
            $priceAdditionalPerson = (float) ($room->price_additional_person ?? 0);

            // If price_additional_person is 0 or not set, calculate it from the difference
            if ($priceAdditionalPerson == 0 && $price2Persons > $price1Person) {
                $priceAdditionalPerson = $price2Persons - $price1Person;
            }

            return [
                'id' => (int) $room->id,
                'number' => (string) ($room->room_number ?? ''),
                'beds' => (int) ($room->beds_count ?? 0),
                'max_capacity' => (int) ($room->max_capacity ?? 2),
                'price' => $defaultPrice, // Keep for backward compatibility
                'occupancyPrices' => $occupancyPrices, // Prices by number of guests
                'price1Person' => $price1Person, // Base price for 1 person
                'price2Persons' => $price2Persons, // Price for 2 persons (for calculation fallback)
                'priceAdditionalPerson' => $priceAdditionalPerson, // Additional price per person
            ];
        })->toArray();
    }
    /**
     * Get DIAN catalogs for customer creation modal.
     * Similar to CustomerController::getTaxCatalogs() pattern.
     * Always returns valid collections, never null.
     */
    private function getDianCatalogs(): array
    {
        try {
            return [
                'identificationDocuments' => DianIdentificationDocument::query()->orderBy('id')->get() ?? collect(),
                'legalOrganizations' => DianLegalOrganization::query()->orderBy('id')->get() ?? collect(),
                'tributes' => DianCustomerTribute::query()->orderBy('id')->get() ?? collect(),
                'municipalities' => DianMunicipality::query()
                    ->orderBy('department')
                    ->orderBy('name')
                    ->get() ?? collect(),
            ];
        } catch (\Exception $e) {
            \Log::error('Error fetching DIAN catalogs: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            // Return empty collections to prevent null errors in frontend
            return [
                'identificationDocuments' => collect(),
                'legalOrganizations' => collect(),
                'tributes' => collect(),
                'municipalities' => collect(),
            ];
        }
    }

    /**
     * Validate dates for a reservation.
     * Ensures check-in is not before today and check-out is after check-in.
     */
    private function validateDates(Carbon $checkIn, Carbon $checkOut): array
    {
        $errors = [];
        $today = Carbon::today();

        // Check if check-in is before today
        if ($checkIn->isBefore($today)) {
            $errors['check_in_date'] = 'La fecha de entrada no puede ser anterior al día actual.';
        }

        // Check if check-out is before or equal to check-in
        if ($checkOut->lte($checkIn)) {
            $errors['check_out_date'] = 'La fecha de salida debe ser posterior a la fecha de entrada.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate availability for multiple rooms.
     */
    private function validateRoomsAvailability(array $roomIds, Carbon $checkIn, Carbon $checkOut): array
    {
        $errors = [];

        foreach ($roomIds as $roomId) {
            if (!$this->isRoomAvailable($roomId, $checkIn, $checkOut)) {
                $room = Room::find($roomId);
                $roomNumber = $room ? $room->room_number : $roomId;
                $errors['room_ids'][] = "La habitación {$roomNumber} ya está reservada para las fechas seleccionadas.";
            }
        }

        return $errors;
    }

    /**
     * Check if a room is available for a given date range.
     */
    private function isRoomAvailable(int $roomId, Carbon $checkIn, Carbon $checkOut, ?int $excludeReservationId = null): bool
    {
        // 🔥 AJUSTE CRÍTICO: Verificar stays activas (ocupación real)
        // Una habitación NO está disponible si tiene una stay activa que intersecta el rango solicitado
        $hasActiveStay = \App\Models\Stay::where('room_id', $roomId)
            ->where('status', 'active')
            ->where(function ($q) use ($checkIn, $checkOut) {
                $q->where('check_in_at', '<', $checkOut->endOfDay())
                  ->where(function ($q2) use ($checkIn) {
                      $q2->whereNull('check_out_at')
                         ->orWhere('check_out_at', '>', $checkIn->startOfDay());
                  });
            })
            ->exists();

        if ($hasActiveStay) {
            return false; // ❌ Habitación ocupada por stay activa
        }

        // Check in main reservations table (single room reservations) - CORREGIDO
        // 🔥 CORRECCIÓN CRÍTICA: Para hoy, evaluar si hay stay activa ANTES de verificar hora
        $checkInDate = \Carbon\Carbon::parse($checkIn);
        if ($checkInDate->isToday()) {
            // 🎯 PRIMERO: Verificar si hay stay activa
            $hasActiveStay = \App\Models\Stay::where('room_id', $roomId)
                ->where('status', 'active')
                ->where(function($query) use ($checkInDate) {
                    $query->whereNull('check_out_at')
                          ->orWhere('check_out_at', '>=', $checkInDate->copy()->startOfDay());
                })
                ->exists();
            
            if ($hasActiveStay) {
                // 🏠 Si hay stay activa, esperar hasta hora de check-out
                if (!\App\Support\HotelTime::isAfterCheckOutTime()) {
                    return false;
                }
                // Si es después de check-out, continuar con verificaciones normales
            }
            // 🏠 Si NO hay stay activa, permitir reserva inmediata (continuar normal)
        }
        
        $existsInReservations = \App\Models\ReservationRoom::where('room_id', $roomId)
            ->where(function ($query) use ($checkIn, $checkOut) {
                $query->where('check_in_date', '<', $checkOut)
                      ->where('check_out_date', '>', $checkIn);
            })
            ->whereHas('reservation', function ($q) use ($excludeReservationId) {
                $q->whereNull('deleted_at')
                  ->when($excludeReservationId, function ($subQ) use ($excludeReservationId) {
                      $subQ->where('id', '!=', $excludeReservationId);
                  });
            })
            ->exists();

        if ($existsInReservations) {
            return false;
        }

        // Check in reservation_rooms table (multi-room reservations) - CORREGIDO
        // Para hoy, ya fue manejado arriba, solo verificar fechas futuras
        if (!$checkInDate->isToday()) {
            $existsInPivot = DB::table('reservation_rooms')
                ->join('reservations', 'reservation_rooms.reservation_id', '=', 'reservations.id')
                ->where('reservation_rooms.room_id', $roomId)
                ->whereNull('reservations.deleted_at')
                ->where(function ($query) use ($checkIn, $checkOut) {
                    $query->where('reservation_rooms.check_in_date', '<', $checkOut)
                          ->where('reservation_rooms.check_out_date', '>', $checkIn);
                })
                ->when($excludeReservationId, function ($q) use ($excludeReservationId) {
                    $q->where('reservations.id', '!=', $excludeReservationId);
                })
                ->exists();

            if ($existsInPivot) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate guest assignment for multiple rooms.
     */
    private function validateGuestAssignment(array $roomGuests, Collection $rooms): array
    {
        $errors = [];
        $guestRoomMap = [];

        foreach ($roomGuests as $roomId => $assignedGuestIds) {
            $room = $rooms->get($roomId);

            if (!$room) {
                $errors['room_guests'][] = "La habitación con ID {$roomId} no existe.";
                continue;
            }

            // Filter valid guest IDs
            $validGuestIds = array_filter($assignedGuestIds, function ($id): bool {
                return !empty($id) && is_numeric($id) && $id > 0;
            });
            $validGuestIds = array_values(array_unique(array_map('intval', $validGuestIds)));

            $guestCount = count($validGuestIds);

            if ($guestCount > $room->max_capacity) {
                $errors['room_guests'][] = "La habitación {$room->room_number} tiene una capacidad máxima de {$room->max_capacity} personas, pero se están intentando asignar {$guestCount}.";
            }

            // Business rule: prevent assigning the same guest to multiple rooms in the same reservation.
            foreach ($validGuestIds as $guestId) {
                if (!isset($guestRoomMap[$guestId])) {
                    $guestRoomMap[$guestId] = (int) $roomId;
                    continue;
                }

                $firstRoomId = (int) $guestRoomMap[$guestId];
                if ($firstRoomId === (int) $roomId) {
                    continue;
                }

                $firstRoom = $rooms->get($firstRoomId);
                $firstRoomNumber = $firstRoom ? $firstRoom->room_number : (string) $firstRoomId;
                $errors['room_guests'][] = "Un huésped no puede estar asignado a dos habitaciones en la misma reserva (Hab. {$firstRoomNumber} y Hab. {$room->room_number}).";
            }
        }

        return $errors;
    }

    /**
     * Assign guests to a specific reservation room.
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
        $validGuestIds = Customer::withoutGlobalScopes()
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
                $existingReservationGuest = \DB::table('reservation_guests')
                    ->where('reservation_id', $reservationRoom->reservation_id)
                    ->where('customer_id', $guestId)
                    ->first();

                $reservationGuestId = $existingReservationGuest
                    ? $existingReservationGuest->id
                    : \DB::table('reservation_guests')->insertGetId([
                        'reservation_id' => $reservationRoom->reservation_id,
                        'customer_id' => $guestId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                // Vincular la entrada de reservation_guests a esta habitación (si no existe ya)
                $existingRoomGuest = \DB::table('reservation_room_guests')
                    ->where('reservation_room_id', $reservationRoom->id)
                    ->where('reservation_guest_id', $reservationGuestId)
                    ->exists();

                if (!$existingRoomGuest) {
                    \DB::table('reservation_room_guests')->insert([
                        'reservation_room_id' => $reservationRoom->id,
                        'reservation_guest_id' => $reservationGuestId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error assigning guests to room', [
                'reservation_room_id' => $reservationRoom->id,
                'guest_ids' => $validGuestIds,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Find rate for a specific number of guests in a room.
     * Similar to RoomManager::findRateForGuests().
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
     * Similar a RoomManager::ensureNightForDate().
     * 
     * SINGLE SOURCE OF TRUTH para el cobro por noches:
     * - Si ya existe una noche para esa fecha, no hace nada
     * - Si no existe, crea una nueva noche con precio calculado desde tarifas
     * - El precio se calcula basándose en la cantidad de huéspedes de la reserva
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
     * Assign guests to reservation (legacy format for single-room reservations).
     * NOTE: This method works with the actual database structure where guests are assigned to reservation_rooms.
     */
    private function assignGuestsToReservationLegacy(Reservation $reservation, array $guestIds): void
    {
        // Filter valid guest IDs
        $validGuestIds = array_filter($guestIds, function ($id): bool {
            return !empty($id) && is_numeric($id) && $id > 0;
        });

        if (empty($validGuestIds)) {
            return;
        }

        // Verify guests exist in database
        $validGuestIds = Customer::withoutGlobalScopes()
            ->whereIn('id', $validGuestIds)
            ->pluck('id')
            ->toArray();

        if (empty($validGuestIds)) {
            return;
        }

        try {
            // Get the first reservation room (for single-room reservations)
            $reservationRoom = $reservation->reservationRooms()->first();
            
            if (!$reservationRoom) {
                \Log::warning('No reservation room found for guest assignment', [
                    'reservation_id' => $reservation->id,
                    'guest_ids' => $validGuestIds,
                ]);
                return;
            }

            // Use transaction to ensure data integrity
            DB::transaction(function () use ($validGuestIds, $reservationRoom, $reservation) {
                foreach ($validGuestIds as $index => $guestId) {
                    // Check if guest is already assigned to this reservation room
                    $existingReservationGuestId = DB::table('reservation_guests')
                        ->where('guest_id', $guestId)
                        ->whereExists(function ($query) use ($reservationRoom) {
                            $query->select(DB::raw(1))
                                ->from('reservation_room_guests')
                                ->whereColumn('reservation_room_guests.reservation_guest_id', 'reservation_guests.id')
                                ->where('reservation_room_guests.reservation_room_id', $reservationRoom->id);
                        })
                        ->value('id');

                    if ($existingReservationGuestId) {
                        // Guest already assigned, skip to next
                        \Log::info('Guest already assigned to reservation room, skipping', [
                            'guest_id' => $guestId,
                            'reservation_room_id' => $reservationRoom->id,
                            'existing_reservation_guest_id' => $existingReservationGuestId,
                        ]);
                        continue;
                    }

                    // Create reservation_guest entry
                    $reservationGuestId = DB::table('reservation_guests')->insertGetId([
                        'guest_id' => $guestId,
                        'is_primary' => $index === 0, // First guest is primary
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Link to reservation room
                    $reservationRoomGuestId = DB::table('reservation_room_guests')->insertGetId([
                        'reservation_room_id' => $reservationRoom->id,
                        'reservation_guest_id' => $reservationGuestId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    \Log::info('Guest assigned to reservation room', [
                        'reservation_id' => $reservation->id,
                        'reservation_room_id' => $reservationRoom->id,
                        'reservation_guest_id' => $reservationGuestId,
                        'reservation_room_guest_id' => $reservationRoomGuestId,
                        'guest_id' => $guestId,
                        'is_primary' => $index === 0,
                    ]);
                }
            });

            \Log::info('Guests assigned to reservation successfully', [
                'reservation_id' => $reservation->id,
                'reservation_room_id' => $reservationRoom->id,
                'guest_ids' => $validGuestIds,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error assigning guests to reservation: ' . $e->getMessage(), [
                'reservation_id' => $reservation->id,
                'guest_ids' => $validGuestIds,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

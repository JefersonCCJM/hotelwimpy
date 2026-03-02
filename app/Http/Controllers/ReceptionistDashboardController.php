<?php

namespace App\Http\Controllers;

use App\Enums\ShiftHandoverStatus;
use App\Enums\ShiftStatus;
use App\Enums\ShiftType;
use App\Models\Shift;
use App\Models\Room;
use App\Models\Sale;
use App\Models\ShiftHandover;
use App\Services\ShiftLifecycleService;
use App\Services\ShiftControlService;
use App\Services\ShiftSnapshotBuilder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\ShiftCashOut;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\ReservationSale;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReceptionistDashboardController extends Controller
{
    public function __construct(
        private readonly ShiftLifecycleService $shiftLifecycle,
        private readonly ShiftControlService $shiftControl,
        private readonly ShiftSnapshotBuilder $snapshotBuilder,
    ) {}

    public function index()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $query = ShiftHandover::with([
            "entregadoPor",
            "recibidoPor",
            "sales",
        ])->orderBy("created_at", "desc");

        // Recepcionistas: solo sus propios turnos. Admin: todos.
        if (!$user->hasRole("Administrador")) {
            $query->where(function ($q) use ($user) {
                $q->where("entregado_por", $user->id)->orWhere(
                    "recibido_por",
                    $user->id,
                );
            });
        }

        $handovers = $query->paginate(15);
        return view("shift-handovers.index", compact("handovers"));
    }

    public function show($id)
    {
        $handover = ShiftHandover::with([
            "entregadoPor",
            "recibidoPor",
            "sales.items.product",
            "cashOutflows",
            "cashOuts",
            "productOuts.product",
        ])->findOrFail($id);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (
            !$user->hasRole("Administrador") &&
            !in_array(
                $user->id,
                [(int) $handover->entregado_por, (int) $handover->recibido_por],
                true,
            )
        ) {
            abort(403);
        }

        // Recalcular totales si el turno estÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ activo
        if ($handover->status === ShiftHandoverStatus::ACTIVE) {
            $handover->updateTotals();
            $handover->refresh();
        }

        $shiftRentals = collect();
        $shiftRoomSales = collect();
        $shiftPayments = collect();
        if ($handover->started_at) {
            $shiftStart = $handover->started_at->copy();
            $shiftEnd = $handover->ended_at
                ? $handover->ended_at->copy()
                : now();

            $rentalsQuery = Stay::query()
                ->with([
                    "room:id,room_number",
                    "reservation:id,reservation_code,client_id,total_amount,created_at",
                    "reservation.customer:id,name",
                    "reservation.reservationRooms:id,reservation_id,room_id,check_in_date,check_out_date,nights,price_per_night,subtotal",
                    "reservation.payments:id,reservation_id,amount,paid_at,created_at",
                ])
                ->where("check_in_at", ">=", $shiftStart);

            if ($handover->ended_at) {
                // LÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­mite exclusivo para evitar duplicidad en frontera entre turnos.
                $rentalsQuery->where("check_in_at", "<", $shiftEnd);
            } else {
                $rentalsQuery->where("check_in_at", "<=", $shiftEnd);
            }

            $shiftRentals = $rentalsQuery
                ->orderByDesc("check_in_at")
                ->get()
                ->map(function (Stay $stay) use (
                    $shiftStart,
                    $shiftEnd,
                    $handover,
                ) {
                    $reservation = $stay->reservation;
                    $reservationRoom = $reservation?->reservationRooms
                        ?->firstWhere("room_id", $stay->room_id) ??
                        $reservation?->reservationRooms?->first();

                    $subtotal = (float) ($reservationRoom->subtotal ?? 0);
                    $rentalTotal = $subtotal > 0
                        ? $subtotal
                        : (float) ($reservation->total_amount ?? 0);

                    $payments = $reservation?->payments ?? collect();
                    $paidTotal = (float) $payments->sum(function ($payment) {
                        return (float) ($payment->amount ?? 0);
                    });

                    $paidInShift = (float) $payments
                        ->filter(function ($payment) use (
                            $shiftStart,
                            $shiftEnd,
                            $handover,
                        ) {
                            $paidAt = $payment->paid_at ?? $payment->created_at;
                            if (!$paidAt) {
                                return false;
                            }

                            $inWindow = $handover->ended_at
                                ? $paidAt->gte($shiftStart) &&
                                    $paidAt->lt($shiftEnd)
                                : $paidAt->gte($shiftStart) &&
                                    $paidAt->lte($shiftEnd);

                            return $inWindow &&
                                (float) ($payment->amount ?? 0) > 0;
                        })
                        ->sum(function ($payment) {
                            return (float) ($payment->amount ?? 0);
                        });

                    $stay->rental_total = round($rentalTotal, 2);
                    $stay->reservation_paid_total = round($paidTotal, 2);
                    $stay->paid_in_shift = round($paidInShift, 2);

                    return $stay;
                });

            $roomSalesQuery = ReservationSale::query()
                ->with([
                    "product:id,name",
                    "reservation:id,reservation_code,client_id",
                    "reservation.customer:id,name",
                    "reservation.reservationRooms:id,reservation_id,room_id",
                    "reservation.reservationRooms.room:id,room_number",
                ])
                ->where("created_at", ">=", $shiftStart);

            if ($handover->ended_at) {
                $roomSalesQuery->where("created_at", "<", $shiftEnd);
            } else {
                $roomSalesQuery->where("created_at", "<=", $shiftEnd);
            }

            $shiftRoomSales = $roomSalesQuery->orderByDesc("created_at")->get();

            $shiftPayments = Payment::query()
                ->with([
                    'paymentMethod',
                    'reservation' => function ($q) {
                        $q->withTrashed()->with([
                            'customer:id,name',
                            'reservationRooms:id,reservation_id,room_id',
                            'reservationRooms.room:id,room_number',
                        ]);
                    },
                ])
                ->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$shiftStart, $shiftEnd])
                ->orderByDesc(DB::raw('COALESCE(paid_at, created_at)'))
                ->get();
        }

        return view(
            "shift-handovers.show",
            compact("handover", "shiftRentals", "shiftRoomSales", "shiftPayments"),
        );
    }

    public function downloadHandoverPdf($id)
    {
        $handover = ShiftHandover::with([
            "entregadoPor",
            "recibidoPor",
            "sales.items.product",
            "cashOutflows",
            "cashOuts",
            "productOuts.product",
        ])->findOrFail($id);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (
            !$user->hasRole("Administrador") &&
            !in_array(
                $user->id,
                [(int) $handover->entregado_por, (int) $handover->recibido_por],
                true,
            )
        ) {
            abort(403);
        }

        if ($handover->status === ShiftHandoverStatus::ACTIVE) {
            $handover->updateTotals();
            $handover->refresh();
            $handover->load([
                "entregadoPor",
                "recibidoPor",
                "sales.items.product",
                "cashOutflows",
                "cashOuts",
                "productOuts.product",
            ]);
        }

        $shiftRentals = collect();
        $shiftRoomSales = collect();
        $shiftInventory = $this->emptyShiftInventorySummary();
        if ($handover->started_at) {
            $shiftStart = $handover->started_at->copy();
            $shiftEnd = $handover->ended_at
                ? $handover->ended_at->copy()
                : now();

            $rentalsQuery = Stay::query()
                ->with([
                    "room:id,room_number",
                    "reservation:id,reservation_code,client_id,total_amount,created_at",
                    "reservation.customer:id,name",
                    "reservation.reservationRooms:id,reservation_id,room_id,check_in_date,check_out_date,nights,price_per_night,subtotal",
                    "reservation.payments:id,reservation_id,amount,paid_at,created_at",
                ])
                ->where("check_in_at", ">=", $shiftStart);

            if ($handover->ended_at) {
                $rentalsQuery->where("check_in_at", "<", $shiftEnd);
            } else {
                $rentalsQuery->where("check_in_at", "<=", $shiftEnd);
            }

            $shiftRentals = $rentalsQuery
                ->orderByDesc("check_in_at")
                ->get()
                ->map(function (Stay $stay) use (
                    $shiftStart,
                    $shiftEnd,
                    $handover,
                ) {
                    $reservation = $stay->reservation;
                    $reservationRoom = $reservation?->reservationRooms
                        ?->firstWhere("room_id", $stay->room_id) ??
                        $reservation?->reservationRooms?->first();

                    $subtotal = (float) ($reservationRoom->subtotal ?? 0);
                    $rentalTotal = $subtotal > 0
                        ? $subtotal
                        : (float) ($reservation->total_amount ?? 0);

                    $payments = $reservation?->payments ?? collect();
                    $paidTotal = (float) $payments->sum(function ($payment) {
                        return (float) ($payment->amount ?? 0);
                    });

                    $paidInShift = (float) $payments
                        ->filter(function ($payment) use (
                            $shiftStart,
                            $shiftEnd,
                            $handover,
                        ) {
                            $paidAt = $payment->paid_at ?? $payment->created_at;
                            if (!$paidAt) {
                                return false;
                            }

                            $inWindow = $handover->ended_at
                                ? $paidAt->gte($shiftStart) &&
                                    $paidAt->lt($shiftEnd)
                                : $paidAt->gte($shiftStart) &&
                                    $paidAt->lte($shiftEnd);

                            return $inWindow &&
                                (float) ($payment->amount ?? 0) > 0;
                        })
                        ->sum(function ($payment) {
                            return (float) ($payment->amount ?? 0);
                        });

                    $stay->rental_total = round($rentalTotal, 2);
                    $stay->reservation_paid_total = round($paidTotal, 2);
                    $stay->paid_in_shift = round($paidInShift, 2);

                    return $stay;
                });

            $roomSalesQuery = ReservationSale::query()
                ->with([
                    "product:id,name",
                    "reservation:id,reservation_code,client_id",
                    "reservation.customer:id,name",
                    "reservation.reservationRooms:id,reservation_id,room_id",
                    "reservation.reservationRooms.room:id,room_number",
                ])
                ->where("created_at", ">=", $shiftStart);

            if ($handover->ended_at) {
                $roomSalesQuery->where("created_at", "<", $shiftEnd);
            } else {
                $roomSalesQuery->where("created_at", "<=", $shiftEnd);
            }

            $shiftRoomSales = $roomSalesQuery->orderByDesc("created_at")->get();
            $shiftInventory = $this->buildShiftInventorySummary(
                $shiftStart,
                $shiftEnd,
                (bool) $handover->ended_at,
            );
        }

        $fileName = "Turno_{$handover->id}_{$handover->shift_date->format(
            "Y-m-d",
        )}.pdf";

        $pdf = Pdf::loadView("shift-handovers.pdf", [
            "handover" => $handover,
            "tolerance" => (float) config("shifts.difference_tolerance", 0),
            "shiftRentals" => $shiftRentals,
            "shiftRoomSales" => $shiftRoomSales,
            "shiftInventory" => $shiftInventory,
        ])->setPaper("a4", "portrait");

        return $pdf->download($fileName);
    }

    public function day()
    {
        return $this->renderDashboard(ShiftType::DAY);
    }

    public function night()
    {
        return $this->renderDashboard(ShiftType::NIGHT);
    }

    private function renderDashboard(ShiftType $type)
    {
        $user = Auth::user();
        $today = Carbon::today();
        $shiftsOperationalEnabled = $this->shiftControl->isOperationalEnabled();

        if (!$user->hasRole("Administrador") && !$shiftsOperationalEnabled) {
            return redirect()
                ->route("dashboard")
                ->with(
                    "error",
                    "La administracion desactivo temporalmente el acceso al panel de turnos.",
                );
        }

        // 1. Detect active or pending shift
        $globalActiveShift = ShiftHandover::with(["entregadoPor:id,name"])
            ->where("status", ShiftHandoverStatus::ACTIVE)
            ->first();

        $globalPendingHandover = ShiftHandover::with([
            "entregadoPor:id,name",
            "recibidoPor:id,name",
        ])
            ->where("status", ShiftHandoverStatus::DELIVERED)
            ->oldest("ended_at")
            ->oldest("id")
            ->first();

        $activeShift = ShiftHandover::where("entregado_por", $user->id)
            ->where("status", ShiftHandoverStatus::ACTIVE)
            ->first();

        $pendingReception = ShiftHandover::where("recibido_por", $user->id)
            ->where("entregado_por", "!=", $user->id)
            ->where("status", ShiftHandoverStatus::DELIVERED)
            ->first();

        if (!$pendingReception) {
            $pendingReception = ShiftHandover::whereNull("recibido_por")
                ->where("entregado_por", "!=", $user->id)
                ->where("status", ShiftHandoverStatus::DELIVERED)
                ->oldest("ended_at")
                ->oldest("id")
                ->first();
        }

        // 2. Summary of sales for the active shift
        $salesSummary = [
            "total" => 0,
            "cash" => 0,
            "transfer" => 0,
        ];

        if ($activeShift) {
            $activeShift->updateTotals();

            $salesSummary["total"] = $activeShift->sales->sum("total");
            $salesSummary["cash"] = $activeShift->total_entradas_efectivo;
            $salesSummary["transfer"] =
                $activeShift->total_entradas_transferencia;
        }

        // 3. Room status summary
        // NOTE: `rooms.status` was removed from schema; use computed operational/cleaning status.
        $roomsSummary = [
            "occupied" => 0,
            "available" => 0,
            "dirty" => 0,
            "cleaning" => 0,
        ];

        $activeRooms = Room::query()->where("is_active", true)->get();
        foreach ($activeRooms as $room) {
            $operationalStatus = $room->getOperationalStatus($today);
            $cleaningStatus = $room->cleaningStatus($today)["code"] ?? null;

            if (
                in_array(
                    $operationalStatus,
                    ["occupied", "pending_checkout"],
                    true,
                )
            ) {
                $roomsSummary["occupied"]++;
            } elseif ($operationalStatus === "pending_cleaning") {
                $roomsSummary["dirty"]++;
            } else {
                $roomsSummary["available"]++;
            }

            // Keep "En Limpieza" card populated with rooms requiring cleaning attention.
            if ($cleaningStatus === "pendiente") {
                $roomsSummary["cleaning"]++;
            }
        }

        // 4. Alerts
        $alerts = [];

        // Pending reception alert
        if ($pendingReception) {
            $alerts[] = [
                "type" => "warning",
                "title" => "Turno pendiente de recibir",
                "message" =>
                    "Tienes un turno entregado que aun no has recibido formalmente.",
                "link" => route("shift-handovers.receive"),
                "link_text" => "Recibir ahora",
            ];
        }

        // Dirty rooms alert
        $dirtyRoomsCount = $roomsSummary["dirty"];
        if ($dirtyRoomsCount > 0) {
            $alerts[] = [
                "type" => "danger",
                "title" => "Habitaciones sucias",
                "message" => "Hay {$dirtyRoomsCount} habitaciones que requieren limpieza.",
                "link" => route("rooms.index", ["status" => "sucia"]),
                "link_text" => "Ver habitaciones",
            ];
        }

        // Pending check-ins today
        $pendingCheckIns = ReservationRoom::query()
            ->whereDate("check_in_date", $today)
            ->whereHas("reservation", function ($query) {
                $query->whereNull("deleted_at");
            })
            ->distinct("reservation_id")
            ->count("reservation_id");
        if ($pendingCheckIns > 0) {
            $alerts[] = [
                "type" => "info",
                "title" => "Check-ins para hoy",
                "message" => "Hay {$pendingCheckIns} ingresos programados para el dia de hoy.",
                "link" => route("reservations.index", [
                    "date" => $today->toDateString(),
                ]),
                "link_text" => "Ver reservas",
            ];
        }

        // Pending check-outs today
        $pendingCheckOuts = ReservationRoom::query()
            ->whereDate("check_out_date", $today)
            ->whereHas("reservation", function ($query) {
                $query->whereNull("deleted_at");
            })
            ->distinct("reservation_id")
            ->count("reservation_id");
        if ($pendingCheckOuts > 0) {
            $alerts[] = [
                "type" => "info",
                "title" => "Check-outs para hoy",
                "message" => "Hay {$pendingCheckOuts} salidas programadas para el dia de hoy.",
                "link" => route("reservations.index", [
                    "date" => $today->toDateString(),
                ]),
                "link_text" => "Ver reservas",
            ];
        }

        // 5. Datos detallados del turno activo
        $shiftSales = collect();
        $shiftRoomDebtSales = collect();
        $shiftOutflows = collect();
        $shiftCashOuts = collect();
        $shiftProductOuts = collect();
        $shiftPayments = collect();
        $shiftInventory = $this->emptyShiftInventorySummary();

        if ($activeShift) {
            $shiftSales = $activeShift
                ->sales()
                ->with("items.product")
                ->latest()
                ->take(15)
                ->get();

            $windowStart = $activeShift->started_at ??
                $activeShift->created_at ??
                now();
            $windowEnd = $activeShift->ended_at ?? now();
            if ($windowEnd->lt($windowStart)) {
                $windowEnd = $windowStart->copy();
            }

            // Consumos de habitacion fiados en este turno.
            // Se guardan en reservation_sales (no en sales), por eso se consultan aparte.
            $shiftRoomDebtSales = ReservationSale::query()
                ->with([
                    "product:id,name",
                    "reservation:id,reservation_code,client_id",
                    "reservation.customer:id,name",
                    "reservation.reservationRooms:id,reservation_id,room_id",
                    "reservation.reservationRooms.room:id,room_number",
                ])
                ->where("is_paid", false)
                ->whereBetween("created_at", [$windowStart, $windowEnd])
                ->latest()
                ->take(15)
                ->get();

            $shiftOutflows = $activeShift
                ->cashOutflows()
                ->latest()
                ->take(15)
                ->get();

            $shiftCashOuts = $activeShift
                ->cashOuts()
                ->latest()
                ->take(15)
                ->get();

            $shiftProductOuts = $activeShift
                ->productOuts()
                ->with("product")
                ->latest()
                ->take(15)
                ->get();

            $shiftInventory = $this->buildShiftInventorySummary(
                $windowStart,
                $windowEnd,
                (bool) $activeShift->ended_at,
            );

            $shiftPayments = Payment::query()
                ->with([
                    'paymentMethod',
                    'reservation' => function ($q) {
                        $q->withTrashed()->with([
                            'customer:id,name',
                            'reservationRooms:id,reservation_id,room_id',
                            'reservationRooms.room:id,room_number',
                        ]);
                    },
                ])
                ->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$windowStart, $windowEnd])
                ->orderByDesc(DB::raw('COALESCE(paid_at, created_at)'))
                ->take(30)
                ->get();
        }

        $operationalShift = Shift::openOperational()->with("openedBy")->first();

        $view =
            $type === ShiftType::DAY
                ? "dashboards.receptionist-day"
                : "dashboards.receptionist-night";

        return view(
            $view,
            compact(
                "user",
                "activeShift",
                "pendingReception",
                "salesSummary",
                "roomsSummary",
                "shiftSales",
                "shiftRoomDebtSales",
                "shiftOutflows",
                "shiftCashOuts",
                "shiftProductOuts",
                "shiftPayments",
                "shiftInventory",
                "alerts",
                "operationalShift",
                "shiftsOperationalEnabled",
                "globalActiveShift",
                "globalPendingHandover",
            ),
        );
    }

    public function startShift(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            "shift_type" => [
                "required",
                "string",
                Rule::in([ShiftType::DAY->value, ShiftType::NIGHT->value]),
            ],
            "base_inicial" => "nullable",
            "receptionist_name" => "required|string|max:120",
        ]);

        $type = ShiftType::from($validated["shift_type"]);
        $receptionistName = trim((string) $validated["receptionist_name"]);
        if ($receptionistName === "") {
            return back()
                ->withErrors([
                    "receptionist_name" =>
                        "El nombre del recepcionista es obligatorio.",
                ])
                ->withInput();
        }

        if (
            !$this->shiftControl->isOperationalEnabled() &&
            !$user->hasRole("Administrador")
        ) {
            return back()->with(
                "error",
                "La apertura de turnos estÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ deshabilitada por administraciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n.",
            );
        }

        $activeHandover = ShiftHandover::query()
            ->where("status", ShiftHandoverStatus::ACTIVE)
            ->first();
        if ($activeHandover) {
            return back()
                ->with(
                    "error",
                    "Ya existe un turno activo en el sistema. Debes entregarlo/recibirlo antes de iniciar uno nuevo.",
                )
                ->withInput();
        }

        $pendingHandover = ShiftHandover::query()
            ->where("status", ShiftHandoverStatus::DELIVERED)
            ->first();
        if ($pendingHandover) {
            return back()
                ->with(
                    "error",
                    "Hay un turno pendiente de recibir. Debe recibirse antes de iniciar un nuevo turno.",
                )
                ->withInput();
        }

        // Bloquear si ya hay un turno operativo abierto (aunque sea de otro usuario)
        if (Shift::openOperational()->exists()) {
            return back()->with(
                "error",
                "Ya existe un turno operativo abierto. Recibe el turno pendiente o pide a un administrador forzarlo.",
            );
        }

        // Base inicial por defecto (configurable)
        $defaultBase = (float) config("shifts.default_initial_base", 0);

        // Limpiar el formato de miles (puntos) antes de guardar
        $baseInicialRaw = $validated["base_inicial"] ?? (string) $defaultBase;
        $baseInicial = str_replace(".", "", (string) $baseInicialRaw);
        $baseInicial = (float) str_replace(",", ".", $baseInicial);

        // Crear shift operativo garantizando unicidad global
        $baseSnapshot = [
            "shift" => [
                "type" => $type->value,
                "date" => Carbon::today()->toDateString(),
            ],
            "cash" => [
                "base_inicial" => $baseInicial,
                "entradas_efectivo" => 0.0,
                "entradas_transferencia" => 0.0,
                "salidas" => 0.0,
                "base_esperada" => $baseInicial,
            ],
            "meta" => [
                "captured_at" => Carbon::now()->toIso8601String(),
            ],
        ];

        try {
            $shift = $this->shiftLifecycle->openFresh(
                $user,
                $type,
                $baseSnapshot,
            );
        } catch (\Throwable $e) {
            $message =
                $e instanceof \App\Exceptions\ShiftRuleViolation
                    ? $e->getMessage()
                    : "No se pudo iniciar el turno.";
            return back()->with("error", $message)->withInput();
        }

        ShiftHandover::create([
            "from_shift_id" => $shift->id,
            "entregado_por" => $user->id,
            "receptionist_name" => $receptionistName,
            "shift_type" => $type->value,
            "shift_date" => Carbon::today(),
            "started_at" => Carbon::now(),
            "base_inicial" => $baseInicial,
            "status" => ShiftHandoverStatus::ACTIVE,
        ]);

        $this->auditLog(
            "shift_start",
            "Usuario {$user->username} inicio turno {$type->value}",
            [
                "shift_type" => $type->value,
                "receptionist_name" => $receptionistName,
                "base_inicial" => $baseInicial,
            ],
        );

        return back()->with("success", "Turno iniciado correctamente.");
    }

    public function deliverShift()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $activeShift = $user->turnoActivo()->first();

        if (!$activeShift) {
            return redirect()
                ->back()
                ->with("error", "No tienes un turno activo para entregar.");
        }

        $activeShift->updateTotals();
        $activeShift->load([
            "sales.items.product",
            "cashOutflows",
            "cashOuts",
            "productOuts.product",
        ]);

        $receivers = User::query()
            ->whereHas("roles", function ($q) {
                $q->whereIn("name", [
                    "Recepcionista DÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­a",
                    "Recepcionista Noche",
                ]);
            })
            ->where("id", "!=", $user->id)
            ->orderBy("name")
            ->get();

        return view(
            "shift-handovers.deliver",
            compact("activeShift", "receivers"),
        );
    }

    public function createHandover()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $activeShift = $user->turnoActivo()->first();
        $operationalShift = Shift::openOperational()->first();
        if (
            !$activeShift ||
            !$operationalShift ||
            (int) ($activeShift->from_shift_id ?? $activeShift->id) !==
                (int) $operationalShift->id
        ) {
            return back()->with(
                "error",
                "Debe haber un turno operativo abierto para registrar una salida de caja.",
            );
        }

        if (!$activeShift) {
            return redirect()
                ->route("shift-handovers.index")
                ->with("error", "No tienes un turno activo para entregar.");
        }

        $receivers = User::query()
            ->whereHas("roles", function ($q) {
                $q->whereIn("name", [
                    "Recepcionista DÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­a",
                    "Recepcionista Noche",
                ]);
            })
            ->where("id", "!=", $user->id)
            ->orderBy("name")
            ->get();

        return view(
            "shift-handovers.create",
            compact("activeShift", "receivers"),
        );
    }

    public function storeHandover(Request $request)
    {
        // Reusar la lógica de entrega del turno (endShift) pero con mÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡s campos.
        return $this->endShift($request);
    }

    public function endShift(Request $request)
    {
        $user = Auth::user();
        $activeShift = $user->turnoActivo()->first();

        if (!$activeShift) {
            return redirect()
                ->route($this->resolveReceptionistDashboardRoute($user))
                ->with("error", "No tienes un turno activo.");
        }

        $request->validate([
            "base_final" => "nullable",
            "observaciones" => "nullable|string|max:2000",
            "recibido_por" => "nullable|integer|exists:users,id",
        ]);

        // Sanitize base_final
        $baseFinal = $request->input("base_final");
        if (is_string($baseFinal)) {
            $baseFinal = str_replace(".", "", $baseFinal);
            $baseFinal = (float) str_replace(",", ".", $baseFinal);
        }

        $activeShift->updateTotals();
        $activeShift->status = ShiftHandoverStatus::DELIVERED;
        $activeShift->ended_at = Carbon::now();
        $activeShift->observaciones_entrega = $request->input("observaciones");
        $activeShift->base_final = $baseFinal ?? $activeShift->base_esperada;

        // Opcional: asignar el receptor desde la entrega
        $receiverId = $request->input("recibido_por");
        if ($receiverId) {
            $receiver = User::find((int) $receiverId);
            if (
                $receiver &&
                ($receiver->hasRole("Recepcionista DÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­a") ||
                    $receiver->hasRole("Recepcionista Noche"))
            ) {
                $activeShift->recibido_por = $receiver->id;
            }
        }

        // Vincular shift operativo y cerrar con snapshot inmutable
        $shift = $activeShift->from_shift_id
            ? Shift::find($activeShift->from_shift_id)
            : Shift::openOperational()->first();
        if (!$shift) {
            return back()->with(
                "error",
                "No se encontró el turno operativo asociado.",
            );
        }

        $closingSnapshot = $this->snapshotBuilder->closingFromHandover(
            $activeShift,
        );
        try {
            DB::transaction(function () use (
                $shift,
                $user,
                $closingSnapshot,
                $activeShift,
            ) {
                $this->shiftLifecycle->closeWithSnapshot(
                    $shift,
                    $user,
                    $closingSnapshot,
                );

                $activeShift->from_shift_id = $activeShift->from_shift_id ?: $shift->id;
                $activeShift->summary = $closingSnapshot;
                $activeShift->save();
            });
        } catch (\Throwable $e) {
            $message =
                $e instanceof \App\Exceptions\ShiftRuleViolation
                    ? $e->getMessage()
                    : "No se pudo cerrar el turno.";
            return back()->with("error", $message);
        }


        $this->auditLog(
            "shift_end",
            "Usuario {$user->username} entregó turno {$activeShift->shift_type->value} (handover #{$activeShift->id})",
            [
                "shift_id" => $activeShift->id,
                "base_final" => $activeShift->base_final,
                "total_efectivo" => $activeShift->total_entradas_efectivo,
            ],
        );

        return redirect()
            ->route(
                $this->resolveReceptionistDashboardRoute(
                    $user,
                    $activeShift->shift_type,
                ),
            )
            ->with(
                "success",
                "Turno entregado correctamente. Pendiente de recepción por el siguiente turno.",
            );

    }

    private function resolveReceptionistDashboardRoute(
        User $user,
        ?ShiftType $shiftType = null,
    ): string {
        $isNight =
            $shiftType === ShiftType::NIGHT || $user->hasRole("Recepcionista Noche");

        $route = $isNight
            ? "dashboard.receptionist.night"
            : "dashboard.receptionist.day";

        return app("router")->has($route) ? $route : "dashboard";
    }

    public function receiveShift()
    {
        $user = Auth::user();
        $pendingReception = ShiftHandover::with([
            "entregadoPor",
            "sales.items.product",
            "cashOutflows",
            "cashOuts",
            "productOuts.product",
        ])
            ->where("recibido_por", $user->id)
            ->where("entregado_por", "!=", $user->id)
            ->where("status", ShiftHandoverStatus::DELIVERED)
            ->first();

        if (!$pendingReception) {
            $pendingReception = ShiftHandover::with([
                "entregadoPor",
                "sales.items.product",
                "cashOutflows",
                "cashOuts",
                "productOuts.product",
            ])
                ->whereNull("recibido_por")
                ->where("entregado_por", "!=", $user->id)
                ->where("status", ShiftHandoverStatus::DELIVERED)
                ->oldest("ended_at")
                ->oldest("id")
                ->first();
        }

        return view("shift-handovers.receive", compact("pendingReception"));
    }

    public function storeReception(Request $request)
    {
        $user = Auth::user();
        $handoverId = $request->input("handover_id");
        $handover = ShiftHandover::findOrFail($handoverId);

        if ($handover->status !== ShiftHandoverStatus::DELIVERED) {
            return back()->with(
                "error",
                "Este turno no esta en estado de entrega.",
            );
        }

        // Validar que el turno sea para este usuario o que estÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â© sin asignar (admin puede todo)
        if (!$user->hasRole("Administrador")) {
            if (
                $handover->recibido_por !== null &&
                (int) $handover->recibido_por !== (int) $user->id
            ) {
                abort(403);
            }

            if ((int) $handover->entregado_por === (int) $user->id) {
                return back()->with(
                    "error",
                    "No puedes recibir un turno que tu mismo entregaste.",
                );
            }
        }

        // Sanitize base_recibida
        $baseRecibida = $request->input("base_recibida");
        if (is_string($baseRecibida)) {
            $baseRecibida = str_replace(".", "", $baseRecibida);
            $baseRecibida = (float) str_replace(",", ".", $baseRecibida);
        }

        $handover->recibido_por = $user->id;
        $handover->received_at = Carbon::now();
        $handover->base_recibida = $baseRecibida;
        $handover->observaciones_recepcion = $request->input("observaciones");
        $handover->diferencia =
            $handover->base_recibida - $handover->base_esperada;

        $tolerance = (float) config("shifts.difference_tolerance", 0);
        if (
            $tolerance > 0 &&
            abs((float) $handover->diferencia) > $tolerance &&
            trim((string) $handover->observaciones_recepcion) === ""
        ) {
            return back()->with(
                "error",
                "La diferencia de base supera la tolerancia permitida (" .
                    number_format($tolerance, 2, ",", ".") .
                    "). Debes registrar observaciones.",
            );
        }

        $handover->status = ShiftHandoverStatus::RECEIVED;

        // Abrir siguiente turno desde snapshot de cierre previo
        $previousShift = $handover->from_shift_id
            ? Shift::find($handover->from_shift_id)
            : null;
        if (!$previousShift || !$previousShift->closing_snapshot) {
            return back()->with(
                "error",
                "No hay snapshot de cierre del turno anterior para iniciar el siguiente.",
            );
        }

        $nextType = $previousShift->type;
        $nextShift = null;
        try {
            DB::transaction(function () use (
                $user,
                $nextType,
                $previousShift,
                $handover,
                &$nextShift,
            ) {
                $nextShift = $this->shiftLifecycle->openFresh(
                    $user,
                    $nextType,
                    $previousShift->closing_snapshot,
                );

                $handover->to_shift_id = $nextShift->id;
                $handover->save();

                // Crear el nuevo handover activo para el receptor, enlazado al shift reciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©n abierto
                ShiftHandover::create([
                    "from_shift_id" => $nextShift->id,
                    "entregado_por" => $user->id,
                    "receptionist_name" => $user->name,
                    "shift_type" => $nextType,
                    "shift_date" => Carbon::today(),
                    "started_at" => Carbon::now(),
                    "base_inicial" => $handover->base_recibida ?? 0,
                    "status" => ShiftHandoverStatus::ACTIVE,
                ]);
            });
        } catch (\Throwable $e) {
            $message =
                $e instanceof \App\Exceptions\ShiftRuleViolation
                    ? $e->getMessage()
                    : "No se pudo iniciar el turno siguiente.";
            return back()->with("error", $message);
        }

        $this->auditLog(
            "shift_receive",
            "Usuario {$user->username} recibió turno #{$handover->id}",
            [
                "shift_id" => $handover->id,
                "base_recibida" => $handover->base_recibida,
                "diferencia" => $handover->diferencia,
                "next_shift_id" => $nextShift->id,
            ],
        );

        return back()->with(
            "success",
            "Turno recibido y nuevo turno iniciado desde snapshot previo.",
        );
    }

    // Solo Admin: forzar cierre de turnos operativos abiertos
    public function forceCloseOperational(Request $request)
    {
        $user = Auth::user();
        if (!$user->hasRole("Administrador")) {
            abort(403);
        }

        $reason = $request->input("reason", "Cierre forzado por administrador");
        $closed = $this->shiftLifecycle->forceCloseOperational(
            $user->id,
            $reason,
        );

        return back()->with(
            "success",
            "Se cerraron {$closed} turno(s) operativo(s). Razón: {$reason}",
        );
    }

    public function toggleShiftOperations(Request $request)
    {
        $user = Auth::user();
        if (!$user->hasRole("Administrador")) {
            abort(403);
        }

        $validated = $request->validate([
            "enabled" => "required|boolean",
            "note" => "nullable|string|max:500",
        ]);

        $enabled = (bool) $validated["enabled"];
        $note = trim((string) ($validated["note"] ?? ""));

        $resetSummary = null;
        if (!$enabled) {
            $resetReason = $note !== ""
                ? $note
                : "Reinicio administrativo al desactivar apertura de turnos";
            $resetSummary = $this->shiftLifecycle->resetOperationalChain(
                (int) $user->id,
                $resetReason,
            );
        }

        $this->shiftControl->setOperationalEnabled(
            $enabled,
            (int) $user->id,
            $note !== "" ? $note : null,
        );

        $this->auditLog(
            "shift_operations_toggle",
            $enabled
                ? "Administrador habilitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³ apertura de turnos."
                : "Administrador deshabilitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³ apertura de turnos.",
            [
                "enabled" => $enabled,
                "note" => $note !== "" ? $note : null,
                "reset_summary" => $resetSummary,
            ],
        );

        return back()->with(
            "success",
            $enabled
                ? "Apertura de turnos habilitada para recepcionistas."
                : "Apertura de turnos deshabilitada. Reinicio aplicado: operativos cerrados {$resetSummary["operational_shifts_closed"]}, entregas pendientes cerradas {$resetSummary["delivered_closed"]}, activos cerrados {$resetSummary["active_orphans_closed"]}.",
        );
    }

    // Cash Out Methods
    public function cashOutsIndex()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $query = ShiftCashOut::with(["user", "shiftHandover"])->orderBy(
            "created_at",
            "desc",
        );
        if (!$user->hasRole("Administrador")) {
            $query->where("user_id", $user->id);
        }

        $cashOuts = $query->paginate(15);

        $activeShift = $user->turnoActivo()->first();
        $deleteWindowMinutes = (int) config(
            "shifts.cash_delete_window_minutes",
            60,
        );

        return view("shift-cash-outs.index", [
            "cashOuts" => $cashOuts,
            "activeShiftId" => $activeShift ? $activeShift->id : null,
            "activeShiftStatus" => $activeShift
                ? $activeShift->status->value
                : null,
            "deleteWindowMinutes" => $deleteWindowMinutes,
            "isAdmin" => $user->hasRole("Administrador"),
        ]);
    }

    public function createCashOut()
    {
        $user = Auth::user();
        $activeShift = $user->turnoActivo()->first();
        return view("shift-cash-outs.create", compact("activeShift"));
    }

    public function storeCashOut(Request $request)
    {
        $user = Auth::user();
        $activeShift = $user->turnoActivo()->first();

        if (!$activeShift && !$user->hasRole("Administrador")) {
            return back()->with(
                "error",
                "Debes tener un turno activo para registrar una salida de caja.",
            );
        }

        $request->validate([
            "amount" => "required",
            "concept" => "required|string|max:255",
        ]);

        // Sanitize amount (remove thousand separators)
        $amount = $request->amount;
        if (is_string($amount)) {
            $amount = str_replace(".", "", $amount);
            $amount = (float) str_replace(",", ".", $amount);
        }

        // Determinar el tipo de turno desde el turno operativo
        $shiftType = $activeShift->shift_type;

        // VALIDACIÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã¢â‚¬Å“N DE SALDO DISPONIBLE
        if ($activeShift) {
            $disponible = $activeShift->getEfectivoDisponible();
            if ($amount > $disponible) {
                return back()
                    ->with(
                        "error",
                        "Saldo insuficiente en caja del turno. Disponible: $" .
                            number_format($disponible, 0, ",", "."),
                    )
                    ->withInput();
            }
        }

        $cashOut = ShiftCashOut::create([
            "shift_handover_id" => $activeShift->id,
            "user_id" => $user->id,
            "amount" => $amount,
            "concept" => $request->concept,
            "observations" => $request->observations,
            "shift_type" => $shiftType,
            "shift_date" => Carbon::today(),
        ]);

        // Actualizar totales del turno
        $activeShift->updateTotals();

        $this->auditLog(
            "cash_out",
            "Retiro de caja por {$amount} - Concepto: {$request->concept}",
            [
                "cash_out_id" => $cashOut->id,
                "amount" => $amount,
                "concept" => $request->concept,
            ],
        );

        return redirect()
            ->route("shift-cash-outs.index")
            ->with("success", "Retiro de caja registrado.");
    }

    public function destroyCashOut($id)
    {
        $cashOut = ShiftCashOut::findOrFail($id);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        if (!$user->hasRole("Administrador")) {
            // Solo puede eliminar el suyo, dentro del turno activo y ventana de tiempo
            $activeShift = $user->turnoActivo()->first();
            $window = (int) config("shifts.cash_delete_window_minutes", 60);
            $createdAt = $cashOut->created_at;

            $canDelete =
                $activeShift &&
                $activeShift->status === ShiftHandoverStatus::ACTIVE &&
                (int) $cashOut->user_id === (int) $user->id &&
                (int) ($cashOut->shift_handover_id ?? 0) ===
                    (int) $activeShift->id &&
                $createdAt &&
                now()->diffInMinutes($createdAt) <= $window;

            if (!$canDelete) {
                abort(403);
            }
        }

        $cashOut->delete();
        $this->auditLog("cash_out_delete", "Eliminó retiro de caja #{$id}");

        // Recalcular turno activo si aplica
        $activeShift = $user->turnoActivo()->first();
        if ($activeShift) {
            $activeShift->updateTotals();
        }

        return back()->with("success", "Retiro de caja eliminado.");
    }

    private function emptyShiftInventorySummary(): array
    {
        return [
            "totals" => [
                "products_with_movement" => 0,
                "movements_count" => 0,
                "opening_units" => 0,
                "entries_units" => 0,
                "outputs_units" => 0,
                "sales_units" => 0,
                "room_consumption_units" => 0,
                "closing_units" => 0,
                "net_units" => 0,
            ],
            "products" => collect(),
        ];
    }

    private function buildShiftInventorySummary(
        Carbon $windowStart,
        Carbon $windowEnd,
        bool $endExclusive = false,
    ): array {
        if ($windowEnd->lt($windowStart)) {
            $windowEnd = $windowStart->copy();
        }

        $query = InventoryMovement::query()
            ->with(["product:id,name,sku"])
            ->where("created_at", ">=", $windowStart);

        if ($endExclusive) {
            $query->where("created_at", "<", $windowEnd);
        } else {
            $query->where("created_at", "<=", $windowEnd);
        }

        $movements = $query
            ->orderBy("product_id")
            ->orderBy("created_at")
            ->orderBy("id")
            ->get();

        if ($movements->isEmpty()) {
            return $this->emptyShiftInventorySummary();
        }

        $products = $movements
            ->groupBy("product_id")
            ->map(function (Collection $productMovements, int|string $productId) {
                $firstMovement = $productMovements->first();
                $lastMovement = $productMovements->last();

                $opening = (int) ($firstMovement->previous_stock ?? 0);
                $closing = (int) ($lastMovement->current_stock ?? $opening);

                $entries = (int) $productMovements
                    ->filter(fn($movement) => (int) $movement->quantity > 0)
                    ->sum("quantity");

                $outputs = (int) abs(
                    (int) $productMovements
                        ->filter(fn($movement) => (int) $movement->quantity < 0)
                        ->sum("quantity"),
                );

                $sales = (int) abs(
                    (int) $productMovements->where("type", "sale")->sum("quantity"),
                );

                $roomConsumption = (int) abs(
                    (int) $productMovements
                        ->where("type", "room_consumption")
                        ->sum("quantity"),
                );

                return [
                    "product_id" => (int) $productId,
                    "product_name" => (string) ($firstMovement->product?->name ??
                        "Producto #{$productId}"),
                    "product_sku" => $firstMovement->product?->sku,
                    "movements_count" => $productMovements->count(),
                    "opening" => $opening,
                    "entries" => $entries,
                    "outputs" => $outputs,
                    "sales" => $sales,
                    "room_consumption" => $roomConsumption,
                    "closing" => $closing,
                    "net" => $closing - $opening,
                ];
            })
            ->sortBy("product_name", SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            "totals" => [
                "products_with_movement" => $products->count(),
                "movements_count" => $movements->count(),
                "opening_units" => (int) $products->sum("opening"),
                "entries_units" => (int) $products->sum("entries"),
                "outputs_units" => (int) $products->sum("outputs"),
                "sales_units" => (int) $products->sum("sales"),
                "room_consumption_units" => (int) $products->sum("room_consumption"),
                "closing_units" => (int) $products->sum("closing"),
                "net_units" => (int) $products->sum("net"),
            ],
            "products" => $products,
        ];
    }

    private function auditLog($event, $description, $metadata = [])
    {
        AuditLog::create([
            "user_id" => Auth::id(),
            "event" => $event,
            "description" => $description,
            "ip_address" => request()->ip(),
            "user_agent" => request()->userAgent(),
            "metadata" => $metadata,
        ]);
    }
}



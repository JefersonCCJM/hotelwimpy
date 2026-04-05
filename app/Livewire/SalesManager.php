<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use App\Enums\ShiftHandoverStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Room;
use App\Models\Product;
use App\Models\Category;
use App\Models\User;
use App\Models\ExternalIncome;
use App\Services\RoomConsumptionLinkService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalesManager extends Component
{
    use WithPagination;

    // Filtros
    public $date;
    public $search = '';
    public $debt_status = '';
    public $receptionist_id = '';
    public $payment_method = '';
    public $category_id = '';
    public $room_id = '';

    // Estado para filtros avanzados
    public $filtersOpen = false;

    // Estado de modales
    public bool $showSaleModalOpen = false;
    public bool $editSaleModalOpen = false;
    public ?int $selectedSaleId = null;
    public int $showSaleModalKey = 0;
    public int $editSaleModalKey = 0;
    public array $paymentMethodSelection = [];

    // Ingresos externos
    public array $externalIncomeForm = [
        'amount' => '',
        'payment_method' => 'efectivo',
        'reason' => '',
        'notes' => '',
    ];

    // Venta rápida (inline)
    public array $quickSaleForm = [
        'product_id' => '',
        'quantity' => 1,
        'room_id' => '',
        'payment_method' => 'efectivo',
        'notes' => '',
    ];

    // Query string para mantener filtros en URL
    protected $queryString = [
        'date' => ['except' => ''],
        'search' => ['except' => ''],
        'debt_status' => ['except' => ''],
        'receptionist_id' => ['except' => ''],
        'payment_method' => ['except' => ''],
        'category_id' => ['except' => ''],
        'room_id' => ['except' => ''],
    ];

    public function mount($date = null)
    {
        // Si se pasa fecha como parámetro, usarla; si no, usar la del query string o la actual
        if ($date) {
            $this->date = $date;
        } elseif (!$this->date) {
            $this->date = request('date') ?: now()->format('Y-m-d');
        }

        // Cargar filtros desde query string si existen
        if (request()->filled('search')) {
            $this->search = request('search');
        }
        if (request()->filled('debt_status')) {
            $this->debt_status = request('debt_status');
        }
        if (request()->filled('receptionist_id')) {
            $this->receptionist_id = request('receptionist_id');
        }
        if (request()->filled('payment_method')) {
            $this->payment_method = request('payment_method');
        }
        if (request()->filled('category_id')) {
            $this->category_id = request('category_id');
        }
        if (request()->filled('room_id')) {
            $this->room_id = request('room_id');
        }

        // Abrir filtros si hay algún filtro activo
        $this->filtersOpen = $this->hasActiveFilters();
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedDebtStatus()
    {
        $this->resetPage();
    }

    public function updatedReceptionistId()
    {
        $this->resetPage();
    }

    public function updatedDate()
    {
        $this->resetPage();
    }

    public function updatedPaymentMethod()
    {
        $this->resetPage();
    }

    public function updatedCategoryId()
    {
        $this->resetPage();
    }

    public function updatedRoomId()
    {
        $this->resetPage();
    }

    public function changeDate($newDate)
    {
        $this->date = $newDate;
        $this->resetPage();
    }

    public function clearFilters()
    {
        $this->search = '';
        $this->debt_status = '';
        $this->receptionist_id = '';
        $this->payment_method = '';
        $this->category_id = '';
        $this->room_id = '';
        $this->date = now()->format('Y-m-d');
        $this->resetPage();
    }

    private function hasActiveFilters(): bool
    {
        return !empty($this->search) ||
               !empty($this->debt_status) ||
               !empty($this->receptionist_id) ||
               !empty($this->payment_method) ||
               !empty($this->category_id) ||
               !empty($this->room_id);
    }

    private function resolveOperationalActiveHandover(?User $user, ?Shift $operationalShift, bool $isAdmin): ?ShiftHandover
    {
        if (!$operationalShift) {
            return null;
        }

        $userActiveHandover = $user?->turnoActivo()->first();
        if (
            $userActiveHandover &&
            (int) ($userActiveHandover->from_shift_id ?? $userActiveHandover->id) === (int) $operationalShift->id
        ) {
            return $userActiveHandover;
        }

        if (!$isAdmin) {
            return null;
        }

        return ShiftHandover::query()
            ->where('status', ShiftHandoverStatus::ACTIVE)
            ->whereRaw('COALESCE(from_shift_id, id) = ?', [(int) $operationalShift->id])
            ->latest('started_at')
            ->latest('id')
            ->first();
    }

    public function openShowSaleModal(int $saleId): void
    {
        if (!Auth::user()?->can('view_sales') || !$this->saleExists($saleId)) {
            return;
        }

        $this->closeAllSaleModals();
        $this->selectedSaleId = $saleId;
        $this->showSaleModalOpen = true;
        $this->showSaleModalKey++;
    }

    public function closeShowSaleModal(): void
    {
        $this->showSaleModalOpen = false;
        $this->selectedSaleId = null;
    }

    public function openEditSaleModal(int $saleId): void
    {
        if (!Auth::user()?->can('edit_sales') || !$this->saleExists($saleId)) {
            return;
        }

        $this->closeAllSaleModals();
        $this->selectedSaleId = $saleId;
        $this->editSaleModalOpen = true;
        $this->editSaleModalKey++;
    }

    public function closeEditSaleModal(): void
    {
        $this->editSaleModalOpen = false;
        $this->selectedSaleId = null;
    }

    #[On('sales-open-show-modal')]
    public function openShowSaleModalFromEvent(int $saleId): void
    {
        $this->openShowSaleModal($saleId);
    }

    #[On('sales-open-edit-modal')]
    public function openEditSaleModalFromEvent(int $saleId): void
    {
        $this->openEditSaleModal($saleId);
    }

    #[On('sales-close-modal')]
    public function closeAllSaleModals(): void
    {
        $this->showSaleModalOpen = false;
        $this->editSaleModalOpen = false;
        $this->selectedSaleId = null;
    }

    private function saleExists(int $saleId): bool
    {
        return Sale::whereKey($saleId)->exists();
    }

    public function getSelectedSaleProperty(): ?Sale
    {
        if (!$this->selectedSaleId) {
            return null;
        }

        return Sale::find($this->selectedSaleId);
    }

    public function markSaleAsPaid(int $saleId): void
    {
        if (!Auth::user()?->can('edit_sales')) {
            return;
        }

        /** @var Sale|null $sale */
        $sale = Sale::find($saleId);
        if (!$sale) {
            $this->dispatch('notify', type: 'error', message: 'La venta no existe.');
            return;
        }

        if ($sale->debt_status !== 'pendiente') {
            $this->dispatch('notify', type: 'warning', message: 'La venta ya está pagada.');
            return;
        }

        $selectedMethod = (string) ($this->paymentMethodSelection[$saleId] ?? 'efectivo');
        if (!in_array($selectedMethod, ['efectivo', 'transferencia'], true)) {
            $selectedMethod = 'efectivo';
        }

        $roomConsumptionLinkService = app(RoomConsumptionLinkService::class);

        DB::transaction(function () use ($sale, $selectedMethod, $roomConsumptionLinkService): void {
            $cashAmount = $selectedMethod === 'efectivo' ? (float) $sale->total : null;
            $transferAmount = $selectedMethod === 'transferencia' ? (float) $sale->total : null;

            $sale->update([
                'payment_method' => $selectedMethod,
                'cash_amount' => $cashAmount,
                'transfer_amount' => $transferAmount,
                'debt_status' => 'pagado',
            ]);
            $sale->refresh();
            $roomConsumptionLinkService->syncPaymentStatusFromSale($sale);

            if ($sale->shiftHandover) {
                $sale->shiftHandover->updateTotals();
            }
        });

        unset($this->paymentMethodSelection[$saleId]);
        $this->dispatch('notify', type: 'success', message: 'Venta marcada como pagada.');
    }

    public function registerQuickSale(): void
    {
        if (!Auth::user()?->can('create_sales')) {
            return;
        }

        $validated = $this->validate([
            'quickSaleForm.product_id' => ['required', 'exists:products,id'],
            'quickSaleForm.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'quickSaleForm.room_id' => ['nullable', 'exists:rooms,id'],
            'quickSaleForm.payment_method' => ['required', 'in:efectivo,transferencia,pendiente'],
            'quickSaleForm.notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'quickSaleForm.product_id.required' => 'Debe seleccionar un producto.',
            'quickSaleForm.quantity.required' => 'La cantidad es obligatoria.',
            'quickSaleForm.quantity.min' => 'La cantidad debe ser mayor a 0.',
            'quickSaleForm.payment_method.required' => 'Debe seleccionar un método de pago.',
        ]);

        $user = Auth::user();
        $operationalShift = Shift::openOperational()->first();
        $isAdmin = $user?->hasRole('Administrador') ?? false;
        $activeHandover = $this->resolveOperationalActiveHandover($user, $operationalShift, $isAdmin);

        if (!$operationalShift || !$activeHandover) {
            $this->dispatch('notify', type: 'error', message: 'Debe existir un turno operativo abierto para registrar la venta.');
            return;
        }

        $selectedDate = $this->date ?: now()->format('Y-m-d');
        $payload = $validated['quickSaleForm'];
        $roomConsumptionLinkService = app(RoomConsumptionLinkService::class);
        $linkedReservation = null;

        if (!empty($payload['room_id'])) {
            $linkedReservation = $roomConsumptionLinkService->resolveReservationForRoomOnDate(
                (int) $payload['room_id'],
                Carbon::parse($selectedDate)
            );

            if (!$linkedReservation) {
                $this->dispatch('notify', type: 'error', message: 'La habitación seleccionada no tiene una reserva activa para cargar el consumo.');
                return;
            }
        }

        try {
            DB::transaction(function () use ($payload, $user, $activeHandover, $selectedDate, $roomConsumptionLinkService, $linkedReservation): void {
                /** @var Product $product */
                $product = Product::query()->lockForUpdate()->findOrFail((int) $payload['product_id']);
                $quantity = (int) $payload['quantity'];

                if ((int) ($product->quantity ?? 0) < $quantity) {
                    throw new \DomainException("Stock insuficiente para {$product->name}. Disponible: {$product->quantity}");
                }

                $total = round((float) ($product->price ?? 0) * $quantity, 2);
                if ($total <= 0) {
                    throw new \DomainException('El total de la venta debe ser mayor a 0.');
                }

                $paymentMethod = (string) $payload['payment_method'];
                $cashAmount = null;
                $transferAmount = null;
                $debtStatus = 'pagado';

                if ($paymentMethod === 'efectivo') {
                    $cashAmount = $total;
                } elseif ($paymentMethod === 'transferencia') {
                    $transferAmount = $total;
                } else {
                    $debtStatus = 'pendiente';
                }

                $sale = Sale::create([
                    'user_id' => (int) $user->id,
                    'room_id' => !empty($payload['room_id']) ? (int) $payload['room_id'] : null,
                    'shift_handover_id' => $activeHandover->id,
                    'payment_method' => $paymentMethod,
                    'cash_amount' => $cashAmount,
                    'transfer_amount' => $transferAmount,
                    'debt_status' => $debtStatus,
                    'sale_date' => $selectedDate,
                    'total' => $total,
                    'notes' => !empty($payload['notes']) ? trim((string) $payload['notes']) : null,
                ]);

                SaleItem::create([
                    'sale_id' => (int) $sale->id,
                    'product_id' => (int) $product->id,
                    'quantity' => $quantity,
                    'unit_price' => (float) $product->price,
                    'total' => $total,
                ]);

                if ($linkedReservation && !empty($payload['room_id'])) {
                    $roomConsumptionLinkService->syncSaleItemsToReservation($sale, $linkedReservation);
                }

                $product->recordMovement(
                    -$quantity,
                    'sale',
                    "Venta #{$sale->id}",
                    !empty($payload['room_id']) ? (int) $payload['room_id'] : null
                );

                $sale->shiftHandover?->updateTotals();
            });

            $this->quickSaleForm = [
                'product_id' => '',
                'quantity' => 1,
                'room_id' => '',
                'payment_method' => 'efectivo',
                'notes' => '',
            ];

            $this->dispatch('notify', type: 'success', message: 'Venta registrada correctamente.');
        } catch (\Throwable $e) {
            \Log::error('Error registering quick sale', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'form' => $this->quickSaleForm,
            ]);
            $message = $e instanceof \DomainException
                ? $e->getMessage()
                : 'Error al registrar la venta. Intente nuevamente.';
            $this->dispatch('notify', type: 'error', message: $message);
        }
    }

    public function registerExternalIncome(): void
    {
        if (!Auth::user()?->can('create_sales')) {
            return;
        }

        $validated = $this->validate([
            'externalIncomeForm.amount' => ['required', 'numeric', 'gt:0'],
            'externalIncomeForm.payment_method' => ['required', 'in:efectivo,transferencia'],
            'externalIncomeForm.reason' => ['required', 'string', 'max:180'],
            'externalIncomeForm.notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'externalIncomeForm.amount.required' => 'El monto es obligatorio.',
            'externalIncomeForm.amount.gt' => 'El monto debe ser mayor a 0.',
            'externalIncomeForm.payment_method.required' => 'Debe seleccionar un método de pago.',
            'externalIncomeForm.reason.required' => 'El motivo es obligatorio.',
        ]);

        $user = Auth::user();
        $operationalShift = Shift::openOperational()->first();
        $isAdmin = $user?->hasRole('Administrador') ?? false;
        $activeHandover = $this->resolveOperationalActiveHandover($user, $operationalShift, $isAdmin);

        if (!$operationalShift || !$activeHandover) {
            $this->dispatch('notify', type: 'error', message: 'Debe existir un turno operativo abierto para registrar ingresos externos.');
            return;
        }

        $selectedDate = $this->date ?: now()->format('Y-m-d');

        DB::transaction(function () use ($validated, $user, $activeHandover, $selectedDate): void {
            $income = ExternalIncome::create([
                'user_id' => (int) $user->id,
                'shift_handover_id' => $activeHandover->id,
                'payment_method' => (string) $validated['externalIncomeForm']['payment_method'],
                'income_date' => $selectedDate,
                'amount' => (float) $validated['externalIncomeForm']['amount'],
                'reason' => trim((string) $validated['externalIncomeForm']['reason']),
                'notes' => !empty($validated['externalIncomeForm']['notes'])
                    ? trim((string) $validated['externalIncomeForm']['notes'])
                    : null,
            ]);

            $income->shiftHandover?->updateTotals();
        });

        $this->externalIncomeForm = [
            'amount' => '',
            'payment_method' => 'efectivo',
            'reason' => '',
            'notes' => '',
        ];

        $this->dispatch('notify', type: 'success', message: 'Ingreso externo registrado correctamente.');
    }

    public function render()
    {
        $user = Auth::user();
        $operationalShift = Shift::openOperational()->first();
        $isAdmin = $user?->hasRole('Administrador') ?? false;
        $activeHandover = $this->resolveOperationalActiveHandover($user, $operationalShift, $isAdmin);

        $query = Sale::with(['user', 'room', 'items.product.category']);

        $selectedDate = $this->date ?: now()->format('Y-m-d');
        $currentDate = Carbon::parse($selectedDate);

        if ($activeHandover) {
            $query->where('shift_handover_id', $activeHandover->id);
        } else {
            $query->byDate($selectedDate);
        }

        // Preparar días para la barra de calendario
        $startOfMonth = $currentDate->copy()->startOfMonth();
        $endOfMonth = $currentDate->copy()->endOfMonth();
        $daysInMonth = [];
        $tempDate = $startOfMonth->copy();
        while ($tempDate <= $endOfMonth) {
            $daysInMonth[] = $tempDate->copy();
            $tempDate->addDay();
        }

        // Filtro por búsqueda
        if ($this->search) {
            $query->where(function($q) {
                $q->whereHas('user', function($userQuery) {
                    $userQuery->where('name', 'like', '%' . $this->search . '%');
                })
                ->orWhereHas('room', function($roomQuery) {
                    $roomQuery->where('room_number', 'like', '%' . $this->search . '%');
                });
            });
        }

        // Filtro por recepcionista
        if ($this->receptionist_id) {
                $query->byReceptionist($this->receptionist_id);
        }

        // Filtro por método de pago
        if ($this->payment_method) {
            $query->byPaymentMethod($this->payment_method);
        }

        // Filtro por estado de deuda
        if ($this->debt_status) {
            $query->where('debt_status', $this->debt_status);
        }

        // Filtro por habitación
        if ($this->room_id) {
            if ($this->room_id === 'normal') {
                $query->whereNull('room_id');
            } else {
                $query->where('room_id', $this->room_id);
            }
        }

        // Filtro por categoría
        if ($this->category_id) {
            $query->whereHas('items.product', function($q) {
                $q->where('category_id', $this->category_id);
            });
        }

        $sales = $query->orderBy('sale_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Calcular estadísticas del turno activo (o fecha seleccionada si no hay turno)
        $statsQuery = $activeHandover
            ? Sale::where('shift_handover_id', $activeHandover->id)
            : Sale::whereDate('sale_date', $selectedDate);
        $totalSales = $statsQuery->count();
        $paidSales = (clone $statsQuery)->where('debt_status', 'pagado')->count();
        $pendingSales = (clone $statsQuery)->where('debt_status', 'pendiente')->count();
        $totalCollected = (clone $statsQuery)->where('debt_status', 'pagado')->sum('total');

        $externalIncomes = ExternalIncome::with(['user'])
            ->when($activeHandover, fn($q) => $q->where('shift_handover_id', $activeHandover->id), fn($q) => $q->byDate($selectedDate))
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();
        $externalIncomesTotal = (float) $externalIncomes->sum('amount');

        // Obtener datos para filtros
        $receptionists = User::whereHas('roles', function($q) {
            $q->whereIn('name', ['Administrador', 'Recepcionista Día', 'Recepcionista Noche']);
        })->with('roles')->get();

        $rooms = Room::all();
        $categories = Category::whereIn('name', ['Bebidas', 'Mecato'])->get();
        $quickProducts = Product::query()
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->whereHas('category', function ($q): void {
                $aseoKeywords = ['aseo', 'limpieza', 'amenities', 'insumo', 'papel', 'jabon', 'cloro', 'mantenimiento'];
                foreach ($aseoKeywords as $keyword) {
                    $q->where('name', 'not like', '%' . $keyword . '%');
                }
            })
            ->with('category')
            ->orderBy('name')
            ->get();

        // Obtener conteo de ventas por día para el mes actual (para indicadores en el calendario)
        $salesByDay = Sale::whereBetween('sale_date', [$startOfMonth->format('Y-m-d'), $endOfMonth->format('Y-m-d')])
            ->selectRaw('DATE(sale_date) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date')
            ->toArray();

        return view('livewire.sales-manager', [
            'sales' => $sales,
            'receptionists' => $receptionists,
            'rooms' => $rooms,
            'categories' => $categories,
            'quickProducts' => $quickProducts,
            'totalSales' => $totalSales,
            'paidSales' => $paidSales,
            'pendingSales' => $pendingSales,
            'totalCollected' => $totalCollected,
            'externalIncomes' => $externalIncomes,
            'externalIncomesTotal' => $externalIncomesTotal,
            'selectedDate' => $selectedDate,
            'currentDate' => $currentDate,
            'daysInMonth' => $daysInMonth,
            'salesByDay' => $salesByDay,
        ]);
    }
}

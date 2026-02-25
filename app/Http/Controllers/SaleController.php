<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\UpdateSaleRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Product;
use App\Models\Room;
use App\Models\Category;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Services\RoomConsumptionLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Carbon\Carbon;
use App\Models\AuditLog;

class SaleController extends Controller
{
    /**
     * Display a listing of the resource.
     * Now handled by Livewire component SalesManager
     */
    public function index(Request $request): View
    {
        return view('sales.index');
    }

    /**
     * Show the form for creating a new resource.
     * Now handled by Livewire component CreateSale
     */
    public function create(): View
    {
        return view('sales.create');
    }

    /**
     * Store a newly created resource in storage.
     * Handles business logic for creating sales.
     */
    public function store(StoreSaleRequest $request, RoomConsumptionLinkService $roomConsumptionLinkService): RedirectResponse
    {
        DB::beginTransaction();

        try {
            $user = Auth::user();
            $saleDate = $request->filled('sale_date')
                ? Carbon::parse((string) $request->sale_date)
                : now();
            $linkedReservation = null;

            // Los valores ya vienen sanitizados por StoreSaleRequest@prepareForValidation
            $cashAmount = (float) $request->cash_amount;
            $transferAmount = (float) $request->transfer_amount;

            // Validar stock de todos los productos
            $items = $request->items;
            $total = 0;

            foreach ($items as $item) {
                $product = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($item['product_id']);

                if ($product->quantity < $item['quantity']) {
                    DB::rollBack();
                    return back()
                        ->withInput()
                        ->withErrors(['items' => "Stock insuficiente para {$product->name}. Disponible: {$product->quantity}"]);
                }

                $itemTotal = $product->price * $item['quantity'];
                $total += $itemTotal;
            }

            // Validar y calcular montos de pago según el método
            if ($request->payment_method === 'efectivo') {
                $cashAmount = $total;
                $transferAmount = null;
            } elseif ($request->payment_method === 'transferencia') {
                $cashAmount = null;
                $transferAmount = $total;
            } elseif ($request->payment_method === 'ambos') {
                // Ya vienen del request sanitizados
                if (abs(($cashAmount + $transferAmount) - $total) > 0.01) {
                    DB::rollBack();
                    return back()
                        ->withInput()
                        ->withErrors(['payment_method' => "La suma de efectivo ($" . number_format($cashAmount, 0, ',', '.') . ") y transferencia ($" . number_format($transferAmount, 0, ',', '.') . ") debe ser igual al total: $" . number_format($total, 0, ',', '.')]);
                }
            } elseif ($request->payment_method === 'pendiente') {
                $cashAmount = null;
                $transferAmount = null;
            }

            // Determinar estado de deuda
            if ($request->payment_method === 'pendiente') {
                $debtStatus = 'pendiente';
            } elseif ($request->room_id) {
                $debtStatus = $request->debt_status ?? 'pagado';
            } else {
                $debtStatus = 'pagado';
            }

            if (!empty($request->room_id)) {
                $linkedReservation = $roomConsumptionLinkService->resolveReservationForRoomOnDate(
                    (int) $request->room_id,
                    $saleDate->copy()
                );

                if (!$linkedReservation) {
                    DB::rollBack();

                    return back()
                        ->withInput()
                        ->withErrors(['room_id' => 'La habitación seleccionada no tiene una reserva activa para cargar el consumo.']);
                }
            }

            $isAdmin = $user->hasRole('Administrador');

            // Validar turno operativo abierto (excepto administradores)
            $operationalShift = Shift::openOperational()->first();
            $activeHandover = $user->turnoActivo()->first();
            if (
                !$isAdmin &&
                (
                    !$operationalShift ||
                    !$activeHandover ||
                    (int) ($activeHandover->from_shift_id ?? $activeHandover->id) !== (int) $operationalShift->id
                )
            ) {
                DB::rollBack();
                return back()->withInput()->withErrors(['turno' => 'Debe existir un turno operativo abierto para registrar la venta.']);
            }

            // Crear la venta
            $sale = Sale::create([
                'user_id' => $user->id,
                'room_id' => $request->room_id ?: null,
                'shift_handover_id' => $activeHandover?->id,
                'payment_method' => $request->payment_method,
                'cash_amount' => $cashAmount,
                'transfer_amount' => $transferAmount,
                'debt_status' => $debtStatus,
                'sale_date' => $saleDate,
                'total' => $total,
                'notes' => $request->notes,
            ]);

            // Crear items y descontar stock
            foreach ($items as $item) {
                $product = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($item['product_id']);
                $itemTotal = $product->price * $item['quantity'];

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'total' => $itemTotal,
                ]);

                // Descontar del inventario y registrar movimiento histórico
                $product->recordMovement(-$item['quantity'], 'sale', "Venta #{$sale->id}", $sale->room_id);
            }

            if ($linkedReservation && !empty($sale->room_id)) {
                $roomConsumptionLinkService->syncSaleItemsToReservation($sale, $linkedReservation);
            }

            // Actualizar totales del turno cuando existe asociación
            if ($activeHandover) {
                $activeHandover->updateTotals();
            }

            $this->auditLog('sale_create', "Venta #{$sale->id} registrada por {$total}. Método: {$sale->payment_method}", ['sale_id' => $sale->id]);

            DB::commit();

            return redirect()
                ->route('sales.index')
                ->with('success', 'Venta registrada exitosamente.');
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Error registrando venta: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all(),
                'user' => Auth::id()
            ]);

            return back()
                ->withInput()
                ->withErrors(['error' => 'Error al registrar la venta. Intente nuevamente.']);
        }
    }

    /**
     * Display the specified resource.
     * Now handled by Livewire component ShowSale
     */
    public function show(Sale $sale): View
    {
        return view('sales.show', compact('sale'));
    }

    /**
     * Show the form for editing the specified resource.
     * Now handled by Livewire component EditSale
     */
    public function edit(Sale $sale): View
    {
        return view('sales.edit', compact('sale'));
    }

    /**
     * Update the specified resource in storage.
     * Handles business logic for updating sales.
     */
    public function update(UpdateSaleRequest $request, Sale $sale, RoomConsumptionLinkService $roomConsumptionLinkService): RedirectResponse
    {
        DB::beginTransaction();

        try {
            // Los valores ya vienen sanitizados por UpdateSaleRequest@prepareForValidation
            $cashAmount = (float) $request->cash_amount;
            $transferAmount = (float) $request->transfer_amount;
            $total = (float) $sale->total;

            if ($request->payment_method === 'efectivo') {
                $cashAmount = $total;
                $transferAmount = null;
            } elseif ($request->payment_method === 'transferencia') {
                $cashAmount = null;
                $transferAmount = $total;
            } elseif ($request->payment_method === 'ambos') {
                // Ya vienen del request sanitizados
                if (abs(($cashAmount + $transferAmount) - $total) > 0.01) {
                    DB::rollBack();
                    return back()
                        ->withInput()
                        ->withErrors(['payment_method' => "La suma de efectivo ($" . number_format($cashAmount, 0, ',', '.') . ") y transferencia ($" . number_format($transferAmount, 0, ',', '.') . ") debe ser igual al total: $" . number_format($total, 0, ',', '.')]);
                }
            } elseif ($request->payment_method === 'pendiente') {
                $cashAmount = null;
                $transferAmount = null;
            }

            // Actualizar data
            $updateData = [
                'payment_method' => $request->payment_method,
                'cash_amount' => $cashAmount,
                'transfer_amount' => $transferAmount,
                'notes' => $request->notes,
            ];

            // Determinar estado de deuda
            if ($request->payment_method === 'pendiente') {
                $updateData['debt_status'] = 'pendiente';
            } elseif ($sale->room_id && $request->filled('debt_status')) {
                $updateData['debt_status'] = $request->debt_status;
            } elseif ($sale->debt_status === 'pendiente' && $request->payment_method !== 'pendiente') {
                $updateData['debt_status'] = 'pagado';
            } else {
                $updateData['debt_status'] = 'pagado';
            }

            $sale->update($updateData);
            $sale->refresh();
            $roomConsumptionLinkService->syncPaymentStatusFromSale($sale);

            // Si la venta tiene un turno asociado, actualizar totales del turno
            if ($sale->shiftHandover) {
                $sale->shiftHandover->updateTotals();
            }

            $this->auditLog('sale_update', "Venta #{$sale->id} actualizada. Nuevo total: {$sale->total}. Método: {$sale->payment_method}", ['sale_id' => $sale->id]);

            DB::commit();

            return redirect()
                ->route('sales.show', $sale)
                ->with('success', 'Venta actualizada exitosamente.');
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Error actualizando venta: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all(),
                'sale_id' => $sale->id
            ]);

            return back()
                ->withInput()
                ->withErrors(['error' => 'Error al actualizar la venta. Intente nuevamente.']);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Sale $sale, RoomConsumptionLinkService $roomConsumptionLinkService): RedirectResponse
    {
        DB::beginTransaction();

        try {
            $roomConsumptionLinkService->deleteLinkedReservationSales($sale);

            // Restaurar stock de productos y registrar ajuste histórico
            foreach ($sale->items as $item) {
                $product = $item->product;
                $product->recordMovement($item['quantity'], 'adjustment', "Venta #{$sale->id} eliminada (restauración)");
            }

            $shiftHandoverId = $sale->shift_handover_id;
            $sale->delete();

            if ($shiftHandoverId) {
                $shift = ShiftHandover::find($shiftHandoverId);
                if ($shift) {
                    $shift->updateTotals();
                }
            }

            $this->auditLog('sale_delete', "Venta #{$sale->id} eliminada. Total era: {$sale->total}", ['sale_id' => $sale->id]);

            DB::commit();

            return redirect()
                ->route('sales.index')
                ->with('success', 'Venta eliminada exitosamente. El stock ha sido restaurado.');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error eliminando venta: ' . $e->getMessage(), [
                'exception' => $e,
                'sale_id' => $sale->id,
            ]);

            return back()
                ->withErrors(['error' => 'Error al eliminar la venta. Intente nuevamente.']);
        }
    }

    /**
     * Show sales grouped by room and category.
     * Now handled by Livewire component SalesByRoom
     */
    public function byRoom(Request $request): View
    {
        return view('sales.by-room');
    }

    /**
     * Show daily sales report.
     * Now handled by Livewire component SalesReports
     */
    public function dailyReport(Request $request): View
    {
        return view('sales.reports');
    }

    private function auditLog($event, $description, $metadata = [])
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'event' => $event,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => $metadata
        ]);
    }
}

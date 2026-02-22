<?php

namespace App\Models;

use App\Enums\ShiftHandoverStatus;
use App\Enums\ShiftType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ShiftHandover extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_shift_id',
        'to_shift_id',
        'entregado_por',
        'receptionist_name',
        'recibido_por',
        'shift_type',
        'shift_date',
        'started_at',
        'ended_at',
        'received_at',
        'base_inicial',
        'base_final',
        'base_recibida',
        'total_entradas_efectivo',
        'total_entradas_transferencia',
        'total_salidas',
        'base_esperada',
        'diferencia',
        'observaciones_entrega',
        'observaciones_recepcion',
        'summary',
        'validated_by',
        'status',
    ];

    protected $casts = [
        'shift_type' => ShiftType::class,
        'shift_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'received_at' => 'datetime',
        'base_inicial' => 'decimal:2',
        'base_final' => 'decimal:2',
        'base_recibida' => 'decimal:2',
        'total_entradas_efectivo' => 'decimal:2',
        'total_entradas_transferencia' => 'decimal:2',
        'total_salidas' => 'decimal:2',
        'base_esperada' => 'decimal:2',
        'diferencia' => 'decimal:2',
        'status' => ShiftHandoverStatus::class,
        'summary' => 'array',
    ];

    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por');
    }

    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function fromShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'from_shift_id');
    }

    public function toShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'to_shift_id');
    }

    public function cashOuts(): HasMany
    {
        return $this->hasMany(ShiftCashOut::class);
    }

    public function cashOutflows(): HasMany
    {
        return $this->hasMany(CashOutflow::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function externalIncomes(): HasMany
    {
        return $this->hasMany(ExternalIncome::class);
    }

    public function productOuts(): HasMany
    {
        return $this->hasMany(ShiftProductOut::class);
    }

    // Business Logic Methods
    public function calcularBaseEsperada(): float
    {
        return (float) ($this->base_inicial + $this->total_entradas_efectivo - $this->total_salidas);
    }

    public function calcularDiferencia(): float
    {
        return (float) ($this->base_recibida - $this->base_esperada);
    }

    public function getTotalEntradasEfectivo(): float
    {
        return (float) $this->total_entradas_efectivo;
    }

    public function getTotalEntradasTransferencia(): float
    {
        return (float) $this->total_entradas_transferencia;
    }

    public function getTotalSalidas(): float
    {
        return (float) $this->total_salidas;
    }

    public function getEfectivoDisponible(): float
    {
        $this->updateTotals();
        return (float) $this->base_esperada;
    }

    public function getReceptionistDisplayNameAttribute(): string
    {
        if (filled($this->receptionist_name)) {
            return (string) $this->receptionist_name;
        }

        return (string) ($this->entregadoPor?->name ?? 'N/A');
    }

    public function updateTotals(): void
    {
        $salesCashTotal = (float) $this->sales()->where('payment_method', 'efectivo')->sum('cash_amount')
            + (float) $this->sales()->where('payment_method', 'ambos')->sum('cash_amount');

        $salesTransferTotal = (float) $this->sales()->where('payment_method', 'transferencia')->sum('transfer_amount')
            + (float) $this->sales()->where('payment_method', 'ambos')->sum('transfer_amount');

        $externalIncomeCashTotal = (float) $this->externalIncomes()
            ->where('payment_method', 'efectivo')
            ->sum('amount');
        $externalIncomeTransferTotal = (float) $this->externalIncomes()
            ->where('payment_method', 'transferencia')
            ->sum('amount');

        $windowStart = $this->started_at ?? $this->created_at ?? now();
        $windowEnd = $this->ended_at ?? now();
        if ($windowEnd->lt($windowStart)) {
            $windowEnd = $windowStart->copy();
        }

        // Incluir pagos de hospedaje (tabla payments) en el mismo turno.
        // Esto permite que "pago de noche" impacte la caja del recepcionista.
        $lodgingPayments = DB::table('payments as p')
            ->leftJoin('payments_methods as pm', 'pm.id', '=', 'p.payment_method_id')
            ->whereBetween(DB::raw('COALESCE(p.paid_at, p.created_at)'), [$windowStart, $windowEnd])
            ->selectRaw("
                COALESCE(SUM(CASE
                    WHEN LOWER(COALESCE(pm.code, '')) IN ('efectivo', 'cash')
                      OR LOWER(COALESCE(pm.name, '')) = 'efectivo'
                    THEN p.amount ELSE 0 END), 0) as cash_total
            ")
            ->selectRaw("
                COALESCE(SUM(CASE
                    WHEN LOWER(COALESCE(pm.code, '')) IN ('transferencia', 'transfer')
                      OR LOWER(COALESCE(pm.name, '')) = 'transferencia'
                    THEN p.amount ELSE 0 END), 0) as transfer_total
            ")
            ->first();

        $paymentsCashTotal = (float) ($lodgingPayments->cash_total ?? 0);
        $paymentsTransferTotal = (float) ($lodgingPayments->transfer_total ?? 0);

        $this->total_entradas_efectivo = $salesCashTotal + $paymentsCashTotal + $externalIncomeCashTotal;
        $this->total_entradas_transferencia = $salesTransferTotal + $paymentsTransferTotal + $externalIncomeTransferTotal;

        // Total salidas de caja del turno:
        // - Gastos (CashOutflow)
        // - Retiros/traslados de efectivo (ShiftCashOut)
        $this->total_salidas = (float) $this->cashOutflows()->sum('amount')
            + (float) $this->cashOuts()->sum('amount');

        $this->base_esperada = $this->calcularBaseEsperada();
        $this->save();
    }

    // Scopes
    public function scopeActivo($query)
    {
        return $query->where('status', ShiftHandoverStatus::ACTIVE);
    }

    public function scopeEntregado($query)
    {
        return $query->where('status', ShiftHandoverStatus::DELIVERED);
    }

    public function scopeRecibido($query)
    {
        return $query->where('status', ShiftHandoverStatus::RECEIVED);
    }

    public function scopePorRecepcionista($query, $userId)
    {
        return $query->where('entregado_por', $userId)->orWhere('recibido_por', $userId);
    }

    public function scopePorTurno($query, ShiftType $type)
    {
        return $query->where('shift_type', $type);
    }
}

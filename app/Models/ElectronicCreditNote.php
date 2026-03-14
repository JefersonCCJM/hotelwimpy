<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicCreditNote extends Model
{
    public const CORRECTION_CONCEPTS = [
        1 => 'Devolucion parcial de los bienes y/o no aceptacion parcial del servicio.',
        2 => 'Anulacion de factura electronica.',
        3 => 'Rebaja o descuento parcial o total.',
        4 => 'Ajuste de precio.',
        5 => 'Descuento comercial por pronto pago.',
        6 => 'Descuento comercial por volumen de ventas.',
    ];

    protected $fillable = [
        'electronic_invoice_id',
        'customer_id',
        'factus_numbering_range_id',
        'referenced_factus_bill_id',
        'factus_credit_note_id',
        'correction_concept_code',
        'customization_id',
        'payment_method_code',
        'send_email',
        'reference_code',
        'document',
        'status',
        'cude',
        'qr',
        'total',
        'tax_amount',
        'gross_value',
        'discount_amount',
        'surcharge_amount',
        'notes',
        'validated_at',
        'payload_sent',
        'response_dian',
    ];

    protected $casts = [
        'send_email' => 'boolean',
        'total' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'gross_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'surcharge_amount' => 'decimal:2',
        'validated_at' => 'datetime',
        'payload_sent' => 'array',
        'response_dian' => 'array',
    ];

    public function electronicInvoice()
    {
        return $this->belongsTo(ElectronicInvoice::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function numberingRange()
    {
        return $this->belongsTo(FactusNumberingRange::class, 'factus_numbering_range_id', 'factus_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(DianPaymentMethod::class, 'payment_method_code', 'code');
    }

    public function items()
    {
        return $this->hasMany(ElectronicCreditNoteItem::class);
    }

    /**
     * @return array<int, array{code:int,label:string}>
     */
    public static function correctionConceptOptions(): array
    {
        $options = [];

        foreach (self::CORRECTION_CONCEPTS as $code => $label) {
            $options[] = [
                'code' => $code,
                'label' => $label,
            ];
        }

        return $options;
    }

    public function getCorrectionConceptLabelAttribute(): string
    {
        return self::CORRECTION_CONCEPTS[$this->correction_concept_code]
            ?? 'Codigo ' . $this->correction_concept_code;
    }

    public function getDianVerificationUrlAttribute(): ?string
    {
        if (!$this->isAccepted()) {
            return null;
        }

        $url = $this->qr ?: data_get($this->response_dian, 'data.credit_note.qr');

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    public function getReferencedBillNumberAttribute(): ?string
    {
        $number = data_get($this->response_dian, 'data.credit_note.number_bill');

        return is_string($number) && $number !== '' ? $number : null;
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function canRetryWithFactus(): bool
    {
        return in_array($this->status, ['pending', 'rejected'], true);
    }

    public function canCleanupInFactus(): bool
    {
        return $this->canRetryWithFactus() && filled($this->reference_code);
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'accepted' => 'Aceptada',
            'rejected' => 'Rechazada',
            'cancelled' => 'Cancelada',
            default => 'Pendiente',
        };
    }
}

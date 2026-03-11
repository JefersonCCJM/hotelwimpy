<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicInvoice extends Model
{
    protected $fillable = [
        'customer_id', 'factus_numbering_range_id',
        'document_type_id', 'operation_type_id',
        'payment_method_code', 'payment_form_code',
        'reference_code', 'document', 'notes', 'status',
        'cufe', 'qr',
        'total', 'tax_amount', 'gross_value', 
        'discount_amount', 'surcharge_amount',
        'validated_at',
        'payload_sent', 'response_dian',
        'pdf_url', 'xml_url',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'gross_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'surcharge_amount' => 'decimal:2',
        'validated_at' => 'datetime',
        'payload_sent' => 'array',
        'response_dian' => 'array',
    ];

    // TODO: Adaptar cuando se implementen las reservas
    // public function reservation()
    // {
    //     return $this->belongsTo(Reservation::class);
    // }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function numberingRange()
    {
        return $this->belongsTo(FactusNumberingRange::class, 'factus_numbering_range_id', 'factus_id');
    }

    public function documentType()
    {
        return $this->belongsTo(DianDocumentType::class, 'document_type_id');
    }

    public function operationType()
    {
        return $this->belongsTo(DianOperationType::class, 'operation_type_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(DianPaymentMethod::class, 'payment_method_code', 'code');
    }

    public function paymentForm()
    {
        return $this->belongsTo(DianPaymentForm::class, 'payment_form_code', 'code');
    }

    public function items()
    {
        return $this->hasMany(ElectronicInvoiceItem::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['pending', 'sent', 'accepted']);
    }

    public function canBeDeleted(): bool
    {
        // Solo se pueden eliminar facturas que no estén validadas por DIAN
        return in_array($this->status, ['pending', 'rejected']) && !in_array($this->status, ['deleted', 'cancelled']);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDeleted(): bool
    {
        return $this->status === 'deleted' || $this->status === 'cancelled';
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'pending' => 'Pendiente',
            'accepted' => 'Aceptada',
            'rejected' => 'Rechazada',
            'deleted' => 'Eliminada',
            'cancelled' => 'Eliminada', // Mostrar 'Eliminada' para 'cancelled' también
            default => ucfirst($this->status),
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'pending' => 'amber',
            'accepted' => 'emerald',
            'rejected' => 'red',
            'deleted' => 'gray',
            'cancelled' => 'gray', // Mismo color que 'deleted'
            default => 'gray',
        };
    }
}

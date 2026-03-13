<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicCreditNoteItem extends Model
{
    protected $fillable = [
        'electronic_credit_note_id',
        'tribute_id',
        'standard_code_id',
        'unit_measure_id',
        'code_reference',
        'name',
        'note',
        'quantity',
        'price',
        'tax_rate',
        'tax_amount',
        'discount_rate',
        'is_excluded',
        'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'is_excluded' => 'boolean',
        'total' => 'decimal:2',
    ];

    public function electronicCreditNote()
    {
        return $this->belongsTo(ElectronicCreditNote::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalIncome extends Model
{
    protected $fillable = [
        'user_id',
        'shift_handover_id',
        'payment_method',
        'income_date',
        'amount',
        'reason',
        'notes',
    ];

    protected $casts = [
        'income_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shiftHandover(): BelongsTo
    {
        return $this->belongsTo(ShiftHandover::class);
    }

    public function scopeByDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('income_date', $date);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomOperationalStatus extends Model
{
    protected $fillable = [
        'room_id',
        'operational_date',
        'observation',
        'cleaning_override_status',
        'maintenance_source_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'operational_date' => 'date',
        'maintenance_source_date' => 'date',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}

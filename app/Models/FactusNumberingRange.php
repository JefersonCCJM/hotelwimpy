<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FactusNumberingRange extends Model
{
    public const CREDIT_NOTE_DOCUMENT_CODE = '22';

    protected $table = 'factus_numbering_ranges';

    protected $fillable = [
        'factus_id',
        'document',
        'document_code',
        'prefix',
        'range_from',
        'range_to',
        'current',
        'resolution_number',
        'technical_key',
        'start_date',
        'end_date',
        'is_expired',
        'is_active',
    ];

    protected $casts = [
        'factus_id' => 'integer',
        'range_from' => 'integer',
        'range_to' => 'integer',
        'current' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_expired' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function electronicInvoices()
    {
        return $this->hasMany(ElectronicInvoice::class, 'factus_numbering_range_id', 'factus_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('is_expired', false);
    }

    public function scopeValid($query)
    {
        return $query->where('is_active', true)
            ->where('is_expired', false)
            ->where(function ($q) {
                $q->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }

    public function scopeForDocument($query, string $document)
    {
        return $query->where('document', $document);
    }

    public function scopeForCreditNotes($query)
    {
        return $query->where(function ($q) {
            $q->whereIn('document', self::creditNoteDocumentIdentifiers())
                ->orWhereIn('document_code', self::creditNoteDocumentCodes());
        });
    }

    public function isValid(): bool
    {
        if (!$this->is_active || $this->is_expired) {
            return false;
        }

        if ($this->start_date && now()->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && now()->gt($this->end_date)) {
            return false;
        }

        return true;
    }

    public function isCreditNoteRange(): bool
    {
        return in_array((string) $this->document, self::creditNoteDocumentIdentifiers(), true)
            || in_array((string) $this->document_code, self::creditNoteDocumentCodes(), true);
    }

    public function isExhausted(): bool
    {
        return $this->range_to !== null
            && $this->range_to > 0
            && $this->current >= $this->range_to;
    }

    public function getRemainingNumbers(): int
    {
        if ($this->range_to === null || $this->range_to <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $this->range_to - $this->current);
    }

    public function getFactusId(): int
    {
        return $this->factus_id;
    }

    /**
     * @return array<int, string>
     */
    public static function creditNoteDocumentIdentifiers(): array
    {
        return [
            'Nota Crédito',
            'Nota Credito',
            'Nota CrÃ©dito',
            'Nota CrÃƒÂ©dito',
            'Nota CrÃƒÆ’Ã‚Â©dito',
            'Nota CrÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©dito',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function creditNoteDocumentCodes(): array
    {
        return [
            self::CREDIT_NOTE_DOCUMENT_CODE,
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $state
 * @property string|null $zip_code
 * @property string|null $notes
 * @property bool $is_active
 * @property bool $requires_electronic_invoice
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read CustomerTaxProfile|null $taxProfile
 *
 * @mixin Builder
 */
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'zip_code',
        'notes',
        'is_active',
        'requires_electronic_invoice',
        'identification_number',
        'identification_type_id',
    ];

    /**
     * Always store and retrieve the name in uppercase and trimmed.
     */
    protected function name(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn (?string $value) => $value ? mb_strtoupper($value) : null,
            set: fn (?string $value) => $value ? trim(mb_strtoupper($value)) : null,
        );
    }

    /**
     * Sanitize phone.
     */
    protected function phone(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            set: fn (?string $value) => $value ? trim($value) : null,
        );
    }

    /**
     * Sanitize email.
     */
    protected function email(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            set: fn (?string $value) => $value ? trim(mb_strtolower($value)) : null,
        );
    }

    protected $casts = [
        'is_active' => 'boolean',
        'requires_electronic_invoice' => 'boolean',
    ];

    /**
     * Get the tax profile for the customer.
     */
    public function taxProfile(): HasOne
    {
        return $this->hasOne(CustomerTaxProfile::class);
    }

    /**
     * Get the reservations for the customer.
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'client_id');
    }

    /**
     * Get the electronic invoices for the customer.
     */
    public function electronicInvoices(): HasMany
    {
        return $this->hasMany(ElectronicInvoice::class);
    }

    /**
     * Get the identification type for the customer.
     */
    public function identificationType()
    {
        return $this->belongsTo(\App\Models\DianIdentificationDocument::class, 'identification_type_id');
    }

    /**
     * Check if customer requires electronic invoice.
     */
    public function requiresElectronicInvoice(): bool
    {
        return $this->requires_electronic_invoice && 
               $this->taxProfile !== null;
    }

    /**
     * Check if customer has complete tax profile data.
     */
    public function hasCompleteTaxProfileData(): bool
    {
        if (!$this->requires_electronic_invoice) {
            return false;
        }
        
        $profile = $this->taxProfile;
        if (!$profile) {
            return false;
        }
        
        // Load necessary relationships
        $profile->load('identificationDocument');
        
        $required = ['identification_document_id', 'identification', 'municipality_id'];
        
        foreach ($required as $field) {
            if (empty($profile->$field)) {
                return false;
            }
        }
        
        if ($profile->requiresDV() && $profile->dv === null) {
            return false;
        }
        
        if ($profile->isJuridicalPerson()) {
            // Para personas jurídicas, usar el nombre del cliente como razón social si no está definida
            if (empty($profile->company) && !empty($this->name)) {
                return true; // Usará el nombre del cliente
            }
            if (empty($profile->company)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get missing tax profile fields.
     *
     * @return array<string>
     */
    public function getMissingTaxProfileFields(): array
    {
        $missing = [];

        if (!$this->requires_electronic_invoice) {
            return ['FacturaciÃ³n electrÃ³nica no estÃ¡ activada'];
        }

        $profile = $this->taxProfile;
        if (!$profile) {
            return ['Perfil fiscal no estÃ¡ configurado. Por favor, complete los datos fiscales del cliente.'];
        }

        // Load necessary relationships
        $profile->load('identificationDocument');

        $required = [
            'identification_document_id' => 'Tipo de documento',
            'identification' => 'NÃºmero de identificaciÃ³n',
            'municipality_id' => 'Municipio',
        ];

        foreach ($required as $field => $label) {
            if (empty($profile->$field)) {
                $missing[] = $label;
            }
        }

        if ($profile->requiresDV() && $profile->dv === null) {
            $missing[] = 'Dígito verificador (DV)';
        }

        if ($profile->isJuridicalPerson() && empty($profile->company)) {
            $missing[] = 'RazÃ³n social / Empresa';
        }

        return $missing;
    }

    /**
     * Scope a query to only include active customers.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

}

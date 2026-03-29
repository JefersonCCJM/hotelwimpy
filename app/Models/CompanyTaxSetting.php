<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyTaxSetting extends Model
{
    protected $fillable = [
        'company_name', 'nit', 'dv', 'email',
        'municipality_id', 'economic_activity',
        'logo_url', 'factus_company_id',
    ];

    public function municipality()
    {
        return $this->belongsTo(DianMunicipality::class, 'municipality_id', 'factus_id');
    }

    public static function getInstance(): ?self
    {
        return self::first();
    }

    public function isConfigured(): bool
    {
        return !empty($this->nit) &&
               $this->dv !== null &&
               !empty($this->municipality_id) &&
               !empty($this->email) &&
               !empty($this->company_name);
    }

    public function hasFactusId(): bool
    {
        return !empty($this->factus_company_id);
    }

    /**
     * Get missing company tax configuration fields.
     *
     * @return array<string>
     */
    public function getMissingFields(): array
    {
        $missing = [];

        if (empty($this->company_name)) {
            $missing[] = 'Nombre de la empresa';
        }

        if (empty($this->nit)) {
            $missing[] = 'NIT';
        }

        if ($this->dv === null) {
            $missing[] = 'Dígito verificador (DV)';
        }

        if (empty($this->email)) {
            $missing[] = 'Email';
        }

        if (empty($this->municipality_id)) {
            $missing[] = 'Municipio';
        }

        return $missing;
    }
}

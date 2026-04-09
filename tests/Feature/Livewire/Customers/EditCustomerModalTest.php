<?php

namespace Tests\Feature\Livewire\Customers;

use App\Livewire\Customers\EditCustomerModal;
use App\Models\Customer;
use App\Models\CustomerTaxProfile;
use App\Models\DianCustomerTribute;
use App\Models\DianIdentificationDocument;
use App\Models\DianLegalOrganization;
use App\Models\DianMunicipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EditCustomerModalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_convert_an_existing_customer_into_an_electronic_invoice_customer(): void
    {
        $document = DianIdentificationDocument::create([
            'code' => 'CC',
            'name' => 'Cedula de ciudadania',
            'requires_dv' => false,
        ]);

        $municipality = DianMunicipality::create([
            'factus_id' => 149,
            'code' => '11001',
            'name' => 'Bogota',
            'department' => 'Cundinamarca',
        ]);

        $legalOrganization = DianLegalOrganization::create([
            'code' => '2',
            'name' => 'Persona natural',
        ]);

        $tribute = DianCustomerTribute::create([
            'code' => 'R-99-PN',
            'name' => 'No responsable de IVA',
        ]);

        $customer = Customer::create([
            'name' => 'JEFFERSON ALDANA',
            'phone' => '3001234567',
            'email' => null,
            'address' => null,
            'is_active' => true,
            'requires_electronic_invoice' => false,
            'identification_number' => '123456789',
            'identification_type_id' => $document->id,
        ]);

        CustomerTaxProfile::create([
            'customer_id' => $customer->id,
            'identification_document_id' => $document->id,
            'identification' => '123456789',
            'municipality_id' => $municipality->factus_id,
            'legal_organization_id' => $legalOrganization->id,
            'tribute_id' => $tribute->id,
        ]);

        Livewire::test(EditCustomerModal::class)
            ->call('open', $customer->id)
            ->set('formData.requires_electronic_invoice', true)
            ->set('formData.identification_document_id', $document->id)
            ->set('formData.email', 'facturacion@hotel.test')
            ->set('formData.address', 'Calle 1 # 1-1')
            ->set('formData.legal_organization_id', $legalOrganization->id)
            ->set('formData.tribute_id', $tribute->id)
            ->set('formData.municipality_id', $municipality->factus_id)
            ->call('update')
            ->assertDispatched('customer-updated')
            ->assertDispatched('notify');

        $customer->refresh();
        $taxProfile = $customer->taxProfile()->first();

        $this->assertTrue($customer->requires_electronic_invoice);
        $this->assertSame('facturacion@hotel.test', $customer->email);
        $this->assertSame('Calle 1 # 1-1', $customer->address);
        $this->assertNotNull($taxProfile);
        $this->assertSame($customer->id, $taxProfile?->customer_id);
        $this->assertSame($municipality->factus_id, $taxProfile?->municipality_id);
        $this->assertSame($legalOrganization->id, $taxProfile?->legal_organization_id);
        $this->assertSame($tribute->id, $taxProfile?->tribute_id);
        $this->assertSame('JEFFERSON ALDANA', $taxProfile?->names);
        $this->assertSame('facturacion@hotel.test', $taxProfile?->email);
    }
}

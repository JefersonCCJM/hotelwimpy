<?php

namespace App\Livewire\Customers;

use Livewire\Component;
use App\Models\Customer;
use Livewire\Attributes\On;

class DeleteCustomerModal extends Component
{
    public bool $isOpen = false;
    public Customer $customer;
    public string $customerName = '';

    #[On('open-delete-customer-modal')]
    public function open(int $customerId, string $customerName): void
    {
        $this->customer = Customer::findOrFail($customerId);
        $this->customerName = $customerName;
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->reset();
    }

    public function delete(): void
    {
        try {
            // Check if customer has reservations or other dependencies
            if ($this->customer->reservations()->exists()) {
                $message = 'No se puede eliminar el cliente porque tiene reservas asociadas.';

                if (!$this->customer->requires_electronic_invoice) {
                    $message .= ' Si lo necesitas para facturacion electronica, editalo y activale esa opcion sobre el mismo registro.';
                }

                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => $message
                ]);
                return;
            }

            // Check if customer has electronic invoices
            if ($this->customer->electronicInvoices()->exists()) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'No se puede eliminar el cliente porque tiene facturas electrónicas asociadas.'
                ]);
                return;
            }

            // Delete the customer's tax profile if it exists
            if ($this->customer->taxProfile) {
                $this->customer->taxProfile->delete();
            }

            // Soft delete the customer
            $this->customer->delete();

            $this->dispatch('customer-deleted');
            $this->close();
            
            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Cliente y su perfil fiscal eliminados exitosamente.'
            ]);
            
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error al eliminar el cliente: ' . $e->getMessage()
            ]);
        }
    }

    public function render()
    {
        return view('livewire.customers.delete-customer-modal');
    }
}

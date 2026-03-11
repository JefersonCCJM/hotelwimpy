@extends('layouts.app')

@section('title', 'Nueva Factura Electrónica')
@section('header', 'Nueva Factura Electrónica')

@push('styles')
<script>
// Define invoiceForm globally BEFORE Alpine initializes - must be in head
window.invoiceForm = function() {
    return {
        customerId: '',
        customerError: '',
        customerHasTaxProfile: false,
        items: [],
        loading: false,
        customerSelect: null,
        showCreateCustomerModal: false,
        showAddTaxProfileModal: false,
        creatingCustomer: false,
        savingTaxProfile: false,
        
        get totals() {
            const subtotal = this.items.reduce((sum, item) => sum + (item.subtotal || 0), 0);
            const tax = this.items.reduce((sum, item) => sum + (item.tax || 0), 0);
            return {
                subtotal: subtotal,
                tax: tax,
                total: subtotal + tax
            };
        },
        
        get canAddTaxProfile() {
            return this.customerId && !this.customerHasTaxProfile;
        },
        
        async validateCustomer() {
            this.customerError = '';
            this.customerHasTaxProfile = false;
            
            if (!this.customerId) {
                return;
            }
            
            try {
                const response = await fetch(`{{ route('api.customers.tax-profile.get', ['customer' => '__ID__']) }}`.replace('__ID__', this.customerId));
                if (response.ok) {
                    const data = await response.json();
                    const customer = data.customer || {};
                    const hasComplete = customer.has_complete_tax_profile || customer.hasCompleteTaxProfile || false;
                    
                    this.customerHasTaxProfile = hasComplete;
                    
                    if (!hasComplete) {
                        this.customerError = 'El cliente seleccionado no tiene perfil fiscal completo. Por favor, complete los datos fiscales del cliente antes de facturar.';
                    }
                } else {
                    this.checkCustomerFromDOM();
                }
            } catch (error) {
                console.error('Error validating customer:', error);
                this.checkCustomerFromDOM();
            }
        },
        
        checkCustomerFromDOM() {
            const select = document.getElementById('customer_id');
            if (select && !select.classList.contains('ts-hidden')) {
                const option = select.options[select.selectedIndex];
                const hasProfile = option?.dataset.hasProfile === '1';
                this.customerHasTaxProfile = hasProfile;
                if (!hasProfile) {
                    this.customerError = 'El cliente seleccionado no tiene perfil fiscal completo. Por favor, complete los datos fiscales del cliente antes de facturar.';
                }
            } else if (this.customerSelect) {
                const value = this.customerSelect.getValue();
                if (value) {
                    const option = this.customerSelect.options[value];
                    if (option) {
                        const hasProfile = option.dataset?.hasProfile === '1';
                        this.customerHasTaxProfile = hasProfile;
                        if (!hasProfile) {
                            this.customerError = 'El cliente seleccionado no tiene perfil fiscal completo. Por favor, complete los datos fiscales del cliente antes de facturar.';
                        }
                    }
                }
            }
        },
        
        addService() {
            this.items.push({
                name: '',
                quantity: 1,
                price: 0,
                tax_rate: 0,
                subtotal: 0,
                tax: 0,
                total: 0
            });
        },
        
        removeItem(index) {
            this.items.splice(index, 1);
        },
        
        updateItem(index) {
            const item = this.items[index];
            
            // Calcular subtotal
            item.subtotal = item.quantity * item.price;
            
            // Calcular impuesto
            item.tax = item.subtotal * (item.tax_rate / 100);
            
            // Calcular total
            item.total = item.subtotal + item.tax;
        },
        
        formatCurrency(value) {
            return new Intl.NumberFormat('es-CO', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(value || 0);
        },
        
        init() {
            this.$nextTick(() => {
                const select = document.getElementById('customer_id');
                const initialOptions = [];
                if (select) {
                    Array.from(select.options).forEach(option => {
                        if (option.value) {
                            initialOptions.push({
                                id: option.value,
                                name: option.text,
                                hasProfile: option.dataset.hasProfile === '1'
                            });
                        }
                    });
                }
                
                this.customerSelect = new TomSelect('#customer_id', {
                    valueField: 'id',
                    labelField: 'name',
                    searchField: ['name'],
                    create: false,
                    options: initialOptions,
                    load: (query, callback) => {
                        if (!query.length) {
                            callback(initialOptions);
                            return;
                        }
                        fetch(`{{ route('api.customers.search') }}?q=${encodeURIComponent(query)}`)
                            .then(response => response.json())
                            .then(data => {
                                const results = data.results.map(customer => ({
                                    id: customer.id,
                                    name: `${customer.name}${customer.identification !== 'S/N' ? ' - ' + customer.identification : ''}`,
                                    identification: customer.identification,
                                    hasProfile: false
                                }));
                                callback(results);
                            })
                            .catch(() => callback(initialOptions));
                    },
                    render: {
                        option: (data, escape) => {
                            return `<div class="flex items-center justify-between p-2">
                                <span>${escape(data.name)}</span>
                            </div>`;
                        }
                    },
                    onChange: (value) => {
                        this.customerId = value || '';
                        if (value) {
                            this.validateCustomer();
                        } else {
                            this.customerError = '';
                            this.customerHasTaxProfile = false;
                        }
                    }
                });
            });
        },
        
        openCreateCustomerModal() {
            this.showCreateCustomerModal = true;
        },
        
        closeCreateCustomerModal() {
            this.showCreateCustomerModal = false;
        },
        
        openAddTaxProfileModal() {
            if (!this.customerId) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Seleccione un cliente',
                    text: 'Por favor, seleccione un cliente primero.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            
            if (this.customerHasTaxProfile) {
                Swal.fire({
                    icon: 'info',
                    title: 'Cliente completo',
                    text: 'Este cliente ya tiene información fiscal completa.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            
            this.showAddTaxProfileModal = true;
        },
        
        closeAddTaxProfileModal() {
            this.showAddTaxProfileModal = false;
        },
        
        async createCustomer() {
            this.creatingCustomer = true;
            const form = document.getElementById('create-customer-form');
            const formData = new FormData(form);
            
            try {
                const response = await fetch('{{ route('customers.store') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    let errorMessage = 'Error al crear el cliente';
                    if (data.errors) {
                        const errors = Object.values(data.errors).flat();
                        errorMessage = errors.join(', ');
                    } else if (data.message) {
                        errorMessage = data.message;
                    }
                    throw new Error(errorMessage);
                }
                
                if (data.success) {
                    const newOption = {
                        id: data.customer.id,
                        name: data.customer.name + (data.customer.tax_profile?.identification ? ' - ' + data.customer.tax_profile.identification : ''),
                        hasProfile: data.customer.tax_profile ? true : false
                    };
                    
                    this.customerSelect.addOption(newOption);
                    this.customerSelect.setValue(data.customer.id);
                    this.customerId = data.customer.id.toString();
                    
                    await this.validateCustomer();
                    
                    this.closeCreateCustomerModal();
                    form.reset();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Cliente creado',
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    throw new Error(data.message || 'Error al crear el cliente');
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: error.message || 'Error al crear el cliente'
                });
            } finally {
                this.creatingCustomer = false;
            }
        },
        
        async saveTaxProfile() {
            if (!this.customerId) return;
            
            this.savingTaxProfile = true;
            const form = document.getElementById('add-tax-profile-form');
            const formData = new FormData(form);
            
            try {
                const url = `{{ route('api.customers.tax-profile.save', ['customer' => '__ID__']) }}`.replace('__ID__', this.customerId);
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    let errorMessage = 'Error al guardar la información fiscal';
                    if (data.errors) {
                        const errors = Object.values(data.errors).flat();
                        errorMessage = errors.join(', ');
                    } else if (data.message) {
                        errorMessage = data.message;
                    }
                    throw new Error(errorMessage);
                }
                
                if (data.success) {
                    if (this.customerSelect && this.customerId) {
                        const option = this.customerSelect.options[this.customerId];
                        if (option) {
                            option.hasProfile = data.customer.has_complete_tax_profile || false;
                        }
                    }
                    
                    this.customerHasTaxProfile = data.customer.has_complete_tax_profile || false;
                    this.customerError = '';
                    
                    await this.validateCustomer();
                    this.closeAddTaxProfileModal();
                    form.reset();
                    Swal.fire({
                        icon: 'success',
                        title: 'Información fiscal guardada',
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    throw new Error(data.message || 'Error al guardar la información fiscal');
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: error.message || 'Error al guardar la información fiscal'
                });
            } finally {
                this.savingTaxProfile = false;
            }
        },
        
        submitForm() {
            if (this.items.length === 0) {
                alert('Debe agregar al menos un servicio a la factura.');
                return;
            }
            
            if (this.customerError) {
                alert('Por favor, corrija los errores antes de continuar.');
                return;
            }
            
            this.loading = true;
            const form = document.querySelector('form[method="POST"]');
            if (form) {
                form.submit();
            }
        }
    };
};
</script>
@endpush

@section('content')
<div class="space-y-4 sm:space-y-6" x-data="invoiceForm()">
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex items-center space-x-3 sm:space-x-4">
            <div class="p-2.5 sm:p-3 rounded-xl bg-blue-50 text-blue-600">
                <i class="fas fa-file-invoice text-lg sm:text-xl"></i>
            </div>
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Nueva Factura Electrónica</h1>
                <p class="text-xs sm:text-sm text-gray-500 mt-1">Crea una factura electrónica con servicios predefinidos</p>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('electronic-invoices.store') }}" @submit.prevent="submitForm">
        @csrf

        <!-- Selección de Cliente -->
        <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-semibold text-gray-900">Cliente</h2>
                <div class="flex items-center gap-2">
                    <button type="button" @click="openAddTaxProfileModal()"
                            class="px-3 py-1.5 rounded-lg border-2 border-indigo-600 text-xs font-semibold transition-all"
                            :class="canAddTaxProfile ? 'text-indigo-600 hover:bg-indigo-50' : 'text-gray-400 border-gray-300 cursor-not-allowed'"
                            :disabled="!canAddTaxProfile"
                            title="Seleccione un cliente sin información fiscal completa">
                        <i class="fas fa-file-invoice mr-1"></i>Agregar Info Fiscal
                    </button>
                    <button type="button" @click="openCreateCustomerModal()"
                            class="px-3 py-1.5 rounded-lg border-2 border-emerald-600 text-emerald-600 text-xs font-semibold hover:bg-emerald-50">
                        <i class="fas fa-user-plus mr-1"></i>Nuevo Cliente
                    </button>
                </div>
            </div>
            <div>
                <label for="customer_id" class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                    Cliente <span class="text-red-500">*</span>
                </label>
                <select id="customer_id" name="customer_id"
                        class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 @error('customer_id') border-red-300 @enderror"
                        required>
                    <option value="">Seleccione un cliente...</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" 
                                data-has-profile="{{ $customer->hasCompleteTaxProfileData() ? '1' : '0' }}">
                            {{ $customer->name }}
                            @if($customer->taxProfile)
                                - {{ $customer->taxProfile->identification }}
                            @endif
                        </option>
                    @endforeach
                </select>
                <div x-show="customerError" x-text="customerError" class="mt-1.5 text-xs text-red-600" x-cloak></div>
                @error('customer_id')
                    <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <!-- Configuración Fiscal -->
        <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Configuración Fiscal</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="document_type_id" class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                        Tipo de Documento <span class="text-red-500">*</span>
                    </label>
                    <select id="document_type_id" name="document_type_id"
                            class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 @error('document_type_id') border-red-300 @enderror"
                            required>
                        <option value="">Seleccione...</option>
                        @foreach($documentTypes as $type)
                            <option value="{{ $type->id }}" {{ old('document_type_id') == $type->id ? 'selected' : '' }}>
                                {{ $type->name }} ({{ $type->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('document_type_id')
                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="operation_type_id" class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                        Tipo de Operación <span class="text-red-500">*</span>
                    </label>
                    <select id="operation_type_id" name="operation_type_id"
                            class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 @error('operation_type_id') border-red-300 @enderror"
                            required>
                        <option value="">Seleccione...</option>
                        @foreach($operationTypes as $type)
                            <option value="{{ $type->id }}" {{ old('operation_type_id') == $type->id ? 'selected' : '' }}>
                                {{ $type->name }} ({{ $type->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('operation_type_id')
                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="payment_method_code" class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                        Método de Pago
                    </label>
                    <select id="payment_method_code" name="payment_method_code"
                            class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <option value="">Seleccione...</option>
                        @foreach($paymentMethods as $method)
                            <option value="{{ $method->code }}" {{ old('payment_method_code') == $method->code ? 'selected' : '' }}>
                                {{ $method->name }} ({{ $method->code }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="payment_form_code" class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                        Forma de Pago
                    </label>
                    <select id="payment_form_code" name="payment_form_code"
                            class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <option value="">Seleccione...</option>
                        @foreach($paymentForms as $form)
                            <option value="{{ $form->code }}" {{ old('payment_form_code') == $form->code ? 'selected' : '' }}>
                                {{ $form->name }} ({{ $form->code }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label for="reference_code" class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                        Código de Referencia
                    </label>
                    <input type="text" id="reference_code" name="reference_code" value="{{ old('reference_code') }}"
                           class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500"
                           placeholder="Código de referencia opcional">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
            <div>
                <label for="notes" class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                    Observaciones
                </label>
                <textarea id="notes" name="notes" rows="3" maxlength="250"
                          class="block w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 @error('notes') border-red-300 @enderror"
                          placeholder="Observaciones que deben enviarse a Factus...">{{ old('notes') }}</textarea>
                <p class="mt-1.5 text-xs text-gray-500">Maximo 250 caracteres.</p>
                @error('notes')
                    <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <!-- Servicios -->
        <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-semibold text-gray-900">Servicios</h2>
                <button type="button" @click="addService()"
                        class="px-3 py-2 rounded-lg border-2 border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700">
                    <i class="fas fa-plus mr-2"></i>Agregar Servicio
                </button>
            </div>

            <div class="space-y-3" x-show="items.length > 0">
                <template x-for="(item, index) in items" :key="index">
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-700 mb-2">Servicio</label>
                                <input type="text" 
                                       x-model="item.name"
                                       :name="`items[${index}][name]`"
                                       class="block w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                       placeholder="Ej: Alojamiento, Alimentación, etc."
                                       required>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-2">Cantidad</label>
                                <input type="number" x-model="item.quantity" @input="updateItem(index)"
                                       :name="`items[${index}][quantity]`"
                                       step="0.001" min="0.001"
                                       class="block w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                       required>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-2">Precio</label>
                                <input type="number" x-model="item.price" @input="updateItem(index)"
                                       :name="`items[${index}][price]`"
                                       step="0.01" min="0"
                                       class="block w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                       required>
                            </div>
                            <div class="flex items-end">
                                <button type="button" @click="removeItem(index)"
                                        class="w-full px-3 py-2 rounded-lg border-2 border-red-600 text-red-600 text-sm font-semibold hover:bg-red-50">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <input type="hidden" :name="`items[${index}][tax_rate]`" :value="item.tax_rate">
                        <div class="mt-2 text-xs text-gray-600">
                            <span x-text="`Subtotal: $${formatCurrency(item.subtotal)}`"></span>
                            <span class="ml-2" x-text="`IVA: $${formatCurrency(item.tax)}`"></span>
                            <span class="ml-2 font-semibold" x-text="`Total: $${formatCurrency(item.total)}`"></span>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="items.length === 0" class="text-center py-8 text-gray-500">
                <i class="fas fa-inbox text-3xl mb-2"></i>
                <p>No hay servicios agregados. Haz clic en "Agregar Servicio" para comenzar.</p>
            </div>
        </div>

        <!-- Totales -->
        <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
            <div class="flex justify-end">
                <div class="w-full md:w-1/3 space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-700">Subtotal:</span>
                        <span class="font-semibold" x-text="`$${formatCurrency(totals.subtotal)}`"></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-700">IVA:</span>
                        <span class="font-semibold" x-text="`$${formatCurrency(totals.tax)}`"></span>
                    </div>
                    <div class="flex justify-between text-lg font-bold border-t border-gray-200 pt-2">
                        <span>Total:</span>
                        <span x-text="`$${formatCurrency(totals.total)}`"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botones -->
        <div class="flex items-center justify-between pt-4 border-t border-gray-100">
            <a href="{{ route('electronic-invoices.index') }}"
               class="px-4 py-2.5 rounded-xl border-2 border-gray-200 bg-white text-gray-700 text-sm font-semibold hover:bg-gray-50">
                <i class="fas fa-arrow-left mr-2"></i>Cancelar
            </a>
            <button type="submit"
                    class="px-4 py-2.5 rounded-xl border-2 border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700"
                    :disabled="items.length === 0 || loading">
                <i class="fas fa-save mr-2"></i>
                <span x-show="!loading">Crear Factura</span>
                <span x-show="loading">Procesando...</span>
            </button>
        </div>
    </form>

    <!-- Modal Crear Cliente -->
    <div x-show="showCreateCustomerModal" 
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="showCreateCustomerModal"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75"
                 @click="closeCreateCustomerModal()"></div>

            <div x-show="showCreateCustomerModal"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
                <form id="create-customer-form" @submit.prevent="createCustomer()">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Nuevo Cliente</h3>
                            <button type="button" @click="closeCreateCustomerModal()" class="text-gray-400 hover:text-gray-500">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        @include('customers.partials.create-form-fields', [
                            'identificationDocuments' => $identificationDocuments,
                            'legalOrganizations' => $legalOrganizations,
                            'tributes' => $tributes,
                            'municipalities' => $municipalities
                        ])
                    </div>
                    
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit"
                                :disabled="creatingCustomer"
                                class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50">
                            <span x-show="!creatingCustomer">Crear Cliente</span>
                            <span x-show="creatingCustomer">Creando...</span>
                        </button>
                        <button type="button" @click="closeCreateCustomerModal()"
                                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Agregar Información Fiscal -->
    <div x-show="showAddTaxProfileModal" 
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="showAddTaxProfileModal"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75"
                 @click="closeAddTaxProfileModal()"></div>

            <div x-show="showAddTaxProfileModal"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
                <form id="add-tax-profile-form" @submit.prevent="saveTaxProfile()">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Agregar Información Fiscal</h3>
                            <button type="button" @click="closeAddTaxProfileModal()" class="text-gray-400 hover:text-gray-500">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <input type="hidden" name="requires_electronic_invoice" value="1">
                        
                        @include('customers.partials.tax-profile-fields', [
                            'identificationDocuments' => $identificationDocuments,
                            'legalOrganizations' => $legalOrganizations,
                            'tributes' => $tributes,
                            'municipalities' => $municipalities
                        ])
                    </div>
                    
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit"
                                :disabled="savingTaxProfile"
                                class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50">
                            <span x-show="!savingTaxProfile">Guardar Información Fiscal</span>
                            <span x-show="savingTaxProfile">Guardando...</span>
                        </button>
                        <button type="button" @click="closeAddTaxProfileModal()"
                                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
<style>
    .ts-control { border-radius: 0.75rem !important; padding: 0.625rem 0.75rem !important; }
    .ts-dropdown { border-radius: 0.75rem !important; margin-top: 0.5rem !important; }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
@endpush

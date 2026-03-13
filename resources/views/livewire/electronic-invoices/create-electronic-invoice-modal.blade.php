<div x-data="{
    isOpen: @entangle('isOpen'),
    formData: @entangle('formData'),
    isCreating: @entangle('isCreating'),
    items: @entangle('items'),
    
    // Inicializar items con valores por defecto si están vacíos
    init() {
        // Asegurar que items sea un array
        if (!Array.isArray(this.items) || this.items.length === 0) {
            this.items = [];
        }
        
        this.items = this.items.map(item => {
            const parsedTaxRate = parseFloat(item.tax_rate);

            return {
                name: item.name || '',
                quantity: parseFloat(item.quantity) || 1,
                price: parseFloat(item.price) || 0,
                tax_rate: Number.isFinite(parsedTaxRate) ? parsedTaxRate : 0,
                subtotal: 0,
                tax: 0,
                total: 0
            };
        });
        
        // Calcular valores iniciales
        this.calculateAllItems();
    },
    
    // Calcular todos los items
    calculateAllItems() {
        this.items.forEach((item, index) => {
            this.calculateItem(item);
        });
    },
    
    // Calcular un item específico
    calculateItem(item) {
        // Asegurar que los valores sean números
        const quantity = parseFloat(item.quantity) || 0;
        const price = parseFloat(item.price) || 0;
        const taxRate = parseFloat(item.tax_rate) || 0;
        
        // Calcular subtotal sin impuesto
        item.subtotal = quantity * price;
        
        // Calcular impuesto
        item.tax = item.subtotal * (taxRate / 100);
        
        // Calcular total del item
        item.total = item.subtotal + item.tax;
        
        // Redondear a 2 decimales
        item.subtotal = Math.round(item.subtotal * 100) / 100;
        item.tax = Math.round(item.tax * 100) / 100;
        item.total = Math.round(item.total * 100) / 100;
    },
    
    // Obtener totales generales
    get totals() {
        const subtotal = this.items.reduce((sum, item) => sum + item.subtotal, 0);
        const tax = this.items.reduce((sum, item) => sum + item.tax, 0);
        const total = subtotal + tax;
        
        return {
            subtotal: Math.round(subtotal * 100) / 100,
            tax: Math.round(tax * 100) / 100,
            total: Math.round(total * 100) / 100
        };
    },
    
    // Formatear como moneda
    formatCurrency(value) {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(value);
    },
    
    // Agregar nuevo item
    addItem() {
        this.$wire.call('addItem');
    },
    
    // Eliminar item
    removeItem(index) {
        this.$wire.call('removeItem', index);
    },
    
    // Sincronizar con Livewire cuando cambia un item
    syncItem(index, field, value) {
        // Actualizar valor local
        this.items[index][field] = value;
        
        // Recalcular item
        this.calculateItem(this.items[index]);
        
        // Sincronizar con Livewire
        this.$wire.set(`items.${index}.${field}`, value);
        this.$wire.set(`items.${index}.subtotal`, this.items[index].subtotal);
        this.$wire.set(`items.${index}.tax`, this.items[index].tax);
        this.$wire.set(`items.${index}.total`, this.items[index].total);
    },
    
    close() {
        this.isOpen = false;
        this.$wire.close();
    }
}" x-show="isOpen" x-transition class="fixed inset-0 z-[60] overflow-y-auto" x-cloak>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div @click="$wire.close()" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-6xl overflow-hidden transform transition-all max-h-[90vh] overflow-y-auto">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 sticky top-0 bg-white z-10">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900">Nueva Factura Electrónica</h3>
                </div>
                <button @click="close()" class="text-gray-400 hover:text-gray-900">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- Form Content -->
            <div class="p-6 space-y-6">
                <!-- Información de la Factura -->
                <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                    <div class="flex items-center space-x-2 sm:space-x-3 mb-4 sm:mb-6">
                        <div class="p-2 rounded-xl bg-blue-50 text-blue-600">
                            <i class="fas fa-file-invoice text-sm"></i>
                        </div>
                        <h2 class="text-base sm:text-lg font-semibold text-gray-900">Información de la Factura</h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                        <!-- Cliente -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-2">
                                Cliente <span class="text-red-500">*</span>
                            </label>
                            <select wire:model="formData.customer_id"
                                    wire:change="$wire.set('formData.customer_document', $event.target.options[$event.target.selectedIndex].dataset.document ?? '')"
                                    class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Seleccione un cliente...</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer['id'] }}" data-document="{{ $customer['identification'] }}">
                                        {{ $customer['name'] }} ({{ $customer['identification'] }})
                                    </option>
                                @endforeach
                            </select>
                            @error('formData.customer_id')
                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                            <!-- Campo oculto para el documento del cliente -->
                            <input type="hidden" wire:model="formData.customer_document">
                        </div>

                        <!-- Tipo de Documento -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-2">
                                Tipo de Documento <span class="text-red-500">*</span>
                            </label>
                            <select wire:model="formData.document_type_id"
                                    class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Seleccione...</option>
                                @foreach($documentTypes as $type)
                                    <option value="{{ $type['id'] }}">{{ $type['name'] }}</option>
                                @endforeach
                            </select>
                            @error('formData.document_type_id')
                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Rango de Numeración -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-2">
                                Rango de Numeración <span class="text-red-500">*</span>
                            </label>
                            <select wire:model="formData.numbering_range_id"
                                    class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Seleccione...</option>
                                @foreach($numberingRanges as $range)
                                    <option value="{{ $range['id'] }}">{{ $range['description'] }}</option>
                                @endforeach
                            </select>
                            @error('formData.numbering_range_id')
                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Método de Pago -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-2">
                                Método de Pago <span class="text-red-500">*</span>
                            </label>
                            <select wire:model="formData.payment_method_code"
                                    class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Seleccione...</option>
                                @foreach($paymentMethods as $method)
                                    <option value="{{ $method['code'] }}">{{ $method['name'] }} ({{ $method['code'] }})</option>
                                @endforeach
                            </select>
                            @error('formData.payment_method_code')
                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Forma de Pago -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-2">
                                Forma de Pago <span class="text-red-500">*</span>
                            </label>
                            <select wire:model="formData.payment_form_code"
                                    class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Seleccione...</option>
                                @foreach($paymentForms as $form)
                                    <option value="{{ $form['code'] }}">{{ $form['name'] }} ({{ $form['code'] }})</option>
                                @endforeach
                            </select>
                            @error('formData.payment_form_code')
                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Observaciones -->
                        <div class="lg:col-span-3">
                            <label class="block text-xs font-semibold text-gray-700 mb-2">
                                Observaciones
                            </label>
                            <textarea wire:model="formData.notes"
                                    rows="3"
                                    maxlength="250"
                                    class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="Notas adicionales de la factura..."></textarea>
                            @error('formData.notes')
                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Servicios -->
                <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-base font-semibold text-gray-900">Servicios</h2>
                        <button type="button" @click="addItem()"
                                class="px-3 py-2 rounded-lg border-2 border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700">
                            <i class="fas fa-plus mr-2"></i>Agregar Servicio
                        </button>
                    </div>

                    <div class="space-y-3">
                        @forelse($items as $index => $item)
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">Servicio <span class="text-red-500">*</span></label>
                                    <input type="text" 
                                           x-model="(items[{{ $index }}] && items[{{ $index }}].name) ? items[{{ $index }}].name : ''"
                                           @input="syncItem({{ $index }}, 'name', $event.target.value)"
                                           @blur="if($event.target.value.trim() === '') { $event.target.value = (items[{{ $index }}] && items[{{ $index }}].name) ? items[{{ $index }}].name : 'Servicio ' + ({{ $index }} + 1); syncItem({{ $index }}, 'name', $event.target.value); }"
                                           class="block w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                           placeholder="Ej: Alojamiento, Alimentación, Servicios varios..."
                                           required>
                                    @error('items.' . $index . '.name')
                                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">Cantidad</label>
                                    <input type="number" 
                                           x-model="(items[{{ $index }}] && items[{{ $index }}].quantity) ? items[{{ $index }}].quantity : 1"
                                           @input="syncItem({{ $index }}, 'quantity', $event.target.value)"
                                           step="0.001" 
                                           min="0.001"
                                           class="block w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                           required>
                                    @error('items.' . $index . '.quantity')
                                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">Precio</label>
                                    <input type="number" 
                                           x-model="(items[{{ $index }}] && items[{{ $index }}].price) ? items[{{ $index }}].price : 0"
                                           @input="syncItem({{ $index }}, 'price', $event.target.value)"
                                           step="0.01" 
                                           min="0"
                                           class="block w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                           required>
                                    @error('items.' . $index . '.price')
                                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">Tasa Imp (%)</label>
                                    <input type="number" 
                                           x-model="(items[{{ $index }}] && items[{{ $index }}].tax_rate != null) ? items[{{ $index }}].tax_rate : 0"
                                           @input="syncItem({{ $index }}, 'tax_rate', $event.target.value)"
                                           step="0.01" 
                                           min="0" 
                                           max="100"
                                           class="block w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                           required>
                                    @error('items.' . $index . '.tax_rate')
                                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">Subtotal</label>
                                    <div class="block w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm font-semibold">
                                        $<span x-text="(items[{{ $index }}] && items[{{ $index }}].subtotal) ? items[{{ $index }}].subtotal.toFixed(2) : '0.00'">0.00</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">Total</label>
                                    <div class="block w-full px-3 py-2 bg-emerald-50 border border-emerald-200 rounded-lg text-sm font-semibold text-emerald-700">
                                        $<span x-text="(items[{{ $index }}] && items[{{ $index }}].total) ? items[{{ $index }}].total.toFixed(2) : '0.00'">0.00</span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 flex justify-end">
                                <button type="button" @click="removeItem({{ $index }})"
                                        class="text-red-600 hover:text-red-800 text-sm font-medium">
                                    <i class="fas fa-trash mr-1"></i>
                                    Eliminar
                                </button>
                            </div>
                        </div>
                        @empty
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-inbox text-3xl mb-2"></i>
                            <p>No hay servicios agregados. Haz clic en "Agregar Servicio" para comenzar.</p>
                        </div>
                        @endforelse
                    </div>
                </div>

                <!-- Resumen -->
                @if(count($items) > 0)
                <div class="bg-gray-50 rounded-xl border border-gray-200 p-4 sm:p-6">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Resumen de la Factura</h3>
                        </div>
                        <div class="text-right">
                            <div class="space-y-1">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Subtotal:</span>
                                    <span class="font-medium text-gray-900">$<span x-text="(totals && totals.subtotal) ? totals.subtotal.toFixed(2) : '0.00'">0.00</span></span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Impuestos:</span>
                                    <span class="font-medium text-gray-900">$<span x-text="(totals && totals.tax) ? totals.tax.toFixed(2) : '0.00'">0.00</span></span>
                                </div>
                                <div class="flex justify-between text-base font-bold text-gray-900 pt-2 border-t border-gray-300">
                                    <span>Total:</span>
                                    <span class="text-emerald-600">$<span x-text="(totals && totals.total) ? totals.total.toFixed(2) : '0.00'">0.00</span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <!-- Footer -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 sm:gap-4 px-6 py-4 border-t border-gray-100 bg-gray-50">
                <div class="text-xs sm:text-sm text-gray-500 flex items-center">
                    <i class="fas fa-info-circle mr-1.5"></i>
                    Los campos marcados con <span class="text-red-500 ml-1">*</span> son obligatorios
                </div>

                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                    <button type="button" 
                            @click="close()"
                            class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-gray-200 bg-white text-gray-700 text-sm font-semibold hover:bg-gray-50 hover:border-gray-300 transition-all duration-200">
                        <i class="fas fa-times mr-2"></i>
                        Cancelar
                    </button>

                    <button type="button" 
                            wire:click="create"
                            wire:loading.attr="disabled"
                            wire:target="create"
                            class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 hover:border-emerald-700 transition-all duration-200 shadow-sm hover:shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                        <span wire:loading.remove wire:target="create">
                            <i class="fas fa-save mr-2"></i>
                            Crear Factura
                        </span>
                        <span wire:loading wire:target="create" class="flex items-center justify-center">
                            <i class="fas fa-spinner fa-spin mr-2"></i>
                            Creando...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

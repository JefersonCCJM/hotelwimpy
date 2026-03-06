<div x-show="$wire.isOpen" 
     x-transition
     class="fixed inset-0 z-[60] overflow-y-auto" 
     x-cloak>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div @click="$wire.close()" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl overflow-hidden transform transition-all max-h-[90vh] overflow-y-auto">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 sticky top-0 bg-white z-10">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Editar Cliente</h3>
                        <p class="text-xs text-gray-500">
                            @if ($this->isElectronicInvoiceCustomer())
                                <i class="fas fa-file-invoice mr-1"></i>Cliente con facturación electrónica
                            @else
                                <i class="fas fa-user mr-1"></i>Cliente básico
                            @endif
                        </p>
                    </div>
                </div>
                <button @click="$wire.close()" class="text-gray-400 hover:text-gray-900">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- Form Content -->
            <div class="p-6 space-y-6">
                <!-- Cliente Básico -->
                @if (!$this->isElectronicInvoiceCustomer())
                    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                        <div class="flex items-center space-x-2 sm:space-x-3 mb-4 sm:mb-6">
                            <div class="p-2 rounded-xl bg-blue-50 text-blue-600">
                                <i class="fas fa-user text-sm"></i>
                            </div>
                            <h2 class="text-base sm:text-lg font-semibold text-gray-900">Información Básica del Cliente</h2>
                        </div>

                        <div class="space-y-5 sm:space-y-6">
                            <!-- Nombre completo -->
                            <div>
                                <label class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                                    Nombre completo <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-user text-gray-400 text-sm"></i>
                                    </div>
                                    <input type="text"
                                           wire:model.blur="formData.name"
                                           oninput="this.value = this.value.toUpperCase()"
                                           style="text-transform: uppercase !important;"
                                           class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all uppercase {{ isset($errors['name']) ? 'border-red-300 focus:ring-red-500' : '' }}"
                                           placeholder="EJ: JUAN PÉREZ GARCÍA">
                                </div>
                                @if(isset($errors['name']))
                                    <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                        <i class="fas fa-exclamation-circle mr-1.5"></i>
                                        {{ $errors['name'] }}
                                    </p>
                                @endif
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5 md:gap-6">
                                <!-- Identificación -->
                                <div>
                                    <label class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                                        Número de identificación <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                                            <i class="fas fa-id-card text-gray-400 text-sm"></i>
                                        </div>
                                        <input type="text"
                                               wire:model.blur="formData.identification"
                                               oninput="this.value = this.value.replace(/\D/g, '');"
                                               maxlength="10"
                                               minlength="6"
                                               pattern="\d{6,10}"
                                               class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 border rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all {{ isset($errors['identification']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                               placeholder="Ej: 12345678"
                                               title="El número de identificación debe tener entre 6 y 10 dígitos">
                                    </div>
                                    @if(isset($errors['identification']))
                                        <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                            <i class="fas fa-exclamation-circle mr-1.5"></i>
                                            {{ $errors['identification'] }}
                                        </p>
                                    @endif
                                    @if($identificationMessage)
                                        <p class="mt-1.5 text-xs flex items-center {{ $identificationExists ? 'text-red-600' : 'text-emerald-600' }}">
                                            <i class="fas {{ $identificationExists ? 'fa-exclamation-circle' : 'fa-check-circle' }} mr-1.5"></i>
                                            <span>{{ $identificationMessage }}</span>
                                        </p>
                                    @endif
                                </div>

                                <!-- Teléfono -->
                                <div>
                                    <label class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                                        Teléfono <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                                            <i class="fas fa-phone text-gray-400 text-sm"></i>
                                        </div>
                                        <input type="text"
                                               wire:model.blur="formData.phone"
                                               oninput="this.value = this.value.replace(/\D/g, ''); if(this.value.length > 10) this.value = this.value.slice(0, 10);"
                                               maxlength="10"
                                               pattern="\d{10}"
                                               class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 border rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all {{ isset($errors['phone']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                               placeholder="Ej: 3001234567">
                                    </div>
                                    @if(isset($errors['phone']))
                                        <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                            <i class="fas fa-exclamation-circle mr-1.5"></i>
                                            {{ $errors['phone'] }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                <!-- Cliente con Facturación Electrónica -->
                @else
                    <!-- Información Básica -->
                    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                        <div class="flex items-center space-x-2 sm:space-x-3 mb-4 sm:mb-6">
                            <div class="p-2 rounded-xl bg-blue-50 text-blue-600">
                                <i class="fas fa-user text-sm"></i>
                            </div>
                            <h2 class="text-base sm:text-lg font-semibold text-gray-900">Información del Cliente</h2>
                        </div>

                        <div class="space-y-5 sm:space-y-6">
                            <!-- Nombre completo -->
                            <div>
                                <label class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                                    Nombre completo <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-user text-gray-400 text-sm"></i>
                                    </div>
                                    <input type="text"
                                           wire:model.blur="formData.name"
                                           oninput="this.value = this.value.toUpperCase()"
                                           style="text-transform: uppercase !important;"
                                           class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all uppercase {{ isset($errors['name']) ? 'border-red-300 focus:ring-red-500' : '' }}"
                                           placeholder="EJ: JUAN PÉREZ GARCÍA">
                                </div>
                                @if(isset($errors['name']))
                                    <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                        <i class="fas fa-exclamation-circle mr-1.5"></i>
                                        {{ $errors['name'] }}
                                    </p>
                                @endif
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5 md:gap-6">
                                <!-- Identificación -->
                                <div>
                                    <label class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                                        Número de identificación <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                                            <i class="fas fa-id-card text-gray-400 text-sm"></i>
                                        </div>
                                        <input type="text"
                                               wire:model.blur="formData.identification"
                                               oninput="this.value = this.value.replace(/\D/g, '');"
                                               maxlength="10"
                                               minlength="6"
                                               pattern="\d{6,10}"
                                               class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 border rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all {{ isset($errors['identification']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                               placeholder="Ej: 12345678"
                                               title="El número de identificación debe tener entre 6 y 10 dígitos">
                                    </div>
                                    @if(isset($errors['identification']))
                                        <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                            <i class="fas fa-exclamation-circle mr-1.5"></i>
                                            {{ $errors['identification'] }}
                                        </p>
                                    @endif
                                    @if($identificationMessage)
                                        <p class="mt-1.5 text-xs flex items-center {{ $identificationExists ? 'text-red-600' : 'text-emerald-600' }}">
                                            <i class="fas {{ $identificationExists ? 'fa-exclamation-circle' : 'fa-check-circle' }} mr-1.5"></i>
                                            <span>{{ $identificationMessage }}</span>
                                        </p>
                                    @endif
                                </div>

                                <!-- Teléfono -->
                                <div>
                                    <label class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                                        Teléfono
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                                            <i class="fas fa-phone text-gray-400 text-sm"></i>
                                        </div>
                                        <input type="text"
                                               wire:model.blur="formData.phone"
                                               oninput="this.value = this.value.replace(/\D/g, ''); if(this.value.length > 10) this.value = this.value.slice(0, 10);"
                                               maxlength="10"
                                               pattern="\d{10}"
                                               class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 border rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all {{ isset($errors['phone']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                               placeholder="Ej: 3001234567">
                                    </div>
                                    @if(isset($errors['phone']))
                                        <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                            <i class="fas fa-exclamation-circle mr-1.5"></i>
                                            {{ $errors['phone'] }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <!-- Email -->
                            <div>
                                <label class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                                    Correo Electrónico <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-envelope text-gray-400 text-sm"></i>
                                    </div>
                                    <input type="email"
                                           wire:model.blur="formData.email"
                                           class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all {{ isset($errors['email']) ? 'border-red-300 focus:ring-red-500' : '' }}"
                                           placeholder="correo@ejemplo.com">
                                </div>
                                @if(isset($errors['email']))
                                    <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                        <i class="fas fa-exclamation-circle mr-1.5"></i>
                                        {{ $errors['email'] }}
                                    </p>
                                @endif
                            </div>

                            <!-- Dirección -->
                            <div>
                                <label class="block text-xs sm:text-sm font-semibold text-gray-700 mb-2">
                                    Dirección <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                                        <i class="fas fa-map-marker-alt text-gray-400 text-sm"></i>
                                    </div>
                                    <input type="text"
                                           wire:model.blur="formData.address"
                                           class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all {{ isset($errors['address']) ? 'border-red-300 focus:ring-red-500' : '' }}"
                                           placeholder="Calle 1 #1-1">
                                </div>
                                @if(isset($errors['address']))
                                    <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                        <i class="fas fa-exclamation-circle mr-1.5"></i>
                                        {{ $errors['address'] }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Información DIAN -->
                    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                        <div class="flex items-center space-x-2 sm:space-x-3 mb-4 sm:mb-6">
                            <div class="p-2 rounded-xl bg-blue-50 text-blue-600">
                                <i class="fas fa-file-invoice text-sm"></i>
                            </div>
                            <h2 class="text-base sm:text-lg font-semibold text-gray-900">Información para Facturación Electrónica DIAN</h2>
                        </div>

                        <div class="space-y-5 sm:space-y-6">
                            <!-- Mensaje informativo -->
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="flex items-start">
                                    <i class="fas fa-info-circle text-blue-600 mt-0.5 mr-3"></i>
                                    <div class="text-sm text-blue-800">
                                        <p class="font-semibold mb-1">Campos Obligatorios para Facturación Electrónica</p>
                                        <p class="text-xs">Complete todos los campos marcados con <span class="text-red-500 font-bold">*</span> para poder generar facturas electrónicas válidas según la normativa DIAN.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Tipo de Documento -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-2">
                                    Tipo de Documento <span class="text-red-500">*</span>
                                </label>
                                <select wire:model.live="formData.identification_document_id"
                                        class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm {{ isset($errors['identification_document_id']) ? 'border-red-300 focus:ring-red-500' : '' }}">
                                    <option value="">Seleccione...</option>
                                    @foreach($identificationDocuments as $doc)
                                        <option value="{{ $doc->id }}"
                                                data-code="{{ $doc->code }}"
                                                data-requires-dv="{{ $doc->requires_dv ? 'true' : 'false' }}">
                                            {{ $doc->name }}@if($doc->code) ({{ $doc->code }})@endif
                                        </option>
                                    @endforeach
                                </select>
                                @if(isset($errors['identification_document_id']))
                                    <p class="mt-1.5 text-xs text-red-600">{{ $errors['identification_document_id'] }}</p>
                                @endif
                            </div>

                            <!-- Dígito Verificador -->
                            @if($this->requiresDV || (filled($formData['dv'] ?? null) && $this->isElectronicInvoiceCustomer()))
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">
                                        Dígito Verificador (DV) <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" 
                                           wire:model="formData.dv"
                                           maxlength="1"
                                           pattern="[0-9]"
                                           oninput="this.value = this.value.replace(/\D/g, '');"
                                           class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent {{ isset($errors['dv']) ? 'border-red-300 focus:ring-red-500' : '' }}"
                                           placeholder="0-9"
                                           value="{{ $formData['dv'] }}">
                                    <p class="mt-1 text-xs text-gray-600">
                                        <i class="fas fa-info-circle mr-1"></i> Un solo dígito (0-9)
                                    </p>
                                    @if(isset($errors['dv']))
                                        <p class="mt-1 text-xs text-red-600 flex items-center">
                                            <i class="fas fa-exclamation-circle mr-1"></i>
                                            {{ $errors['dv'] }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                            @endif

                            <!-- Información Tributaria -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">
                                        Tipo de Organización Legal <span class="text-red-500">*</span>
                                    </label>
                                    <select wire:model="formData.legal_organization_id"
                                            class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm {{ isset($errors['legal_organization_id']) ? 'border-red-300 focus:ring-red-500' : '' }}">
                                        <option value="">Seleccione...</option>
                                        @foreach($legalOrganizations as $org)
                                            <option value="{{ $org->id }}">{{ $org->name }}</option>
                                        @endforeach
                                    </select>
                                    @if(isset($errors['legal_organization_id']))
                                        <p class="mt-1.5 text-xs text-red-600">{{ $errors['legal_organization_id'] }}</p>
                                    @endif
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">
                                        Tipo de Tributo <span class="text-red-500">*</span>
                                    </label>
                                    <select wire:model="formData.tribute_id"
                                            class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm {{ isset($errors['tribute_id']) ? 'border-red-300 focus:ring-red-500' : '' }}">
                                        <option value="">Seleccione...</option>
                                        @foreach($tributes as $tribute)
                                            <option value="{{ $tribute->id }}">{{ $tribute->name }}@if($tribute->code) ({{ $tribute->code }})@endif</option>
                                        @endforeach
                                    </select>
                                    @if(isset($errors['tribute_id']))
                                        <p class="mt-1.5 text-xs text-red-600">{{ $errors['tribute_id'] }}</p>
                                    @endif
                                </div>
                            </div>

                            <!-- Municipio -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-2">
                                    Municipio <span class="text-red-500">*</span>
                                </label>
                                <select wire:model="formData.municipality_id"
                                        class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm {{ isset($errors['municipality_id']) ? 'border-red-300 focus:ring-red-500' : '' }}">
                                    <option value="">Seleccione un municipio...</option>
                                    @php
                                        $currentDepartment = null;
                                    @endphp
                                    @foreach($municipalities as $municipality)
                                        @if($currentDepartment !== $municipality->department)
                                            @if($currentDepartment !== null)
                                                </optgroup>
                                            @endif
                                            <optgroup label="{{ $municipality->department }}">
                                                @php
                                                    $currentDepartment = $municipality->department;
                                                @endphp
                                        @endif
                                        <option value="{{ $municipality->factus_id }}">
                                            {{ $municipality->department }} - {{ $municipality->name }}
                                        </option>
                                        @if($loop->last)
                                            </optgroup>
                                        @endif
                                    @endforeach
                                </select>
                                @if(isset($errors['municipality_id']))
                                    <p class="mt-1.5 text-xs text-red-600">{{ $errors['municipality_id'] }}</p>
                                @endif
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
                            wire:click="close"
                            class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-gray-200 bg-white text-gray-700 text-sm font-semibold hover:bg-gray-50 hover:border-gray-300 transition-all duration-200">
                        <i class="fas fa-times mr-2"></i>
                        Cancelar
                    </button>

                    <button type="button" 
                            wire:click="update"
                            wire:loading.attr="disabled"
                            wire:target="update"
                            class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-indigo-600 bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 hover:border-indigo-700 transition-all duration-200 shadow-sm hover:shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                        <span wire:loading.remove wire:target="update">
                            <i class="fas fa-save mr-2"></i>
                            Actualizar Cliente
                        </span>
                        <span wire:loading wire:target="update" class="flex items-center justify-center">
                            <i class="fas fa-spinner fa-spin mr-2"></i>
                            Actualizando...
                        </span>
                    </button>
                </div>
            </div>
            
            @if(isset($errors['general']))
                <div class="px-6 pb-4">
                    <p class="text-[10px] text-red-600 flex items-center">
                        <i class="fas fa-exclamation-circle mr-1 text-[8px]"></i>
                        {{ $errors['general'] }}
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>

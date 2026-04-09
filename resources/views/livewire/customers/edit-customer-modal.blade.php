<div x-show="$wire.isOpen"
     x-transition
     class="fixed inset-0 z-[60] overflow-y-auto"
     x-cloak>
    <div class="flex min-h-screen items-center justify-center p-4">
        <div @click="$wire.close()" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>

        <div class="relative w-full max-w-4xl overflow-hidden rounded-2xl bg-white shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-white px-6 py-4">
                <div class="flex items-center space-x-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Editar Cliente</h3>
                        <p class="text-xs text-gray-500">
                            @if ($this->isElectronicInvoiceCustomer())
                                <i class="fas fa-file-invoice mr-1"></i>Cliente habilitado para facturacion electronica
                            @else
                                <i class="fas fa-user mr-1"></i>Cliente basico
                            @endif
                        </p>
                    </div>
                </div>

                <button @click="$wire.close()" class="text-gray-400 hover:text-gray-900">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <div class="space-y-6 p-6">
                <div class="rounded-xl border border-emerald-100 bg-emerald-50/70 p-4 sm:p-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="max-w-2xl">
                            <p class="text-sm font-semibold text-emerald-900">Facturacion electronica</p>
                            <p class="mt-1 text-xs sm:text-sm text-emerald-800">
                                Activa esta opcion para usar el mismo cliente como facturador electronico sin borrar reservas o walk-ins ya asociados.
                            </p>
                        </div>

                        <button type="button"
                                wire:click="toggleElectronicInvoice"
                                class="inline-flex items-center gap-3 self-start rounded-full border border-emerald-200 bg-white px-4 py-2 shadow-sm sm:self-center">
                            <span class="text-sm font-semibold text-gray-700">Requiere factura</span>
                            <span class="relative inline-flex h-7 w-12 items-center rounded-full transition-colors duration-200 {{ $this->isElectronicInvoiceCustomer() ? 'bg-emerald-600' : 'bg-gray-300' }}">
                                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200 {{ $this->isElectronicInvoiceCustomer() ? 'translate-x-6' : 'translate-x-1' }}"></span>
                            </span>
                        </button>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-100 bg-white p-4 sm:p-6">
                    <div class="mb-6 flex items-center space-x-3">
                        <div class="rounded-xl bg-blue-50 p-2 text-blue-600">
                            <i class="fas fa-user text-sm"></i>
                        </div>
                        <h2 class="text-base font-semibold text-gray-900 sm:text-lg">Informacion del cliente</h2>
                    </div>

                    <div class="space-y-5 sm:space-y-6">
                        <div>
                            <label class="mb-2 block text-xs font-semibold text-gray-700 sm:text-sm">
                                Nombre completo <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 sm:pl-4">
                                    <i class="fas fa-user text-sm text-gray-400"></i>
                                </div>
                                <input type="text"
                                       wire:model.blur="formData.name"
                                       oninput="this.value = this.value.toUpperCase()"
                                       style="text-transform: uppercase !important;"
                                       class="block w-full rounded-xl border px-10 py-2.5 text-sm uppercase text-gray-900 placeholder-gray-400 transition-all focus:border-transparent focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:px-11 {{ isset($errors['name']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                       placeholder="EJ: JUAN PEREZ GARCIA">
                            </div>
                            @if(isset($errors['name']))
                                <p class="mt-1.5 text-xs text-red-600">{{ $errors['name'] }}</p>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-xs font-semibold text-gray-700 sm:text-sm">
                                    Numero de identificacion <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 sm:pl-4">
                                        <i class="fas fa-id-card text-sm text-gray-400"></i>
                                    </div>
                                    <input type="text"
                                           wire:model.blur="formData.identification"
                                           oninput="this.value = this.value.replace(/\D/g, '');"
                                           maxlength="10"
                                           minlength="6"
                                           pattern="\d{6,10}"
                                           class="block w-full rounded-xl border px-10 py-2.5 text-sm text-gray-900 placeholder-gray-400 transition-all focus:border-transparent focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:px-11 {{ isset($errors['identification']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                           placeholder="Ej: 12345678">
                                </div>
                                @if(isset($errors['identification']))
                                    <p class="mt-1.5 text-xs text-red-600">{{ $errors['identification'] }}</p>
                                @endif
                                @if($identificationMessage)
                                    <p class="mt-1.5 text-xs {{ $identificationExists ? 'text-red-600' : 'text-emerald-600' }}">
                                        {{ $identificationMessage }}
                                    </p>
                                @endif
                            </div>

                            <div>
                                <label class="mb-2 block text-xs font-semibold text-gray-700 sm:text-sm">
                                    Telefono
                                </label>
                                <div class="relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 sm:pl-4">
                                        <i class="fas fa-phone text-sm text-gray-400"></i>
                                    </div>
                                    <input type="text"
                                           wire:model.blur="formData.phone"
                                           oninput="this.value = this.value.replace(/\D/g, ''); if(this.value.length > 10) this.value = this.value.slice(0, 10);"
                                           maxlength="10"
                                           class="block w-full rounded-xl border px-10 py-2.5 text-sm text-gray-900 placeholder-gray-400 transition-all focus:border-transparent focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:px-11 {{ isset($errors['phone']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                           placeholder="Ej: 3001234567">
                                </div>
                                @if(isset($errors['phone']))
                                    <p class="mt-1.5 text-xs text-red-600">{{ $errors['phone'] }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-xs font-semibold text-gray-700 sm:text-sm">
                                    Correo electronico @if($this->isElectronicInvoiceCustomer())<span class="text-red-500">*</span>@endif
                                </label>
                                <div class="relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 sm:pl-4">
                                        <i class="fas fa-envelope text-sm text-gray-400"></i>
                                    </div>
                                    <input type="email"
                                           wire:model.blur="formData.email"
                                           class="block w-full rounded-xl border px-10 py-2.5 text-sm text-gray-900 placeholder-gray-400 transition-all focus:border-transparent focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:px-11 {{ isset($errors['email']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                           placeholder="correo@ejemplo.com">
                                </div>
                                @if(isset($errors['email']))
                                    <p class="mt-1.5 text-xs text-red-600">{{ $errors['email'] }}</p>
                                @endif
                            </div>

                            <div>
                                <label class="mb-2 block text-xs font-semibold text-gray-700 sm:text-sm">
                                    Direccion @if($this->isElectronicInvoiceCustomer())<span class="text-red-500">*</span>@endif
                                </label>
                                <div class="relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 sm:pl-4">
                                        <i class="fas fa-map-marker-alt text-sm text-gray-400"></i>
                                    </div>
                                    <input type="text"
                                           wire:model.blur="formData.address"
                                           class="block w-full rounded-xl border px-10 py-2.5 text-sm text-gray-900 placeholder-gray-400 transition-all focus:border-transparent focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:px-11 {{ isset($errors['address']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                           placeholder="Calle 1 #1-1">
                                </div>
                                @if(isset($errors['address']))
                                    <p class="mt-1.5 text-xs text-red-600">{{ $errors['address'] }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                @if ($this->isElectronicInvoiceCustomer())
                    <div class="rounded-xl border border-gray-100 bg-white p-4 sm:p-6">
                        <div class="mb-6 flex items-center space-x-3">
                            <div class="rounded-xl bg-blue-50 p-2 text-blue-600">
                                <i class="fas fa-file-invoice text-sm"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900 sm:text-lg">Informacion DIAN</h2>
                                <p class="text-xs text-gray-500">Completa los campos obligatorios para dejar este cliente listo para facturar.</p>
                            </div>
                        </div>

                        <div class="space-y-5 sm:space-y-6">
                            <div>
                                <label class="mb-2 block text-xs font-semibold text-gray-700">
                                    Tipo de documento <span class="text-red-500">*</span>
                                </label>
                                <select wire:model.live="formData.identification_document_id"
                                        class="block w-full rounded-lg border px-3 py-2.5 text-sm {{ isset($errors['identification_document_id']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}">
                                    <option value="">Seleccione...</option>
                                    @foreach($identificationDocuments as $doc)
                                        <option value="{{ $doc->id }}">
                                            {{ $doc->name }}@if($doc->code) ({{ $doc->code }})@endif
                                        </option>
                                    @endforeach
                                </select>
                                @if(isset($errors['identification_document_id']))
                                    <p class="mt-1.5 text-xs text-red-600">{{ $errors['identification_document_id'] }}</p>
                                @endif
                            </div>

                            @if($requiresDV || (!empty($formData['dv']) && $this->isElectronicInvoiceCustomer()))
                                <div>
                                    <label class="mb-2 block text-xs font-semibold text-gray-700">
                                        Digito verificador (DV) <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text"
                                           wire:model="formData.dv"
                                           maxlength="1"
                                           pattern="[0-9]"
                                           oninput="this.value = this.value.replace(/\D/g, '');"
                                           class="w-full rounded-lg border px-3 py-2.5 text-sm font-semibold focus:border-transparent focus:outline-none focus:ring-2 focus:ring-indigo-500 {{ isset($errors['dv']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                           placeholder="0-9">
                                    @if(isset($errors['dv']))
                                        <p class="mt-1.5 text-xs text-red-600">{{ $errors['dv'] }}</p>
                                    @endif
                                </div>
                            @endif

                            @if($isJuridicalPerson)
                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="mb-2 block text-xs font-semibold text-gray-700">
                                            Razon social <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text"
                                               wire:model.blur="formData.company"
                                               oninput="this.value = this.value.toUpperCase()"
                                               style="text-transform: uppercase !important;"
                                               class="block w-full rounded-lg border px-3 py-2.5 text-sm uppercase {{ isset($errors['company']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}"
                                               placeholder="EJ: HOTEL SAN PEDRO SAS">
                                        @if(isset($errors['company']))
                                            <p class="mt-1.5 text-xs text-red-600">{{ $errors['company'] }}</p>
                                        @endif
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-xs font-semibold text-gray-700">
                                            Nombre comercial
                                        </label>
                                        <input type="text"
                                               wire:model.blur="formData.trade_name"
                                               oninput="this.value = this.value.toUpperCase()"
                                               style="text-transform: uppercase !important;"
                                               class="block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm uppercase"
                                               placeholder="Opcional">
                                    </div>
                                </div>
                            @endif

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-xs font-semibold text-gray-700">
                                        Organizacion legal <span class="text-red-500">*</span>
                                    </label>
                                    <select wire:model="formData.legal_organization_id"
                                            class="block w-full rounded-lg border px-3 py-2.5 text-sm {{ isset($errors['legal_organization_id']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}">
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
                                    <label class="mb-2 block text-xs font-semibold text-gray-700">
                                        Tipo de tributo <span class="text-red-500">*</span>
                                    </label>
                                    <select wire:model="formData.tribute_id"
                                            class="block w-full rounded-lg border px-3 py-2.5 text-sm {{ isset($errors['tribute_id']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}">
                                        <option value="">Seleccione...</option>
                                        @foreach($tributes as $tribute)
                                            <option value="{{ $tribute->id }}">
                                                {{ $tribute->name }}@if($tribute->code) ({{ $tribute->code }})@endif
                                            </option>
                                        @endforeach
                                    </select>
                                    @if(isset($errors['tribute_id']))
                                        <p class="mt-1.5 text-xs text-red-600">{{ $errors['tribute_id'] }}</p>
                                    @endif
                                </div>
                            </div>

                            <div>
                                <label class="mb-2 block text-xs font-semibold text-gray-700">
                                    Municipio <span class="text-red-500">*</span>
                                </label>
                                <select wire:model="formData.municipality_id"
                                        class="block w-full rounded-lg border px-3 py-2.5 text-sm {{ isset($errors['municipality_id']) ? 'border-red-300 focus:ring-red-500' : 'border-gray-300' }}">
                                    <option value="">Seleccione un municipio...</option>
                                    @php $currentDepartment = null; @endphp
                                    @foreach($municipalities as $municipality)
                                        @if($currentDepartment !== $municipality->department)
                                            @if($currentDepartment !== null)
                                                </optgroup>
                                            @endif
                                            <optgroup label="{{ $municipality->department }}">
                                                @php $currentDepartment = $municipality->department; @endphp
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

            <div class="flex flex-col gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center text-xs text-gray-500 sm:text-sm">
                    <i class="fas fa-info-circle mr-1.5"></i>
                    Los campos marcados con <span class="ml-1 text-red-500">*</span> son obligatorios
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <button type="button"
                            wire:click="close"
                            class="inline-flex items-center justify-center rounded-xl border-2 border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition-all duration-200 hover:border-gray-300 hover:bg-gray-50 sm:px-5">
                        <i class="fas fa-times mr-2"></i>
                        Cancelar
                    </button>

                    <button type="button"
                            wire:click="update"
                            wire:loading.attr="disabled"
                            wire:target="update"
                            class="inline-flex items-center justify-center rounded-xl border-2 border-indigo-600 bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:border-indigo-700 hover:bg-indigo-700 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50 sm:px-5">
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
                    <p class="text-xs text-red-600">{{ $errors['general'] }}</p>
                </div>
            @endif
        </div>
    </div>
</div>

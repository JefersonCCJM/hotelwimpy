<div x-show="$wire.isOpen" x-transition class="fixed inset-0 z-[60] overflow-y-auto" x-cloak>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div @click="$wire.close()" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div
            class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl overflow-hidden transform transition-all max-h-[90vh] overflow-y-auto">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 sticky top-0 bg-white z-10">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900">Crear Nuevo Cliente</h3>
                </div>
                <button @click="$wire.close()" class="text-gray-400 hover:text-gray-900">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- Tabs Navigation -->
            <div class="border-b border-gray-200 bg-white sticky top-16 z-10">
                <nav class="flex space-x-8 px-6" aria-label="Tabs">
                    <button @click="$wire.setActiveTab('basic')"
                        :class="$wire.activeTab === 'basic' ? 'border-emerald-500 text-emerald-600' :
                            'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200">
                        <div class="flex items-center space-x-2">
                            <i class="fas fa-user"></i>
                            <span>Cliente Básico</span>
                        </div>
                    </button>
                    <button @click="$wire.setActiveTab('complete')"
                        :class="$wire.activeTab === 'complete' ? 'border-emerald-500 text-emerald-600' :
                            'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200">
                        <div class="flex items-center space-x-2">
                            <i class="fas fa-file-invoice"></i>
                            <span>Cliente para Facturación Electrónica</span>
                        </div>
                    </button>
                </nav>
            </div>

            <!-- Tab Content -->
            <div class="p-6">
                <!-- Tab 1: Cliente Básico -->
                <div x-show="$wire.activeTab === 'basic'" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
                    <form wire:submit.prevent="createBasic" class="space-y-6">
                        <div class="bg-white rounded-xl border border-gray-100 p-6">
                            <!-- Nombre completo -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Nombre completo <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-user text-gray-400 text-sm"></i>
                                    </div>
                                    <input type="text" wire:model.blur="basicData.name"
                                        oninput="this.value = this.value.toUpperCase()"
                                        class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all uppercase"
                                        placeholder="EJ: JUAN PÉREZ GARCÍA">
                                </div>
                                @if (isset($errors['name']))
                                    <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                        <i class="fas fa-exclamation-circle mr-1.5"></i>
                                        {{ $errors['name'] }}
                                    </p>
                                @endif
                            </div>

                            <!-- Tipo y Número de Identificación -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Tipo de Identificación <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <select wire:model.live="basicData.identification_document_id"
                                            class="block w-full pl-3 pr-10 py-2.5 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent appearance-none bg-white">
                                            <option value="">Seleccione...</option>
                                            @foreach ($identificationDocuments as $doc)
                                                <option value="{{ $doc->id }}">{{ $doc->name }}</option>
                                            @endforeach
                                        </select>
                                        <div
                                            class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                            <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                                        </div>
                                    </div>
                                    @if (isset($errors['identification_document_id']))
                                        <p class="mt-1.5 text-xs text-red-600">
                                            {{ $errors['identification_document_id'] }}</p>
                                    @endif
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Número de Identificación <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <div
                                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <i class="fas fa-id-card text-gray-400 text-sm"></i>
                                        </div>
                                        <input type="text" wire:model.blur="basicData.identification"
                                            oninput="this.value = this.value.replace(/\D/g, '');" maxlength="10"
                                            minlength="6" pattern="\d{6,10}"
                                            class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all"
                                            placeholder="Ej: 123456789"
                                            title="El número de identificación debe tener entre 6 y 10 dígitos">
                                    </div>
                                    @if (isset($errors['identification']))
                                        <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                            <i class="fas fa-exclamation-circle mr-1.5"></i>
                                            {{ $errors['identification'] }}
                                        </p>
                                    @endif
                                    @if ($identificationMessage)
                                        <p
                                            class="mt-1.5 text-xs flex items-center {{ $identificationExists ? 'text-red-600' : 'text-emerald-600' }}">
                                            <i
                                                class="fas {{ $identificationExists ? 'fa-exclamation-circle' : 'fa-check-circle' }} mr-1.5"></i>
                                            <span>{{ $identificationMessage }}</span>
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <!-- Teléfono -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Teléfono <span class="text-gray-400 font-normal">(Opcional)</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-phone text-gray-400 text-sm"></i>
                                    </div>
                                    <input type="text" wire:model.blur="basicData.phone"
                                        oninput="this.value = this.value.replace(/\D/g, ''); if(this.value.length > 10) this.value = this.value.slice(0, 10);"
                                        maxlength="10"
                                        class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all"
                                        placeholder="Ej: 3001234567">
                                </div>
                                @if (isset($errors['phone']))
                                    <p class="mt-1.5 text-xs text-red-600 flex items-center">
                                        <i class="fas fa-exclamation-circle mr-1.5"></i>
                                        {{ $errors['phone'] }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        <!-- Botón de creación -->
                        <div class="flex justify-end">
                            <button type="submit" wire:loading.attr="disabled" wire:target="createBasic"
                                class="inline-flex items-center justify-center px-6 py-3 rounded-xl border-2 border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 hover:border-emerald-700 transition-all duration-200 shadow-sm hover:shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                                <span wire:loading.remove wire:target="createBasic">
                                    <i class="fas fa-save mr-2"></i>
                                    Crear Cliente Básico
                                </span>
                                <span wire:loading wire:target="createBasic" class="flex items-center justify-center">
                                    <i class="fas fa-spinner fa-spin mr-2"></i>
                                    Creando...
                                </span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Tab 2: Cliente para Facturación Electrónica DIAN -->
                <div x-show="$wire.activeTab === 'complete'" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
                    <form wire:submit.prevent="createDian" class="space-y-6">
                        <div class="bg-white rounded-xl border border-gray-100 p-6 space-y-6">

                            <!-- Mensaje informativo -->
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="flex items-start">
                                    <i class="fas fa-info-circle text-blue-600 mt-0.5 mr-3"></i>
                                    <div class="text-sm text-blue-800">
                                        <p class="font-semibold mb-1">Campos Obligatorios para Facturación Electrónica
                                        </p>
                                        <p class="text-xs">Complete todos los campos marcados con <span
                                                class="text-red-500 font-bold">*</span> para poder generar facturas
                                            electrónicas válidas según la normativa DIAN.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- 1️⃣ Identificación del cliente -->
                            <div class="border-l-4 border-blue-500 pl-4">
                                <h4 class="text-sm font-semibold text-gray-900 mb-4">1️⃣ Identificación del cliente
                                </h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-2">
                                            Tipo de documento <span class="text-red-500">*</span>
                                        </label>
                                        <select wire:model.live="dianData.identification_document_id"
                                            class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                                            <option value="">Seleccione...</option>
                                            @foreach ($identificationDocuments as $doc)
                                                <option value="{{ $doc->id }}" data-code="{{ $doc->code }}">
                                                    {{ $doc->name }}@if ($doc->code)
                                                        ({{ $doc->code }})
                                                    @endif
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-2">
                                            Número de identificación <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" wire:model="dianData.identification"
                                            oninput="this.value = this.value.replace(/\D/g, '');" maxlength="10"
                                            minlength="6" pattern="\d{6,10}"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm"
                                            placeholder="Ej: 123456789"
                                            title="El número de identificación debe tener entre 6 y 10 dígitos">
                                    </div>
                                </div>

                                <!-- Dígito Verificación (solo para NIT) -->
                                <div wire:show="selectedDocumentCode === 'NIT'" class="mt-4">
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">
                                        Dígito Verificación (DV) <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" wire:model="dianData.dv" maxlength="1"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm font-bold text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        placeholder="Ej: 5">
                                    <p class="mt-1 text-xs text-gray-500">
                                        <i class="fas fa-info-circle mr-1"></i> Diligencie el dígito de verificación
                                        del NIT
                                    </p>
                                </div>
                            </div>

                            <!-- 2️⃣ Nombre del cliente -->
                            <div class="border-l-4 border-green-500 pl-4">
                                <h4 class="text-sm font-semibold text-gray-900 mb-4">2️⃣ Nombre del cliente</h4>

                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-2">
                                            Nombre completo <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" wire:model="dianData.name"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
                                    </div>
                                    
                                    <!-- Campo Company (solo para personas jurídicas) -->
                                    <div wire:show="dianData.legal_organization_id == 1" class="mt-4">
                                        <label class="block text-xs font-semibold text-gray-700 mb-2">
                                            Razón Social (Empresa) <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" wire:model="dianData.company"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm"
                                            placeholder="Se autocompleta con el nombre del cliente">
                                        <p class="mt-1 text-xs text-gray-500">
                                            <i class="fas fa-info-circle mr-1"></i> 
                                            Este campo se autocompleta cuando selecciona "Persona Jurídica"
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- 3️⃣ Información de contacto -->
                            <div class="border-l-4 border-orange-500 pl-4">
                                <h4 class="text-sm font-semibold text-gray-900 mb-4">3️⃣ Información de contacto</h4>

                                <!-- País -->
                                <div class="mb-4">
                                    <label class="block text-xs font-semibold text-gray-700 mb-2">
                                        País <span class="text-red-500">*</span>
                                    </label>
                                    <select wire:model.live="dianData.country"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
                                        <option value="CO">🇨🇴 Colombia</option>
                                        <option value="OTRO">🌍 Otro país</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">
                                        <i class="fas fa-info-circle mr-1"></i> Para clientes colombianos, el municipio es obligatorio
                                    </p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-2">
                                            Dirección <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" wire:model="dianData.address"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-2">
                                            Correo electrónico <span class="text-red-500">*</span>
                                        </label>
                                        <input type="email" wire:model="dianData.email"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
                                        <p class="mt-1 text-xs text-gray-500">
                                            <i class="fas fa-envelope mr-1"></i>
                                            Usado para enviar facturas electrónicas
                                        </p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-2">
                                            Teléfono
                                        </label>
                                        <input type="text" wire:model="dianData.phone"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-2">
                                            Municipio <span wire:show="dianData.country === 'CO'"   
                                                class="text-red-500">*</span>
                                        </label>
                                        <select wire:model="dianData.municipality_id"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
                                            <option value="">Seleccione un municipio...</option>
                                            @php
                                                $currentDepartment = null;
                                            @endphp
                                            @foreach ($municipalities as $municipality)
                                                @if ($currentDepartment !== $municipality->department)
                                                    @if ($currentDepartment !== null)
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
                                                @if ($loop->last)
                                                    </optgroup>
                                                @endif
                                            @endforeach
                                        </select>
                                        @if ($dianData['country'] != 'CO')
                                            <p class="mt-1 text-xs text-gray-500">
                                                <i class="fas fa-globe mr-1"></i>
                                                Opcional para clientes extranjeros
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <!-- 4️⃣ Información tributaria -->
                            <div class="border-l-4 border-red-500 pl-4">
                                <h4 class="text-sm font-semibold text-gray-900 mb-4">4️⃣ Información tributaria
                                    (OBLIGATORIA)</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-2">
                                            Tipo de organización legal <span class="text-red-500">*</span>
                                        </label>
                                        <select wire:model="dianData.legal_organization_id"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
                                            <option value="">Seleccione...</option>
                                            @foreach ($legalOrganizations as $org)
                                                <option value="{{ $org->id }}">{{ $org->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-2">
                                            Tipo de tributo <span class="text-red-500">*</span>
                                        </label>
                                        <select wire:model="dianData.tribute_id"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
                                            <option value="">Seleccione...</option>
                                            @foreach ($tributes as $tribute)
                                                <option value="{{ $tribute->id }}">{{ $tribute->name }}@if ($tribute->code)
                                                        ({{ $tribute->code }})
                                                    @endif
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Botón de creación -->
                        <div class="flex justify-end">
                            <button type="submit" wire:loading.attr="disabled" wire:target="createDian"
                                class="inline-flex items-center justify-center px-6 py-3 rounded-xl border-2 border-blue-600 bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 hover:border-blue-700 transition-all duration-200 shadow-sm hover:shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                                <span wire:loading.remove wire:target="createDian">
                                    <i class="fas fa-save mr-2"></i>
                                    Crear Cliente para Facturación Electrónica prueba
                                </span>
                                <span wire:loading wire:target="createDian" class="flex items-center justify-center">
                                    <i class="fas fa-spinner fa-spin mr-2"></i>
                                    Creando...
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            @if (isset($errors['general']))
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
